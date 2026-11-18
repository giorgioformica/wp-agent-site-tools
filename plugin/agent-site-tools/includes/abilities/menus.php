<?php
/**
 * Abilities menu: lettura ad albero, riscrittura completa, posizioni del tema.
 */

namespace AgentSiteTools\Abilities;

use AgentSiteTools\Abilities;
use AgentSiteTools\Snapshots;
use AgentSiteTools\Util;

defined( 'ABSPATH' ) || exit;

final class Menus {

	public static function tree( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
		if ( ! $items ) {
			return array();
		}
		$nodes = array();
		foreach ( $items as $it ) {
			$nodes[ $it->ID ] = array(
				'id'        => (int) $it->ID,
				'title'     => $it->title,
				'url'       => $it->url,
				'type'      => $it->type,
				'object'    => $it->object,
				'object_id' => (int) $it->object_id,
				'target'    => $it->target,
				'classes'   => array_values( array_filter( (array) $it->classes ) ),
				'parent'    => (int) $it->menu_item_parent,
				'children'  => array(),
			);
		}
		$root = array();
		foreach ( $nodes as $id => &$n ) {
			if ( $n['parent'] && isset( $nodes[ $n['parent'] ] ) ) {
				$nodes[ $n['parent'] ]['children'][] = &$n;
			} else {
				$root[] = &$n;
			}
		}
		unset( $n );
		return json_decode( wp_json_encode( $root ), true ); // rompe i riferimenti
	}

	/** Riscrive tutte le voci del menu. @return array|\WP_Error */
	public static function write( int $menu_id, array $items ) {
		$old = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
		foreach ( (array) $old as $it ) {
			wp_delete_post( $it->ID, true );
		}
		$pos   = 0;
		$count = 0;
		$err   = self::insert( $menu_id, $items, 0, $pos, $count );
		return is_wp_error( $err ) ? $err : $count;
	}

	/** @return true|\WP_Error */
	private static function insert( int $menu_id, array $items, int $parent, int &$pos, int &$count ) {
		foreach ( $items as $it ) {
			$pos++;
			$args = array(
				'menu-item-title'     => (string) ( $it['title'] ?? '' ),
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $parent,
				'menu-item-position'  => $pos,
				'menu-item-target'    => (string) ( $it['target'] ?? '' ),
				'menu-item-classes'   => implode( ' ', (array) ( $it['classes'] ?? array() ) ),
			);
			$type = $it['type'] ?? ( ! empty( $it['object_id'] ) ? 'post_type' : 'custom' );
			if ( 'custom' === $type ) {
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = esc_url_raw( (string) ( $it['url'] ?? '#' ) );
			} else {
				$args['menu-item-type']      = $type;
				$args['menu-item-object']    = (string) ( $it['object'] ?? 'page' );
				$args['menu-item-object-id'] = (int) ( $it['object_id'] ?? 0 );
			}
			$id = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$count++;
			if ( ! empty( $it['children'] ) && is_array( $it['children'] ) ) {
				$r = self::insert( $menu_id, $it['children'], (int) $id, $pos, $count );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		return true;
	}

	/** @return array|\WP_Error */
	public static function restore( int $menu_id, array $data, string $note ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return Util::err( 'forbidden', 'Permessi insufficienti.', 403 );
		}
		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			return Util::err( 'menu_not_found', 'Menu inesistente.', 404 );
		}
		Snapshots::take( 'menu', (string) $menu_id, array( 'items' => self::tree( $menu_id ) ), 'menu ' . $menu_id, $note );
		$n = self::write( $menu_id, (array) ( $data['items'] ?? array() ) );
		return is_wp_error( $n ) ? $n : array( 'items' => $n );
	}
}

Abilities::add(
	'menus-get',
	array(
		'label'       => 'Menu: leggi',
		'description' => 'Elenca i menu con le voci ad albero e le posizioni del tema (quali menu sono assegnati a header, footer...).',
		'input'       => Util::obj( array( 'menu_id' => Util::i( 'Solo questo menu (opzionale).' ) ) ),
		'cap'         => 'edit_theme_options',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$only  = Util::int( $in, 'menu_id' );
			$menus = array();
			foreach ( wp_get_nav_menus() as $m ) {
				if ( $only && (int) $m->term_id !== $only ) {
					continue;
				}
				$menus[] = array(
					'id'    => (int) $m->term_id,
					'name'  => $m->name,
					'slug'  => $m->slug,
					'items' => Menus::tree( (int) $m->term_id ),
				);
			}
			return array(
				'menus'     => $menus,
				'locations' => array(
					'registered' => get_registered_nav_menus(),
					'assigned'   => get_nav_menu_locations(),
				),
			);
		},
	)
);

Abilities::add(
	'menu-save',
	array(
		'label'       => 'Menu: crea o riscrivi',
		'description' => 'Riscrive tutte le voci di un menu (o lo crea con name) da un albero: voci { title, type: custom|post_type|taxonomy, url (custom), object (page, post, category...), object_id, target, classes, children: [] }. Può assegnarlo a una posizione del tema. Snapshot automatico.',
		'input'       => Util::obj(
			array(
				'menu_id'  => Util::i( 'Menu da riscrivere.' ),
				'name'     => Util::s( 'Nome (crea il menu se non esiste).' ),
				'items'    => Util::a( 'Voci ad albero.', array( 'type' => 'object' ) ),
				'location' => Util::s( 'Posizione del tema a cui assegnarlo (opzionale).' ),
				'note'     => Util::note(),
			),
			array( 'items' )
		),
		'cap'         => 'edit_theme_options',
		'destructive' => true,
		'run'         => static function ( array $in ) {
			$id = Util::int( $in, 'menu_id' );
			if ( ! $id ) {
				$name = Util::str( $in, 'name' );
				if ( '' === $name ) {
					return Util::err( 'menu', 'Indicare menu_id oppure name.' );
				}
				$m  = wp_get_nav_menu_object( $name );
				$id = $m ? (int) $m->term_id : wp_create_nav_menu( $name );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
			}
			if ( ! wp_get_nav_menu_object( $id ) ) {
				return Util::err( 'menu_not_found', 'Menu inesistente.', 404 );
			}
			$snap = Snapshots::take( 'menu', (string) $id, array( 'items' => Menus::tree( $id ) ), 'menu ' . $id, Util::str( $in, 'note' ) );
			$n    = Menus::write( $id, Util::arr( $in, 'items' ) );
			if ( is_wp_error( $n ) ) {
				return $n;
			}
			$loc = Util::str( $in, 'location' );
			if ( $loc ) {
				$locs         = get_nav_menu_locations();
				$locs[ $loc ] = $id;
				set_theme_mod( 'nav_menu_locations', $locs );
			}
			return array(
				'menu_id'     => $id,
				'items'       => $n,
				'snapshot_id' => $snap,
			);
		},
	)
);
