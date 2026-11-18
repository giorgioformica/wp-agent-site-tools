<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Accesso ai file di wp-content: lettura ovunque, scrittura solo nelle radici consentite
 * (themes/, mu-plugins/, plugins/ast-*). Ogni scrittura salva uno snapshot; i file PHP vengono controllati
 * (sintassi prima, richiesta di verifica dopo) e ripristinati se il sito va in errore fatale.
 */
final class Files {

	const OPTION_WRITE = 'ast_file_write';
	const MAX_READ     = 1048576;  // 1 MB
	const MAX_WRITE    = 2097152;  // 2 MB
	const PING_ACTION  = 'ast_ping';
	const PING_MARK    = 'ast-ok';

	const TEXT_EXT   = array( 'php', 'css', 'js', 'json', 'txt', 'md', 'html', 'htm', 'svg', 'xml', 'po', 'pot', 'twig', 'scss', 'less', 'ini', 'htaccess', 'log' );
	const BINARY_EXT = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'mo' );

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::PING_ACTION, array( __CLASS__, 'ping' ) );
		add_action( 'wp_ajax_nopriv_' . self::PING_ACTION, array( __CLASS__, 'ping' ) );
	}

	/** Risposta minima della richiesta di verifica: se arriva, WordPress + tema + mu-plugins si caricano. */
	public static function ping(): void {
		nocache_headers();
		echo self::PING_MARK; // phpcs:ignore
		wp_die();
	}

	public static function write_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_WRITE, '1' );
	}

	public static function base(): string {
		return wp_normalize_path( untrailingslashit( realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR ) );
	}

	/** Radici scrivibili, relative a wp-content. */
	public static function writable_roots(): array {
		return (array) apply_filters( 'ast_writable_roots', array( 'themes', 'mu-plugins', 'plugins/ast-*' ) );
	}

	/* ---------- percorsi ---------- */

	/**
	 * Converte un percorso relativo a wp-content in assoluto, verificando che resti dentro wp-content.
	 *
	 * @return array{abs:string,rel:string}|\WP_Error
	 */
	public static function resolve( string $rel ) {
		$rel = trim( wp_normalize_path( $rel ), '/' );
		if ( false !== strpos( $rel, "\0" ) ) {
			return Util::err( 'bad_path', 'Percorso non valido.' );
		}
		foreach ( explode( '/', $rel ) as $seg ) {
			if ( '..' === $seg ) {
				return Util::err( 'bad_path', 'Percorso non valido ("..").' );
			}
		}
		$base = self::base();
		$abs  = '' === $rel ? $base : $base . '/' . $rel;

		// Verifica sul primo antenato esistente (gestisce symlink e file non ancora creati).
		$probe = $abs;
		while ( ! file_exists( $probe ) && strlen( $probe ) > strlen( $base ) ) {
			$probe = dirname( $probe );
		}
		$real = wp_normalize_path( (string) realpath( $probe ) );
		if ( '' === $real || ( $real !== $base && 0 !== strpos( $real . '/', $base . '/' ) ) ) {
			return Util::err( 'bad_path', 'Percorso fuori da wp-content.' );
		}
		return array(
			'abs' => $abs,
			'rel' => $rel,
		);
	}

	/** Radici con jolly ammesse (es. "plugins/ast-*"): il confronto avviene segmento per segmento. */
	public static function is_writable_path( string $rel ): bool {
		$segs = explode( '/', $rel );
		foreach ( self::writable_roots() as $root ) {
			$rsegs = explode( '/', trim( $root, '/' ) );
			if ( count( $segs ) <= count( $rsegs ) ) {
				continue; // la radice stessa non è un file scrivibile
			}
			$ok = true;
			foreach ( $rsegs as $i => $r ) {
				if ( ! fnmatch( $r, $segs[ $i ] ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	public static function ext( string $path ): string {
		$b = strtolower( basename( $path ) );
		if ( '.htaccess' === $b ) {
			return 'htaccess';
		}
		return strtolower( pathinfo( $b, PATHINFO_EXTENSION ) );
	}

	private static function is_binary_string( string $s ): bool {
		return '' !== $s && ( false !== strpos( substr( $s, 0, 8000 ), "\0" ) || ! mb_check_encoding( $s, 'UTF-8' ) );
	}

	/* ---------- lettura ---------- */

	/** @return array|\WP_Error */
	public static function read( string $rel, int $offset = 0, int $limit = 0 ) {
		$p = self::resolve( $rel );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( ! is_file( $p['abs'] ) ) {
			return Util::err( 'not_found', 'File inesistente: ' . $p['rel'], 404 );
		}
		$size = (int) filesize( $p['abs'] );
		if ( $size > self::MAX_READ && $limit <= 0 ) {
			return Util::err( 'too_big', 'File di ' . $size . ' byte: usa offset/limit (righe).', 413 );
		}
		$raw = (string) file_get_contents( $p['abs'] ); // phpcs:ignore
		$out = array(
			'path'  => $p['rel'],
			'bytes' => $size,
			'sha1'  => sha1( $raw ),
			'mtime' => gmdate( 'c', (int) filemtime( $p['abs'] ) ),
		);
		if ( self::is_binary_string( $raw ) ) {
			$out['binary'] = true;
			$out['base64'] = $size <= self::MAX_READ ? base64_encode( $raw ) : null; // phpcs:ignore
			return $out;
		}
		$lines          = preg_split( '/\r\n|\n|\r/', $raw );
		$out['lines']   = count( $lines );
		if ( $offset > 0 || $limit > 0 ) {
			$start          = max( 1, $offset );
			$slice          = array_slice( $lines, $start - 1, $limit > 0 ? $limit : null );
			$out['from']    = $start;
			$out['to']      = $start + count( $slice ) - 1;
			$out['content'] = implode( "\n", $slice );
		} else {
			$out['content'] = $raw;
		}
		return $out;
	}

	/**
	 * Elenco ricorsivo. Con hash=true aggiunge md5 di ogni file (per confrontare con un pacchetto originale).
	 *
	 * @return array|\WP_Error
	 */
	public static function listing( string $rel, int $depth = 1, bool $hash = false, string $pattern = '', int $max = 2000 ) {
		$p = self::resolve( $rel );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( ! is_dir( $p['abs'] ) ) {
			return Util::err( 'not_found', 'Cartella inesistente: ' . $p['rel'], 404 );
		}
		$max   = max( 1, min( 10000, $max ) );
		$depth = max( 0, min( 20, $depth ) );
		$it    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $p['abs'], \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$it->setMaxDepth( $depth );
		$base_len  = strlen( wp_normalize_path( $p['abs'] ) ) + 1;
		$entries   = array();
		$truncated = false;
		foreach ( $it as $f ) {
			$rp = substr( wp_normalize_path( $f->getPathname() ), $base_len );
			if ( '' !== $pattern && ! $f->isDir() && ! fnmatch( $pattern, basename( $rp ) ) ) {
				continue;
			}
			if ( count( $entries ) >= $max ) {
				$truncated = true;
				break;
			}
			$e = array(
				'path' => $rp,
				'type' => $f->isDir() ? 'dir' : 'file',
			);
			if ( $f->isFile() ) {
				$e['bytes'] = $f->getSize();
				$e['mtime'] = gmdate( 'c', $f->getMTime() );
				if ( $hash ) {
					$e['md5'] = md5_file( $f->getPathname() );
				}
			}
			$entries[] = $e;
		}
		usort(
			$entries,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);
		return array(
			'path'      => $p['rel'],
			'count'     => count( $entries ),
			'truncated' => $truncated,
			'entries'   => $entries,
		);
	}

	/** @return array|\WP_Error */
	public static function search( string $rel, string $query, bool $regex = false, string $pattern = '', int $max = 200 ) {
		$p = self::resolve( $rel );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( '' === $query ) {
			return Util::err( 'bad_query', 'Testo da cercare mancante.' );
		}
		if ( $regex && false === @preg_match( $query, '' ) ) { // phpcs:ignore
			return Util::err( 'bad_regex', 'Espressione regolare non valida (serve con delimitatori, es. /foo/i).' );
		}
		$files = is_file( $p['abs'] ) ? array( new \SplFileInfo( $p['abs'] ) ) : new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $p['abs'], \FilesystemIterator::SKIP_DOTS ) );
		$base_len = strlen( self::base() ) + 1;
		$max      = max( 1, min( 1000, $max ) );
		$hits     = array();
		$scanned  = 0;
		foreach ( $files as $f ) {
			if ( ! $f->isFile() || $f->getSize() > self::MAX_READ || ! in_array( self::ext( $f->getFilename() ), self::TEXT_EXT, true ) ) {
				continue;
			}
			if ( '' !== $pattern && ! fnmatch( $pattern, $f->getFilename() ) ) {
				continue;
			}
			++$scanned;
			$fh = fopen( $f->getPathname(), 'r' ); // phpcs:ignore
			if ( ! $fh ) {
				continue;
			}
			$n = 0;
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore
				++$n;
				$ok = $regex ? preg_match( $query, $line ) : ( false !== stripos( $line, $query ) );
				if ( $ok ) {
					$hits[] = array(
						'path' => substr( wp_normalize_path( $f->getPathname() ), $base_len ),
						'line' => $n,
						'text' => mb_substr( rtrim( $line ), 0, 300 ),
					);
					if ( count( $hits ) >= $max ) {
						fclose( $fh ); // phpcs:ignore
						return array( 'matches' => $hits, 'truncated' => true, 'files_scanned' => $scanned );
					}
				}
			}
			fclose( $fh ); // phpcs:ignore
		}
		return array( 'matches' => $hits, 'truncated' => false, 'files_scanned' => $scanned );
	}

	/** Ultime righe di un file, leggendo dalla fine (senza caricare file grandi in memoria). */
	public static function tail( string $file, int $lines, string $filter = '' ): array {
		$fh = @fopen( $file, 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			return array();
		}
		$lines  = max( 1, min( 2000, $lines ) );
		$need   = '' === $filter ? $lines : $lines * 20;
		$chunk  = 65536;
		$pos    = (int) filesize( $file );
		$buffer = '';
		$count  = 0;
		while ( $pos > 0 && $count <= $need && strlen( $buffer ) < 8 * self::MAX_READ ) {
			$read    = min( $chunk, $pos );
			$pos    -= $read;
			fseek( $fh, $pos );
			$buffer  = fread( $fh, $read ) . $buffer; // phpcs:ignore
			$count   = substr_count( $buffer, "\n" );
		}
		fclose( $fh ); // phpcs:ignore
		$all = preg_split( '/\r\n|\n|\r/', rtrim( $buffer ) );
		if ( '' !== $filter ) {
			$all = array_values(
				array_filter(
					$all,
					static function ( $l ) use ( $filter ) {
						return false !== stripos( $l, $filter );
					}
				)
			);
		}
		return array_slice( $all, -$lines );
	}

	/** File di log disponibili (debug.log di WordPress e error_log di PHP). */
	public static function log_files(): array {
		$out = array();
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			$out['wp_debug'] = WP_DEBUG_LOG;
		} else {
			$out['wp_debug'] = WP_CONTENT_DIR . '/debug.log';
		}
		$php = (string) ini_get( 'error_log' );
		if ( '' !== $php && $php !== $out['wp_debug'] ) {
			$out['php'] = $php;
		}
		return $out;
	}

	/* ---------- scrittura ---------- */

	private static function state( string $abs ): array {
		$exists = is_file( $abs );
		return array(
			'exists' => $exists,
			'b64'    => $exists ? base64_encode( (string) file_get_contents( $abs ) ) : '', // phpcs:ignore
		);
	}

	private static function put( string $abs, string $content ): bool {
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$ok = false !== file_put_contents( $abs, $content, LOCK_EX ); // phpcs:ignore
		self::invalidate( $abs );
		return $ok;
	}

	private static function invalidate( string $abs ): void {
		clearstatcache( true, $abs );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $abs, true ); // phpcs:ignore
		}
	}

	/** Riporta il file allo stato salvato (contenuto o assenza). */
	private static function apply_state( string $abs, array $state ): void {
		if ( empty( $state['exists'] ) ) {
			if ( is_file( $abs ) ) {
				unlink( $abs ); // phpcs:ignore
			}
			self::invalidate( $abs );
			return;
		}
		self::put( $abs, (string) base64_decode( (string) $state['b64'] ) ); // phpcs:ignore
	}

	/** Controllo sintassi PHP senza eseguire il codice. @return array|null errore */
	public static function lint_php( string $code ): ?array {
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return array(
				'message' => $e->getMessage(),
				'line'    => $e->getLine(),
			);
		}
		return null;
	}

	/**
	 * Richiesta di verifica in un nuovo processo PHP (admin-ajax, mai in cache):
	 * carica WordPress, mu-plugins, plugin e functions.php del tema attivo.
	 *
	 * @return array{status:string,http?:int,detail?:string}  status: ok | fatal | unverified
	 */
	public static function verify_site(): array {
		$url = add_query_arg(
			array(
				'action' => self::PING_ACTION,
				't'      => wp_rand(),
			),
			admin_url( 'admin-ajax.php' )
		);
		$r   = wp_remote_post(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => false,
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return array(
				'status' => 'unverified',
				'detail' => 'Richiesta di verifica non riuscita: ' . $r->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = (string) wp_remote_retrieve_body( $r );
		if ( false !== strpos( $body, self::PING_MARK ) ) {
			return array( 'status' => 'ok', 'http' => $code );
		}
		$fatal = $code >= 500 || preg_match( '/critical error|errore critico|Fatal error|Parse error/i', $body );
		return array(
			'status' => $fatal ? 'fatal' : 'unverified',
			'http'   => $code,
			'detail' => mb_substr( trim( wp_strip_all_tags( $body ) ), 0, 600 ),
		);
	}

	/**
	 * Scrive un file: contenuto completo oppure modifiche puntuali (old_str/new_str).
	 *
	 * @param array $args content|base64|edits, expected_sha1, verify(bool), note
	 * @return array|\WP_Error
	 */
	public static function write( string $rel, array $args ) {
		if ( ! self::write_enabled() ) {
			return Util::err( 'write_disabled', 'Scrittura file disattivata (Strumenti → Agent Site Tools).', 403 );
		}
		$p = self::resolve( $rel );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( ! self::is_writable_path( $p['rel'] ) ) {
			return Util::err( 'forbidden_path', 'Scrittura consentita solo in: ' . implode( ', ', self::writable_roots() ) . '.', 403 );
		}
		$ext = self::ext( $p['rel'] );
		if ( ! in_array( $ext, self::TEXT_EXT, true ) && ! in_array( $ext, self::BINARY_EXT, true ) ) {
			return Util::err( 'bad_ext', 'Estensione non consentita: ' . $ext );
		}
		if ( is_dir( $p['abs'] ) ) {
			return Util::err( 'is_dir', 'Il percorso è una cartella.' );
		}

		$before  = self::state( $p['abs'] );
		$current = $before['exists'] ? (string) base64_decode( $before['b64'] ) : ''; // phpcs:ignore

		$expected = (string) ( $args['expected_sha1'] ?? '' );
		if ( '' !== $expected && $before['exists'] && sha1( $current ) !== $expected ) {
			return Util::err( 'changed', 'Il file è cambiato dopo la lettura (sha1 diverso): rileggilo.', 409 );
		}

		if ( isset( $args['edits'] ) && is_array( $args['edits'] ) && $args['edits'] ) {
			if ( ! $before['exists'] ) {
				return Util::err( 'not_found', 'Modifiche puntuali su un file inesistente.', 404 );
			}
			$new = $current;
			foreach ( array_values( $args['edits'] ) as $i => $e ) {
				$old = (string) ( $e['old_str'] ?? '' );
				$rep = (string) ( $e['new_str'] ?? '' );
				$n   = '' === $old ? 0 : substr_count( $new, $old );
				if ( 1 !== $n ) {
					return Util::err( 'edit_mismatch', 'Modifica #' . ( $i + 1 ) . ': old_str trovato ' . $n . ' volte (deve essere 1). Nessuna modifica applicata.' );
				}
				$new = str_replace( $old, $rep, $new );
			}
		} elseif ( isset( $args['base64'] ) && '' !== (string) $args['base64'] ) {
			$new = base64_decode( (string) $args['base64'], true ); // phpcs:ignore
			if ( false === $new ) {
				return Util::err( 'bad_base64', 'base64 non valido.' );
			}
		} elseif ( array_key_exists( 'content', $args ) ) {
			$new = (string) $args['content'];
		} else {
			return Util::err( 'no_content', 'Serve content, base64 oppure edits.' );
		}

		if ( strlen( $new ) > self::MAX_WRITE ) {
			return Util::err( 'too_big', 'Contenuto oltre ' . self::MAX_WRITE . ' byte.' );
		}
		if ( $before['exists'] && $new === $current ) {
			return array( 'path' => $p['rel'], 'unchanged' => true, 'sha1' => sha1( $new ) );
		}

		$is_php = 'php' === $ext;
		if ( $is_php ) {
			$lint = self::lint_php( $new );
			if ( $lint ) {
				return Util::err( 'php_syntax', 'Errore di sintassi PHP alla riga ' . $lint['line'] . ': ' . $lint['message'] . '. File non modificato.' );
			}
		}

		$snap = Snapshots::take( 'file', $p['rel'], $before, $p['rel'], (string) ( $args['note'] ?? '' ) );

		if ( ! self::put( $p['abs'], $new ) ) {
			return Util::err( 'write_failed', 'Impossibile scrivere ' . $p['rel'] . ' (permessi?).', 500 );
		}

		$out = array(
			'path'        => $p['rel'],
			'created'     => ! $before['exists'],
			'bytes'       => strlen( $new ),
			'sha1'        => sha1( $new ),
			'snapshot_id' => $snap,
		);

		if ( $is_php && ( ! array_key_exists( 'verify', $args ) || ! empty( $args['verify'] ) ) ) {
			$check         = self::verify_site();
			$out['verify'] = $check;
			if ( 'fatal' === $check['status'] ) {
				self::apply_state( $p['abs'], $before );
				$again = self::verify_site();
				return Util::err(
					'fatal_rolled_back',
					'Il sito è andato in errore fatale dopo la scrittura: file ripristinato (verifica dopo il ripristino: ' . $again['status'] . '). Dettaglio: ' . ( $check['detail'] ?? '' ) . ' — Ultime righe log: ' . implode( ' | ', self::recent_log_lines( 8 ) ),
					500
				);
			}
		}
		return $out;
	}

	private static function recent_log_lines( int $n ): array {
		$lines = array();
		foreach ( self::log_files() as $f ) {
			if ( is_readable( $f ) ) {
				$lines = array_merge( $lines, self::tail( $f, $n, '' ) );
			}
		}
		return array_slice( $lines, -$n );
	}

	/** @return array|\WP_Error */
	public static function delete( string $rel, string $note = '' ) {
		if ( ! self::write_enabled() ) {
			return Util::err( 'write_disabled', 'Scrittura file disattivata (Strumenti → Agent Site Tools).', 403 );
		}
		$p = self::resolve( $rel );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( ! self::is_writable_path( $p['rel'] ) ) {
			return Util::err( 'forbidden_path', 'Eliminazione consentita solo in: ' . implode( ', ', self::writable_roots() ) . '.', 403 );
		}
		if ( ! is_file( $p['abs'] ) ) {
			return Util::err( 'not_found', 'File inesistente: ' . $p['rel'], 404 );
		}
		$snap = Snapshots::take( 'file', $p['rel'], self::state( $p['abs'] ), $p['rel'], $note );
		unlink( $p['abs'] ); // phpcs:ignore
		self::invalidate( $p['abs'] );
		return array( 'deleted' => $p['rel'], 'snapshot_id' => $snap );
	}

	/** Ripristino da snapshot (chiamato da Snapshots::restore). @return array|\WP_Error */
	public static function restore( string $rel, array $data, string $note ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Util::err( 'forbidden', 'Permessi insufficienti.', 403 );
		}
		$p = self::resolve( $rel );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		Snapshots::take( 'file', $p['rel'], self::state( $p['abs'] ), $p['rel'], $note );
		self::apply_state( $p['abs'], $data );
		return array( 'restored' => $p['rel'] );
	}

	/* ---------- plugin ---------- */

	/** @return array|\WP_Error */
	public static function plugins_list() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );
		$out    = array();
		foreach ( get_plugins() as $file => $d ) {
			$out[] = array(
				'file'     => $file,
				'name'     => $d['Name'],
				'version'  => $d['Version'],
				'active'   => in_array( $file, $active, true ),
				'writable' => self::is_writable_path( 'plugins/' . $file ),
			);
		}
		return array( 'plugins' => $out );
	}

	/** Controllo sintassi di tutti i PHP di un plugin. @return array errori */
	private static function lint_dir( string $abs ): array {
		$errors = array();
		$files  = is_file( $abs ) ? array( new \SplFileInfo( $abs ) ) : new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $f ) {
			if ( $f->isFile() && 'php' === self::ext( $f->getFilename() ) ) {
				$e = self::lint_php( (string) file_get_contents( $f->getPathname() ) ); // phpcs:ignore
				if ( $e ) {
					$errors[] = substr( wp_normalize_path( $f->getPathname() ), strlen( self::base() ) + 1 ) . ':' . $e['line'] . ' ' . $e['message'];
				}
			}
		}
		return $errors;
	}

	/**
	 * Attiva un plugin installato. Prima prova il caricamento in un processo separato (richiesta di verifica):
	 * se va in errore fatale il plugin resta disattivato. Solo dopo esegue l'attivazione vera (hook di attivazione).
	 *
	 * @return array|\WP_Error
	 */
	public static function plugin_activate( string $file, string $note = '' ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = trim( wp_normalize_path( $file ), '/' );
		if ( 0 !== validate_file( $file ) || ! isset( get_plugins()[ $file ] ) ) {
			return Util::err( 'plugin_not_found', 'Plugin non installato: ' . $file . ' (usa plugin-list per il percorso esatto, es. "ast-x/ast-x.php").', 404 );
		}
		$before = (array) get_option( 'active_plugins', array() );
		if ( in_array( $file, $before, true ) ) {
			return array( 'plugin' => $file, 'active' => true, 'unchanged' => true );
		}

		// Sintassi: solo per i plugin nelle radici scrivibili (i nostri), per non scansionare plugin enormi.
		$dir = WP_PLUGIN_DIR . '/' . ( false !== strpos( $file, '/' ) ? dirname( $file ) : $file );
		if ( self::is_writable_path( 'plugins/' . $file ) ) {
			$errors = self::lint_dir( $dir );
			if ( $errors ) {
				return Util::err( 'php_syntax', 'Errori di sintassi, plugin non attivato: ' . implode( ' | ', array_slice( $errors, 0, 10 ) ) );
			}
		}

		$snap = Snapshots::take( 'option', 'active_plugins', array( 'exists' => true, 'value' => $before ), 'active_plugins', '' !== $note ? $note : 'pre attivazione ' . $file );

		// Prova di caricamento in un processo separato.
		$trial = $before;
		$trial[] = $file;
		sort( $trial );
		update_option( 'active_plugins', $trial );
		$check = self::verify_site();
		update_option( 'active_plugins', $before );
		if ( 'fatal' === $check['status'] ) {
			$again = self::verify_site();
			return Util::err( 'fatal_rolled_back', 'Il plugin manda il sito in errore fatale: non attivato (verifica dopo: ' . $again['status'] . '). Dettaglio: ' . ( $check['detail'] ?? '' ) . ' — Ultime righe log: ' . implode( ' | ', self::recent_log_lines( 8 ) ), 500 );
		}

		// Attivazione vera, con hook di attivazione.
		$res = activate_plugin( $file, '', false, false );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'plugin'      => $file,
			'active'      => is_plugin_active( $file ),
			'verify'      => $check,
			'snapshot_id' => $snap,
		);
	}

	/** @return array|\WP_Error */
	public static function plugin_deactivate( string $file, string $note = '' ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = trim( wp_normalize_path( $file ), '/' );
		if ( plugin_basename( AST_FILE ) === $file ) {
			return Util::err( 'forbidden', 'Agent Site Tools non può disattivare sé stesso (si perderebbe il connettore).', 403 );
		}
		$before = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( $file, $before, true ) ) {
			return array( 'plugin' => $file, 'active' => false, 'unchanged' => true );
		}
		$snap = Snapshots::take( 'option', 'active_plugins', array( 'exists' => true, 'value' => $before ), 'active_plugins', '' !== $note ? $note : 'pre disattivazione ' . $file );
		deactivate_plugins( $file, false, false );
		$check = self::verify_site();
		if ( 'fatal' === $check['status'] ) {
			update_option( 'active_plugins', $before );
			return Util::err( 'fatal_rolled_back', 'Il sito va in errore fatale senza questo plugin (dipendenza?): riattivato. Dettaglio: ' . ( $check['detail'] ?? '' ), 500 );
		}
		return array(
			'plugin'      => $file,
			'active'      => false,
			'verify'      => $check,
			'snapshot_id' => $snap,
		);
	}

	/* ---------- tema ---------- */

	/**
	 * Attiva un tema. Con copy_mods copia i theme mods del tema attuale (menu, logo, ecc.) nel nuovo
	 * se questo non ne ha. Verifica il sito dopo il cambio e torna indietro in caso di errore fatale.
	 *
	 * @return array|\WP_Error
	 */
	public static function switch_theme( string $stylesheet, bool $copy_mods, bool $overwrite_mods, string $note = '' ) {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return Util::err( 'theme_not_found', 'Tema inesistente: ' . $stylesheet, 404 );
		}
		if ( $theme->errors() ) {
			return Util::err( 'theme_broken', 'Tema non valido: ' . $theme->errors()->get_error_message() );
		}
		$prev = get_stylesheet();
		if ( $prev === $stylesheet ) {
			return array( 'active' => $stylesheet, 'unchanged' => true );
		}

		$snap = Snapshots::take( 'theme', 'active', array( 'stylesheet' => $prev ), 'Tema attivo: ' . $prev, $note );

		$copied = false;
		if ( $copy_mods ) {
			$src_mods = get_option( 'theme_mods_' . $prev );
			$dst_name = 'theme_mods_' . $stylesheet;
			$dst_mods = get_option( $dst_name );
			if ( is_array( $src_mods ) && ( $overwrite_mods || empty( $dst_mods ) ) ) {
				Snapshots::take( 'option', $dst_name, array( 'exists' => false !== $dst_mods, 'value' => $dst_mods ), $dst_name, 'pre theme-switch' );
				update_option( $dst_name, $src_mods );
				$copied = true;
			}
		}

		switch_theme( $stylesheet );

		$check = self::verify_site();
		if ( 'fatal' === $check['status'] ) {
			switch_theme( $prev );
			return Util::err( 'fatal_rolled_back', 'Errore fatale con il nuovo tema: riattivato ' . $prev . '. Dettaglio: ' . ( $check['detail'] ?? '' ) . ' — Ultime righe log: ' . implode( ' | ', self::recent_log_lines( 8 ) ), 500 );
		}

		return array(
			'active'      => get_stylesheet(),
			'template'    => get_template(),
			'previous'    => $prev,
			'mods_copied' => $copied,
			'verify'      => $check,
			'snapshot_id' => $snap,
		);
	}
}
