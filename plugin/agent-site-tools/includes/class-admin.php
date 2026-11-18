<?php
namespace AgentSiteTools;

defined( 'ABSPATH' ) || exit;

/**
 * Pagina Strumenti → Agent Site Tools: stato, endpoint, motore animazioni, snapshot.
 */
final class Admin {

	const SLUG = 'agent-site-tools';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ast_motion', array( __CLASS__, 'save_motion' ) );
		add_action( 'admin_post_ast_restore', array( __CLASS__, 'restore' ) );
		add_action( 'admin_post_ast_files', array( __CLASS__, 'save_files' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AST_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function links( array $l ): array {
		array_unshift( $l, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">Impostazioni</a>' );
		return $l;
	}

	public static function menu(): void {
		add_management_page( 'Agent Site Tools', 'Agent Site Tools', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function notice(): void {
		if ( Server::adapter_available() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'tools_page_' . self::SLUG ), true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Agent Site Tools:</strong> installa e attiva il plugin <a href="' . esc_url( admin_url( 'plugin-install.php?s=mcp-adapter&tab=search&type=term' ) ) . '">MCP Adapter</a> per attivare l\'endpoint MCP dedicato.</p></div>';
	}

	public static function save_motion(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permessi insufficienti.' );
		}
		check_admin_referer( 'ast_motion' );
		$p = wp_unslash( $_POST ); // phpcs:ignore
		Motion::update(
			array(
				'enabled'        => ! empty( $p['enabled'] ),
				'lenis'          => ! empty( $p['lenis'] ),
				'lenis_touch'    => ! empty( $p['lenis_touch'] ),
				'lenis_duration' => $p['lenis_duration'] ?? 1.15,
				'distance'       => $p['distance'] ?? 48,
				'duration'       => $p['duration'] ?? 1,
				'ease'           => $p['ease'] ?? 'power3.out',
				'pin_offset'     => $p['pin_offset'] ?? 120,
				'anchor_offset'  => $p['anchor_offset'] ?? 100,
			)
		);
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG . '&saved=1' ) );
		exit;
	}

	public static function save_files(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permessi insufficienti.' );
		}
		check_admin_referer( 'ast_files' );
		update_option( Files::OPTION_WRITE, empty( $_POST['file_write'] ) ? '0' : '1', false ); // phpcs:ignore
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG . '&saved=1' ) );
		exit;
	}

	public static function restore(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'Permessi insufficienti.' );
		}
		check_admin_referer( 'ast_restore' );
		$res = Snapshots::restore( (int) ( $_POST['snapshot_id'] ?? 0 ) ); // phpcs:ignore
		$arg = is_wp_error( $res ) ? 'error=' . rawurlencode( $res->get_error_message() ) : 'restored=1';
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG . '&' . $arg ) );
		exit;
	}

	public static function render(): void {
		$m     = Motion::settings();
		$snaps = Snapshots::list( '', '', 40 );
		$ok    = Server::adapter_available();
		?>
		<div class="wrap">
			<h1>Agent Site Tools <small style="font-weight:400;color:#646970">v<?php echo esc_html( AST_VERSION ); ?></small></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Impostazioni salvate.</p></div>
			<?php elseif ( isset( $_GET['restored'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Snapshot ripristinato.</p></div>
			<?php elseif ( isset( $_GET['error'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); // phpcs:ignore ?></p></div>
			<?php endif; ?>

			<h2>Connessione MCP</h2>
			<table class="form-table" role="presentation">
				<tr><th>MCP Adapter</th><td><?php echo $ok ? '<span style="color:#008a20">● attivo</span>' : '<span style="color:#d63638">● non installato</span>'; ?></td></tr>
				<tr><th>Endpoint</th><td><code><?php echo esc_html( Server::endpoint() ); ?></code></td></tr>
				<tr><th>Strumenti</th><td><?php echo (int) count( Abilities::names() ); ?> abilities registrate (categoria <code>agent-site-tools</code>)</td></tr>
				<tr><th>Configurazione client</th><td>
<pre style="background:#f6f7f7;padding:12px;max-width:760px;overflow:auto">"mio-sito-tools": {
  "command": "npx",
  "args": ["-y", "mcp-remote", "<?php echo esc_html( Server::endpoint() ); ?>",
           "--header", "Authorization:${AUTH_HEADER}"],
  "env": { "AUTH_HEADER": "Basic &lt;base64 utente:password-applicazione&gt;" }
}</pre></td></tr>
			</table>

			<h2>Motore animazioni</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ast_motion">
				<?php wp_nonce_field( 'ast_motion' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>Animazioni</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $m['enabled'] ); ?>> Attive (GSAP + ScrollTrigger + SplitText)</label></td></tr>
					<tr><th>Scroll fluido</th><td>
						<label><input type="checkbox" name="lenis" value="1" <?php checked( $m['lenis'] ); ?>> Lenis</label>&nbsp;&nbsp;
						<label><input type="checkbox" name="lenis_touch" value="1" <?php checked( $m['lenis_touch'] ); ?>> anche su touch</label>&nbsp;&nbsp;
						durata <input type="number" step="0.05" min="0.3" max="3" name="lenis_duration" value="<?php echo esc_attr( $m['lenis_duration'] ); ?>" class="small-text"> s
					</td></tr>
					<tr><th>Rivelazione</th><td>
						distanza <input type="number" name="distance" value="<?php echo esc_attr( $m['distance'] ); ?>" class="small-text"> px&nbsp;&nbsp;
						durata <input type="number" step="0.05" name="duration" value="<?php echo esc_attr( $m['duration'] ); ?>" class="small-text"> s&nbsp;&nbsp;
						easing <input type="text" name="ease" value="<?php echo esc_attr( $m['ease'] ); ?>" class="regular-text" style="width:140px">
					</td></tr>
					<tr><th>Offset</th><td>
						pin <input type="number" name="pin_offset" value="<?php echo esc_attr( $m['pin_offset'] ); ?>" class="small-text"> px&nbsp;&nbsp;
						ancore <input type="number" name="anchor_offset" value="<?php echo esc_attr( $m['anchor_offset'] ); ?>" class="small-text"> px
					</td></tr>
				</table>
				<?php submit_button( 'Salva' ); ?>
			</form>

			<h2>Accesso ai file</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ast_files">
				<?php wp_nonce_field( 'ast_files' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>Lettura</th><td>Sempre attiva per gli amministratori: tutto <code>wp-content</code> e i log di errore.</td></tr>
					<tr><th>Scrittura</th><td><label><input type="checkbox" name="file_write" value="1" <?php checked( Files::write_enabled() ); ?>> Consenti la scrittura in <code><?php echo esc_html( implode( ', ', Files::writable_roots() ) ); ?></code></label>
						<p class="description">Ogni scrittura salva uno snapshot. I file PHP vengono controllati e ripristinati se il sito va in errore fatale.</p></td></tr>
				</table>
				<?php submit_button( 'Salva' ); ?>
			</form>

			<h2>Snapshot recenti</h2>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th>#</th><th>Tipo</th><th>Oggetto</th><th>Nota</th><th>Data</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $snaps ) : ?>
					<tr><td colspan="6">Nessuno snapshot.</td></tr>
				<?php endif; ?>
				<?php foreach ( $snaps as $s ) : ?>
					<tr>
						<td><?php echo (int) $s['id']; ?></td>
						<td><?php echo esc_html( $s['object_type'] ); ?></td>
						<td><?php echo esc_html( $s['label'] ? $s['label'] . ' (' . $s['object_key'] . ')' : $s['object_key'] ); ?></td>
						<td><?php echo esc_html( $s['note'] ); ?></td>
						<td><?php echo esc_html( $s['created_at'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Ripristinare questo snapshot?');">
								<input type="hidden" name="action" value="ast_restore">
								<input type="hidden" name="snapshot_id" value="<?php echo (int) $s['id']; ?>">
								<?php wp_nonce_field( 'ast_restore' ); ?>
								<button class="button button-small">Ripristina</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
