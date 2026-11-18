<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Motore di animazione: GSAP + ScrollTrigger + SplitText + Lenis, guidato da classi.
 * Ogni ruolo si attiva con la classe globale V4 "ast-<ruolo>", con la classe CSS
 * classica "ast-<ruolo>" (widget V3 o del tema) o con l'attributo data-ast="<ruolo>".
 */
final class Motion {

	const OPTION = 'ast_motion';
	const ROLES  = array( 'reveal', 'stagger', 'split-text', 'parallax', 'pin', 'hscroll', 'counter', 'rule' );

	public static function defaults(): array {
		return array(
			'enabled'        => true,
			'lenis'          => true,
			'lenis_duration' => 1.15,
			'lenis_touch'    => false,
			'distance'       => 48,
			'duration'       => 1.0,
			'ease'           => 'power3.out',
			'pin_offset'     => 120,
			'anchor_offset'  => 100,
		);
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function update( array $patch ): array {
		$cur = self::settings();
		foreach ( self::defaults() as $k => $def ) {
			if ( ! array_key_exists( $k, $patch ) ) {
				continue;
			}
			$v = $patch[ $k ];
			if ( is_bool( $def ) ) {
				$cur[ $k ] = (bool) filter_var( $v, FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_int( $def ) ) {
				$cur[ $k ] = (int) $v;
			} elseif ( is_float( $def ) ) {
				$cur[ $k ] = (float) $v;
			} else {
				$cur[ $k ] = preg_replace( '/[^a-zA-Z0-9.()_,-]/', '', (string) $v );
			}
		}
		update_option( self::OPTION, $cur, false );
		return $cur;
	}

	/** Selettore CSS per ruolo, includendo l'id della classe globale V4 se esiste. */
	public static function selectors(): array {
		$map = ElementorDoc::global_classes();
		$out = array();
		foreach ( self::ROLES as $role ) {
			$label = 'ast-' . $role;
			$sel   = array( '.' . $label, '[data-ast~="' . $role . '"]' );
			if ( ! empty( $map[ $label ] ) && $map[ $label ] !== $label ) {
				$sel[] = '.' . sanitize_html_class( $map[ $label ] );
			}
			$out[ $role ] = implode( ', ', $sel );
		}
		return $out;
	}

	private static function active(): bool {
		if ( is_admin() || wp_doing_ajax() || isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore
			return false;
		}
		return (bool) self::settings()['enabled'];
	}

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
	}

	public static function head(): void {
		if ( ! self::active() ) {
			return;
		}
		// Nasconde gli elementi da animare solo se il motore partirà; rete di sicurezza a 3,5 s.
		echo "<script>(function(d){if(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches)return;var h=d.documentElement;h.classList.add('ast-motion');setTimeout(function(){if(!h.classList.contains('ast-motion-on'))h.classList.remove('ast-motion');},3500);})(document);</script>\n"; // phpcs:ignore
	}

	public static function enqueue(): void {
		if ( ! self::active() ) {
			return;
		}
		$s   = self::settings();
		$v   = AST_VERSION;
		$opt = array(
			'in_footer' => true,
			'strategy'  => 'defer',
		);
		$u   = AST_URL . 'assets/';
		// Le librerie GSAP e Lenis non sono distribuite con il plugin: vedi assets/vendor/LEGGIMI.md.
		if ( ! file_exists( AST_DIR . 'assets/vendor/gsap.min.js' ) ) {
			return;
		}

		wp_register_script( 'ast-gsap', $u . 'vendor/gsap.min.js', array(), '3.15.0', $opt );
		wp_register_script( 'ast-gsap-st', $u . 'vendor/ScrollTrigger.min.js', array( 'ast-gsap' ), '3.15.0', $opt );
		wp_register_script( 'ast-gsap-split', $u . 'vendor/SplitText.min.js', array( 'ast-gsap' ), '3.15.0', $opt );
		$deps = array( 'ast-gsap', 'ast-gsap-st', 'ast-gsap-split' );
		if ( $s['lenis'] ) {
			wp_register_script( 'ast-lenis', $u . 'vendor/lenis.min.js', array(), '1.3.27', $opt );
			$deps[] = 'ast-lenis';
		}
		wp_enqueue_script( 'ast-motion', $u . 'js/ast-motion.js', $deps, $v, $opt );
		wp_enqueue_style( 'ast-motion', $u . 'css/ast-motion.css', array(), $v );

		$sel = self::selectors();
		$cfg = array(
			'enabled'       => true,
			'lenis'         => (bool) $s['lenis'],
			'lenisDuration' => (float) $s['lenis_duration'],
			'lenisTouch'    => (bool) $s['lenis_touch'],
			'distance'      => (int) $s['distance'],
			'duration'      => (float) $s['duration'],
			'ease'          => (string) $s['ease'],
			'pinOffset'     => (int) $s['pin_offset'],
			'anchorOffset'  => (int) $s['anchor_offset'],
			'selectors'     => $sel,
		);
		wp_add_inline_script( 'ast-motion', 'window.AST_MOTION=' . wp_json_encode( $cfg ) . ';', 'before' );

		$hide = array(
			$sel['reveal'],
			$sel['split-text'],
			self::child_selector( $sel['stagger'] ),
		);
		wp_add_inline_style( 'ast-motion', 'html.ast-motion :is(' . implode( ', ', $hide ) . '){opacity:0}' );
	}

	private static function child_selector( string $list ): string {
		return implode(
			', ',
			array_map(
				static function ( $s ) {
					return trim( $s ) . ' > *';
				},
				explode( ',', $list )
			)
		);
	}
}
