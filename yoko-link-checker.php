<?php
/**
 * Plugin Name:       Yoko Link Checker
 * Plugin URI:        https://github.com/Yoko-Co/yoko-link-checker
 * Description:       A performant, extensible broken link checker for WordPress. Scans content for links, checks their validity, and reports issues.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      8.0
 * Author:            Yoko Co.
 * Author URI:        https://yokoco.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yoko-link-checker
 * Domain Path:       /languages
 *
 * @package YokoLinkChecker
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'YOKO_LC_VERSION', '1.2.0' );
define( 'YOKO_LC_PLUGIN_FILE', __FILE__ );
define( 'YOKO_LC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YOKO_LC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YOKO_LC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Minimum requirements check.
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Yoko Link Checker requires PHP 8.0 or higher. Please upgrade your PHP version.',
					'yoko-link-checker'
				)
			);
		}
	);
	return;
}

// Autoloader.
spl_autoload_register(
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- Standard autoloader parameter name.
	function ( string $class ): void {
		$prefix   = 'YokoLinkChecker\\';
		$base_dir = YOKO_LC_PLUGIN_DIR . 'src/';

		// Check if the class uses the namespace prefix.
		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		// Get the relative class name.
		$relative_class = substr( $class, $len );

		// Replace namespace separators with directory separators.
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// If the file exists, require it.
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Returns the main plugin instance.
 *
 * @since 1.0.0
 * @return YokoLinkChecker\Plugin
 */
function yoko_lc(): YokoLinkChecker\Plugin {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new YokoLinkChecker\Plugin();
	}

	return $instance;
}

// Activation hook.
register_activation_hook(
	__FILE__,
	function (): void {
		require_once YOKO_LC_PLUGIN_DIR . 'src/Activator.php';
		YokoLinkChecker\Activator::activate();
	}
);

// Deactivation hook.
register_deactivation_hook(
	__FILE__,
	function (): void {
		require_once YOKO_LC_PLUGIN_DIR . 'src/Deactivator.php';
		YokoLinkChecker\Deactivator::deactivate();
	}
);

// Initialize the plugin.
add_action(
	'plugins_loaded',
	function (): void {
		// Load text domain.
		load_plugin_textdomain(
			'yoko-link-checker',
			false,
			dirname( YOKO_LC_PLUGIN_BASENAME ) . '/languages'
		);

		// Boot the plugin.
		yoko_lc()->boot();
	}
);
