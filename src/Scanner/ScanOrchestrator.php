<?php
/**
 * Scan Orchestrator class.
 *
 * High-level coordinator for scan operations.
 * Manages scan lifecycle, phase transitions, and scheduling.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Scanner;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Repository\ScanRepository;
use YokoLinkChecker\Repository\UrlRepository;
use YokoLinkChecker\Model\Scan;
use YokoLinkChecker\Util\Logger;

/**
 * Scan orchestrator class.
 *
 * @since 1.0.0
 */
class ScanOrchestrator {

	/**
	 * Cron hook name for scheduled scans.
	 */
	public const CRON_HOOK = 'yoko_lc_process_scan_batch';

	/**
	 * Batch processor instance.
	 *
	 * @var BatchProcessor
	 */
	private BatchProcessor $batch_processor;

	/**
	 * Scan repository instance.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scan_repository;

	/**
	 * URL repository instance.
	 *
	 * @var UrlRepository
	 */
	private UrlRepository $url_repository;

	/**
	 * Content discovery instance.
	 *
	 * @var ContentDiscovery
	 */
	private ContentDiscovery $content_discovery;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param BatchProcessor   $batch_processor   Batch processor instance.
	 * @param ScanRepository   $scan_repository   Scan repository instance.
	 * @param UrlRepository    $url_repository    URL repository instance.
	 * @param ContentDiscovery $content_discovery Content discovery instance.
	 */
	public function __construct(
		BatchProcessor $batch_processor,
		ScanRepository $scan_repository,
		UrlRepository $url_repository,
		ContentDiscovery $content_discovery
	) {
		$this->batch_processor   = $batch_processor;
		$this->scan_repository   = $scan_repository;
		$this->url_repository    = $url_repository;
		$this->content_discovery = $content_discovery;
	}

	/**
	 * Start a new scan.
	 *
	 * @since 1.0.0
	 * @param string $type Scan type: 'full' or 'incremental'.
	 * @return int|false Scan ID or false on failure.
	 */
	public function start_scan( string $type = 'full' ) {
		Logger::debug( 'start_scan() called', array( 'type' => $type ) );

		// Check if a scan is already running.
		$running = $this->scan_repository->get_running();
		if ( $running ) {
			// Check for stale scan (no progress in 30 minutes).
			$stale_threshold = strtotime( '-30 minutes' );
			$last_activity   = $this->get_scan_last_activity( $running->id );
			$last_update     = $last_activity ? $last_activity : strtotime( $running->started_at );

			if ( $last_update && $last_update < $stale_threshold ) {
				Logger::debug( 'start_scan - Stale scan detected, failing it', array( 'scan_id' => $running->id ) );
				$this->scan_repository->fail(
					$running,
					__( 'Scan timed out — no progress detected for 30 minutes.', 'yoko-link-checker' )
				);

				// Clean up cursor and activity options for the failed scan.
				delete_option( "yoko_lc_scan_{$running->id}_cursor_discovery" );
				delete_option( "yoko_lc_scan_{$running->id}_cursor_checking" );
				delete_option( "yoko_lc_scan_{$running->id}_last_activity" );

				// Clear any scheduled batches for the stale scan.
				wp_clear_scheduled_hook( self::CRON_HOOK, array( $running->id ) );
			} else {
				Logger::debug( 'start_scan - A scan is already running', array( 'scan_id' => $running->id ) );
				return false;
			}
		}

		// Count total posts to scan.
		$total_posts = $this->content_discovery->count_posts();
		Logger::debug( 'start_scan - Total posts to scan', array( 'total_posts' => $total_posts ) );

		// Create scan record with proper arguments.
		$scan = $this->scan_repository->create( $type );
		Logger::debug( 'start_scan - Created scan', array( 'scan_id' => $scan ? $scan->id : null ) );

		if ( ! $scan || ! $scan->id ) {
			Logger::debug( 'start_scan - Failed to create scan record' );
			return false;
		}

		// Set scan to running status with phase and totals.
		$scan->status        = Scan::STATUS_RUNNING;
		$scan->current_phase = Scan::PHASE_DISCOVERY;
		$scan->total_posts   = $total_posts;
		$scan->started_at    = current_time( 'mysql' );

		$update_result = $this->scan_repository->update( $scan );
		Logger::debug( 'start_scan - Updated scan', array( 'success' => (bool) $update_result ) );

		$scan_id = $scan->id;

		/**
		 * Fires when a scan starts.
		 *
		 * @since 1.0.0
		 * @param int    $scan_id Scan ID.
		 * @param string $type    Scan type.
		 */
		do_action( 'yoko_lc_scan_started', $scan_id, $type );

		// Schedule first batch.
		$this->schedule_next_batch( $scan_id );

		return $scan_id;
	}

	/**
	 * Process the next batch of a scan.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	public function process_batch( int $scan_id ): void {
		$lock_key = 'yoko_lc_batch_lock_' . $scan_id;

		// Prevent concurrent batch processing using atomic lock.
		$acquired = wp_cache_add( $lock_key, true, 'yoko_lc', 120 );
		if ( ! $acquired ) {
			// Fallback for environments without persistent object cache.
			if ( get_transient( $lock_key ) ) {
				Logger::debug( 'process_batch - Concurrent execution blocked', array( 'scan_id' => $scan_id ) );
				return;
			}
			set_transient( $lock_key, true, 120 );
		}

		try {
			$scan = $this->scan_repository->find( $scan_id );
			Logger::debug(
				'process_batch',
				array(
					'scan_id' => $scan_id,
					'found'   => (bool) $scan,
				)
			);

			if ( ! $scan ) {
				Logger::debug( 'process_batch - No scan found, returning' );
				return;
			}

			Logger::debug(
				'process_batch - Scan state',
				array(
					'status' => $scan->status,
					'phase'  => $scan->current_phase,
				)
			);

			if ( Scan::STATUS_RUNNING !== $scan->status ) {
				Logger::debug( 'process_batch - Scan not running, returning' );
				return;
			}

			// Record activity for stale scan detection.
			$this->set_scan_last_activity( $scan_id );

			$start_time = microtime( true );
			$state      = null;

			if ( Scan::PHASE_DISCOVERY === $scan->current_phase ) {
				Logger::debug( 'Calling process_discovery_phase' );
				$state = $this->process_discovery_phase( $scan );
				Logger::debug(
					'Discovery phase result',
					$state ? array(
						'total'     => $state->total,
						'processed' => $state->processed,
						'complete'  => $state->complete,
					) : array()
				);
			} elseif ( Scan::PHASE_CHECKING === $scan->current_phase ) {
				Logger::debug( 'Calling process_checking_phase' );
				$state = $this->process_checking_phase( $scan );
			}

			if ( ! $state ) {
				Logger::debug( 'No state returned from phase processing' );
				return;
			}

			$batch_time = microtime( true ) - $start_time;

			// Log progress.
			$this->log_progress( $scan_id, $state, $batch_time );

			// Handle phase transitions and scheduling.
			$this->handle_phase_completion( $scan_id, $state );
		} finally {
			wp_cache_delete( $lock_key, 'yoko_lc' );
			delete_transient( $lock_key );
		}
	}

	/**
	 * Process discovery phase batch.
	 *
	 * @since 1.0.0
	 * @param Scan $scan Scan model.
	 * @return ScanState|null
	 */
	private function process_discovery_phase( Scan $scan ): ?ScanState {
		/**
		 * Filters the discovery batch size.
		 *
		 * @since 1.0.0
		 * @param int $batch_size Default batch size.
		 */
		$batch_size = apply_filters( 'yoko_lc_discovery_batch_size', 50 );
		$batch_size = max( 1, min( (int) $batch_size, 500 ) );

		// Get cursor from scan metadata.
		$cursor = $this->get_scan_cursor( $scan->id, 'discovery' );

		$state = $this->batch_processor->process_discovery_batch(
			$scan->id,
			$cursor,
			$batch_size
		);

		// Save cursor.
		$this->set_scan_cursor( $scan->id, 'discovery', $state->cursor );

		return $state;
	}

	/**
	 * Process checking phase batch.
	 *
	 * @since 1.0.0
	 * @param Scan $scan Scan model.
	 * @return ScanState|null
	 */
	private function process_checking_phase( Scan $scan ): ?ScanState {
		/**
		 * Filters the checking batch size.
		 * Smaller batches = more reliable but slower overall.
		 * Larger batches = faster but may timeout.
		 *
		 * @since 1.0.0
		 * @param int $batch_size Default batch size.
		 */
		$batch_size = apply_filters( 'yoko_lc_checking_batch_size', 5 );
		$batch_size = max( 1, min( (int) $batch_size, 500 ) );

		// Get cursor from scan metadata.
		$cursor = $this->get_scan_cursor( $scan->id, 'checking' );

		$state = $this->batch_processor->process_checking_batch(
			$scan->id,
			$cursor,
			$batch_size
		);

		// Save cursor.
		$this->set_scan_cursor( $scan->id, 'checking', $state->cursor );

		return $state;
	}

	/**
	 * Handle phase completion and transitions.
	 *
	 * @since 1.0.0
	 * @param int       $scan_id Scan ID.
	 * @param ScanState $state   Current state.
	 * @return void
	 */
	private function handle_phase_completion( int $scan_id, ScanState $state ): void {
		if ( ! $state->complete ) {
			// More work to do, schedule next batch.
			$this->schedule_next_batch( $scan_id );
			return;
		}

		$scan = $this->scan_repository->find( $scan_id );
		if ( ! $scan ) {
			return;
		}

		if ( 'discovery' === $state->phase ) {
			// Transition to checking phase.
			$this->transition_to_checking( $scan_id );
		} elseif ( 'checking' === $state->phase ) {
			// Scan complete.
			$this->complete_scan( $scan_id );
		}
	}

	/**
	 * Transition scan to checking phase.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private function transition_to_checking( int $scan_id ): void {
		// Count only the URLs the checker will actually fetch: pending, and not
		// ignored. Counting ignored ones too left them permanently in the
		// denominator and never in the numerator, so the bar stalled short of 100%.
		$total_urls = $this->url_repository->count_pending_checkable();

		$this->scan_repository->update_phase( $scan_id, Scan::PHASE_CHECKING );

		// Update total URLs in scan record.
		$scan = $this->scan_repository->find( $scan_id );
		if ( $scan ) {
			$scan->total_urls = $total_urls;
			$this->scan_repository->update( $scan );
		}

		/**
		 * Fires when scan transitions to checking phase.
		 *
		 * @since 1.0.0
		 * @param int $scan_id   Scan ID.
		 * @param int $total_urls Total URLs to check.
		 */
		do_action( 'yoko_lc_scan_phase_checking', $scan_id, $total_urls );

		// Schedule next batch.
		$this->schedule_next_batch( $scan_id );
	}

	/**
	 * Complete a scan.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private function complete_scan( int $scan_id ): void {
		$scan = $this->scan_repository->find( $scan_id );
		if ( ! $scan ) {
			return;
		}
		$this->scan_repository->complete( $scan );

		// Clean up cursors and activity tracking.
		delete_option( "yoko_lc_scan_{$scan_id}_cursor_discovery" );
		delete_option( "yoko_lc_scan_{$scan_id}_cursor_checking" );
		delete_option( "yoko_lc_scan_{$scan_id}_last_activity" );

		/**
		 * Fires when a scan completes.
		 *
		 * @since 1.0.0
		 * @param int $scan_id Scan ID.
		 */
		do_action( 'yoko_lc_scan_completed', $scan_id );

		// Schedule next automatic scan if enabled.
		$this->maybe_schedule_next_scan();
	}

	/**
	 * Pause a running scan.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID.
	 * @return bool Whether pause was successful.
	 */
	public function pause_scan( int $scan_id ): bool {
		$scan = $this->scan_repository->find( $scan_id );

		if ( ! $scan || Scan::STATUS_RUNNING !== $scan->status ) {
			return false;
		}

		$this->scan_repository->pause( $scan );

		// Clear scheduled batch.
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $scan_id ) );

		/**
		 * Fires when a scan is paused.
		 *
		 * @since 1.0.0
		 * @param int $scan_id Scan ID.
		 */
		do_action( 'yoko_lc_scan_paused', $scan_id );

		return true;
	}

	/**
	 * Resume a paused scan.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID.
	 * @return bool Whether resume was successful.
	 */
	public function resume_scan( int $scan_id ): bool {
		$scan = $this->scan_repository->find( $scan_id );

		if ( ! $scan || Scan::STATUS_PAUSED !== $scan->status ) {
			return false;
		}

		$this->scan_repository->resume( $scan );
		$this->schedule_next_batch( $scan_id );

		/**
		 * Fires when a scan is resumed.
		 *
		 * @since 1.0.0
		 * @param int $scan_id Scan ID.
		 */
		do_action( 'yoko_lc_scan_resumed', $scan_id );

		return true;
	}

	/**
	 * Cancel a scan.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID.
	 * @return bool Whether cancel was successful.
	 */
	public function cancel_scan( int $scan_id ): bool {
		$scan = $this->scan_repository->find( $scan_id );

		if ( ! $scan ) {
			return false;
		}

		if ( Scan::STATUS_COMPLETED === $scan->status ) {
			return false;
		}

		$this->scan_repository->cancel( $scan_id );

		// Clear scheduled batch.
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $scan_id ) );

		// Clean up cursors and activity tracking.
		delete_option( "yoko_lc_scan_{$scan_id}_cursor_discovery" );
		delete_option( "yoko_lc_scan_{$scan_id}_cursor_checking" );
		delete_option( "yoko_lc_scan_{$scan_id}_last_activity" );

		/**
		 * Fires when a scan is cancelled.
		 *
		 * @since 1.0.0
		 * @param int $scan_id Scan ID.
		 */
		do_action( 'yoko_lc_scan_cancelled', $scan_id );

		return true;
	}

	/**
	 * Schedule the next batch for processing.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private function schedule_next_batch( int $scan_id ): void {
		/**
		 * Filters the delay between batches in seconds.
		 *
		 * @since 1.0.0
		 * @param int $delay Default delay.
		 */
		$delay = apply_filters( 'yoko_lc_batch_delay', 1 );
		$delay = max( 0, min( (int) $delay, 300 ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $scan_id ) ) ) {
			wp_schedule_single_event(
				time() + $delay,
				self::CRON_HOOK,
				array( $scan_id )
			);
		}
	}

	/**
	 * Maybe schedule the next automatic scan.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function maybe_schedule_next_scan(): void {
		$auto_scan = get_option( 'yoko_lc_auto_scan_enabled', false );

		if ( ! $auto_scan ) {
			return;
		}

		$frequency = get_option( 'yoko_lc_auto_scan_frequency', 'weekly' );
		$hook      = 'yoko_lc_auto_scan';

		// Clear existing.
		wp_clear_scheduled_hook( $hook );

		// Schedule next.
		wp_schedule_event( time(), $frequency, $hook );
	}

	/**
	 * Get seconds for a frequency.
	 *
	 * @since 1.0.0
	 * @param string $frequency Frequency name.
	 * @return int Seconds.
	 */
	private function get_frequency_seconds( string $frequency ): int {
		$schedules = array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
			'weekly'     => WEEK_IN_SECONDS,
		);

		return $schedules[ $frequency ] ?? WEEK_IN_SECONDS;
	}

	/**
	 * Get cursor for a scan phase.
	 *
	 * @since 1.0.0
	 * @param int    $scan_id Scan ID.
	 * @param string $phase   Phase name.
	 * @return int Cursor value.
	 */
	private function get_scan_cursor( int $scan_id, string $phase ): int {
		return (int) get_option( "yoko_lc_scan_{$scan_id}_cursor_{$phase}", 0 );
	}

	/**
	 * Set cursor for a scan phase.
	 *
	 * @since 1.0.0
	 * @param int    $scan_id Scan ID.
	 * @param string $phase   Phase name.
	 * @param int    $cursor  Cursor value.
	 * @return void
	 */
	private function set_scan_cursor( int $scan_id, string $phase, int $cursor ): void {
		update_option( "yoko_lc_scan_{$scan_id}_cursor_{$phase}", $cursor, false );
	}

	/**
	 * Get last activity timestamp for a scan.
	 *
	 * Used by stale scan detection to determine if a running scan
	 * has made progress recently.
	 *
	 * @since 1.0.8
	 * @param int $scan_id Scan ID.
	 * @return int|false Unix timestamp or false if not set.
	 */
	private function get_scan_last_activity( int $scan_id ) {
		$value = get_option( "yoko_lc_scan_{$scan_id}_last_activity", false );
		return false !== $value ? (int) $value : false;
	}

	/**
	 * Record last activity timestamp for a scan.
	 *
	 * Called during batch processing to track scan progress
	 * for stale scan detection.
	 *
	 * @since 1.0.8
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private function set_scan_last_activity( int $scan_id ): void {
		update_option( "yoko_lc_scan_{$scan_id}_last_activity", time(), false );
	}

	/**
	 * Log progress.
	 *
	 * @since 1.0.0
	 * @param int       $scan_id    Scan ID.
	 * @param ScanState $state      Current state.
	 * @param float     $batch_time Batch processing time.
	 * @return void
	 */
	private function log_progress( int $scan_id, ScanState $state, float $batch_time ): void {
		Logger::info(
			sprintf( 'Scan %d: %s phase - %d/%d (%.1f%%)', $scan_id, $state->phase, $state->processed, $state->total, $state->get_progress() ),
			array(
				'batch_count' => $state->last_batch_count,
				'batch_time'  => round( $batch_time, 2 ),
			)
		);
	}

	/**
	 * Get current scan status summary.
	 *
	 * @since 1.0.0
	 * @return array|null Status array or null if no scan.
	 */
	public function get_status(): ?array {
		$scan = $this->scan_repository->get_running();

		if ( ! $scan ) {
			$scan = $this->scan_repository->get_last_completed();
		}

		if ( ! $scan ) {
			return null;
		}

		$progress = 0.0;
		$total    = 0;
		$done     = 0;

		// Completed is checked first on purpose. complete() leaves current_phase
		// at 'checking', so testing the phase first made this branch unreachable
		// and a finished scan reported checking-phase math forever.
		if ( Scan::STATUS_COMPLETED === $scan->status ) {
			$progress = 100.0;
		} elseif ( Scan::PHASE_DISCOVERY === $scan->current_phase ) {
			$total    = $scan->total_posts;
			$done     = $scan->processed_posts;
			$progress = $total > 0 ? min( ( $done / $total ) * 50.0, 50.0 ) : 0.0;
		} elseif ( Scan::PHASE_CHECKING === $scan->current_phase ) {
			$done     = $scan->checked_urls;
			$total    = $scan->total_urls;
			$progress = 50.0 + ( $total > 0 ? min( ( $done / $total ) * 50.0, 50.0 ) : 0.0 );
		}

		$progress = max( 0.0, min( 100.0, $progress ) );

		return array(
			'scan_id'      => $scan->id,
			'status'       => $scan->status,
			'phase'        => $scan->current_phase,
			'progress'     => round( $progress, 1 ),
			'total_posts'  => $scan->total_posts,
			'posts_done'   => $scan->processed_posts,
			'total_urls'   => $scan->total_urls,
			'urls_checked' => $scan->checked_urls,
			'started_at'   => $scan->started_at,
			'completed_at' => $scan->completed_at,
		);
	}
}
