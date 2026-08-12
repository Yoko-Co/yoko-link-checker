<?php
/**
 * AJAX Handler class.
 *
 * Handles all AJAX requests for the plugin.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Admin;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Scanner\ScanOrchestrator;
use YokoLinkChecker\Scanner\BatchProcessor;
use YokoLinkChecker\Model\Url;
use YokoLinkChecker\Repository\LinkQuery;
use YokoLinkChecker\Repository\LinkRepository;
use YokoLinkChecker\Repository\LinkStats;
use YokoLinkChecker\Repository\UrlRepository;
use YokoLinkChecker\Util\Logger;

/**
 * AJAX handler class.
 *
 * @since 1.0.0
 */
class AjaxHandler {

	/**
	 * Scan orchestrator instance.
	 *
	 * @var ScanOrchestrator
	 */
	private ScanOrchestrator $scan_orchestrator;

	/**
	 * Batch processor instance.
	 *
	 * @var BatchProcessor
	 */
	private BatchProcessor $batch_processor;

	/**
	 * URL repository instance.
	 *
	 * @var UrlRepository
	 */
	private UrlRepository $url_repository;

	/**
	 * Link repository instance.
	 *
	 * @var LinkRepository
	 */
	private LinkRepository $link_repository;

	/**
	 * Link statistics service.
	 *
	 * @var LinkStats
	 */
	private LinkStats $link_stats;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Takes the stats service.
	 * @param ScanOrchestrator $scan_orchestrator Scan orchestrator.
	 * @param BatchProcessor   $batch_processor   Batch processor.
	 * @param UrlRepository    $url_repository    URL repository.
	 * @param LinkRepository   $link_repository   Link repository.
	 * @param LinkStats        $link_stats        Link statistics service.
	 */
	public function __construct(
		ScanOrchestrator $scan_orchestrator,
		BatchProcessor $batch_processor,
		UrlRepository $url_repository,
		LinkRepository $link_repository,
		LinkStats $link_stats
	) {
		$this->scan_orchestrator = $scan_orchestrator;
		$this->batch_processor   = $batch_processor;
		$this->url_repository    = $url_repository;
		$this->link_repository   = $link_repository;
		$this->link_stats        = $link_stats;
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register(): void {
		// Scan actions.
		add_action( 'wp_ajax_yoko_lc_start_scan', array( $this, 'start_scan' ) );
		add_action( 'wp_ajax_yoko_lc_pause_scan', array( $this, 'pause_scan' ) );
		add_action( 'wp_ajax_yoko_lc_resume_scan', array( $this, 'resume_scan' ) );
		add_action( 'wp_ajax_yoko_lc_cancel_scan', array( $this, 'cancel_scan' ) );
		add_action( 'wp_ajax_yoko_lc_get_scan_status', array( $this, 'get_scan_status' ) );

		// Link actions.
		add_action( 'wp_ajax_yoko_lc_recheck_url', array( $this, 'recheck_url' ) );
		add_action( 'wp_ajax_yoko_lc_ignore_link', array( $this, 'ignore_link' ) );
		add_action( 'wp_ajax_yoko_lc_unignore_link', array( $this, 'unignore_link' ) );

		// Stats — internal API endpoint. Not currently called by the admin JS
		// (dashboard stats are rendered server-side), but retained for future
		// programmatic / REST API access (see #055).
		add_action( 'wp_ajax_yoko_lc_get_stats', array( $this, 'get_stats' ) );

		// Data management.
		add_action( 'wp_ajax_yoko_lc_clear_data', array( $this, 'clear_data' ) );
	}

	/**
	 * Start a new scan.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function start_scan(): void {
		$this->verify_request( 'yoko_lc_manage_scans' );

		try {
			Logger::debug( 'start_scan AJAX called' );
			$scan_id = $this->scan_orchestrator->start_scan( 'full' );
			Logger::debug( 'start_scan returned', array( 'scan_id' => $scan_id ) );

			if ( ! $scan_id ) {
				wp_send_json_error(
					array(
						'message' => __( 'A scan is already running.', 'yoko-link-checker' ),
					)
				);
			}

			wp_send_json_success(
				array(
					'scan_id' => $scan_id,
					'message' => __( 'Scan started.', 'yoko-link-checker' ),
				)
			);
		} catch ( \Throwable $e ) {
			Logger::exception( $e );
			wp_send_json_error( array( 'message' => __( 'An unexpected error occurred.', 'yoko-link-checker' ) ) );
		}
	}

	/**
	 * Pause a scan.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function pause_scan(): void {
		$this->verify_request( 'yoko_lc_manage_scans' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;

		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan ID.', 'yoko-link-checker' ) ) );
		}

		$result = $this->scan_orchestrator->pause_scan( $scan_id );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not pause scan.', 'yoko-link-checker' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Scan paused.', 'yoko-link-checker' ) ) );
	}

	/**
	 * Resume a scan.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function resume_scan(): void {
		$this->verify_request( 'yoko_lc_manage_scans' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;

		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan ID.', 'yoko-link-checker' ) ) );
		}

		$result = $this->scan_orchestrator->resume_scan( $scan_id );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not resume scan.', 'yoko-link-checker' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Scan resumed.', 'yoko-link-checker' ) ) );
	}

	/**
	 * Cancel a scan.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function cancel_scan(): void {
		$this->verify_request( 'yoko_lc_manage_scans' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;

		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan ID.', 'yoko-link-checker' ) ) );
		}

		$result = $this->scan_orchestrator->cancel_scan( $scan_id );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not cancel scan.', 'yoko-link-checker' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Scan cancelled.', 'yoko-link-checker' ) ) );
	}

	/**
	 * Get scan status.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function get_scan_status(): void {
		$this->verify_request( 'yoko_lc_view_results' );

		try {
			$status = $this->scan_orchestrator->get_status();

			Logger::debug( 'get_scan_status called', array( 'status' => $status ) );

			// Ensure WP-Cron is scheduled to process the next batch if scan is running.
			if ( $status && 'running' === $status['status'] ) {
				if ( ! wp_next_scheduled( 'yoko_lc_process_scan_batch', array( $status['scan_id'] ) ) ) {
					wp_schedule_single_event( time(), 'yoko_lc_process_scan_batch', array( $status['scan_id'] ) );
				}
				spawn_cron();
			}
		} catch ( \Throwable $e ) {
			Logger::exception( $e );
			wp_send_json_error( array( 'message' => __( 'An unexpected error occurred.', 'yoko-link-checker' ) ) );
			return;
		}

		if ( ! $status ) {
			wp_send_json_success(
				array(
					'running'  => false,
					'status'   => null,
					'progress' => 0,
				)
			);
		}

		wp_send_json_success(
			array(
				'running'  => 'running' === $status['status'],
				'status'   => $status,
				'progress' => $status['progress'],
			)
		);
	}

	/**
	 * Recheck a URL.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function recheck_url(): void {
		$this->verify_request( 'yoko_lc_manage_scans' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$url_id = isset( $_POST['url_id'] ) ? absint( $_POST['url_id'] ) : 0;

		if ( ! $url_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid URL ID.', 'yoko-link-checker' ) ) );
		}

		$result = $this->batch_processor->recheck_url( $url_id );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'URL not found.', 'yoko-link-checker' ) ) );
		}

		// Get updated URL data.
		$url = $this->url_repository->find( $url_id );

		wp_send_json_success(
			array(
				'message' => __( 'URL rechecked.', 'yoko-link-checker' ),
				'url'     => $url ? array(
					'status'    => $url->status,
					'http_code' => $url->http_code,
				) : null,
			)
		);
	}

	/**
	 * Ignore a link.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ignore_link(): void {
		$this->verify_request( 'yoko_lc_manage_scans' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;

		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid link ID.', 'yoko-link-checker' ) ) );
		}

		$link = $this->link_repository->find( $link_id );

		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'yoko-link-checker' ) ) );
		}

		$result = $this->url_repository->mark_ignored( $link->url_id );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not ignore link.', 'yoko-link-checker' ) ) );
		}

		/**
		 * Fires when a link is ignored via AJAX.
		 *
		 * @since 1.0.0
		 * @param int $link_id Link ID.
		 * @param int $url_id  URL ID.
		 */
		do_action( 'yoko_lc_link_ignored', $link_id, $link->url_id );

		wp_send_json_success( array( 'message' => __( 'Link ignored.', 'yoko-link-checker' ) ) );
	}

	/**
	 * Un-ignore a link.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function unignore_link(): void {
		$this->verify_request( 'yoko_lc_manage_scans' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;

		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid link ID.', 'yoko-link-checker' ) ) );
		}

		$link = $this->link_repository->find( $link_id );

		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'yoko-link-checker' ) ) );
		}

		$result = $this->url_repository->unmark_ignored( $link->url_id );

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not un-ignore link.', 'yoko-link-checker' ) ) );
		}

		/**
		 * Fires when a link is un-ignored via AJAX.
		 *
		 * @since 1.0.0
		 * @param int $link_id Link ID.
		 * @param int $url_id  URL ID.
		 */
		do_action( 'yoko_lc_link_unignored', $link_id, $link->url_id );

		wp_send_json_success( array( 'message' => __( 'Link un-ignored.', 'yoko-link-checker' ) ) );
	}

	/**
	 * Get stats.
	 *
	 * Reads from LinkStats like every other surface, and reports both units --
	 * this endpoint used to be a fifth independent counter with its own shape.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Backed by LinkStats; response now carries both units.
	 * @return void
	 */
	public function get_stats(): void {
		$this->verify_request( 'yoko_lc_view_results' );

		$counts = $this->link_stats->status_counts( new LinkQuery() );
		$stats  = array(
			'total_urls'  => $counts->total_urls(),
			'total_links' => $counts->total_links(),
			'by_status'   => array(),
		);

		foreach ( Url::STATUSES as $status ) {
			$stats['by_status'][ $status ] = array(
				'urls'  => $counts->urls( $status ),
				'links' => $counts->links( $status ),
			);
		}

		wp_send_json_success( $stats );
	}

	/**
	 * Verify AJAX request.
	 *
	 * @since 1.0.0
	 * @param string $capability Required capability.
	 * @return void
	 */
	private function verify_request( string $capability ): void {
		// Verify nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'yoko_lc_admin' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'yoko-link-checker' ),
				),
				403
			);
		}

		// Check capability with fallback to manage_options.
		$has_cap = current_user_can( $capability );
		if ( ! $has_cap ) {
			$has_cap = current_user_can( 'manage_options' );
		}

		if ( ! $has_cap ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'yoko-link-checker' ),
				),
				403
			);
		}
	}

	/**
	 * Clear all scan data.
	 *
	 * @since 1.0.3
	 * @return void
	 */
	public function clear_data(): void {
		$this->verify_request( 'yoko_lc_manage_scans' );

		global $wpdb;

		$links_table = $wpdb->prefix . 'yoko_lc_links';
		$urls_table  = $wpdb->prefix . 'yoko_lc_urls';
		$scans_table = $wpdb->prefix . 'yoko_lc_scans';

		// Truncate all tables. TRUNCATE is O(1) — it drops and re-creates the
		// table rather than deleting rows one-by-one. Note: TRUNCATE performs an
		// implicit commit so it cannot be wrapped in a transaction.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterized.
		$r1 = $wpdb->query( "TRUNCATE TABLE {$links_table}" );
		$r2 = $wpdb->query( "TRUNCATE TABLE {$urls_table}" );
		$r3 = $wpdb->query( "TRUNCATE TABLE {$scans_table}" );
		// phpcs:enable

		if ( false === $r1 || false === $r2 || false === $r3 ) {
			wp_send_json_error( array( 'message' => __( 'Failed to clear data.', 'yoko-link-checker' ) ) );
			return;
		}

		// Clear any scheduled cron events.
		wp_clear_scheduled_hook( 'yoko_lc_process_scan_batch' );

		wp_send_json_success(
			array(
				'message' => __( 'All scan data has been cleared.', 'yoko-link-checker' ),
			)
		);
	}
}
