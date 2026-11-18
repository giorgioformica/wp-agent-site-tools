<?php
/**
 * Abilities media: import da URL o base64, elenco, aggiornamento metadati.
 */

namespace AgentSiteTools\Abilities;

use AgentSiteTools\Abilities;
use AgentSiteTools\Media;
use AgentSiteTools\Util;

defined( 'ABSPATH' ) || exit;

Abilities::add(
	'media-upload',
	array(
		'label'       => 'Media: carica',
		'description' => 'Importa nella libreria media un\'immagine, un video, un SVG o un documento da URL (deduplicato: lo stesso URL non viene importato due volte) oppure da contenuto base64. Imposta titolo, testo alternativo e didascalia.',
		'input'       => Util::obj(
			array(
				'url'         => Util::s( 'URL sorgente.' ),
				'base64'      => Util::s( 'Contenuto base64 (in alternativa all\'URL).' ),
				'filename'    => Util::s( 'Nome file (obbligatorio con base64).' ),
				'title'       => Util::s( 'Titolo.' ),
				'alt'         => Util::s( 'Testo alternativo.' ),
				'caption'     => Util::s( 'Didascalia.' ),
				'description' => Util::s( 'Descrizione.' ),
				'post_id'     => Util::i( 'Contenuto a cui allegarlo (opzionale).' ),
			)
		),
		'cap'         => 'upload_files',
		'run'         => static function ( array $in ) {
			$meta = array_intersect_key( $in, array_flip( array( 'title', 'alt', 'caption', 'description', 'filename' ) ) );
			$par  = Util::int( $in, 'post_id' );
			if ( Util::str( $in, 'url' ) ) {
				$id = Media::from_url( Util::str( $in, 'url' ), $meta, $par );
			} elseif ( Util::str( $in, 'base64' ) ) {
				if ( ! Util::str( $in, 'filename' ) ) {
					return Util::err( 'filename', 'Con base64 serve filename.' );
				}
				$id = Media::from_base64( Util::str( $in, 'base64' ), Util::str( $in, 'filename' ), $meta, $par );
			} else {
				return Util::err( 'source', 'Indicare url o base64.' );
			}
			return is_wp_error( $id ) ? $id : Media::describe( $id );
		},
	)
);

Abilities::add(
	'media-list',
	array(
		'label'       => 'Media: elenco',
		'description' => 'Elenca i file della libreria media con URL, dimensioni, testo alternativo. Filtri per testo e tipo (image, video, application/pdf, image/svg+xml).',
		'input'       => Util::obj(
			array(
				'search'   => Util::s( 'Testo.' ),
				'mime'     => Util::s( 'Tipo MIME o prefisso (image, video...).' ),
				'per_page' => Util::i( 'Default 40, max 100.' ),
				'page'     => Util::i( 'Pagina.' ),
			)
		),
		'cap'         => 'upload_files',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$q = new \WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					's'                      => Util::str( $in, 'search' ),
					'post_mime_type'         => Util::str( $in, 'mime' ),
					'posts_per_page'         => max( 1, min( 100, Util::int( $in, 'per_page', 40 ) ) ),
					'paged'                  => max( 1, Util::int( $in, 'page', 1 ) ),
					'update_post_term_cache' => false,
				)
			);
			return array(
				'total' => (int) $q->found_posts,
				'items' => array_map(
					static function ( $p ) {
						return Media::describe( $p->ID );
					},
					$q->posts
				),
			);
		},
	)
);

Abilities::add(
	'media-update',
	array(
		'label'       => 'Media: aggiorna metadati',
		'description' => 'Aggiorna titolo, testo alternativo, didascalia e descrizione di un file media.',
		'input'       => Util::obj(
			array(
				'id'          => Util::i( 'ID media.' ),
				'title'       => Util::s( 'Titolo.' ),
				'alt'         => Util::s( 'Testo alternativo.' ),
				'caption'     => Util::s( 'Didascalia.' ),
				'description' => Util::s( 'Descrizione.' ),
			),
			array( 'id' )
		),
		'perm'        => static function ( array $in ): bool {
			return current_user_can( 'edit_post', Util::int( $in, 'id' ) );
		},
		'idempotent'  => true,
		'run'         => static function ( array $in ) {
			$id = Util::int( $in, 'id' );
			if ( 'attachment' !== get_post_type( $id ) ) {
				return Util::err( 'not_found', 'Media inesistente.', 404 );
			}
			Media::apply_meta( $id, $in );
			return Media::describe( $id );
		},
	)
);
