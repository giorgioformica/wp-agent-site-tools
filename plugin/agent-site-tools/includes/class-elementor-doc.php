<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Lettura/scrittura diretta di _elementor_data (V3 classico, widget di temi e plugin terzi, V4 atomico),
 * operazioni sull'albero degli elementi, invalidazione CSS.
 */
final class ElementorDoc {

	const META = '_elementor_data';

	/* ---------- lettura / scrittura ---------- */

	/** @return array|\WP_Error */
	public static function load( int $post_id ) {
		if ( ! get_post( $post_id ) ) {
			return Util::err( 'not_found', 'Documento inesistente: ' . $post_id, 404 );
		}
		$raw = get_post_meta( $post_id, self::META, true );
		if ( '' === $raw || null === $raw ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return Util::err( 'bad_json', 'Il JSON Elementor del documento non è leggibile.', 500 );
		}
		return $data;
	}

	public static function page_settings( int $post_id ): array {
		$s = get_post_meta( $post_id, '_elementor_page_settings', true );
		return is_array( $s ) ? $s : array();
	}

	public static function snapshot( int $post_id, string $note = '', string $type = 'elementor' ): int {
		return Snapshots::take(
			$type,
			(string) $post_id,
			array(
				'elementor_data' => (string) get_post_meta( $post_id, self::META, true ),
				'page_settings'  => self::page_settings( $post_id ),
			),
			get_the_title( $post_id ),
			$note
		);
	}

	/**
	 * Salva l'albero (con snapshot preventivo). Scrittura diretta sul meta per non perdere
	 * widget di terze parti (es. del tema) non istanziati nel contesto REST.
	 *
	 * @return array|\WP_Error
	 */
	public static function save( int $post_id, array $elements, ?array $settings = null, string $note = '' ) {
		$snap = self::snapshot( $post_id, $note );

		$elements = array_values( $elements );
		self::normalize( $elements, self::collect_ids( $elements ) );

		update_post_meta( $post_id, self::META, wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		if ( ! get_post_meta( $post_id, '_elementor_version', true ) && defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
		if ( null !== $settings ) {
			if ( $settings ) {
				update_post_meta( $post_id, '_elementor_page_settings', $settings );
			} else {
				delete_post_meta( $post_id, '_elementor_page_settings' );
			}
		}
		self::flush_css( $post_id );

		return array(
			'post_id'     => $post_id,
			'snapshot_id' => $snap,
			'elements'    => self::count( $elements ),
		);
	}

	/** Invalida CSS e cache elementi del documento (e del kit se richiesto). */
	public static function flush_css( int $post_id = 0 ): void {
		if ( $post_id ) {
			delete_post_meta( $post_id, '_elementor_css' );
			delete_post_meta( $post_id, '_elementor_element_cache' );
			delete_post_meta( $post_id, '_elementor_page_assets' );
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				try {
					\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
				} catch ( \Throwable $e ) { // phpcs:ignore
				}
			}
			clean_post_cache( $post_id );
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( \Throwable $e ) { // phpcs:ignore
			}
		}
	}

	/* ---------- id ---------- */

	public static function collect_ids( array $els, array &$acc = array() ): array {
		foreach ( $els as $el ) {
			if ( isset( $el['id'] ) ) {
				$acc[ (string) $el['id'] ] = true;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::collect_ids( $el['elements'], $acc );
			}
		}
		return $acc;
	}

	public static function new_id( array &$taken ): string {
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $taken[ $id ] ) );
		$taken[ $id ] = true;
		return $id;
	}

	/** Garantisce id, elements e settings su ogni nodo. */
	public static function normalize( array &$els, array $taken ): void {
		$seen = array();
		self::normalize_walk( $els, $taken, $seen );
	}

	private static function normalize_walk( array &$els, array &$taken, array &$seen ): void {
		foreach ( $els as &$el ) {
			if ( empty( $el['id'] ) || isset( $seen[ (string) $el['id'] ] ) ) {
				$el['id'] = self::new_id( $taken );
			}
			$seen[ (string) $el['id'] ] = true;
			if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				$el['settings'] = array();
			}
			if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
				$el['elements'] = array();
			}
			if ( empty( $el['elType'] ) ) {
				$el['elType'] = isset( $el['widgetType'] ) ? 'widget' : 'container';
			}
			if ( $el['elements'] ) {
				self::normalize_walk( $el['elements'], $taken, $seen );
			}
		}
		unset( $el );
	}

	/** Copia profonda con id nuovi. */
	public static function reid( array $el, array &$taken ): array {
		$el['id'] = self::new_id( $taken );
		if ( ! empty( $el['elements'] ) ) {
			foreach ( $el['elements'] as $k => $child ) {
				$el['elements'][ $k ] = self::reid( $child, $taken );
			}
		}
		return $el;
	}

	/* ---------- navigazione ---------- */

	/** @return int[]|null percorso di indici fino all'elemento. */
	public static function locate( array $els, string $id, array $trail = array() ): ?array {
		foreach ( $els as $i => $el ) {
			$here = array_merge( $trail, array( $i ) );
			if ( isset( $el['id'] ) && (string) $el['id'] === $id ) {
				return $here;
			}
			if ( ! empty( $el['elements'] ) ) {
				$r = self::locate( $el['elements'], $id, $here );
				if ( null !== $r ) {
					return $r;
				}
			}
		}
		return null;
	}

	/** Riferimento all'array che contiene l'ultimo indice del percorso. */
	public static function &siblings( array &$els, array $path ): array {
		$ref  = &$els;
		$last = count( $path ) - 1;
		for ( $n = 0; $n < $last; $n++ ) {
			$ref = &$ref[ $path[ $n ] ]['elements'];
		}
		return $ref;
	}

	/** Riferimento ai figli di un contenitore ('document' = radice). */
	public static function &children_of( array &$els, string $parent_id ): ?array {
		$null = null;
		if ( '' === $parent_id || 'document' === $parent_id ) {
			return $els;
		}
		$path = self::locate( $els, $parent_id );
		if ( null === $path ) {
			return $null;
		}
		$sib = &self::siblings( $els, $path );
		$idx = end( $path );
		if ( ! isset( $sib[ $idx ]['elements'] ) || ! is_array( $sib[ $idx ]['elements'] ) ) {
			$sib[ $idx ]['elements'] = array();
		}
		return $sib[ $idx ]['elements'];
	}

	public static function count( array $els ): int {
		$n = 0;
		foreach ( $els as $el ) {
			$n++;
			if ( ! empty( $el['elements'] ) ) {
				$n += self::count( $el['elements'] );
			}
		}
		return $n;
	}

	/* ---------- operazioni ---------- */

	/**
	 * Applica una lista di operazioni sull'albero in memoria.
	 *
	 * @return array|\WP_Error report per operazione
	 */
	public static function apply_ops( array &$els, array $ops ) {
		$taken  = self::collect_ids( $els );
		$report = array();

		foreach ( $ops as $n => $op ) {
			$type = (string) ( $op['op'] ?? '' );
			$id   = (string) ( $op['id'] ?? '' );

			switch ( $type ) {
				case 'update':
				case 'replace':
				case 'delete':
				case 'duplicate':
				case 'move':
					$path = self::locate( $els, $id );
					if ( null === $path ) {
						return Util::err( 'element_not_found', "Operazione {$n}: elemento {$id} non trovato." );
					}
					break;
			}

			switch ( $type ) {
				case 'update':
					$sib = &self::siblings( $els, $path );
					$idx = end( $path );
					$el  = &$sib[ $idx ];
					if ( isset( $op['settings'] ) && is_array( $op['settings'] ) ) {
						$base           = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
						$el['settings'] = ! empty( $op['replace_settings'] ) ? $op['settings'] : Util::merge_nullable( $base, $op['settings'] );
					}
					foreach ( array( 'styles', 'editor_settings', 'interactions' ) as $k ) {
						if ( isset( $op[ $k ] ) ) {
							$el[ $k ] = $op[ $k ];
						}
					}
					if ( isset( $op['isInner'] ) ) {
						$el['isInner'] = (bool) $op['isInner'];
					}
					unset( $el, $sib );
					$report[] = array( 'op' => 'update', 'id' => $id );
					break;

				case 'replace':
					$new = is_array( $op['element'] ?? null ) ? $op['element'] : null;
					if ( ! $new ) {
						return Util::err( 'bad_op', "Operazione {$n}: 'element' mancante." );
					}
					$sib = &self::siblings( $els, $path );
					$new['id'] = $new['id'] ?? $id;
					$sib[ end( $path ) ] = $new;
					unset( $sib );
					$report[] = array( 'op' => 'replace', 'id' => $id );
					break;

				case 'delete':
					$sib = &self::siblings( $els, $path );
					array_splice( $sib, end( $path ), 1 );
					unset( $sib );
					$report[] = array( 'op' => 'delete', 'id' => $id );
					break;

				case 'duplicate':
					$sib  = &self::siblings( $els, $path );
					$idx  = end( $path );
					$copy = self::reid( $sib[ $idx ], $taken );
					array_splice( $sib, $idx + 1, 0, array( $copy ) );
					unset( $sib );
					$report[] = array( 'op' => 'duplicate', 'id' => $id, 'new_id' => $copy['id'] );
					break;

				case 'move':
					$sib  = &self::siblings( $els, $path );
					$node = $sib[ end( $path ) ];
					array_splice( $sib, end( $path ), 1 );
					unset( $sib );
					$parent = (string) ( $op['parent_id'] ?? 'document' );
					$dest   = &self::children_of( $els, $parent );
					if ( null === $dest ) {
						return Util::err( 'parent_not_found', "Operazione {$n}: contenitore {$parent} non trovato." );
					}
					$pos = isset( $op['position'] ) ? (int) $op['position'] : -1;
					array_splice( $dest, ( $pos < 0 || $pos > count( $dest ) ) ? count( $dest ) : $pos, 0, array( $node ) );
					unset( $dest );
					$report[] = array( 'op' => 'move', 'id' => $id, 'parent_id' => $parent );
					break;

				case 'insert':
					$items = $op['elements'] ?? ( isset( $op['element'] ) ? array( $op['element'] ) : array() );
					if ( ! is_array( $items ) || ! $items ) {
						return Util::err( 'bad_op', "Operazione {$n}: 'element' o 'elements' mancante." );
					}
					$parent = (string) ( $op['parent_id'] ?? 'document' );
					$dest   = &self::children_of( $els, $parent );
					if ( null === $dest ) {
						return Util::err( 'parent_not_found', "Operazione {$n}: contenitore {$parent} non trovato." );
					}
					$items = array_values( $items );
					self::normalize( $items, $taken );
					$taken = self::collect_ids( $items, $taken );
					$pos   = isset( $op['position'] ) ? (int) $op['position'] : -1;
					array_splice( $dest, ( $pos < 0 || $pos > count( $dest ) ) ? count( $dest ) : $pos, 0, $items );
					unset( $dest );
					$report[] = array(
						'op'        => 'insert',
						'parent_id' => $parent,
						'ids'       => array_map(
							static function ( $e ) {
								return $e['id'];
							},
							$items
						),
					);
					break;

				default:
					return Util::err( 'bad_op', "Operazione {$n}: tipo '{$type}' non valido (update|replace|delete|duplicate|move|insert)." );
			}
		}
		return $report;
	}

	/* ---------- outline / ricerca ---------- */

	const PREVIEW_KEYS = array( 'title', 'editor', 'text', 'heading', 'description', 'content', 'paragraph', 'caption', 'button_text', 'link_text', 'label', 'tab_title', 'alert_title' );

	public static function outline( array $els, int $depth = 0, int $max_depth = 20 ): array {
		$out = array();
		foreach ( $els as $el ) {
			$s    = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
			$node = array(
				'id' => $el['id'] ?? '',
				'el' => $el['elType'] ?? '',
			);
			if ( ! empty( $el['widgetType'] ) ) {
				$node['w'] = $el['widgetType'];
			}
			foreach ( self::PREVIEW_KEYS as $k ) {
				if ( isset( $s[ $k ] ) ) {
					$t = Util::excerpt( $s[ $k ] );
					if ( '' !== $t ) {
						$node['t'] = $t;
						break;
					}
				}
			}
			if ( isset( $s['image']['url'] ) ) {
				$node['img'] = $s['image']['url'];
			}
			if ( ! empty( $s['_title'] ) ) {
				$node['name'] = $s['_title'];
			}
			if ( ! empty( $s['classes']['value'] ) && is_array( $s['classes']['value'] ) ) {
				$node['cls'] = $s['classes']['value'];
			} elseif ( ! empty( $s['_css_classes'] ) ) {
				$node['cls'] = $s['_css_classes'];
			}
			if ( ! empty( $el['elements'] ) ) {
				$node['c'] = $depth < $max_depth ? self::outline( $el['elements'], $depth + 1, $max_depth ) : count( $el['elements'] );
			}
			$out[] = $node;
		}
		return $out;
	}

	/** Restituisce gli id degli elementi le cui impostazioni contengono il testo. */
	public static function find_text( array $els, array $needles, array &$hits = array() ): array {
		foreach ( $els as $el ) {
			$blob = wp_json_encode( $el['settings'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			foreach ( $needles as $n ) {
				if ( '' !== $n && false !== stripos( $blob, $n ) ) {
					$hits[] = array(
						'id' => $el['id'] ?? '',
						'el' => $el['elType'] ?? '',
						'w'  => $el['widgetType'] ?? '',
					);
					break;
				}
			}
			if ( ! empty( $el['elements'] ) ) {
				self::find_text( $el['elements'], $needles, $hits );
			}
		}
		return $hits;
	}

	/** Sostituzione testuale su tutte le foglie stringa. */
	public static function replace_strings( &$node, string $search, string $replace, int &$count ): void {
		if ( is_string( $node ) ) {
			$c = 0;
			$node = str_replace( $search, $replace, $node, $c );
			$count += $c;
		} elseif ( is_array( $node ) ) {
			foreach ( $node as &$v ) {
				self::replace_strings( $v, $search, $replace, $count );
			}
			unset( $v );
		}
	}

	/* ---------- kit / classi globali V4 ---------- */

	public static function kit_id(): int {
		return (int) get_option( 'elementor_active_kit' );
	}

	/** Mappa label => id delle classi globali V4 (lette dai meta del kit). */
	public static function global_classes(): array {
		$kit = self::kit_id();
		if ( ! $kit ) {
			return array();
		}
		$map = array();
		foreach ( get_post_meta( $kit ) as $key => $vals ) {
			if ( false === strpos( $key, 'global_classes' ) ) {
				continue;
			}
			$raw = maybe_unserialize( $vals[0] ?? '' );
			if ( is_string( $raw ) ) {
				$raw = json_decode( $raw, true );
			}
			$items = is_array( $raw ) ? ( $raw['items'] ?? $raw ) : array();
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $k => $item ) {
				if ( is_string( $item ) && '' !== $item && false !== strpos( $key, 'labels' ) && false === strpos( $key, 'preview' ) ) {
					// Elementor 4.3+: _elementor_global_classes_labels = { id: label }.
					$map[ $item ] = (string) $k;
				} elseif ( is_array( $item ) && ! empty( $item['label'] ) ) {
					$map[ (string) $item['label'] ] = (string) ( $item['id'] ?? $k );
				}
			}
		}
		return $map;
	}
}
