<?php
/**
 * Abilities file: lettura/ricerca in wp-content, scrittura in themes/, mu-plugins/ e plugins/ast-*, log, plugin, cambio tema.
 */

namespace AgentSiteTools\Abilities;

use AgentSiteTools\Abilities;
use AgentSiteTools\Files;
use AgentSiteTools\Util;

defined( 'ABSPATH' ) || exit;

Abilities::add(
	'file-list',
	array(
		'label'       => 'File: elenco',
		'description' => 'Elenca file e cartelle sotto wp-content (percorso relativo, es. "themes/nome-tema", "plugins/elementor", "mu-plugins"; vuoto = wp-content). Con hash=true restituisce l\'md5 di ogni file, utile per confrontare un tema/plugin con il pacchetto originale e trovare le modifiche.',
		'input'       => Util::obj(
			array(
				'path'    => Util::s( 'Cartella relativa a wp-content (default "").' ),
				'depth'   => Util::i( 'Profondità (0 = solo il livello indicato; default 1, max 20).' ),
				'hash'    => Util::b( 'Aggiungi md5 di ogni file.' ),
				'pattern' => Util::s( 'Filtro sul nome file, es. "*.php".' ),
				'max'     => Util::i( 'Massimo voci (default 2000, max 10000).' ),
			)
		),
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			return Files::listing( Util::str( $in, 'path' ), Util::int( $in, 'depth', 1 ), Util::bool( $in, 'hash' ), Util::str( $in, 'pattern' ), Util::int( $in, 'max', 2000 ) );
		},
	)
);

Abilities::add(
	'file-read',
	array(
		'label'       => 'File: leggi',
		'description' => 'Legge un file sotto wp-content (es. "themes/nome-tema/functions.php", "plugins/x/x.php"). Restituisce contenuto, sha1 (da passare a file-write come expected_sha1), dimensione, data. Con offset/limit legge solo un intervallo di righe. I file binari tornano in base64.',
		'input'       => Util::obj(
			array(
				'path'   => Util::s( 'File relativo a wp-content.' ),
				'offset' => Util::i( 'Prima riga (1-based, opzionale).' ),
				'limit'  => Util::i( 'Numero di righe (opzionale).' ),
			),
			array( 'path' )
		),
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			return Files::read( Util::str( $in, 'path' ), Util::int( $in, 'offset' ), Util::int( $in, 'limit' ) );
		},
	)
);

Abilities::add(
	'file-search',
	array(
		'label'       => 'File: cerca nel codice',
		'description' => 'Cerca un testo (o una regex PHP con delimitatori, es. "/add_filter\\(\\s*\'the_content/") nei file di testo sotto un percorso di wp-content. Restituisce file, riga e testo di ogni occorrenza.',
		'input'       => Util::obj(
			array(
				'path'    => Util::s( 'Cartella o file relativo a wp-content (default "").' ),
				'query'   => Util::s( 'Testo da cercare (case-insensitive) o regex.' ),
				'regex'   => Util::b( 'Interpreta query come regex.' ),
				'pattern' => Util::s( 'Filtro sul nome file, es. "*.php".' ),
				'max'     => Util::i( 'Massimo risultati (default 200).' ),
			),
			array( 'query' )
		),
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			return Files::search( Util::str( $in, 'path' ), Util::str( $in, 'query' ), Util::bool( $in, 'regex' ), Util::str( $in, 'pattern' ), Util::int( $in, 'max', 200 ) );
		},
	)
);

Abilities::add(
	'file-write',
	array(
		'label'       => 'File: scrivi',
		'description' => 'Crea o modifica un file in themes/, mu-plugins/ o plugins/ast-* (es. child theme, functions.php, un plugin nuovo "plugins/ast-nome/ast-nome.php"). Le cartelle mancanti vengono create. Passare content (file completo), oppure edits [{old_str,new_str}] (ogni old_str deve comparire una sola volta; tutte o nessuna), oppure base64 per file binari (es. screenshot.png). expected_sha1 (da file-read) blocca la scrittura se il file è cambiato. Per i PHP: controllo sintassi prima, poi richiesta di verifica al sito; se va in errore fatale il file viene ripristinato. Snapshot automatico.',
		'input'       => Util::obj(
			array(
				'path'          => Util::s( 'File relativo a wp-content, es. "themes/nome-tema-child/functions.php".' ),
				'content'       => Util::s( 'Contenuto completo.' ),
				'edits'         => Util::a(
					'Modifiche puntuali.',
					Util::obj(
						array(
							'old_str' => Util::s( 'Testo esatto da sostituire (unico nel file).' ),
							'new_str' => Util::s( 'Testo nuovo (vuoto = elimina).' ),
						),
						array( 'old_str', 'new_str' )
					)
				),
				'base64'        => Util::s( 'Contenuto binario in base64.' ),
				'expected_sha1' => Util::s( 'sha1 atteso del file attuale (opzionale).' ),
				'verify'        => Util::b( 'Verifica il sito dopo la scrittura di un PHP (default true).' ),
				'note'          => Util::note(),
			),
			array( 'path' )
		),
		'cap'         => 'manage_options',
		'destructive' => true,
		'run'         => static function ( array $in ) {
			return Files::write( Util::str( $in, 'path' ), $in );
		},
	)
);

Abilities::add(
	'file-delete',
	array(
		'label'       => 'File: elimina',
		'description' => 'Elimina un file in themes/, mu-plugins/ o plugins/ast-*. Snapshot automatico (ripristinabile con snapshot-restore).',
		'input'       => Util::obj(
			array(
				'path' => Util::s( 'File relativo a wp-content.' ),
				'note' => Util::note(),
			),
			array( 'path' )
		),
		'cap'         => 'manage_options',
		'destructive' => true,
		'run'         => static function ( array $in ) {
			return Files::delete( Util::str( $in, 'path' ), Util::str( $in, 'note' ) );
		},
	)
);

Abilities::add(
	'log-read',
	array(
		'label'       => 'Sistema: leggi log errori',
		'description' => 'Ultime righe del debug.log di WordPress e dell\'error_log di PHP, con filtro testuale opzionale. Indica anche se WP_DEBUG / WP_DEBUG_LOG sono attivi.',
		'input'       => Util::obj(
			array(
				'lines'  => Util::i( 'Righe per file (default 100, max 2000).' ),
				'filter' => Util::s( 'Mostra solo le righe che contengono questo testo.' ),
			)
		),
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$out = array(
				'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
				'logs'         => array(),
			);
			foreach ( Files::log_files() as $k => $f ) {
				$readable           = is_readable( $f );
				$out['logs'][ $k ] = array(
					'file'     => $f,
					'readable' => $readable,
					'bytes'    => $readable ? (int) filesize( $f ) : 0,
					'lines'    => $readable ? Files::tail( $f, Util::int( $in, 'lines', 100 ), Util::str( $in, 'filter' ) ) : array(),
				);
			}
			return $out;
		},
	)
);

Abilities::add(
	'theme-switch',
	array(
		'label'       => 'Tema: attiva',
		'description' => 'Attiva un tema installato (es. il child theme "nome-tema-child"). Con copy_mods (default true) copia nel nuovo tema i theme mods di quello attuale (posizioni menu, logo, ecc.) se non ne ha già. Verifica il sito dopo il cambio e torna al tema precedente in caso di errore fatale. Snapshot automatico.',
		'input'       => Util::obj(
			array(
				'stylesheet'     => Util::s( 'Cartella del tema da attivare.' ),
				'copy_mods'      => Util::b( 'Copia i theme mods del tema attuale (default true).' ),
				'overwrite_mods' => Util::b( 'Sovrascrivi i theme mods già presenti nel nuovo tema (default false).' ),
				'note'           => Util::note(),
			),
			array( 'stylesheet' )
		),
		'cap'         => 'switch_themes',
		'destructive' => true,
		'run'         => static function ( array $in ) {
			return Files::switch_theme( Util::str( $in, 'stylesheet' ), Util::bool( $in, 'copy_mods', true ), Util::bool( $in, 'overwrite_mods' ), Util::str( $in, 'note' ) );
		},
	)
);

Abilities::add(
	'plugin-list',
	array(
		'label'       => 'Plugin: elenco',
		'description' => 'Tutti i plugin installati (attivi e no) con file principale, versione, stato e se i loro file sono scrivibili (plugins/ast-*).',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			return Files::plugins_list();
		},
	)
);

Abilities::add(
	'plugin-activate',
	array(
		'label'       => 'Plugin: attiva',
		'description' => 'Attiva un plugin installato (file principale, es. "ast-schema/ast-schema.php"). Per i plugin ast-* controlla prima la sintassi di tutti i PHP; poi prova il caricamento in un processo separato e, se il sito va in errore fatale, lascia il plugin disattivato. Solo dopo esegue l\'attivazione vera (hook di attivazione). Snapshot di active_plugins.',
		'input'       => Util::obj(
			array(
				'plugin' => Util::s( 'File principale del plugin, relativo a plugins/.' ),
				'note'   => Util::note(),
			),
			array( 'plugin' )
		),
		'cap'         => 'activate_plugins',
		'destructive' => true,
		'run'         => static function ( array $in ) {
			return Files::plugin_activate( Util::str( $in, 'plugin' ), Util::str( $in, 'note' ) );
		},
	)
);

Abilities::add(
	'plugin-deactivate',
	array(
		'label'       => 'Plugin: disattiva',
		'description' => 'Disattiva un plugin (non Agent Site Tools). Se il sito va in errore fatale dopo la disattivazione, lo riattiva. Snapshot di active_plugins.',
		'input'       => Util::obj(
			array(
				'plugin' => Util::s( 'File principale del plugin, relativo a plugins/.' ),
				'note'   => Util::note(),
			),
			array( 'plugin' )
		),
		'cap'         => 'activate_plugins',
		'destructive' => true,
		'run'         => static function ( array $in ) {
			return Files::plugin_deactivate( Util::str( $in, 'plugin' ), Util::str( $in, 'note' ) );
		},
	)
);
