<?php
/**
 * Abilities Elementor: lettura/scrittura completa dei documenti (V3, widget del tema, V4),
 * operazioni puntuali, ricerca/sostituzione, template e condizioni, kit.
 */

namespace AgentSiteTools\Abilities;

use AgentSiteTools\Abilities;
use AgentSiteTools\ElementorDoc;
use AgentSiteTools\Util;

defined( 'ABSPATH' ) || exit;

$can_edit = static function ( array $in ): bool {
	$id = Util::int( $in, 'post_id' );
	return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_pages' );
};

$element_schema = Util::o( 'Elemento Elementor: { id?, elType: container|widget|section|column, widgetType?, settings: {}, elements: [], isInner?, styles?, editor_settings? }. Gli id mancanti vengono generati.' );

Abilities::add(
	'elementor-outline',
	array(
		'label'       => 'Elementor: struttura compatta',
		'description' => 'Albero compatto di un documento Elementor (pagina, articolo, header, footer, template): id, tipo, widget, anteprima del testo, immagine, classi. Leggerlo prima di modificare. Funziona anche con i widget del tema e i V3 classici.',
		'input'       => Util::obj(
			array(
				'post_id'   => Util::i( 'ID del documento.' ),
				'max_depth' => Util::i( 'Profondità massima (default 20).' ),
			),
			array( 'post_id' )
		),
		'perm'        => $can_edit,
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$id  = Util::int( $in, 'post_id' );
			$els = ElementorDoc::load( $id );
			if ( is_wp_error( $els ) ) {
				return $els;
			}
			return array(
				'post_id'       => $id,
				'title'         => get_the_title( $id ),
				'post_type'     => get_post_type( $id ),
				'template_type' => (string) get_post_meta( $id, '_elementor_template_type', true ),
				'edit_mode'     => (string) get_post_meta( $id, '_elementor_edit_mode', true ),
				'count'         => ElementorDoc::count( $els ),
				'tree'          => ElementorDoc::outline( $els, 0, Util::int( $in, 'max_depth', 20 ) ),
			);
		},
	)
);

Abilities::add(
	'elementor-get',
	array(
		'label'       => 'Elementor: leggi documento o elemento',
		'description' => 'JSON completo di un documento Elementor oppure di un singolo elemento (element_id), con impostazioni pagina. Usare element_id per non caricare pagine intere.',
		'input'       => Util::obj(
			array(
				'post_id'          => Util::i( 'ID del documento.' ),
				'element_id'       => Util::s( 'ID elemento (opzionale): restituisce solo quel nodo con i figli.' ),
				'include_settings' => Util::b( 'Includere le impostazioni pagina (default true).' ),
			),
			array( 'post_id' )
		),
		'perm'        => $can_edit,
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$id  = Util::int( $in, 'post_id' );
			$els = ElementorDoc::load( $id );
			if ( is_wp_error( $els ) ) {
				return $els;
			}
			$eid = Util::str( $in, 'element_id' );
			if ( '' !== $eid ) {
				$path = ElementorDoc::locate( $els, $eid );
				if ( null === $path ) {
					return Util::err( 'element_not_found', 'Elemento non trovato: ' . $eid, 404 );
				}
				$sib = ElementorDoc::siblings( $els, $path );
				return array(
					'post_id' => $id,
					'element' => $sib[ end( $path ) ],
				);
			}
			$out = array(
				'post_id'  => $id,
				'elements' => $els,
			);
			if ( Util::bool( $in, 'include_settings', true ) ) {
				$out['page_settings'] = ElementorDoc::page_settings( $id );
			}
			return $out;
		},
	)
);

Abilities::add(
	'elementor-save',
	array(
		'label'       => 'Elementor: salva documento intero',
		'description' => 'Sostituisce l\'intero albero di un documento Elementor (ed eventualmente le impostazioni pagina). Snapshot automatico prima della scrittura. Per modifiche puntuali usare elementor-patch.',
		'input'       => Util::obj(
			array(
				'post_id'       => Util::i( 'ID del documento.' ),
				'elements'      => Util::a( 'Albero completo degli elementi.', array( 'type' => 'object' ) ),
				'page_settings' => Util::o( 'Impostazioni pagina (opzionale; sostituisce quelle esistenti).' ),
				'note'          => Util::note(),
			),
			array( 'post_id', 'elements' )
		),
		'perm'        => $can_edit,
		'destructive' => true,
		'run'         => static function ( array $in ) {
			$id = Util::int( $in, 'post_id' );
			if ( ! get_post( $id ) ) {
				return Util::err( 'not_found', 'Documento inesistente.', 404 );
			}
			$settings = array_key_exists( 'page_settings', $in ) && is_array( $in['page_settings'] ) ? $in['page_settings'] : null;
			return ElementorDoc::save( $id, Util::arr( $in, 'elements' ), $settings, Util::str( $in, 'note' ) );
		},
	)
);

Abilities::add(
	'elementor-patch',
	array(
		'label'       => 'Elementor: modifiche puntuali',
		'description' => 'Applica in un solo salvataggio una lista di operazioni sull\'albero: '
			. 'update {id, settings (merge; null cancella la chiave), replace_settings?, styles?, interactions?}; '
			. 'replace {id, element}; delete {id}; duplicate {id}; move {id, parent_id, position}; '
			. 'insert {parent_id ("document" = radice), position (-1 = in fondo), element | elements}. '
			. 'Funziona su widget V3, widget del tema e V4. Snapshot automatico.',
		'input'       => Util::obj(
			array(
				'post_id'    => Util::i( 'ID del documento.' ),
				'operations' => Util::a(
					'Operazioni, applicate in ordine.',
					Util::obj(
						array(
							'op'               => Util::s( 'update|replace|delete|duplicate|move|insert', array( 'enum' => array( 'update', 'replace', 'delete', 'duplicate', 'move', 'insert' ) ) ),
							'id'               => Util::s( 'ID elemento (tutte tranne insert).' ),
							'settings'         => Util::o( 'Impostazioni da unire (update).' ),
							'replace_settings' => Util::b( 'Sostituisce le impostazioni invece di unirle.' ),
							'styles'           => Util::any( 'Stili V4 dell\'elemento (update).' ),
							'interactions'     => Util::any( 'Interazioni V4 (update).' ),
							'element'          => $element_schema,
							'elements'         => Util::a( 'Più elementi da inserire.', array( 'type' => 'object' ) ),
							'parent_id'        => Util::s( 'Contenitore di destinazione (insert/move).' ),
							'position'         => Util::i( 'Indice di destinazione, -1 in fondo.' ),
						),
						array( 'op' )
					)
				),
				'dry_run'    => Util::b( 'Valida e restituisce il risultato senza salvare.' ),
				'note'       => Util::note(),
			),
			array( 'post_id', 'operations' )
		),
		'perm'        => $can_edit,
		'destructive' => true,
		'run'         => static function ( array $in ) {
			$id  = Util::int( $in, 'post_id' );
			$els = ElementorDoc::load( $id );
			if ( is_wp_error( $els ) ) {
				return $els;
			}
			$report = ElementorDoc::apply_ops( $els, Util::arr( $in, 'operations' ) );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			if ( Util::bool( $in, 'dry_run' ) ) {
				return array(
					'dry_run'    => true,
					'operations' => $report,
					'tree'       => ElementorDoc::outline( $els ),
				);
			}
			$saved = ElementorDoc::save( $id, $els, null, Util::str( $in, 'note' ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$saved['operations'] = $report;
			return $saved;
		},
	)
);

Abilities::add(
	'elementor-search',
	array(
		'label'       => 'Elementor: cerca in tutti i documenti',
		'description' => 'Trova pagine, articoli e template il cui contenuto Elementor contiene un testo (es. URL del logo, una frase, un widgetType). Restituisce i documenti e gli id degli elementi coinvolti. Una sola query.',
		'input'       => Util::obj(
			array(
				'needle'     => Util::s( 'Testo da cercare.' ),
				'post_types' => Util::a( 'Limita ai post type indicati (es. page, post, elementor_library).', array( 'type' => 'string' ) ),
				'limit'      => Util::i( 'Massimo documenti (default 50).' ),
			),
			array( 'needle' )
		),
		'cap'         => 'edit_pages',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			global $wpdb;
			$needle  = Util::str( $in, 'needle' );
			$escaped = trim( (string) wp_json_encode( $needle ), '"' );
			$needles = array_values( array_unique( array_filter( array( $needle, $escaped ) ) ) );
			if ( ! $needles ) {
				return Util::err( 'empty', 'Testo di ricerca vuoto.' );
			}
			$likes = array();
			$args  = array();
			foreach ( $needles as $n ) {
				$likes[] = 'm.meta_value LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $n ) . '%';
			}
			$types_sql = '';
			$types     = array_filter( array_map( 'sanitize_key', Util::arr( $in, 'post_types' ) ) );
			if ( $types ) {
				$types_sql = ' AND p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
				$args      = array_merge( $args, array_values( $types ) );
			}
			$args[] = max( 1, min( 200, Util::int( $in, 'limit', 50 ) ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_type, p.post_status, m.meta_value
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
					 WHERE (" . implode( ' OR ', $likes ) . ") AND p.post_status NOT IN ('trash','auto-draft','inherit'){$types_sql}
					 ORDER BY p.post_type, p.ID LIMIT %d",
					$args
				),
				ARRAY_A
			);

			$find = array_values( array_unique( array( $needle, wp_unslash( $escaped ) ) ) );
			$out  = array();
			foreach ( (array) $rows as $r ) {
				$els   = json_decode( $r['meta_value'], true );
				$out[] = array(
					'post_id'       => (int) $r['ID'],
					'title'         => $r['post_title'],
					'post_type'     => $r['post_type'],
					'status'        => $r['post_status'],
					'template_type' => (string) get_post_meta( (int) $r['ID'], '_elementor_template_type', true ),
					'elements'      => is_array( $els ) ? ElementorDoc::find_text( $els, $find ) : array(),
				);
			}
			return array(
				'needle'  => $needle,
				'matches' => $out,
			);
		},
	)
);

Abilities::add(
	'elementor-replace',
	array(
		'label'       => 'Elementor: sostituisci testo',
		'description' => 'Sostituisce un testo in tutte le stringhe dei documenti Elementor indicati (o in tutti quelli che lo contengono): testi, URL di immagini e loghi, link. Snapshot per ogni documento modificato. Usare dry_run per vedere prima i conteggi.',
		'input'       => Util::obj(
			array(
				'search'   => Util::s( 'Testo da cercare (esatto, case-sensitive).' ),
				'replace'  => Util::s( 'Testo sostitutivo.' ),
				'post_ids' => Util::a( 'Documenti da modificare (vuoto = tutti quelli che contengono il testo).', array( 'type' => 'integer' ) ),
				'dry_run'  => Util::b( 'Solo conteggio, nessuna scrittura.' ),
				'note'     => Util::note(),
			),
			array( 'search', 'replace' )
		),
		'cap'         => 'edit_pages',
		'destructive' => true,
		'run'         => static function ( array $in ) {
			global $wpdb;
			$search  = Util::str( $in, 'search' );
			$replace = Util::str( $in, 'replace' );
			if ( '' === $search ) {
				return Util::err( 'empty', 'Testo di ricerca vuoto.' );
			}
			$ids = array_filter( array_map( 'intval', Util::arr( $in, 'post_ids' ) ) );
			if ( ! $ids ) {
				$escaped = trim( (string) wp_json_encode( $search ), '"' );
				$ids     = array_map(
					'intval',
					$wpdb->get_col(
						$wpdb->prepare(
							"SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
							 WHERE m.meta_key = '_elementor_data' AND p.post_status NOT IN ('trash','auto-draft','inherit')
							 AND (m.meta_value LIKE %s OR m.meta_value LIKE %s)",
							'%' . $wpdb->esc_like( $search ) . '%',
							'%' . $wpdb->esc_like( $escaped ) . '%'
						)
					)
				);
			}
			$dry = Util::bool( $in, 'dry_run' );
			$out = array();
			foreach ( $ids as $id ) {
				if ( ! current_user_can( 'edit_post', $id ) ) {
					$out[] = array( 'post_id' => $id, 'error' => 'forbidden' );
					continue;
				}
				$els = ElementorDoc::load( $id );
				if ( is_wp_error( $els ) || ! $els ) {
					continue;
				}
				$n = 0;
				ElementorDoc::replace_strings( $els, $search, $replace, $n );
				if ( ! $n ) {
					continue;
				}
				$row = array(
					'post_id'      => $id,
					'title'        => get_the_title( $id ),
					'replacements' => $n,
				);
				if ( ! $dry ) {
					$saved = ElementorDoc::save( $id, $els, null, Util::str( $in, 'note', 'replace: ' . $search ) );
					if ( ! is_wp_error( $saved ) ) {
						$row['snapshot_id'] = $saved['snapshot_id'];
					}
				}
				$out[] = $row;
			}
			return array(
				'dry_run'   => $dry,
				'documents' => $out,
			);
		},
	)
);

Abilities::add(
	'elementor-create',
	array(
		'label'       => 'Elementor: crea documento',
		'description' => 'Crea una pagina, un articolo o un template Elementor (header, footer, section, container, single-post, single-page, archive, popup, loop-item...) con contenuto opzionale e, per i template del Theme Builder, condizioni di visualizzazione.',
		'input'       => Util::obj(
			array(
				'title'         => Util::s( 'Titolo.' ),
				'type'          => Util::s( 'page | post | header | footer | section | container | single-post | single-page | archive | search-results | error-404 | popup | loop-item' ),
				'status'        => Util::s( 'draft (default) | publish | private' ),
				'elements'      => Util::a( 'Albero iniziale (opzionale).', array( 'type' => 'object' ) ),
				'page_settings' => Util::o( 'Impostazioni pagina (opzionale).' ),
				'page_template' => Util::s( 'Template pagina: elementor_header_footer | elementor_canvas | default (solo page/post).' ),
				'conditions'    => Util::a( 'Condizioni Theme Builder, es. ["include/general"], ["include/singular/page/123"].', array( 'type' => 'string' ) ),
				'slug'          => Util::s( 'Slug (opzionale).' ),
			),
			array( 'title', 'type' )
		),
		'cap'         => 'edit_pages',
		'run'         => static function ( array $in ) {
			$type   = sanitize_key( Util::str( $in, 'type', 'page' ) );
			$status = in_array( Util::str( $in, 'status', 'draft' ), array( 'draft', 'publish', 'private', 'pending' ), true ) ? Util::str( $in, 'status', 'draft' ) : 'draft';
			$is_wp  = in_array( $type, array( 'page', 'post' ), true );

			$post = array(
				'post_title'  => Util::str( $in, 'title' ),
				'post_status' => $status,
				'post_type'   => $is_wp ? $type : 'elementor_library',
			);
			if ( Util::str( $in, 'slug' ) ) {
				$post['post_name'] = sanitize_title( Util::str( $in, 'slug' ) );
			}
			if ( 'publish' === $status && ! current_user_can( $is_wp && 'post' === $type ? 'publish_posts' : 'publish_pages' ) ) {
				$post['post_status'] = 'draft';
			}
			$id = wp_insert_post( wp_slash( $post ), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			update_post_meta( $id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $id, '_elementor_template_type', $is_wp ? 'wp-' . $type : $type );
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
			}
			if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
				update_post_meta( $id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
			}
			if ( ! $is_wp ) {
				wp_set_object_terms( $id, $type, 'elementor_library_type' );
			}
			$tpl = Util::str( $in, 'page_template' );
			if ( $is_wp && $tpl ) {
				update_post_meta( $id, '_wp_page_template', sanitize_text_field( $tpl ) );
			}

			$settings = isset( $in['page_settings'] ) && is_array( $in['page_settings'] ) ? $in['page_settings'] : null;
			$saved    = ElementorDoc::save( $id, Util::arr( $in, 'elements' ), $settings, 'create' );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$out = array(
				'post_id'  => $id,
				'type'     => $type,
				'status'   => get_post_status( $id ),
				'url'      => get_permalink( $id ),
				'edit_url' => admin_url( 'post.php?post=' . $id . '&action=elementor' ),
			);
			$conds = Util::arr( $in, 'conditions' );
			if ( $conds && ! $is_wp ) {
				$out['conditions'] = ast_set_conditions( $id, $conds );
			}
			return $out;
		},
	)
);

/**
 * Salva le condizioni del Theme Builder (Elementor Pro), con rigenerazione cache.
 *
 * @param string[] $conds es. include/general, include/singular/page/123
 * @return array|string
 */
function ast_set_conditions( int $post_id, array $conds ) {
	$conds = array_values( array_filter( array_map( 'strval', $conds ) ) );
	if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
		try {
			$cm = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
			if ( method_exists( $cm, 'save_conditions' ) ) {
				$objs = array();
				foreach ( $conds as $c ) {
					$p      = explode( '/', $c );
					$objs[] = array(
						'type'     => $p[0] ?? 'include',
						'name'     => $p[1] ?? 'general',
						'sub_name' => $p[2] ?? '',
						'sub_id'   => $p[3] ?? '',
					);
				}
				$cm->save_conditions( $post_id, $objs );
				return (array) get_post_meta( $post_id, '_elementor_conditions', true );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
		}
	}
	update_post_meta( $post_id, '_elementor_conditions', $conds );
	if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
		try {
			$cache = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache();
			if ( method_exists( $cache, 'regenerate' ) ) {
				$cache->regenerate();
			}
		} catch ( \Throwable $e ) { // phpcs:ignore
		}
	}
	return $conds;
}

Abilities::add(
	'elementor-templates',
	array(
		'label'       => 'Elementor: template e Theme Builder',
		'description' => 'Elenca i template Elementor (header, footer, single, archive, popup, section...) con tipo, stato e condizioni di visualizzazione.',
		'input'       => Util::obj(
			array(
				'type' => Util::s( 'Filtra per tipo template (opzionale).' ),
			)
		),
		'cap'         => 'edit_pages',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$args = array(
				'post_type'              => 'elementor_library',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 300,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			);
			$type = sanitize_key( Util::str( $in, 'type' ) );
			if ( $type ) {
				$args['meta_query'] = array( array( 'key' => '_elementor_template_type', 'value' => $type ) ); // phpcs:ignore
			}
			$out = array();
			foreach ( get_posts( $args ) as $p ) {
				$out[] = array(
					'post_id'    => $p->ID,
					'title'      => $p->post_title,
					'type'       => (string) get_post_meta( $p->ID, '_elementor_template_type', true ),
					'status'     => $p->post_status,
					'conditions' => (array) get_post_meta( $p->ID, '_elementor_conditions', true ),
					'modified'   => $p->post_modified,
				);
			}
			return array( 'templates' => $out );
		},
	)
);

Abilities::add(
	'elementor-set-conditions',
	array(
		'label'       => 'Elementor: condizioni Theme Builder',
		'description' => 'Imposta dove viene mostrato un template del Theme Builder: es. ["include/general"] per tutto il sito, ["include/singular/front_page"], ["include/singular/page/123"], ["exclude/singular/page/45"]. Lista vuota = template disattivato.',
		'input'       => Util::obj(
			array(
				'post_id'    => Util::i( 'ID del template.' ),
				'conditions' => Util::a( 'Condizioni.', array( 'type' => 'string' ) ),
			),
			array( 'post_id', 'conditions' )
		),
		'perm'        => $can_edit,
		'run'         => static function ( array $in ) {
			$id = Util::int( $in, 'post_id' );
			if ( 'elementor_library' !== get_post_type( $id ) ) {
				return Util::err( 'not_template', 'Il documento non è un template Elementor.' );
			}
			return array(
				'post_id'    => $id,
				'conditions' => ast_set_conditions( $id, Util::arr( $in, 'conditions' ) ),
			);
		},
	)
);

Abilities::add(
	'elementor-kit-get',
	array(
		'label'       => 'Elementor: impostazioni sito (kit)',
		'description' => 'Legge le impostazioni globali del kit Elementor: colori e tipografie globali V3, layout (larghezza contenuti, breakpoint), identità sito, CSS personalizzato. Usare keys per filtrare.',
		'input'       => Util::obj(
			array(
				'keys' => Util::a( 'Chiavi da restituire (vuoto = tutte).', array( 'type' => 'string' ) ),
			)
		),
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$kit = ElementorDoc::kit_id();
			if ( ! $kit ) {
				return Util::err( 'no_kit', 'Kit Elementor attivo non trovato.', 404 );
			}
			$s    = ElementorDoc::page_settings( $kit );
			$keys = Util::arr( $in, 'keys' );
			if ( $keys ) {
				$s = array_intersect_key( $s, array_flip( $keys ) );
			}
			return array(
				'kit_id'         => $kit,
				'settings'       => $s,
				'global_classes' => ElementorDoc::global_classes(),
			);
		},
	)
);

Abilities::add(
	'elementor-kit-update',
	array(
		'label'       => 'Elementor: aggiorna impostazioni sito (kit)',
		'description' => 'Unisce nuove impostazioni nel kit Elementor (null cancella una chiave): system_colors, custom_colors, system_typography, custom_typography, container_width, custom_css, site_name, site_logo... Snapshot automatico. Le variabili e classi V4 si gestiscono con gli strumenti Elementor MCP.',
		'input'       => Util::obj(
			array(
				'settings' => Util::o( 'Impostazioni da unire.' ),
				'note'     => Util::note(),
			),
			array( 'settings' )
		),
		'destructive' => true,
		'run'         => static function ( array $in ) {
			$kit = ElementorDoc::kit_id();
			if ( ! $kit ) {
				return Util::err( 'no_kit', 'Kit Elementor attivo non trovato.', 404 );
			}
			$snap = ElementorDoc::snapshot( $kit, Util::str( $in, 'note' ), 'kit' );
			$new  = Util::merge_nullable( ElementorDoc::page_settings( $kit ), Util::arr( $in, 'settings' ) );
			update_post_meta( $kit, '_elementor_page_settings', $new );
			ElementorDoc::flush_css( $kit );
			return array(
				'kit_id'      => $kit,
				'snapshot_id' => $snap,
				'keys'        => array_keys( Util::arr( $in, 'settings' ) ),
			);
		},
	)
);
