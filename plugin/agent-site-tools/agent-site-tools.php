<?php
/**
 * Plugin Name:       Agent Site Tools
 * Description:       Strumenti MCP per il controllo completo di contenuti, Elementor, media, menu, stile, file (tema, mu-plugins, plugin ast-*) e plugin del sito, con snapshot ripristinabili e motore di animazione GSAP + Lenis.
 * Version:           1.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            GeniusWeb S.r.l.
 * License:           GPL-2.0-or-later
 * Text Domain:       agent-site-tools
 */

defined( 'ABSPATH' ) || exit;

define( 'AST_VERSION', '1.2.0' );
define( 'AST_DB_VERSION', '1' );
define( 'AST_FILE', __FILE__ );
define( 'AST_DIR', plugin_dir_path( __FILE__ ) );
define( 'AST_URL', plugin_dir_url( __FILE__ ) );

require_once AST_DIR . 'includes/class-util.php';
require_once AST_DIR . 'includes/class-snapshots.php';
require_once AST_DIR . 'includes/class-abilities.php';
require_once AST_DIR . 'includes/class-elementor-doc.php';
require_once AST_DIR . 'includes/class-media.php';
require_once AST_DIR . 'includes/class-assets.php';
require_once AST_DIR . 'includes/class-files.php';
require_once AST_DIR . 'includes/class-motion.php';
require_once AST_DIR . 'includes/class-server.php';
require_once AST_DIR . 'includes/class-admin.php';

require_once AST_DIR . 'includes/abilities/elementor.php';
require_once AST_DIR . 'includes/abilities/content.php';
require_once AST_DIR . 'includes/abilities/media.php';
require_once AST_DIR . 'includes/abilities/menus.php';
require_once AST_DIR . 'includes/abilities/site.php';
require_once AST_DIR . 'includes/abilities/design.php';
require_once AST_DIR . 'includes/abilities/system.php';
require_once AST_DIR . 'includes/abilities/files.php';

register_activation_hook( __FILE__, array( '\AgentSiteTools\Snapshots', 'install' ) );
register_activation_hook( __FILE__, array( '\AgentSiteTools\Assets', 'ensure_dir' ) );

add_action( 'plugins_loaded', function () {
	\AgentSiteTools\Snapshots::maybe_upgrade();
	\AgentSiteTools\Abilities::boot();
	\AgentSiteTools\Server::boot();
	\AgentSiteTools\Assets::boot();
	\AgentSiteTools\Files::boot();
	\AgentSiteTools\Motion::boot();
	\AgentSiteTools\Admin::boot();
} );
