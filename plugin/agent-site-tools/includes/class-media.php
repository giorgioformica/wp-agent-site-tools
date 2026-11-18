<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Import media da URL o base64, con deduplica per URL sorgente.
 */
final class Media {

	private static function load_wp_admin(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/** @return int|\WP_Error attachment id */
	public static function from_url( string $url, array $meta = array(), int $parent = 0 ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return Util::err( 'bad_url', 'URL non valido.' );
		}
		global $wpdb;
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ast_source_url' AND meta_value = %s LIMIT 1", $url )
		);
		if ( $existing && get_post( $existing ) ) {
			self::apply_meta( $existing, $meta );
			return $existing;
		}

		self::load_wp_admin();
		$tmp = download_url( $url, 90 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$name = $meta['filename'] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$id   = self::sideload( $tmp, $name, $meta, $parent );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_ast_source_url', $url );
		}
		return $id;
	}

	/** @return int|\WP_Error */
	public static function from_base64( string $b64, string $filename, array $meta = array(), int $parent = 0 ) {
		if ( preg_match( '#^data:[^;]+;base64,#', $b64 ) ) {
			$b64 = substr( $b64, strpos( $b64, ',' ) + 1 );
		}
		$bin = base64_decode( $b64, true );
		if ( false === $bin ) {
			return Util::err( 'bad_base64', 'Contenuto base64 non valido.' );
		}
		self::load_wp_admin();
		$tmp = wp_tempnam( $filename );
		file_put_contents( $tmp, $bin ); // phpcs:ignore
		return self::sideload( $tmp, $filename, $meta, $parent );
	}

	/** @return int|\WP_Error */
	private static function sideload( string $tmp, string $name, array $meta, int $parent ) {
		$name = sanitize_file_name( $name ?: 'file' );
		if ( ! pathinfo( $name, PATHINFO_EXTENSION ) ) {
			$mime = wp_get_image_mime( $tmp );
			if ( ! $mime && function_exists( 'mime_content_type' ) ) {
				$mime = mime_content_type( $tmp );
			}
			$ext = $mime ? array_search( $mime, wp_get_mime_types(), true ) : false;
			if ( $ext ) {
				$name .= '.' . explode( '|', $ext )[0];
			}
		}
		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		$id = media_handle_sideload( $file, $parent, $meta['title'] ?? null );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return $id;
		}
		self::apply_meta( $id, $meta );
		return (int) $id;
	}

	public static function apply_meta( int $id, array $meta ): void {
		$upd = array( 'ID' => $id );
		if ( isset( $meta['title'] ) ) {
			$upd['post_title'] = $meta['title'];
		}
		if ( isset( $meta['caption'] ) ) {
			$upd['post_excerpt'] = $meta['caption'];
		}
		if ( isset( $meta['description'] ) ) {
			$upd['post_content'] = $meta['description'];
		}
		if ( count( $upd ) > 1 ) {
			wp_update_post( wp_slash( $upd ) );
		}
		if ( isset( $meta['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $meta['alt'] ) );
		}
	}

	public static function describe( int $id ): array {
		$m = wp_get_attachment_metadata( $id );
		return array(
			'id'     => $id,
			'url'    => wp_get_attachment_url( $id ),
			'title'  => get_the_title( $id ),
			'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'mime'   => get_post_mime_type( $id ),
			'width'  => isset( $m['width'] ) ? (int) $m['width'] : null,
			'height' => isset( $m['height'] ) ? (int) $m['height'] : null,
		);
	}

	/** Accetta id numerico o URL: restituisce un attachment id. @return int|\WP_Error */
	public static function resolve( $ref ) {
		if ( is_numeric( $ref ) ) {
			return get_post( (int) $ref ) ? (int) $ref : Util::err( 'media_not_found', 'Media inesistente: ' . $ref, 404 );
		}
		if ( is_string( $ref ) && preg_match( '#^https?://#', $ref ) ) {
			$local = attachment_url_to_postid( $ref );
			return $local ? $local : self::from_url( $ref );
		}
		return Util::err( 'bad_media', 'Media non valido: usare id o URL.' );
	}
}
