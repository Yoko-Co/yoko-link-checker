<?php
/**
 * Scan Repository.
 *
 * Handles CRUD operations for the yoko_lc_scans table.
 * Manages scan run state and resumability.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Repository;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Model\Scan;

/**
 * Scan repository for database operations.
 *
 * @since 1.0.0
 */
final class ScanRepository {

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table = $wpdb->prefix . 'yoko_lc_scans';
	}

	/**
	 * Find scan by ID.
	 *
	 * @since 1.0.0
	 * @param int $id Scan ID.
	 * @return Scan|null
	 */
	public function find( int $id ): ?Scan {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		return $row ? Scan::from_row( $row ) : null;
	}

	/**
	 * Get any currently running scan.
	 *
	 * @since 1.0.0
	 * @return Scan|null
	 */
	public function get_running(): ?Scan {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Scan::STATUS_RUNNING
			)
		);

		return $row ? Scan::from_row( $row ) : null;
	}

	/**
	 * Get the last completed scan.
	 *
	 * @since 1.0.0
	 * @return Scan|null
	 */
	public function get_last_completed(): ?Scan {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = %s ORDER BY completed_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Scan::STATUS_COMPLETED
			)
		);

		return $row ? Scan::from_row( $row ) : null;
	}

	/**
	 * Count all scans.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public function count_all(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Update scan phase.
	 *
	 * @since 1.0.0
	 * @param int    $scan_id Scan ID.
	 * @param string $phase   New phase.
	 * @return bool Whether update succeeded.
	 */
	public function update_phase( int $scan_id, string $phase ): bool {
		$scan = $this->find( $scan_id );
		if ( ! $scan ) {
			return false;
		}
		$scan->current_phase = $phase;
		return $this->update( $scan );
	}

	/**
	 * Create a new scan.
	 *
	 * @since 1.0.0
	 * @param string               $scan_type Scan type (full/incremental/recheck).
	 * @param array<string, mixed> $options   Scan options.
	 * @return Scan|null Scan entity, or null on failure.
	 */
	public function create( string $scan_type = Scan::TYPE_FULL, array $options = array() ): ?Scan {
		$scan            = new Scan();
		$scan->scan_type = $scan_type;
		$scan->status    = Scan::STATUS_PENDING;
		$scan->options   = $options;

		return $this->insert( $scan );
	}

	/**
	 * Insert a new scan.
	 *
	 * @since 1.0.0
	 * @param Scan $scan Scan entity.
	 * @return Scan|null Scan with ID populated, or null on failure.
	 */
	public function insert( Scan $scan ): ?Scan {
		global $wpdb;

		$data = $scan->to_row();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table,
			$data,
			$this->get_format( $data )
		);

		if ( false === $result ) {
			return null;
		}

		$scan->id = (int) $wpdb->insert_id;

		return $scan;
	}

	/**
	 * Update an existing scan.
	 *
	 * @since 1.0.0
	 * @param Scan $scan Scan entity.
	 * @return bool Whether update succeeded.
	 */
	public function update( Scan $scan ): bool {
		global $wpdb;

		if ( null === $scan->id ) {
			return false;
		}

		$data = $scan->to_row();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $scan->id ),
			$this->get_format( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Complete a scan.
	 *
	 * @since 1.0.0
	 * @param Scan $scan Scan to complete.
	 * @return bool
	 */
	public function complete( Scan $scan ): bool {
		$scan->status       = Scan::STATUS_COMPLETED;
		$scan->completed_at = current_time( 'mysql' );

		// The scan is what changed the numbers; drop the cached counts.
		LinkStats::flush();

		return $this->update( $scan );
	}

	/**
	 * Fail a scan.
	 *
	 * @since 1.0.0
	 * @param Scan   $scan    Scan that failed.
	 * @param string $message Error message.
	 * @return bool
	 */
	public function fail( Scan $scan, string $message ): bool {
		$scan->status        = Scan::STATUS_FAILED;
		$scan->error_message = $message;
		$scan->completed_at  = current_time( 'mysql' );

		return $this->update( $scan );
	}

	/**
	 * Pause a scan.
	 *
	 * @since 1.0.0
	 * @param Scan $scan Scan to pause.
	 * @return bool
	 */
	public function pause( Scan $scan ): bool {
		$scan->status = Scan::STATUS_PAUSED;

		return $this->update( $scan );
	}

	/**
	 * Resume a paused scan.
	 *
	 * @since 1.0.0
	 * @param Scan $scan Scan to resume.
	 * @return bool
	 */
	public function resume( Scan $scan ): bool {
		$scan->status = Scan::STATUS_RUNNING;

		return $this->update( $scan );
	}

	/**
	 * Cancel a scan by ID.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID to cancel.
	 * @return bool
	 */
	public function cancel( int $scan_id ): bool {
		$scan = $this->find( $scan_id );
		if ( ! $scan ) {
			return false;
		}

		$scan->status       = Scan::STATUS_CANCELLED;
		$scan->completed_at = current_time( 'mysql' );

		return $this->update( $scan );
	}

	/**
	 * Update scan progress.
	 *
	 * @since 1.0.0
	 * @param Scan   $scan           Scan to update.
	 * @param string $phase          Current phase.
	 * @param int    $processed      Items processed.
	 * @param int    $last_cursor_id Last cursor ID.
	 * @return bool
	 */
	public function update_progress( Scan $scan, string $phase, int $processed, int $last_cursor_id ): bool {
		$scan->current_phase = $phase;

		if ( Scan::PHASE_DISCOVERY === $phase ) {
			$scan->processed_posts = $processed;
			$scan->last_post_id    = $last_cursor_id;
		} else {
			$scan->checked_urls = $processed;
			$scan->last_url_id  = $last_cursor_id;
		}

		return $this->update( $scan );
	}

	/**
	 * Get sprintf format array for data.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Data array.
	 * @return array<string>
	 */
	private function get_format( array $data ): array {
		$formats = array();

		foreach ( $data as $key => $value ) {
			if ( is_int( $value ) || in_array( $key, array( 'total_posts', 'processed_posts', 'total_urls', 'checked_urls', 'last_post_id', 'last_url_id' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
