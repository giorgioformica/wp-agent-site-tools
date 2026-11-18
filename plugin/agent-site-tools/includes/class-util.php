<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Helper condivisi: schemi JSON, parsing input, errori.
 */
final class Util {

	/** Tipi JSON ammessi per un valore "qualsiasi". */
	const ANY = array( 'string', 'number', 'integer', 'boolean', 'object', 'array', 'null' );

	public static function err( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'ast_' . $code, $message, array( 'status' => $status ) );
	}

	/* ---------- schema builders ---------- */

	public static function obj( array $props, array $required = array() ): array {
		$s = array(
			'type'       => 'object',
			'properties' => $props,
		);
		if ( $required ) {
			$s['required'] = $required;
		}
		return $s;
	}

	public static function s( string $desc, array $extra = array() ): array {
		return array_merge( array( 'type' => 'string', 'description' => $desc ), $extra );
	}

	public static function i( string $desc, array $extra = array() ): array {
		return array_merge( array( 'type' => 'integer', 'description' => $desc ), $extra );
	}

	public static function b( string $desc ): array {
		return array( 'type' => 'boolean', 'description' => $desc );
	}

	public static function o( string $desc ): array {
		return array( 'type' => 'object', 'description' => $desc, 'additionalProperties' => true );
	}

	public static function a( string $desc, array $items = array( 'type' => self::ANY ) ): array {
		return array( 'type' => 'array', 'description' => $desc, 'items' => $items );
	}

	public static function any( string $desc ): array {
		return array( 'type' => self::ANY, 'description' => $desc );
	}

	/** Proprietà opzionale "note" usata dagli snapshot e come placeholder per i tool senza argomenti. */
	public static function note(): array {
		return self::s( 'Nota libera salvata con lo snapshot (opzionale).' );
	}

	/* ---------- input ---------- */

	public static function int( array $in, string $k, int $def = 0 ): int {
		return isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? (int) $in[ $k ] : $def;
	}

	public static function str( array $in, string $k, string $def = '' ): string {
		return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : $def;
	}

	public static function bool( array $in, string $k, bool $def = false ): bool {
		if ( ! array_key_exists( $k, $in ) ) {
			return $def;
		}
		return filter_var( $in[ $k ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? $def;
	}

	public static function arr( array $in, string $k ): array {
		return isset( $in[ $k ] ) && is_array( $in[ $k ] ) ? $in[ $k ] : array();
	}

	/** Merge superficiale: le chiavi con valore null vengono rimosse. */
	public static function merge_nullable( array $base, array $patch ): array {
		foreach ( $patch as $k => $v ) {
			if ( null === $v ) {
				unset( $base[ $k ] );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	public static function excerpt( $value, int $len = 90 ): string {
		if ( is_array( $value ) ) {
			$value = $value['value'] ?? ( $value['content'] ?? '' );
			if ( is_array( $value ) ) {
				$value = $value['value'] ?? '';
			}
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );
		return mb_strlen( $t ) > $len ? mb_substr( $t, 0, $len - 1 ) . '…' : $t;
	}

	/** Nomi di opzione/chiavi che contengono segreti: mai restituiti in chiaro. */
	public static function is_secret_name( string $name ): bool {
		return (bool) preg_match( '/(secret|token|passw|salt|auth_key|api_?key|license|nonce|private)/i', $name );
	}
}
