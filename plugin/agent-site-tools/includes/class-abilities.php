<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Registro centrale: ogni modulo dichiara le sue abilities con Abilities::add(),
 * qui vengono registrate sull'Abilities API e rese pubbliche per MCP.
 */
final class Abilities {

	const PREFIX   = 'ast/';
	const CATEGORY = 'agent-site-tools';

	/** @var array<string,array> */
	private static $defs = array();

	/**
	 * @param array $def label, description, input (schema), run (callable), cap|perm, readonly, destructive, idempotent
	 */
	public static function add( string $name, array $def ): void {
		self::$defs[ $name ] = $def;
	}

	/** @return string[] nomi completi delle abilities (per il server MCP dedicato). */
	public static function names(): array {
		return array_map(
			static function ( $n ) {
				return self::PREFIX . $n;
			},
			array_keys( self::$defs )
		);
	}

	public static function boot(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_all' ) );
	}

	public static function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				self::CATEGORY,
				array(
					'label'       => 'Agent Site Tools',
					'description' => 'Controllo completo di contenuti, Elementor, media, menu e stile del sito.',
				)
			);
		}
	}

	public static function register_all(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( self::$defs as $name => $def ) {
			$run  = $def['run'];
			$cap  = $def['cap'] ?? 'manage_options';
			$perm = $def['perm'] ?? null;

			$input = $def['input'] ?? Util::obj( array( 'note' => Util::note() ) );
			if ( empty( $input['properties'] ) ) {
				$input['properties'] = array( 'note' => Util::note() );
			}

			wp_register_ability(
				self::PREFIX . $name,
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => $input,
					'execute_callback'    => static function ( $in = null ) use ( $run ) {
						$in = is_array( $in ) ? $in : array();
						try {
							return call_user_func( $run, $in );
						} catch ( \Throwable $e ) {
							return Util::err( 'exception', $e->getMessage(), 500 );
						}
					},
					'permission_callback' => static function ( $in = null ) use ( $cap, $perm ) {
						if ( is_callable( $perm ) ) {
							return (bool) call_user_func( $perm, is_array( $in ) ? $in : array() );
						}
						return current_user_can( $cap );
					},
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'readonly'    => ! empty( $def['readonly'] ),
							'destructive' => ! empty( $def['destructive'] ),
							'idempotent'  => ! empty( $def['idempotent'] ),
						),
					),
				)
			);
		}
	}
}
