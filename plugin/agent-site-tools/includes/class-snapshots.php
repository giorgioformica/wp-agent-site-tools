<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot di ogni oggetto prima di una scrittura, ripristinabili.
 * Tipi: elementor, post, option, theme_mod, asset, menu, kit, file, theme.
 */
final class Snapshots {

	const KEEP = 30; // snapshot conservati per singolo oggetto

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ast_snapshots';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(20) NOT NULL,
			object_key VARCHAR(191) NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			note VARCHAR(255) NOT NULL DEFAULT '',
			data LONGTEXT NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY obj (object_type, object_key, id)
		) {$charset};"
		);
		update_option( 'ast_db', AST_DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'ast_db' ) !== AST_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * @param mixed $data Stato corrente da conservare.
	 */
	public static function take( string $type, string $key, $data, string $label = '', string $note = '' ): int {
		global $wpdb;
		$t = self::table();
		$wpdb->insert(
			$t,
			array(
				'object_type' => $type,
				'object_key'  => $key,
				'label'       => mb_substr( $label, 0, 255 ),
				'note'        => mb_substr( $note, 0, 255 ),
				'data'        => wp_json_encode( $data ),
				'user_id'     => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;

		// Potatura in una sola query: tiene gli ultimi KEEP per oggetto.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE s FROM {$t} s
				 JOIN ( SELECT id FROM {$t} WHERE object_type = %s AND object_key = %s ORDER BY id DESC LIMIT 18446744073709551615 OFFSET %d ) old ON old.id = s.id",
				$type,
				$key,
				self::KEEP
			)
		);
		return $id;
	}

	public static function list( string $type = '', string $key = '', int $limit = 30 ): array {
		global $wpdb;
		$t     = self::table();
		$where = '1=1';
		$args  = array();
		if ( '' !== $type ) {
			$where .= ' AND object_type = %s';
			$args[] = $type;
		}
		if ( '' !== $key ) {
			$where .= ' AND object_key = %s';
			$args[] = $key;
		}
		$args[] = max( 1, min( 200, $limit ) );
		$sql    = "SELECT id, object_type, object_key, label, note, user_id, created_at, CHAR_LENGTH(data) AS bytes FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['data'] = json_decode( $row['data'], true );
		return $row;
	}

	/**
	 * Ripristina uno snapshot. Prima salva lo stato attuale, così il ripristino è annullabile.
	 *
	 * @return array|\WP_Error
	 */
	public static function restore( int $id ) {
		$snap = self::get( $id );
		if ( ! $snap ) {
			return Util::err( 'snapshot_not_found', 'Snapshot inesistente.', 404 );
		}
		$type = $snap['object_type'];
		$key  = $snap['object_key'];
		$data = $snap['data'];
		$note = 'pre-restore #' . $id;

		switch ( $type ) {
			case 'elementor':
			case 'kit':
				$post_id = (int) $key;
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return Util::err( 'forbidden', 'Permessi insufficienti.', 403 );
				}
				ElementorDoc::snapshot( $post_id, $note, $type );
				if ( array_key_exists( 'elementor_data', $data ) ) {
					update_post_meta( $post_id, '_elementor_data', wp_slash( (string) $data['elementor_data'] ) );
				}
				if ( array_key_exists( 'page_settings', $data ) ) {
					if ( is_array( $data['page_settings'] ) && $data['page_settings'] ) {
						update_post_meta( $post_id, '_elementor_page_settings', $data['page_settings'] );
					} else {
						delete_post_meta( $post_id, '_elementor_page_settings' );
					}
				}
				ElementorDoc::flush_css( $post_id );
				break;

			case 'post':
				$post_id = (int) $key;
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return Util::err( 'forbidden', 'Permessi insufficienti.', 403 );
				}
				$cur = get_post( $post_id );
				if ( $cur ) {
					self::take( 'post', $key, self::post_state( $cur ), $cur->post_title, $note );
				}
				$upd = array_intersect_key( (array) $data, array_flip( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' ) ) );
				$upd['ID'] = $post_id;
				$res       = wp_update_post( wp_slash( $upd ), true );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				break;

			case 'option':
				if ( ! current_user_can( 'manage_options' ) ) {
					return Util::err( 'forbidden', 'Permessi insufficienti.', 403 );
				}
				self::take( 'option', $key, array( 'exists' => false !== get_option( $key, false ), 'value' => get_option( $key ) ), $key, $note );
				if ( empty( $data['exists'] ) ) {
					delete_option( $key );
				} else {
					update_option( $key, $data['value'] );
				}
				break;

			case 'theme_mod':
				if ( ! current_user_can( 'manage_options' ) ) {
					return Util::err( 'forbidden', 'Permessi insufficienti.', 403 );
				}
				self::take( 'theme_mod', $key, array( 'value' => get_theme_mod( $key ) ), $key, $note );
				if ( null === $data['value'] || false === $data['value'] ) {
					remove_theme_mod( $key );
				} else {
					set_theme_mod( $key, $data['value'] );
				}
				break;

			case 'asset':
				$res = Assets::restore( $key, (array) $data, $note );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				break;

			case 'menu':
				$res = \AgentSiteTools\Abilities\Menus::restore( (int) $key, (array) $data, $note );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				break;

			case 'file':
				$res = Files::restore( $key, (array) $data, $note );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				break;

			case 'theme':
				if ( ! current_user_can( 'switch_themes' ) ) {
					return Util::err( 'forbidden', 'Permessi insufficienti.', 403 );
				}
				$target = (string) ( $data['stylesheet'] ?? '' );
				if ( '' === $target || ! wp_get_theme( $target )->exists() ) {
					return Util::err( 'theme_not_found', 'Tema dello snapshot non più installato: ' . $target, 404 );
				}
				self::take( 'theme', 'active', array( 'stylesheet' => get_stylesheet() ), 'Tema attivo: ' . get_stylesheet(), $note );
				switch_theme( $target );
				break;

			default:
				return Util::err( 'snapshot_type', 'Tipo snapshot non gestito: ' . $type );
		}

		return array(
			'restored'    => $id,
			'object_type' => $type,
			'object_key'  => $key,
		);
	}

	public static function post_state( \WP_Post $p ): array {
		return array(
			'post_title'   => $p->post_title,
			'post_content' => $p->post_content,
			'post_excerpt' => $p->post_excerpt,
			'post_status'  => $p->post_status,
			'post_name'    => $p->post_name,
			'post_parent'  => $p->post_parent,
			'menu_order'   => $p->menu_order,
		);
	}
}
