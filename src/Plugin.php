<?php
/**
 * Main Plugin orchestrator class.
 *
 * Acts as a lightweight service container and coordinates
 * initialization of all plugin components.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Admin\AdminController;
use YokoLinkChecker\Admin\AjaxHandler;
use YokoLinkChecker\Admin\DashboardPage;
use YokoLinkChecker\Admin\ResultsPage;
use YokoLinkChecker\Checker\HttpClient;
use YokoLinkChecker\Checker\StatusClassifier;
use YokoLinkChecker\Checker\UrlChecker;
use YokoLinkChecker\Extractor\ExtractorRegistry;
use YokoLinkChecker\Extractor\HtmlExtractor;
use YokoLinkChecker\Cli\StatsCommand;
use YokoLinkChecker\Repository\LinkRepository;
use YokoLinkChecker\Repository\LinkStats;
use YokoLinkChecker\Repository\ScanRepository;
use YokoLinkChecker\Repository\UrlRepository;
use YokoLinkChecker\Scanner\BatchProcessor;
use YokoLinkChecker\Scanner\ContentDiscovery;
use YokoLinkChecker\Scanner\ScanOrchestrator;
use YokoLinkChecker\Util\UrlNormalizer;

/**
 * Plugin container and orchestrator.
 *
 * Provides lazy-loaded access to all plugin services.
 * Services are instantiated on first access and cached.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Service container for lazy-loaded instances.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Whether the plugin has been booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Boot the plugin.
	 *
	 * Initializes hooks and loads components.
	 * Safe to call multiple times; will only boot once.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		// Register custom cron schedules on every request so WP-Cron recognizes them.
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 60-second interval is intentional for responsive batch processing.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Check if database needs setup (in case activation hook didn't run).
		$this->maybe_run_activation();

		// Initialize admin components only in admin context.
		if ( is_admin() ) {
			$this->admin_controller()->register();
		}

		// Register cron hooks (available in all contexts for WP-Cron).
		$this->register_cron_hooks();

		// Content hooks run in every context -- posts are deleted from cron and WP-CLI too.
		$this->register_content_hooks();

		// Register WP-CLI commands when running under WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			StatsCommand::register( $this );
		}

		/**
		 * Fires after the plugin has fully booted.
		 *
		 * @since 1.0.0
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'yoko_lc_booted', $this );

		$this->booted = true;
	}

	/**
	 * Maybe run activation if not yet done.
	 *
	 * This handles cases where the plugin was updated or
	 * the activation hook didn't run properly.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function maybe_run_activation(): void {
		$schema_version = get_option( 'yoko_lc_schema_version', '' );

		if ( \YokoLinkChecker\Activator::SCHEMA_VERSION !== $schema_version ) {
			require_once YOKO_LC_PLUGIN_DIR . 'src/Activator.php';
			\YokoLinkChecker\Activator::activate();
			// Activator::activate() already writes the schema version.
		}
	}

	/**
	 * Register WP-Cron hooks for background processing.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_cron_hooks(): void {
		// WP SEAM: yoko_lc_process_scan_batch -- our own cron event, one batch per firing.
		add_action( 'yoko_lc_process_scan_batch', array( $this, 'handle_cron_batch' ), 10, 1 );

		// WP SEAM: yoko_lc_auto_scan -- our own recurring event from the Settings tab.
		add_action( 'yoko_lc_auto_scan', array( $this, 'handle_auto_scan' ), 10, 0 );
	}

	/**
	 * Register hooks that keep stored links in step with site content.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function register_content_hooks(): void {
		// WP SEAM: before_delete_post -- fires on permanent deletion, while the post
		// row still exists. Prunes that post's link occurrences so occurrence counts
		// don't drift above URL counts as content is removed. Trash is deliberately
		// not hooked: trashed posts are recoverable and their links should survive.
		// DEBUG: wp post delete <id> --force, then `wp yoko-lc verify` -- orphans must be 0.
		add_action( 'before_delete_post', array( $this, 'handle_post_deleted' ), 10, 1 );
	}

	/**
	 * Remove stored link occurrences for a permanently deleted post.
	 *
	 * @since 1.2.0
	 * @param int $post_id ID of the post being deleted.
	 * @return void
	 */
	public function handle_post_deleted( int $post_id ): void {
		$deleted = $this->link_repository()->delete_by_source( $post_id );

		if ( $deleted > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[yoko-link-checker] Pruned %1$d link(s) for deleted post %2$d.', $deleted, $post_id ) );
		}
	}

	/**
	 * Add custom cron schedules.
	 *
	 * Registered on every request so WP-Cron always recognizes
	 * the plugin's custom intervals.
	 *
	 * @since 1.0.8
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified cron schedules.
	 */
	public function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['yoko_lc_every_minute'] ) ) {
			$schedules['yoko_lc_every_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every Minute', 'yoko-link-checker' ),
			);
		}
		return $schedules;
	}

	/**
	 * Handle a cron-triggered scan batch.
	 *
	 * @since 1.0.0
	 * @param int $scan_id The scan ID to process.
	 * @return void
	 */
	public function handle_cron_batch( int $scan_id ): void {
		$this->scan_orchestrator()->process_batch( $scan_id );
	}

	/**
	 * Handle a cron-triggered automatic scan.
	 *
	 * Starts a full scan when the yoko_lc_auto_scan event fires.
	 *
	 * @since 1.0.9
	 * @return void
	 */
	public function handle_auto_scan(): void {
		if ( ! get_option( 'yoko_lc_auto_scan_enabled', false ) ) {
			return;
		}

		$this->scan_orchestrator()->start_scan( 'full' );
	}

	/**
	 * Get the URL normalizer service.
	 *
	 * @since 1.0.0
	 * @return UrlNormalizer
	 */
	public function url_normalizer(): UrlNormalizer {
		return $this->get_service(
			UrlNormalizer::class,
			fn() => new UrlNormalizer( home_url() )
		);
	}

	/**
	 * Get the URL repository.
	 *
	 * @since 1.0.0
	 * @return UrlRepository
	 */
	public function url_repository(): UrlRepository {
		return $this->get_service(
			UrlRepository::class,
			fn() => new UrlRepository( $this->url_normalizer() )
		);
	}

	/**
	 * Get the link repository.
	 *
	 * @since 1.0.0
	 * @return LinkRepository
	 */
	public function link_repository(): LinkRepository {
		return $this->get_service(
			LinkRepository::class,
			fn() => new LinkRepository()
		);
	}

	/**
	 * Get the link statistics service.
	 *
	 * Every count displayed by the plugin comes from here.
	 *
	 * @since 1.2.0
	 * @return LinkStats
	 */
	public function link_stats(): LinkStats {
		return $this->get_service(
			LinkStats::class,
			fn() => new LinkStats()
		);
	}

	/**
	 * Get the scan repository.
	 *
	 * @since 1.0.0
	 * @return ScanRepository
	 */
	public function scan_repository(): ScanRepository {
		return $this->get_service(
			ScanRepository::class,
			fn() => new ScanRepository()
		);
	}

	/**
	 * Get the extractor registry.
	 *
	 * @since 1.0.0
	 * @return ExtractorRegistry
	 */
	public function extractor_registry(): ExtractorRegistry {
		return $this->get_service(
			ExtractorRegistry::class,
			function () {
				$registry = new ExtractorRegistry();

				// Register core extractors.
				$registry->register( new HtmlExtractor( $this->url_normalizer() ) );

				/**
				 * Filters the registered extractors.
				 *
				 * @since 1.0.0
				 * @param ExtractorRegistry $registry The extractor registry.
				 */
				do_action( 'yoko_lc_register_extractors', $registry );

				return $registry;
			}
		);
	}

	/**
	 * Get the HTTP client.
	 *
	 * @since 1.0.0
	 * @return HttpClient
	 */
	public function http_client(): HttpClient {
		return $this->get_service(
			HttpClient::class,
			fn() => new HttpClient( (int) get_option( 'yoko_lc_check_timeout', 30 ) )
		);
	}

	/**
	 * Get the status classifier.
	 *
	 * @since 1.0.0
	 * @return StatusClassifier
	 */
	public function status_classifier(): StatusClassifier {
		return $this->get_service(
			StatusClassifier::class,
			fn() => new StatusClassifier()
		);
	}

	/**
	 * Get the URL checker service.
	 *
	 * @since 1.0.0
	 * @return UrlChecker
	 */
	public function url_checker(): UrlChecker {
		return $this->get_service(
			UrlChecker::class,
			fn() => new UrlChecker(
				$this->http_client(),
				$this->status_classifier()
			)
		);
	}

	/**
	 * Get the content discovery service.
	 *
	 * @since 1.0.0
	 * @return ContentDiscovery
	 */
	public function content_discovery(): ContentDiscovery {
		return $this->get_service(
			ContentDiscovery::class,
			fn() => new ContentDiscovery()
		);
	}

	/**
	 * Get the batch processor.
	 *
	 * @since 1.0.0
	 * @return BatchProcessor
	 */
	public function batch_processor(): BatchProcessor {
		return $this->get_service(
			BatchProcessor::class,
			fn() => new BatchProcessor(
				$this->content_discovery(),
				$this->extractor_registry(),
				$this->url_repository(),
				$this->link_repository(),
				$this->scan_repository(),
				$this->url_checker(),
				$this->status_classifier()
			)
		);
	}

	/**
	 * Get the scan orchestrator.
	 *
	 * @since 1.0.0
	 * @return ScanOrchestrator
	 */
	public function scan_orchestrator(): ScanOrchestrator {
		return $this->get_service(
			ScanOrchestrator::class,
			fn() => new ScanOrchestrator(
				$this->batch_processor(),
				$this->scan_repository(),
				$this->url_repository(),
				$this->content_discovery()
			)
		);
	}

	/**
	 * Get the dashboard page.
	 *
	 * @since 1.0.0
	 * @return DashboardPage
	 */
	public function dashboard_page(): DashboardPage {
		return $this->get_service(
			DashboardPage::class,
			fn() => new DashboardPage(
				$this->link_repository(),
				$this->link_stats(),
				$this->scan_repository(),
				$this->scan_orchestrator()
			)
		);
	}

	/**
	 * Get the results page.
	 *
	 * @since 1.0.0
	 * @return ResultsPage
	 */
	public function results_page(): ResultsPage {
		return $this->get_service(
			ResultsPage::class,
			fn() => new ResultsPage( $this->link_repository(), $this->url_repository(), $this->link_stats() )
		);
	}

	/**
	 * Get the admin controller.
	 *
	 * @since 1.0.0
	 * @return AdminController
	 */
	public function admin_controller(): AdminController {
		return $this->get_service(
			AdminController::class,
			fn() => new AdminController(
				$this->dashboard_page(),
				$this->results_page(),
				$this->ajax_handler()
			)
		);
	}

	/**
	 * Get the AJAX handler.
	 *
	 * @since 1.0.0
	 * @return AjaxHandler
	 */
	public function ajax_handler(): AjaxHandler {
		return $this->get_service(
			AjaxHandler::class,
			fn() => new AjaxHandler(
				$this->scan_orchestrator(),
				$this->batch_processor(),
				$this->url_repository(),
				$this->link_repository(),
				$this->link_stats()
			)
		);
	}

	/**
	 * Override a service instance in the container.
	 *
	 * Intended for use in tests to inject mocks or stubs.
	 *
	 * @since 1.0.8
	 * @param string $key      Service identifier (typically a class name).
	 * @param object $instance The service instance to store.
	 * @return void
	 */
	public function set_service( string $key, object $instance ): void {
		if ( $this->booted && ! defined( 'YOKO_LC_TESTING' ) ) {
			_doing_it_wrong( __METHOD__, 'Services cannot be replaced after the plugin has booted.', '1.1.0' );
			return;
		}

		$this->services[ $key ] = $instance;
	}

	/**
	 * Get or create a service instance.
	 *
	 * @since 1.0.0
	 * @template T
	 * @param class-string<T> $key     Service identifier (typically class name).
	 * @param callable():T    $factory Factory function to create the service.
	 * @return T
	 */
	private function get_service( string $key, callable $factory ): object { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = $factory();
		}

		return $this->services[ $key ];
	}
}
