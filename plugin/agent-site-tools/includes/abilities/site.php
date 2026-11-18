<?php
/**
 * Abilities sito: panoramica, opzioni (incluse quelle del tema), theme mods, homepage.
 */

namespace AgentSiteTools\Abilities;

use AgentSiteTools\Abilities;
use AgentSiteTools\Assets;
use AgentSiteTools\Motion;
use AgentSiteTools\Server;
use AgentSiteTools\Snapshots;
use AgentSiteTools\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Prefissi delle opzioni del tema attivo che l'assistente puo leggere e scrivere.
 * Vuoto per default: si aggiungono con il filtro 'ast_theme_option_prefixes' (vedi AGENTS.md).
 */
function ast_theme_option_prefixes(): array {
	return array_values( array_filter( (array) apply_filters( 'ast_theme_option_prefixes', array() ), 'is_string' ) );
}

/** Opzioni scrivibili: elenco esplicito + prefissi. */
function ast_option_writable( string $name ): bool {
	$exact = array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'date_format', 'time_format', 'site_icon', 'sidebars_widgets', 'thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h' );
	if ( in_array( $name, $exact, true ) ) {
		return true;
	}
	if ( Util::is_secret_name( $name ) ) {
		return false;
	}
	foreach ( array_merge( ast_theme_option_prefixes(), array( 'elementor_', 'widget_', 'aioseo_options', 'theme_mods_' ) ) as $prefix ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return true;
		}
	}
	return false;
}

Abilities::add(
	'site-info',
	array(
		'label'       => 'Sito: panoramica',
		'description' => 'Versioni (WordPress, PHP, Elementor, Pro), tema, plugin attivi, homepage e pagina articoli, posizioni menu, opzioni del tema presenti (prefissi da ast_theme_option_prefixes), endpoint MCP, impostazioni motion e asset caricati. Leggere all\'inizio di ogni sessione.',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			global $wpdb;
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all     = get_plugins();
			$plugins = array();
			foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
				$plugins[] = array(
					'file'    => $file,
					'name'    => $all[ $file ]['Name'] ?? $file,
					'version' => $all[ $file ]['Version'] ?? '',
				);
			}
			$theme = wp_get_theme();
			$theme_opts = array();
			$prefixes   = ast_theme_option_prefixes();
			if ( $prefixes ) {
				$likes      = implode( ' OR ', array_fill( 0, count( $prefixes ), 'option_name LIKE %s' ) );
				$args       = array_map( static function ( $p ) use ( $wpdb ) { return $wpdb->esc_like( $p ) . '%'; }, $prefixes );
				$theme_opts = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE {$likes} ORDER BY option_name LIMIT 100", $args ) ); // phpcs:ignore
			}

			return array(
				'site'      => array(
					'name'        => get_bloginfo( 'name' ),
					'description' => get_bloginfo( 'description' ),
					'url'         => home_url( '/' ),
					'locale'      => get_locale(),
					'timezone'    => wp_timezone_string(),
				),
				'versions'  => array(
					'wordpress'     => get_bloginfo( 'version' ),
					'php'           => PHP_VERSION,
					'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
					'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
					'agent_site_tools'  => AST_VERSION,
				),
				'theme'     => array(
					'name'     => $theme->get( 'Name' ),
					'version'  => $theme->get( 'Version' ),
					'template' => $theme->get_template(),
					'child'    => is_child_theme(),
				),
				'reading'   => array(
					'show_on_front'  => get_option( 'show_on_front' ),
					'page_on_front'  => (int) get_option( 'page_on_front' ),
					'page_for_posts' => (int) get_option( 'page_for_posts' ),
				),
				'menus'     => array(
					'registered' => get_registered_nav_menus(),
					'assigned'   => get_nav_menu_locations(),
				),
				'logo'      => array(
					'custom_logo' => (int) get_theme_mod( 'custom_logo' ),
					'site_icon'   => (int) get_option( 'site_icon' ),
				),
				'theme_options' => $theme_opts,
				'plugins'   => $plugins,
				'mcp'       => array(
					'adapter'  => Server::adapter_available(),
					'endpoint' => Server::endpoint(),
				),
				'motion'    => Motion::settings(),
				'assets'    => Assets::registry(),
			);
		},
	)
);

Abilities::add(
	'option-get',
	array(
		'label'       => 'Opzioni: leggi',
		'description' => 'Legge una o più opzioni WordPress (comprese quelle del tema, di Elementor e di All in One SEO). Con path legge un sotto-valore di un\'opzione array (es. "header-logo_regular"). Le opzioni con segreti vengono mascherate.',
		'input'       => Util::obj(
			array(
				'names' => Util::a( 'Nomi opzione.', array( 'type' => 'string' ) ),
				'path'  => Util::s( 'Chiave interna (opzionale, separatore "/").' ),
				'keys'  => Util::b( 'Restituire solo le chiavi di primo livello (per opzioni molto grandi).' ),
			),
			array( 'names' )
		),
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$out = array();
			foreach ( Util::arr( $in, 'names' ) as $name ) {
				$name = (string) $name;
				if ( Util::is_secret_name( $name ) ) {
					$out[ $name ] = '***';
					continue;
				}
				$v    = get_option( $name, null );
				$path = Util::str( $in, 'path' );
				if ( '' !== $path && is_array( $v ) ) {
					foreach ( explode( '/', $path ) as $seg ) {
						$v = is_array( $v ) && array_key_exists( $seg, $v ) ? $v[ $seg ] : null;
					}
				}
				if ( Util::bool( $in, 'keys' ) && is_array( $v ) ) {
					$v = array_keys( $v );
				}
				$out[ $name ] = $v;
			}
			return array( 'options' => $out );
		},
	)
);

Abilities::add(
	'option-update',
	array(
		'label'       => 'Opzioni: aggiorna',
		'description' => 'Aggiorna un\'opzione consentita (lettura, homepage, icona sito, opzioni del tema (prefissi da ast_theme_option_prefixes), elementor_*, widget, aioseo_options). Con merge=true unisce un array a quello esistente (utile per le opzioni del tema salvate in un unico array, es. loghi). Con path aggiorna una sola chiave interna. Snapshot automatico.',
		'input'       => Util::obj(
			array(
				'name'  => Util::s( 'Nome opzione.' ),
				'value' => Util::any( 'Nuovo valore.' ),
				'merge' => Util::b( 'Unisci array con quello esistente.' ),
				'path'  => Util::s( 'Chiave interna da aggiornare (separatore "/").' ),
				'note'  => Util::note(),
			),
			array( 'name', 'value' )
		),
		'destructive' => true,
		'run'         => static function ( array $in ) {
			$name = Util::str( $in, 'name' );
			if ( ! ast_option_writable( $name ) ) {
				return Util::err( 'option_locked', "Opzione non modificabile da qui: {$name}.", 403 );
			}
			$cur    = get_option( $name, null );
			$exists = null !== $cur;
			$snap   = Snapshots::take( 'option', $name, array( 'exists' => $exists, 'value' => $cur ), $name, Util::str( $in, 'note' ) );
			$value  = $in['value'] ?? null;

			$path = Util::str( $in, 'path' );
			if ( '' !== $path ) {
				$base = is_array( $cur ) ? $cur : array();
				$ref  = &$base;
				foreach ( explode( '/', $path ) as $seg ) {
					if ( ! isset( $ref[ $seg ] ) || ! is_array( $ref[ $seg ] ) ) {
						$ref[ $seg ] = $ref[ $seg ] ?? array();
					}
					$ref = &$ref[ $seg ];
				}
				$ref = $value;
				unset( $ref );
				$value = $base;
			} elseif ( Util::bool( $in, 'merge' ) && is_array( $cur ) && is_array( $value ) ) {
				$value = array_replace_recursive( $cur, $value );
			}
			update_option( $name, $value );

			// Temi che generano CSS dinamico dalle proprie opzioni: si agganciano qui per rigenerarlo.
			do_action( 'ast_option_updated', $name, $value );
			return array(
				'name'        => $name,
				'snapshot_id' => $snap,
			);
		},
	)
);

Abilities::add(
	'theme-mods',
	array(
		'label'       => 'Tema: theme mods',
		'description' => 'Legge (senza name/value) o imposta un theme mod (es. custom_logo con ID media, nav_menu_locations). value null rimuove. Snapshot automatico.',
		'input'       => Util::obj(
			array(
				'name'  => Util::s( 'Nome theme mod (vuoto = solo lettura di tutti).' ),
				'value' => Util::any( 'Nuovo valore.' ),
				'note'  => Util::note(),
			)
		),
		'cap'         => 'edit_theme_options',
		'run'         => static function ( array $in ) {
			$name = Util::str( $in, 'name' );
			if ( '' === $name || ! array_key_exists( 'value', $in ) ) {
				$mods = get_theme_mods();
				return array( 'theme_mods' => '' === $name ? $mods : array( $name => $mods[ $name ] ?? null ) );
			}
			$snap = Snapshots::take( 'theme_mod', $name, array( 'value' => get_theme_mod( $name ) ), $name, Util::str( $in, 'note' ) );
			if ( null === $in['value'] ) {
				remove_theme_mod( $name );
			} else {
				set_theme_mod( $name, $in['value'] );
			}
			return array(
				'name'        => $name,
				'snapshot_id' => $snap,
			);
		},
	)
);

Abilities::add(
	'homepage-set',
	array(
		'label'       => 'Sito: imposta homepage',
		'description' => 'Imposta la pagina statica di homepage e, opzionalmente, la pagina degli articoli.',
		'input'       => Util::obj(
			array(
				'page_id'       => Util::i( 'Pagina homepage.' ),
				'posts_page_id' => Util::i( 'Pagina articoli (opzionale).' ),
			),
			array( 'page_id' )
		),
		'run'         => static function ( array $in ) {
			$page = Util::int( $in, 'page_id' );
			if ( 'page' !== get_post_type( $page ) ) {
				return Util::err( 'not_page', 'L\'ID indicato non è una pagina.' );
			}
			foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $o ) {
				Snapshots::take( 'option', $o, array( 'exists' => true, 'value' => get_option( $o ) ), $o, 'homepage-set' );
			}
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page );
			if ( isset( $in['posts_page_id'] ) ) {
				update_option( 'page_for_posts', Util::int( $in, 'posts_page_id' ) );
			}
			return array(
				'page_on_front'  => (int) get_option( 'page_on_front' ),
				'page_for_posts' => (int) get_option( 'page_for_posts' ),
			);
		},
	)
);
