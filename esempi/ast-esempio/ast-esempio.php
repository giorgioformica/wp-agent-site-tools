<?php
/**
 * Plugin Name:       AST Esempio
 * Description:       Esempio di plugin che aggiunge abilities ad Agent Site Tools senza modificarlo: una lettura e una scrittura con snapshot.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\AgentSiteTools\Abilities' ) ) {
			return;
		}

		/*
		 * Lettura: bozze in attesa per tipo di contenuto.
		 * Una sola query per post type (wp_count_posts usa la cache di WordPress).
		 */
		\AgentSiteTools\Abilities::add(
			'esempio-bozze',
			array(
				'label'       => 'Esempio: bozze in attesa',
				'description' => 'Conta bozze e contenuti in revisione per articoli e pagine. Utile prima di una sessione di pubblicazione. Restituisce { post_type: { draft, pending } }.',
				'cap'         => 'edit_posts',
				'readonly'    => true,
				'idempotent'  => true,
				'run'         => static function ( array $in ) {
					$out = array();
					foreach ( array( 'post', 'page' ) as $type ) {
						$c            = wp_count_posts( $type );
						$out[ $type ] = array(
							'draft'   => (int) ( $c->draft ?? 0 ),
							'pending' => (int) ( $c->pending ?? 0 ),
						);
					}
					return $out;
				},
			)
		);

		/*
		 * Scrittura: aggiorna l'estratto di un contenuto, con snapshot ripristinabile.
		 */
		\AgentSiteTools\Abilities::add(
			'esempio-estratto',
			array(
				'label'       => 'Esempio: aggiorna estratto',
				'description' => 'Sostituisce l\'estratto di un articolo o di una pagina. Leggere prima il contenuto con content-get. Salva uno snapshot (ripristinabile con snapshot-restore) e restituisce snapshot_id. Dopo la modifica eseguire cache-purge.',
				'input'       => \AgentSiteTools\Util::obj(
					array(
						'post_id' => \AgentSiteTools\Util::i( 'ID del contenuto.' ),
						'excerpt' => \AgentSiteTools\Util::s( 'Nuovo estratto, testo semplice, al massimo 300 caratteri.' ),
						'note'    => \AgentSiteTools\Util::note(),
					),
					array( 'post_id', 'excerpt' )
				),
				'perm'        => static function ( array $in ): bool {
					return current_user_can( 'edit_post', \AgentSiteTools\Util::int( $in, 'post_id' ) );
				},
				'run'         => static function ( array $in ) {
					$id = \AgentSiteTools\Util::int( $in, 'post_id' );
					$p  = get_post( $id );
					if ( ! $p ) {
						return \AgentSiteTools\Util::err( 'not_found', 'Contenuto inesistente: verificare l\'ID con content-list.', 404 );
					}
					$excerpt = mb_substr( sanitize_textarea_field( \AgentSiteTools\Util::str( $in, 'excerpt' ) ), 0, 300 );
					$snap    = \AgentSiteTools\Snapshots::take(
						'post',
						(string) $id,
						\AgentSiteTools\Snapshots::post_state( $p ),
						$p->post_title,
						\AgentSiteTools\Util::str( $in, 'note' )
					);
					$res = wp_update_post(
						array(
							'ID'           => $id,
							'post_excerpt' => $excerpt,
						),
						true
					);
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					return array(
						'post_id'     => $id,
						'excerpt'     => $excerpt,
						'snapshot_id' => $snap,
					);
				},
			)
		);
	},
	20
);
