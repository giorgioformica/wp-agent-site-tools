<?php
/**
 * Abilities contenuti: pagine, articoli e qualsiasi post type; tassonomie; SEO (All in One SEO).
 */

namespace AgentSiteTools\Abilities;

use AgentSiteTools\Abilities;
use AgentSiteTools\Media;
use AgentSiteTools\Snapshots;
use AgentSiteTools\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Campi SEO di All in One SEO (tabella aioseo_posts).
 */
final class Seo {

	const MAP = array(
		'title'          => 'title',
		'description'    => 'description',
		'og_title'       => 'og_title',
		'og_description' => 'og_description',
		'canonical'      => 'canonical_url',
		'keyphrase'      => 'keyphrases',
	);

	public static function table(): ?string {
		global $wpdb;
		$t = $wpdb->prefix . 'aioseo_posts';
		static $exists = null;
		if ( null === $exists ) {
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		}
		return $exists ? $t : null;
	}

	public static function get_many( array $ids ): array {
		global $wpdb;
		$t = self::table();
		if ( ! $t || ! $ids ) {
			return array();
		}
		$ids  = array_map( 'intval', $ids );
		$rows = $wpdb->get_results(
			"SELECT post_id, title, description, og_title, og_description, canonical_url, robots_noindex, robots_default FROM {$t} WHERE post_id IN (" . implode( ',', $ids ) . ')',
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['post_id'] ] = array(
				'title'          => $r['title'],
				'description'    => $r['description'],
				'og_title'       => $r['og_title'],
				'og_description' => $r['og_description'],
				'canonical'      => $r['canonical_url'],
				'noindex'        => (bool) $r['robots_noindex'] && ! (bool) $r['robots_default'],
			);
		}
		return $out;
	}

	public static function save( int $post_id, array $seo ): array {
		global $wpdb;
		$t = self::table();
		if ( ! $t ) {
			return array( 'skipped' => 'All in One SEO non rilevato' );
		}
		$data = array();
		foreach ( self::MAP as $in => $col ) {
			if ( array_key_exists( $in, $seo ) && 'keyphrases' !== $col ) {
				$data[ $col ] = null === $seo[ $in ] ? null : sanitize_text_field( (string) $seo[ $in ] );
			}
		}
		if ( array_key_exists( 'keyphrase', $seo ) ) {
			$data['keyphrases'] = wp_json_encode(
				array(
					'focus'      => array( 'keyphrase' => sanitize_text_field( (string) $seo['keyphrase'] ) ),
					'additional' => array(),
				)
			);
		}
		if ( array_key_exists( 'noindex', $seo ) ) {
			$data['robots_default'] = $seo['noindex'] ? 0 : 1;
			$data['robots_noindex'] = $seo['noindex'] ? 1 : 0;
		}
		if ( ! $data ) {
			return array();
		}

		// Via preferita: il modello di AIOSEO (gestisce validazione e cache interne).
		$model = '\AIOSEO\Plugin\Common\Models\Post';
		if ( class_exists( $model ) && method_exists( $model, 'getPost' ) ) {
			try {
				$p = $model::getPost( $post_id );
				if ( is_object( $p ) ) {
					$p->post_id = $post_id;
					foreach ( $data as $col => $v ) {
						$p->$col = $v;
					}
					$p->save();
					return array_keys( $data );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore -- fallback su SQL diretto
			}
		}

		$data['updated'] = current_time( 'mysql', true );
		$id              = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE post_id = %d", $post_id ) );
		if ( $id ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) );
		} else {
			$data['post_id'] = $post_id;
			$data['created'] = $data['updated'];
			$wpdb->insert( $t, $data );
		}
		return array_keys( $data );
	}
}

/** Risolve termini da id o nomi (creandoli se mancano). */
function ast_resolve_terms( string $tax, array $items ): array {
	$ids = array();
	foreach ( $items as $it ) {
		if ( is_numeric( $it ) ) {
			$ids[] = (int) $it;
			continue;
		}
		$name = trim( (string) $it );
		if ( '' === $name ) {
			continue;
		}
		$t = get_term_by( 'name', $name, $tax );
		if ( ! $t ) {
			$t = get_term_by( 'slug', sanitize_title( $name ), $tax );
		}
		if ( $t ) {
			$ids[] = (int) $t->term_id;
		} else {
			$new = wp_insert_term( $name, $tax );
			if ( ! is_wp_error( $new ) ) {
				$ids[] = (int) $new['term_id'];
			}
		}
	}
	return $ids;
}

function ast_post_summary( \WP_Post $p, array $seo = array() ): array {
	$out = array(
		'id'       => $p->ID,
		'type'     => $p->post_type,
		'title'    => $p->post_title,
		'status'   => $p->post_status,
		'slug'     => $p->post_name,
		'date'     => $p->post_date,
		'modified' => $p->post_modified,
		'parent'   => $p->post_parent,
		'url'      => get_permalink( $p ),
		'excerpt'  => $p->post_excerpt,
		'featured' => get_the_post_thumbnail_url( $p, 'full' ) ?: null,
		'elementor' => 'builder' === get_post_meta( $p->ID, '_elementor_edit_mode', true ),
	);
	$terms = array();
	foreach ( get_object_taxonomies( $p->post_type, 'objects' ) as $tax ) {
		if ( ! $tax->public ) {
			continue;
		}
		$tt = get_the_terms( $p, $tax->name );
		if ( $tt && ! is_wp_error( $tt ) ) {
			$terms[ $tax->name ] = wp_list_pluck( $tt, 'name', 'term_id' );
		}
	}
	$out['terms'] = $terms;
	if ( $seo ) {
		$out['seo'] = $seo;
	}
	return $out;
}

Abilities::add(
	'content-list',
	array(
		'label'       => 'Contenuti: elenco',
		'description' => 'Elenca pagine, articoli o qualsiasi post type con filtri (stato, ricerca, categoria, tag), immagine in evidenza, termini e SEO. Query batch.',
		'input'       => Util::obj(
			array(
				'post_type' => Util::s( 'post (default) | page | qualsiasi post type | any' ),
				'status'    => Util::s( 'publish | draft | private | pending | any (default any)' ),
				'search'    => Util::s( 'Testo da cercare.' ),
				'category'  => Util::s( 'Slug categoria.' ),
				'tag'       => Util::s( 'Slug tag.' ),
				'per_page'  => Util::i( 'Default 20, max 100.' ),
				'page'      => Util::i( 'Pagina, default 1.' ),
				'orderby'   => Util::s( 'date | modified | title | menu_order (default date)' ),
			)
		),
		'cap'         => 'edit_posts',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$q = new \WP_Query(
				array(
					'post_type'      => Util::str( $in, 'post_type', 'post' ),
					'post_status'    => Util::str( $in, 'status', 'any' ),
					's'              => Util::str( $in, 'search' ),
					'category_name'  => Util::str( $in, 'category' ),
					'tag'            => Util::str( $in, 'tag' ),
					'posts_per_page' => max( 1, min( 100, Util::int( $in, 'per_page', 20 ) ) ),
					'paged'          => max( 1, Util::int( $in, 'page', 1 ) ),
					'orderby'        => Util::str( $in, 'orderby', 'date' ),
					'order'          => 'title' === Util::str( $in, 'orderby' ) ? 'ASC' : 'DESC',
				)
			);
			update_post_thumbnail_cache( $q );
			$seo = Seo::get_many( wp_list_pluck( $q->posts, 'ID' ) );
			$out = array();
			foreach ( $q->posts as $p ) {
				$out[] = ast_post_summary( $p, $seo[ $p->ID ] ?? array() );
			}
			return array(
				'total' => (int) $q->found_posts,
				'pages' => (int) $q->max_num_pages,
				'items' => $out,
			);
		},
	)
);

Abilities::add(
	'content-get',
	array(
		'label'       => 'Contenuti: dettaglio',
		'description' => 'Tutti i dati di un contenuto: testo, estratto, stato, template, termini, immagine in evidenza, meta pubblici, SEO.',
		'input'       => Util::obj( array( 'post_id' => Util::i( 'ID.' ) ), array( 'post_id' ) ),
		'perm'        => static function ( array $in ): bool {
			return current_user_can( 'edit_post', Util::int( $in, 'post_id' ) );
		},
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$p = get_post( Util::int( $in, 'post_id' ) );
			if ( ! $p ) {
				return Util::err( 'not_found', 'Contenuto inesistente.', 404 );
			}
			$seo           = Seo::get_many( array( $p->ID ) );
			$out           = ast_post_summary( $p, $seo[ $p->ID ] ?? array() );
			$out['content'] = $p->post_content;
			$out['template'] = (string) get_page_template_slug( $p );
			$out['menu_order'] = $p->menu_order;
			$meta = array();
			foreach ( get_post_meta( $p->ID ) as $k => $v ) {
				if ( ! is_protected_meta( $k, 'post' ) ) {
					$meta[ $k ] = maybe_unserialize( $v[0] ?? '' );
				}
			}
			$out['meta'] = $meta;
			return $out;
		},
	)
);

Abilities::add(
	'content-save',
	array(
		'label'       => 'Contenuti: crea o aggiorna',
		'description' => 'Crea (senza post_id) o aggiorna (con post_id) un articolo, una pagina o altro post type: titolo, contenuto HTML/blocchi, estratto, stato, slug, data, genitore, template, categorie e tag (nomi o id, creati se mancano), altre tassonomie, immagine in evidenza (id o URL, importata automaticamente), meta pubblici, SEO (title, description, og_title, og_description, canonical, keyphrase, noindex). Snapshot automatico sugli aggiornamenti.',
		'input'       => Util::obj(
			array(
				'post_id'        => Util::i( 'ID da aggiornare (assente = nuovo).' ),
				'post_type'      => Util::s( 'post (default) | page | altro' ),
				'title'          => Util::s( 'Titolo.' ),
				'content'        => Util::s( 'Contenuto (HTML o markup a blocchi).' ),
				'excerpt'        => Util::s( 'Estratto.' ),
				'status'         => Util::s( 'draft | publish | private | pending | future' ),
				'slug'           => Util::s( 'Slug.' ),
				'date'           => Util::s( 'Data pubblicazione Y-m-d H:i:s (fuso del sito).' ),
				'parent'         => Util::i( 'ID genitore.' ),
				'menu_order'     => Util::i( 'Ordine.' ),
				'template'       => Util::s( 'Template pagina (es. elementor_header_footer).' ),
				'author'         => Util::i( 'ID autore.' ),
				'categories'     => Util::a( 'Categorie (id o nomi).' ),
				'tags'           => Util::a( 'Tag (id o nomi).' ),
				'terms'          => Util::o( 'Altre tassonomie: { taxonomy: [id o nomi] }.' ),
				'featured_image' => Util::any( 'Immagine in evidenza: id media, URL (importata) o null per rimuoverla.' ),
				'meta'           => Util::o( 'Meta pubblici da impostare (null cancella).' ),
				'seo'            => Util::o( 'SEO: title, description, og_title, og_description, canonical, keyphrase, noindex.' ),
				'note'           => Util::note(),
			)
		),
		'cap'         => 'edit_posts',
		'run'         => static function ( array $in ) {
			$id   = Util::int( $in, 'post_id' );
			$type = $id ? get_post_type( $id ) : sanitize_key( Util::str( $in, 'post_type', 'post' ) );
			if ( ! $type || ! post_type_exists( $type ) ) {
				return Util::err( 'bad_type', 'Post type non valido.' );
			}
			$pto = get_post_type_object( $type );
			if ( $id && ! current_user_can( 'edit_post', $id ) ) {
				return Util::err( 'forbidden', 'Permessi insufficienti su questo contenuto.', 403 );
			}
			if ( ! $id && ! current_user_can( $pto->cap->create_posts ) ) {
				return Util::err( 'forbidden', 'Permessi insufficienti per creare ' . $type . '.', 403 );
			}

			$map = array(
				'title'      => 'post_title',
				'content'    => 'post_content',
				'excerpt'    => 'post_excerpt',
				'status'     => 'post_status',
				'slug'       => 'post_name',
				'date'       => 'post_date',
				'parent'     => 'post_parent',
				'menu_order' => 'menu_order',
				'author'     => 'post_author',
			);
			$post = array( 'post_type' => $type );
			foreach ( $map as $k => $col ) {
				if ( array_key_exists( $k, $in ) ) {
					$post[ $col ] = $in[ $k ];
				}
			}
			if ( isset( $post['post_status'] ) && 'publish' === $post['post_status'] && ! current_user_can( $pto->cap->publish_posts ) ) {
				$post['post_status'] = 'pending';
			}

			$snap = 0;
			if ( $id ) {
				$snap       = Snapshots::take( 'post', (string) $id, Snapshots::post_state( get_post( $id ) ), get_the_title( $id ), Util::str( $in, 'note' ) );
				$post['ID'] = $id;
				$res        = wp_update_post( wp_slash( $post ), true );
			} else {
				$post['post_status'] = $post['post_status'] ?? 'draft';
				$res                 = wp_insert_post( wp_slash( $post ), true );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$id = (int) $res;

			if ( array_key_exists( 'template', $in ) ) {
				update_post_meta( $id, '_wp_page_template', sanitize_text_field( (string) $in['template'] ) );
			}
			if ( isset( $in['categories'] ) && is_object_in_taxonomy( $type, 'category' ) ) {
				wp_set_post_terms( $id, ast_resolve_terms( 'category', (array) $in['categories'] ), 'category' );
			}
			if ( isset( $in['tags'] ) && is_object_in_taxonomy( $type, 'post_tag' ) ) {
				wp_set_post_terms( $id, ast_resolve_terms( 'post_tag', (array) $in['tags'] ), 'post_tag' );
			}
			foreach ( Util::arr( $in, 'terms' ) as $tax => $items ) {
				if ( taxonomy_exists( $tax ) && is_object_in_taxonomy( $type, $tax ) ) {
					wp_set_object_terms( $id, ast_resolve_terms( $tax, (array) $items ), $tax );
				}
			}

			$warnings = array();
			if ( array_key_exists( 'featured_image', $in ) ) {
				if ( null === $in['featured_image'] || '' === $in['featured_image'] ) {
					delete_post_thumbnail( $id );
				} else {
					$mid = Media::resolve( $in['featured_image'] );
					if ( is_wp_error( $mid ) ) {
						$warnings[] = 'featured_image: ' . $mid->get_error_message();
					} else {
						set_post_thumbnail( $id, $mid );
					}
				}
			}
			foreach ( Util::arr( $in, 'meta' ) as $k => $v ) {
				if ( is_protected_meta( $k, 'post' ) ) {
					$warnings[] = 'meta protetto ignorato: ' . $k;
					continue;
				}
				if ( null === $v ) {
					delete_post_meta( $id, $k );
				} else {
					update_post_meta( $id, $k, wp_slash( $v ) );
				}
			}
			$seo = array();
			if ( isset( $in['seo'] ) && is_array( $in['seo'] ) ) {
				$seo = Seo::save( $id, $in['seo'] );
			}

			$p   = get_post( $id );
			$out = ast_post_summary( $p, Seo::get_many( array( $id ) )[ $id ] ?? array() );
			if ( $snap ) {
				$out['snapshot_id'] = $snap;
			}
			if ( $warnings ) {
				$out['warnings'] = $warnings;
			}
			return $out;
		},
	)
);

Abilities::add(
	'content-delete',
	array(
		'label'       => 'Contenuti: elimina',
		'description' => 'Sposta nel cestino un contenuto (o lo elimina definitivamente con force=true). Snapshot automatico.',
		'input'       => Util::obj(
			array(
				'post_id' => Util::i( 'ID.' ),
				'force'   => Util::b( 'Eliminazione definitiva (default false = cestino).' ),
			),
			array( 'post_id' )
		),
		'perm'        => static function ( array $in ): bool {
			return current_user_can( 'delete_post', Util::int( $in, 'post_id' ) );
		},
		'destructive' => true,
		'run'         => static function ( array $in ) {
			$id = Util::int( $in, 'post_id' );
			$p  = get_post( $id );
			if ( ! $p ) {
				return Util::err( 'not_found', 'Contenuto inesistente.', 404 );
			}
			$snap  = Snapshots::take( 'post', (string) $id, Snapshots::post_state( $p ), $p->post_title, 'pre-delete' );
			$force = Util::bool( $in, 'force' );
			$res   = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
			return array(
				'post_id'     => $id,
				'deleted'     => (bool) $res,
				'trashed'     => ! $force,
				'snapshot_id' => $snap,
			);
		},
	)
);

Abilities::add(
	'terms-list',
	array(
		'label'       => 'Tassonomie: elenco termini',
		'description' => 'Elenca categorie, tag o termini di qualsiasi tassonomia con conteggi.',
		'input'       => Util::obj(
			array(
				'taxonomy' => Util::s( 'category (default) | post_tag | altra' ),
				'search'   => Util::s( 'Filtro testo.' ),
			)
		),
		'cap'         => 'edit_posts',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$tax   = Util::str( $in, 'taxonomy', 'category' );
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'search'     => Util::str( $in, 'search' ),
					'number'     => 500,
				)
			);
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			return array(
				'taxonomy' => $tax,
				'terms'    => array_map(
					static function ( $t ) {
						return array(
							'id'          => $t->term_id,
							'name'        => $t->name,
							'slug'        => $t->slug,
							'parent'      => $t->parent,
							'count'       => $t->count,
							'description' => $t->description,
						);
					},
					$terms
				),
			);
		},
	)
);

Abilities::add(
	'terms-save',
	array(
		'label'       => 'Tassonomie: crea o aggiorna termine',
		'description' => 'Crea (senza term_id) o aggiorna un termine: nome, slug, genitore, descrizione.',
		'input'       => Util::obj(
			array(
				'taxonomy'    => Util::s( 'Tassonomia.' ),
				'term_id'     => Util::i( 'ID da aggiornare.' ),
				'name'        => Util::s( 'Nome.' ),
				'slug'        => Util::s( 'Slug.' ),
				'parent'      => Util::i( 'ID genitore.' ),
				'description' => Util::s( 'Descrizione.' ),
			),
			array( 'taxonomy' )
		),
		'cap'         => 'manage_categories',
		'run'         => static function ( array $in ) {
			$tax  = Util::str( $in, 'taxonomy' );
			$args = array_intersect_key( $in, array_flip( array( 'name', 'slug', 'parent', 'description' ) ) );
			$tid  = Util::int( $in, 'term_id' );
			$res  = $tid ? wp_update_term( $tid, $tax, $args ) : wp_insert_term( Util::str( $in, 'name' ), $tax, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array(
				'taxonomy' => $tax,
				'term_id'  => (int) $res['term_id'],
			);
		},
	)
);
