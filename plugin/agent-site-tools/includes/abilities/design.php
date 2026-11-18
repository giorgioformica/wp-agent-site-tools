<?php
/**
 * Abilities design: file CSS/JS del sito e impostazioni del motore di animazione.
 */

namespace AgentSiteTools\Abilities;

use AgentSiteTools\Abilities;
use AgentSiteTools\Assets;
use AgentSiteTools\Motion;
use AgentSiteTools\Util;

defined( 'ABSPATH' ) || exit;

Abilities::add(
	'asset-write',
	array(
		'label'       => 'Design: scrivi file CSS/JS',
		'description' => 'Crea o sostituisce un file CSS o JS del sito (uploads/agent-site-tools/), caricato su tutto il sito (scope global), solo in homepage (front) o su pagine specifiche (pages). Il JS viene caricato dopo il motore di animazione e può usare gsap, ScrollTrigger, SplitText e window.AST.lenis. Snapshot automatico.',
		'input'       => Util::obj(
			array(
				'name'     => Util::s( 'Nome (a-z, 0-9, trattini), es. "home" o "global".' ),
				'type'     => Util::s( 'css | js', array( 'enum' => array( 'css', 'js' ) ) ),
				'content'  => Util::s( 'Contenuto completo del file.' ),
				'scope'    => Util::s( 'global | front | pages', array( 'enum' => array( 'global', 'front', 'pages' ) ) ),
				'pages'    => Util::a( 'ID pagine (scope pages).', array( 'type' => 'integer' ) ),
				'enabled'  => Util::b( 'Attivo (default true).' ),
				'priority' => Util::i( 'Ordine di caricamento (default 10).' ),
				'note'     => Util::note(),
			),
			array( 'name', 'type', 'content' )
		),
		'destructive' => true,
		'run'         => static function ( array $in ) {
			return Assets::write(
				Util::str( $in, 'name' ),
				Util::str( $in, 'type' ),
				Util::str( $in, 'content' ),
				array_intersect_key( $in, array_flip( array( 'scope', 'pages', 'enabled', 'priority' ) ) ),
				Util::str( $in, 'note' )
			);
		},
	)
);

Abilities::add(
	'asset-list',
	array(
		'label'       => 'Design: elenco file CSS/JS',
		'description' => 'Elenca i file CSS/JS gestiti, con ambito, stato, versione e URL. Con name restituisce anche il contenuto.',
		'input'       => Util::obj( array( 'name' => Util::s( 'Nome completo (es. home.css) per leggerne il contenuto.' ) ) ),
		'readonly'    => true,
		'run'         => static function ( array $in ) {
			$name = basename( Util::str( $in, 'name' ) );
			if ( '' !== $name ) {
				$file = Assets::dir() . '/' . $name;
				if ( ! file_exists( $file ) ) {
					return Util::err( 'asset_not_found', 'Asset inesistente: ' . $name, 404 );
				}
				return array(
					'name'    => $name,
					'entry'   => Assets::registry()[ $name ] ?? null,
					'content' => (string) file_get_contents( $file ), // phpcs:ignore
				);
			}
			$out = array();
			foreach ( Assets::registry() as $key => $e ) {
				$out[] = array_merge( array( 'name' => $key, 'url' => Assets::url() . '/' . $key ), $e );
			}
			return array( 'assets' => $out );
		},
	)
);

Abilities::add(
	'asset-delete',
	array(
		'label'       => 'Design: elimina file CSS/JS',
		'description' => 'Elimina un file CSS/JS gestito (ripristinabile dallo snapshot).',
		'input'       => Util::obj(
			array(
				'name' => Util::s( 'Nome completo, es. home.css.' ),
				'note' => Util::note(),
			),
			array( 'name' )
		),
		'destructive' => true,
		'run'         => static function ( array $in ) {
			return Assets::delete( basename( Util::str( $in, 'name' ) ), Util::str( $in, 'note' ) );
		},
	)
);

Abilities::add(
	'motion-settings',
	array(
		'label'       => 'Design: motore animazioni',
		'description' => 'Legge o aggiorna il motore GSAP + Lenis. Ruoli attivabili con classi globali V4 ast-reveal, ast-stagger (figli in sequenza), ast-split-text (titoli riga per riga), ast-parallax, ast-pin (colonna fissa, desktop), ast-hscroll (scorrimento orizzontale del primo figlio, desktop), ast-counter (numeri che crescono), ast-rule (linea che si disegna); su widget V3 o del tema con la classe CSS omonima; ovunque con data-ast="ruolo". Attributi opzionali: data-ast-delay, data-ast-duration, data-ast-y, data-ast-speed, data-ast-stagger. Impostazioni: enabled, lenis, lenis_duration, lenis_touch, distance, duration, ease, pin_offset, anchor_offset.',
		'input'       => Util::obj(
			array(
				'settings' => Util::o( 'Impostazioni da aggiornare (vuoto = sola lettura).' ),
			)
		),
		'run'         => static function ( array $in ) {
			$patch = Util::arr( $in, 'settings' );
			return array(
				'settings'  => $patch ? Motion::update( $patch ) : Motion::settings(),
				'selectors' => Motion::selectors(),
			);
		},
	)
);
