<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Server MCP dedicato: /wp-json/ast/mcp, con tutte le abilities esposte come tool diretti.
 * Richiede il plugin "MCP Adapter" (WordPress.org).
 */
final class Server {

	const ID        = 'agent-site-tools';
	const NS        = 'ast';
	const ROUTE     = 'mcp';

	public static function boot(): void {
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
		add_action( 'mcp_adapter_init', array( __CLASS__, 'create' ) );
	}

	public static function adapter_available(): bool {
		return class_exists( '\WP\MCP\Core\McpAdapter' );
	}

	public static function endpoint(): string {
		return rest_url( self::NS . '/' . self::ROUTE );
	}

	/** @param object $adapter */
	public static function create( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		$transport = self::first_class(
			array(
				'\WP\MCP\Transport\HttpTransport',
				'\WP\MCP\Transport\Http\HttpTransport',
			)
		);
		$errors    = self::first_class( array( '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler' ) );
		$observe   = self::first_class( array( '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler' ) );
		if ( ! $transport ) {
			return;
		}
		try {
			$adapter->create_server(
				self::ID,
				self::NS,
				self::ROUTE,
				'Agent Site Tools',
				'Controllo completo del sito: documenti Elementor (anche widget di temi e plugin terzi), articoli e pagine, SEO, media, menu, opzioni, design e animazioni, con snapshot ripristinabili.',
				'v' . AST_VERSION,
				array( $transport ),
				$errors,
				$observe,
				array_merge( Abilities::names(), self::partner_abilities() ),
				array(),
				array()
			);
		} catch ( \Throwable $e ) {
			error_log( '[agent-site-tools] create_server: ' . $e->getMessage() ); // phpcs:ignore
		}
	}

	/**
	 * Abilities di altri plugin da esporre sullo stesso endpoint (es. All in One SEO 4.9.8+),
	 * così basta un solo connettore.
	 *
	 * @return string[]
	 */
	public static function partner_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$prefixes = (array) apply_filters( 'ast_partner_prefixes', array( 'aioseo/' ) );
		$out      = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
			foreach ( $prefixes as $p ) {
				if ( '' !== $name && 0 === strpos( $name, $p ) ) {
					$out[] = $name;
					break;
				}
			}
		}
		return $out;
	}

	private static function first_class( array $candidates ): ?string {
		foreach ( $candidates as $c ) {
			if ( class_exists( $c ) ) {
				return ltrim( $c, '\\' );
			}
		}
		return null;
	}
}
