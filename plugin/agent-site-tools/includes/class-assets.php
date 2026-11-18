<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * File CSS/JS versionati in uploads/agent-site-tools/, caricati su tutto il sito,
 * solo in homepage o su pagine specifiche. Fuori dal plugin: gli aggiornamenti non li toccano.
 */
final class Assets {

	const OPTION = 'ast_assets';

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 30 );
	}

	public static function dir(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'agent-site-tools';
	}

	public static function url(): string {
		return trailingslashit( wp_upload_dir()['baseurl'] ) . 'agent-site-tools';
	}

	public static function ensure_dir(): void {
		$d = self::dir();
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
		}
		if ( ! file_exists( $d . '/index.php' ) ) {
			file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
	}

	public static function registry(): array {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) ? $r : array();
	}

	public static function key( string $name, string $type ): string {
		return $name . '.' . $type;
	}

	public static function sanitize_name( string $name ): string {
		return trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $name ) ), '-' );
	}

	/** @return array|\WP_Error */
	public static function write( string $name, string $type, string $content, array $opts = array(), string $note = '' ) {
		$name = self::sanitize_name( $name );
		if ( '' === $name || ! in_array( $type, array( 'css', 'js' ), true ) ) {
			return Util::err( 'bad_asset', "Nome o tipo non validi (type: css|js)." );
		}
		if ( 'js' === $type && ! current_user_can( 'unfiltered_html' ) ) {
			return Util::err( 'forbidden', 'Serve il permesso unfiltered_html per scrivere JavaScript.', 403 );
		}
		self::ensure_dir();
		$key  = self::key( $name, $type );
		$file = self::dir() . '/' . $key;
		$reg  = self::registry();
		$prev = $reg[ $key ] ?? null;

		$snap = Snapshots::take(
			'asset',
			$key,
			array(
				'exists'  => file_exists( $file ),
				'content' => file_exists( $file ) ? (string) file_get_contents( $file ) : '', // phpcs:ignore
				'entry'   => $prev,
			),
			$key,
			$note
		);

		if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore
			return Util::err( 'write_failed', 'Impossibile scrivere ' . $file, 500 );
		}

		$entry = array(
			'type'     => $type,
			'scope'    => in_array( $opts['scope'] ?? ( $prev['scope'] ?? 'global' ), array( 'global', 'front', 'pages' ), true ) ? ( $opts['scope'] ?? ( $prev['scope'] ?? 'global' ) ) : 'global',
			'pages'    => array_map( 'intval', (array) ( $opts['pages'] ?? ( $prev['pages'] ?? array() ) ) ),
			'enabled'  => isset( $opts['enabled'] ) ? (bool) $opts['enabled'] : ( $prev['enabled'] ?? true ),
			'priority' => isset( $opts['priority'] ) ? (int) $opts['priority'] : ( $prev['priority'] ?? 10 ),
			'version'  => (string) time(),
		);
		$reg[ $key ] = $entry;
		update_option( self::OPTION, $reg, false );

		return array_merge(
			array(
				'name'        => $key,
				'url'         => self::url() . '/' . $key,
				'bytes'       => strlen( $content ),
				'snapshot_id' => $snap,
			),
			$entry
		);
	}

	/** @return array|\WP_Error */
	public static function restore( string $key, array $data, string $note ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Util::err( 'forbidden', 'Permessi insufficienti.', 403 );
		}
		$file = self::dir() . '/' . basename( $key );
		Snapshots::take(
			'asset',
			$key,
			array(
				'exists'  => file_exists( $file ),
				'content' => file_exists( $file ) ? (string) file_get_contents( $file ) : '', // phpcs:ignore
				'entry'   => self::registry()[ $key ] ?? null,
			),
			$key,
			$note
		);
		$reg = self::registry();
		if ( empty( $data['exists'] ) ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore
			}
			unset( $reg[ $key ] );
		} else {
			self::ensure_dir();
			file_put_contents( $file, (string) $data['content'] ); // phpcs:ignore
			$entry            = is_array( $data['entry'] ?? null ) ? $data['entry'] : array( 'type' => pathinfo( $key, PATHINFO_EXTENSION ), 'scope' => 'global', 'pages' => array(), 'enabled' => true, 'priority' => 10 );
			$entry['version'] = (string) time();
			$reg[ $key ]      = $entry;
		}
		update_option( self::OPTION, $reg, false );
		return array( 'restored' => $key );
	}

	/** @return array|\WP_Error */
	public static function delete( string $key, string $note = '' ) {
		$reg  = self::registry();
		$file = self::dir() . '/' . basename( $key );
		if ( ! isset( $reg[ $key ] ) && ! file_exists( $file ) ) {
			return Util::err( 'asset_not_found', 'Asset inesistente: ' . $key, 404 );
		}
		$snap = Snapshots::take(
			'asset',
			$key,
			array(
				'exists'  => file_exists( $file ),
				'content' => file_exists( $file ) ? (string) file_get_contents( $file ) : '', // phpcs:ignore
				'entry'   => $reg[ $key ] ?? null,
			),
			$key,
			$note
		);
		if ( file_exists( $file ) ) {
			unlink( $file ); // phpcs:ignore
		}
		unset( $reg[ $key ] );
		update_option( self::OPTION, $reg, false );
		return array(
			'deleted'     => $key,
			'snapshot_id' => $snap,
		);
	}

	public static function enqueue(): void {
		if ( is_admin() || isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore
			// Nel preview dell'editor si caricano solo i CSS: il JS potrebbe interferire con l'editing.
			$css_only = true;
		} else {
			$css_only = false;
		}
		$reg = self::registry();
		uasort(
			$reg,
			static function ( $a, $b ) {
				return ( $a['priority'] ?? 10 ) <=> ( $b['priority'] ?? 10 );
			}
		);
		$motion = wp_script_is( 'ast-motion', 'registered' );

		foreach ( $reg as $key => $e ) {
			if ( empty( $e['enabled'] ) || ! self::in_scope( $e ) ) {
				continue;
			}
			$src    = self::url() . '/' . $key;
			$handle = 'ast-asset-' . str_replace( '.', '-', $key );
			if ( 'css' === $e['type'] ) {
				wp_enqueue_style( $handle, $src, array(), $e['version'] );
			} elseif ( ! $css_only ) {
				wp_enqueue_script(
					$handle,
					$src,
					$motion ? array( 'ast-motion' ) : array(),
					$e['version'],
					array(
						'in_footer' => true,
						'strategy'  => 'defer',
					)
				);
			}
		}
	}

	private static function in_scope( array $e ): bool {
		switch ( $e['scope'] ?? 'global' ) {
			case 'front':
				return is_front_page();
			case 'pages':
				if ( ! is_singular() ) {
					return false;
				}
				$id = (int) get_queried_object_id();
				// Traduzioni Polylang: valgono gli ID della pagina nella lingua predefinita
				if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) ) {
					$it = (int) pll_get_post( $id, pll_default_language() );
					if ( $it ) {
						$id = $it;
					}
				}
				return in_array( $id, (array) ( $e['pages'] ?? array() ), true );
			default:
				return true;
		}
	}
}
