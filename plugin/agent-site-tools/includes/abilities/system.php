<?php
/**
 * Abilities sistema: cache e snapshot.
 */

namespace AgentSiteTools\Abilities;

use AgentSiteTools\Abilities;
use AgentSiteTools\ElementorDoc;
use AgentSiteTools\Snapshots;
use AgentSiteTools\Util;

defined( 'ABSPATH' ) || exit;

Abilities::add(
	'cache-purge',
	array(
		'label'       => 'Sistema: svuota cache',
		'description' => 'Rigenera i CSS di Elementor e svuota la cache SiteGround (Speed Optimizer, se presente), le cache registrate con il filtro ast_cache_purge e la object cache. Da eseguire dopo una serie di modifiche per vederle online.',
		'cap'         => 'edit_pages',
		'idempotent'  => true,
		'run'         => static function ( array $in ) {
			$done = array();
			ElementorDoc::flush_css();
			$done[] = 'elementor_css';
			if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
				sg_cachepress_purge_everything();
				$done[] = 'siteground_everything';
			} elseif ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
				$done[] = 'siteground_cache';
			}
			// Temi o plugin con cache proprie si agganciano qui (vedi AGENTS.md).
			$done = (array) apply_filters( 'ast_cache_purge', $done );
			wp_cache_flush();
			$done[] = 'object_cache';
			return array( 'purged' => $done );
		},
	)
);

Abilities::add(
	'snapshots-list',
	array(
		'label'       => 'Sistema: elenco snapshot',
		'description' => 'Elenca gli snapshot salvati prima di ogni modifica (tipo: elementor, kit, post, option, theme_mod, asset, menu), filtrabili per tipo e oggetto (es. ID pagina).',
		'input'       => Util::obj(
			array(
				'object_type' => Util::s( 'Tipo (opzionale).' ),
				'object_key'  => Util::s( 'Oggetto: ID post, nome opzione, nome asset, ID menu (opzionale).' ),
				'limit'       => Util::i( 'Default 30.' ),
			)
		),
		'cap'         => 'edit_pages',
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			return array(
				'snapshots' => Snapshots::list( Util::str( $in, 'object_type' ), Util::str( $in, 'object_key' ), Util::int( $in, 'limit', 30 ) ),
			);
		},
	)
);

Abilities::add(
	'snapshot-restore',
	array(
		'label'       => 'Sistema: ripristina snapshot',
		'description' => 'Riporta un oggetto allo stato di uno snapshot. Lo stato attuale viene salvato prima, quindi anche il ripristino è annullabile.',
		'input'       => Util::obj( array( 'snapshot_id' => Util::i( 'ID snapshot.' ) ), array( 'snapshot_id' ) ),
		'cap'         => 'edit_pages',
		'destructive' => true,
		'run'         => static function ( array $in ) {
			return Snapshots::restore( Util::int( $in, 'snapshot_id' ) );
		},
	)
);
