<?php
/**
 * Admin Controller class.
 *
 * Owns the plugin's single admin screen: registers it under Tools, routes the
 * ?tab= parameter to the right page object, and builds every URL that points
 * back at the screen so no other file has to know where the plugin lives.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller class.
 *
 * @since 1.0.0
 */
class AdminController {

	/**
	 * Menu slug.
	 */
	public const MENU_SLUG = 'yoko-link-checker';

	/**
	 * Parent menu file the plugin screen hangs from.
	 *
	 * @since 1.2.0
	 */
	public const PARENT_SLUG = 'tools.php';

	/**
	 * Tab slugs, in the order they appear in the nav.
	 *
	 * @since 1.2.0
	 * @var array<string>
	 */
	public const TABS = array( 'dashboard', 'reports', 'settings' );

	/**
	 * Capability required to view the screen at all.
	 *
	 * @since 1.2.0
	 */
	public const VIEW_CAP = 'yoko_lc_view_results';

	/**
	 * Capability required for the Settings tab.
	 *
	 * @since 1.2.0
	 */
	public const SETTINGS_CAP = 'yoko_lc_manage_settings';

	/**
	 * Dashboard page instance.
	 *
	 * @var DashboardPage
	 */
	private DashboardPage $dashboard_page;

	/**
	 * Results page instance.
	 *
	 * @var ResultsPage
	 */
	private ResultsPage $results_page;

	/**
	 * AJAX handler instance.
	 *
	 * @var AjaxHandler
	 */
	private AjaxHandler $ajax_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param DashboardPage $dashboard_page Dashboard page instance.
	 * @param ResultsPage   $results_page   Results page instance.
	 * @param AjaxHandler   $ajax_handler   AJAX handler instance.
	 */
	public function __construct(
		DashboardPage $dashboard_page,
		ResultsPage $results_page,
		AjaxHandler $ajax_handler
	) {
		$this->dashboard_page = $dashboard_page;
		$this->results_page   = $results_page;
		$this->ajax_handler   = $ajax_handler;
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register(): void {
		// WP SEAM: admin_menu -- fires on every admin request after the user is known.
		// Registers the plugin's single screen under Tools.
		add_action( 'admin_menu', array( $this, 'register_menu' ) );

		// WP SEAM: admin_enqueue_scripts -- fires per admin screen with the hook suffix.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// WP SEAM: set_screen_option_{$option} -- core runs this during admin bootstrap,
		// well before our screen's load hook, so it cannot be registered alongside
		// add_screen_option(). Returning the value is what lets core persist it.
		add_filter( 'set_screen_option_' . LinksListTable::PER_PAGE_OPTION, array( $this, 'save_per_page_option' ), 10, 3 );

		// Register AJAX handlers.
		$this->ajax_handler->register();
	}

	/**
	 * Register the admin screen under Tools.
	 *
	 * One submenu entry, three tabs. Three sibling entries under Tools would
	 * crowd a menu shared with core, so Dashboard/Reports/Settings are tabs on
	 * a single page (the Site Health pattern) routed by ?tab=.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Moved from a top-level menu to Tools, with in-page tabs.
	 * @return void
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Yoko Link Checker', 'yoko-link-checker' ),
			__( 'Yoko Link Checker', 'yoko-link-checker' ),
			self::VIEW_CAP,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		// Everything that sends headers or persists a screen option has to run on
		// the load hook, before the admin page starts writing output.
		if ( $hook ) {
			// WP SEAM: load-{$hook} -- fires only for this screen, before render.
			add_action( "load-{$hook}", array( $this, 'maybe_handle_export' ) );
			add_action( "load-{$hook}", array( $this, 'maybe_handle_reports_actions' ) );
			add_action( "load-{$hook}", array( $this, 'maybe_handle_settings_save' ) );
		}
	}

	/**
	 * Route the current request to the page object for the active tab.
	 *
	 * Unknown tabs fall back to the dashboard, never to a privileged tab.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function render(): void {
		switch ( self::current_tab() ) {
			case 'reports':
				$this->results_page->render();
				break;

			case 'settings':
				$this->render_settings();
				break;

			default:
				$this->dashboard_page->render();
				break;
		}
	}

	/**
	 * Get the active tab slug.
	 *
	 * @since 1.2.0
	 * @return string One of self::TABS.
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameter.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		return in_array( $tab, self::TABS, true ) ? $tab : 'dashboard';
	}

	/**
	 * Build a URL to one of the plugin's tabs.
	 *
	 * Every link that points back at this plugin goes through here, so the
	 * screen can be moved again by changing PARENT_SLUG alone.
	 *
	 * @since 1.2.0
	 * @param string               $tab   Tab slug.
	 * @param array<string, mixed> $extra Extra query args (status, search, etc.).
	 * @return string Escaped-on-output-ready URL.
	 */
	public static function page_url( string $tab = 'dashboard', array $extra = array() ): string {
		$args = array_merge(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => in_array( $tab, self::TABS, true ) ? $tab : 'dashboard',
			),
			$extra
		);

		return add_query_arg( $args, admin_url( self::PARENT_SLUG ) );
	}

	/**
	 * Tab labels, keyed by slug, filtered to what the current user may see.
	 *
	 * @since 1.2.0
	 * @return array<string, string>
	 */
	public static function visible_tabs(): array {
		$tabs = array(
			'dashboard' => __( 'Dashboard', 'yoko-link-checker' ),
			'reports'   => __( 'Reports', 'yoko-link-checker' ),
			'settings'  => __( 'Settings', 'yoko-link-checker' ),
		);

		if ( ! self::can_manage_settings() ) {
			unset( $tabs['settings'] );
		}

		return $tabs;
	}

	/**
	 * Whether the current user may change plugin settings.
	 *
	 * The screen itself only requires VIEW_CAP now that all three tabs share one
	 * menu entry, so the Settings tab has to check its own capability.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( self::SETTINGS_CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Handle CSV export on the screen's load hook.
	 *
	 * Runs before any output so handle_export() can send its own headers.
	 *
	 * @since 1.0.11
	 * @since 1.2.0 Scoped to the Reports tab.
	 * @return void
	 */
	public function maybe_handle_export(): void {
		if ( 'reports' !== self::current_tab() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in handle_export().
		if ( isset( $_GET['action'] ) && 'export' === $_GET['action'] ) {
			$this->results_page->handle_export();
		}
	}

	/**
	 * Run the Reports tab's load-time work: screen options and row actions.
	 *
	 * Both need to happen before output -- add_screen_option() so WordPress can
	 * persist the choice, and the ignore/un-ignore actions so their redirect
	 * isn't fighting headers that have already been sent.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function maybe_handle_reports_actions(): void {
		if ( 'reports' !== self::current_tab() ) {
			return;
		}

		$this->results_page->register_screen_options();
		$this->results_page->maybe_handle_actions();
	}

	/**
	 * Persist the Reports table's rows-per-page screen option.
	 *
	 * @since 1.2.0
	 * @param mixed  $status Value to save, or false to skip saving.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return int Clamped rows per page.
	 */
	public function save_per_page_option( $status, string $option, $value ): int {
		return min( 500, max( 1, absint( $value ) ) );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Only load on plugin pages.
		if ( ! $this->is_plugin_page( $hook_suffix ) ) {
			return;
		}

		// Styles.
		wp_enqueue_style(
			'ylc-admin',
			YOKO_LC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			YOKO_LC_VERSION
		);

		// Scripts.
		wp_enqueue_script(
			'ylc-admin',
			YOKO_LC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			YOKO_LC_VERSION,
			true
		);

		// Localize script.
		wp_localize_script( 'ylc-admin', 'ylcAdmin', $this->get_js_data() );
	}

	/**
	 * Check if current page is a plugin page.
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix Hook suffix.
	 * @return bool
	 */
	private function is_plugin_page( string $hook_suffix ): bool {
		// Check if the hook contains our menu slug.
		return false !== strpos( $hook_suffix, self::MENU_SLUG );
	}

	/**
	 * Get JavaScript localization data.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_js_data(): array {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			// One nonce per endpoint, keyed by action. A single shared nonce meant
			// anything that could read this object could call clear_data.
			'nonces'  => AjaxHandler::nonces(),
			'strings' => array(
				'confirmStart'  => __( 'Start a new scan?', 'yoko-link-checker' ),
				'confirmCancel' => __( 'Cancel the current scan?', 'yoko-link-checker' ),
				'confirmIgnore' => __( 'Ignore this link?', 'yoko-link-checker' ),
				'confirmClear'  => __( 'Are you sure you want to delete all scan data? This cannot be undone.', 'yoko-link-checker' ),
				'scanning'      => __( 'Scanning...', 'yoko-link-checker' ),
				'checking'      => __( 'Checking...', 'yoko-link-checker' ),
				'clearing'      => __( 'Clearing...', 'yoko-link-checker' ),
				'clearData'     => __( 'Clear All Scan Data', 'yoko-link-checker' ),
				'startNewScan'  => __( 'Start New Scan', 'yoko-link-checker' ),
				'complete'      => __( 'Complete', 'yoko-link-checker' ),
				'dataCleared'   => __( 'All scan data has been cleared.', 'yoko-link-checker' ),
				'error'         => __( 'An error occurred. Please try again.', 'yoko-link-checker' ),
				/* translators: Label shown before the scan phase name, e.g. "Phase: Discovery" */
				'phase'         => __( 'Phase:', 'yoko-link-checker' ),
			),
		);
	}

	/**
	 * Render settings page.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Checks its own capability -- the shared menu entry only requires VIEW_CAP.
	 * @return void
	 */
	public function render_settings(): void {
		if ( ! self::can_manage_settings() ) {
			wp_die(
				esc_html__( 'You do not have permission to change Yoko Link Checker settings.', 'yoko-link-checker' ),
				'',
				array( 'response' => 403 )
			);
		}

		// The save itself runs on the load hook (see maybe_handle_settings_save)
		// and redirects, so by the time we render there is only a result to show.
		$this->queue_settings_notice();

		$settings = $this->get_settings();

		include YOKO_LC_PLUGIN_DIR . 'templates/admin/settings.php';
	}

	/**
	 * Save settings on the screen's load hook, then redirect.
	 *
	 * Post/Redirect/Get. The save used to run during render, which left the POST
	 * in the browser's history: refreshing the page silently re-submitted the
	 * form, and the "are you sure you want to resubmit" dialog was the only thing
	 * standing between a stray refresh and a repeat write (including a cron
	 * reschedule). Running before output means we can redirect instead.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function maybe_handle_settings_save(): void {
		if ( 'settings' !== self::current_tab() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_settings_save().
		if ( ! isset( $_POST['yoko_lc_settings_nonce'] ) ) {
			return;
		}

		$result = $this->handle_settings_save();

		wp_safe_redirect( self::page_url( 'settings', array( 'ylc_notice' => $result ) ) );
		exit;
	}

	/**
	 * Turn the redirect's result code back into an admin notice.
	 *
	 * Notices queued with add_settings_error() do not survive a redirect, so the
	 * outcome travels in the URL and is translated back into one here.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function queue_settings_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only, and allow-listed below.
		$notice = isset( $_GET['ylc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ylc_notice'] ) ) : '';

		$notices = array(
			'saved'      => array( __( 'Settings saved.', 'yoko-link-checker' ), 'success' ),
			'nonce'      => array( __( 'Security check failed. Please try again.', 'yoko-link-checker' ), 'error' ),
			'permission' => array( __( 'Permission denied.', 'yoko-link-checker' ), 'error' ),
		);

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		add_settings_error( 'yoko_lc_settings', "yoko_lc_{$notice}", $notices[ $notice ][0], $notices[ $notice ][1] );
	}

	/**
	 * Handle settings save.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Returns a result code instead of queueing a notice, so the
	 *              caller can redirect (Post/Redirect/Get).
	 * @return string One of 'saved', 'nonce', 'permission'.
	 */
	private function handle_settings_save(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_verify_nonce() hashes the value.
		if ( ! wp_verify_nonce( wp_unslash( $_POST['yoko_lc_settings_nonce'] ?? '' ), 'yoko_lc_settings' ) ) {
			return 'nonce';
		}

		if ( ! self::can_manage_settings() ) {
			return 'permission';
		}

		// Only post types that actually exist and are scannable. Sanitizing alone
		// let arbitrary slugs be stored, which then sat in the options table
		// forever looking like a configuration the site no longer had.
		$submitted  = isset( $_POST['yoko_lc_post_types'] ) && is_array( $_POST['yoko_lc_post_types'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['yoko_lc_post_types'] ) )
			: array();
		$post_types = array_values( array_filter( $submitted, 'post_type_exists' ) );

		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$check_timeout = isset( $_POST['yoko_lc_check_timeout'] )
			? absint( wp_unslash( $_POST['yoko_lc_check_timeout'] ) )
			: 30;

		$auto_scan = isset( $_POST['yoko_lc_auto_scan_enabled'] );

		$scan_frequency = isset( $_POST['yoko_lc_auto_scan_frequency'] )
			? sanitize_key( wp_unslash( $_POST['yoko_lc_auto_scan_frequency'] ) )
			: 'weekly';

		$allowed_frequencies = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
		if ( ! in_array( $scan_frequency, $allowed_frequencies, true ) ) {
			$scan_frequency = 'weekly';
		}

		update_option( 'yoko_lc_post_types', $post_types );
		update_option( 'yoko_lc_check_timeout', min( 120, max( 5, $check_timeout ) ) );
		update_option( 'yoko_lc_auto_scan_enabled', $auto_scan );
		update_option( 'yoko_lc_auto_scan_frequency', $scan_frequency );

		// uninstall.php reads this and defaults to destroying everything. Until
		// now nothing wrote it, so there was no way to answer the question.
		update_option( 'yoko_lc_remove_data_on_uninstall', isset( $_POST['yoko_lc_remove_data_on_uninstall'] ) );

		// Sync cron schedule with saved auto-scan settings.
		wp_clear_scheduled_hook( 'yoko_lc_auto_scan' );

		if ( $auto_scan ) {
			wp_schedule_event( time(), $scan_frequency, 'yoko_lc_auto_scan' );
		}

		return 'saved';
	}

	/**
	 * Get current settings.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_settings(): array {
		return array(
			'post_types'          => get_option( 'yoko_lc_post_types', array( 'post', 'page' ) ),
			'check_timeout'       => get_option( 'yoko_lc_check_timeout', 30 ),
			'auto_scan_enabled'   => get_option( 'yoko_lc_auto_scan_enabled', false ),
			'auto_scan_frequency' => get_option( 'yoko_lc_auto_scan_frequency', 'weekly' ),
			// Matches uninstall.php's own default, so the checkbox reflects what
			// would actually happen if the plugin were deleted right now.
			'remove_data'         => (bool) get_option( 'yoko_lc_remove_data_on_uninstall', true ),
		);
	}
}
