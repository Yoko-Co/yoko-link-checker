<?php
/**
 * URL Repository.
 *
 * Handles CRUD operations for the yoko_lc_urls table.
 * Manages unique URLs with deduplication via hash.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Repository;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Model\Url;
use YokoLinkChecker\Util\UrlNormalizer;

/**
 * URL repository for database operations.
 *
 * @since 1.0.0
 */
final class UrlRepository {

	/**
	 * Predicate for "a URL this scan will fetch".
	 *
	 * Shared by get_pending() and count_pending_checkable() so the scan's
	 * numerator and denominator can never describe different populations. Takes
	 * one %s placeholder for the status.
	 */
	private const PENDING_CHECKABLE_WHERE = 'status = %s AND is_ignored = 0';

	/**
	 * URL normalizer instance.
	 *
	 * @var UrlNormalizer
	 */
	private UrlNormalizer $normalizer;

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
	 * @param UrlNormalizer $normalizer URL normalizer instance.
	 */
	public function __construct( UrlNormalizer $normalizer ) {
		global $wpdb;

		$this->normalizer = $normalizer;
		$this->table      = $wpdb->prefix . 'yoko_lc_urls';
	}

	/**
	 * Find URL by ID.
	 *
	 * @since 1.0.0
	 * @param int $id URL ID.
	 * @return Url|null
	 */
	public function find( int $id ): ?Url {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		return $row ? Url::from_row( $row ) : null;
	}

	/**
	 * Find URL by normalized URL hash.
	 *
	 * @since 1.0.0
	 * @param string $url_hash SHA-256 hash of normalized URL.
	 * @return Url|null
	 */
	public function find_by_hash( string $url_hash ): ?Url {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE url_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$url_hash
			)
		);

		return $row ? Url::from_row( $row ) : null;
	}

	/**
	 * Find multiple URLs by their normalized URL hashes in a single query.
	 *
	 * @since 1.0.11
	 * @param array<string> $hashes Array of SHA-256 hashes.
	 * @return array<string, Url> URL objects keyed by url_hash.
	 */
	public function find_by_hashes( array $hashes ): array {
		if ( empty( $hashes ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are generated safely above.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built dynamically.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE url_hash IN ({$placeholders})",
				...$hashes
			)
		);
		// phpcs:enable

		$result = array();
		foreach ( $rows as $row ) {
			$url                      = Url::from_row( $row );
			$result[ $url->url_hash ] = $url;
		}

		return $result;
	}

	/**
	 * Get the URL normalizer instance.
	 *
	 * Provides access for batch operations that need to normalize and hash
	 * URLs before performing bulk lookups.
	 *
	 * @since 1.0.11
	 * @return UrlNormalizer
	 */
	public function get_normalizer(): UrlNormalizer {
		return $this->normalizer;
	}

	/**
	 * Find or create URL from raw URL string.
	 *
	 * Handles normalization and internal detection automatically.
	 *
	 * @since 1.0.0
	 * @param string $raw_url The raw URL as found in content.
	 * @return Url|null The URL entity, or null if URL is invalid.
	 */
	public function find_or_create_from_raw( string $raw_url ): ?Url {
		$raw_url = trim( $raw_url );

		// Normalize the URL (the normalizer handles skippable schemes).
		$normalized = $this->normalizer->normalize( $raw_url );
		if ( empty( $normalized ) ) {
			return null;
		}

		// Determine if internal.
		$is_internal = $this->normalizer->is_internal( $normalized );

		return $this->find_or_create( $raw_url, $normalized, $is_internal );
	}

	/**
	 * Find or create URL by normalized URL.
	 *
	 * @since 1.0.0
	 * @param string $original_url   The original URL as found.
	 * @param string $normalized_url The normalized URL.
	 * @param bool   $is_internal    Whether URL is internal.
	 * @return Url|null The existing or newly created URL, or null on failure.
	 */
	public function find_or_create( string $original_url, string $normalized_url, bool $is_internal ): ?Url {
		$url_hash = $this->normalizer->hash( $normalized_url );

		// Try to find existing.
		$existing = $this->find_by_hash( $url_hash );

		if ( $existing ) {
			return $existing;
		}

		// Create new URL record.
		$url                 = new Url();
		$url->url_hash       = $url_hash;
		$url->url            = $original_url;
		$url->url_normalized = $normalized_url;
		$url->is_internal    = $is_internal;
		$url->status         = Url::STATUS_PENDING;
		$url->first_seen     = current_time( 'mysql' );

		$result = $this->insert( $url );

		if ( null === $result ) {
			// Insert failed, likely duplicate key from a race condition. Re-fetch.
			return $this->find_by_hash( $url_hash );
		}

		return $result;
	}

	/**
	 * Insert a new URL.
	 *
	 * @since 1.0.0
	 * @param Url $url URL entity.
	 * @return Url|null URL with ID populated, or null on failure.
	 */
	public function insert( Url $url ): ?Url {
		global $wpdb;

		$data = $url->to_row();

		// Ensure first_seen is set.
		if ( empty( $data['first_seen'] ) ) {
			$data['first_seen'] = current_time( 'mysql' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table,
			$data,
			$this->get_format( $data )
		);

		if ( false === $result ) {
			return null;
		}

		$url->id = (int) $wpdb->insert_id;

		return $url;
	}

	/**
	 * Update an existing URL.
	 *
	 * @since 1.0.0
	 * @param Url $url URL entity.
	 * @return bool Whether update succeeded.
	 */
	public function update( Url $url ): bool {
		global $wpdb;

		if ( null === $url->id ) {
			return false;
		}

		$data = $url->to_row();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $url->id ),
			$this->get_format( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get URLs needing checking.
	 *
	 * @since 1.0.0
	 * @param int $limit      Maximum URLs to return.
	 * @param int $after_id   Start after this ID (for cursor pagination).
	 * @param int $scan_id    Optional scan ID for phase tracking (reserved for future use).
	 * @return array<Url>
	 */
	public function get_pending( int $limit = 20, int $after_id = 0, int $scan_id = 0 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future use.
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- PENDING_CHECKABLE_WHERE is a literal constant containing only a %s placeholder.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The sniff cannot see the placeholder inside the constant.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE " . self::PENDING_CHECKABLE_WHERE . '
				AND id > %d
				ORDER BY id ASC
				LIMIT %d',
				Url::STATUS_PENDING,
				$after_id,
				$limit
			)
		);
		// phpcs:enable

		return array_map( fn( $row ) => Url::from_row( $row ), $rows );
	}

	/**
	 * Count the URLs a scan will actually fetch.
	 *
	 * Shares its WHERE clause with get_pending() so the scan's denominator and
	 * the rows it works through always describe the same population -- they
	 * previously differed by is_ignored, which stalled the progress bar.
	 *
	 * DEBUG: wp eval 'var_dump( yoko_lc()->url_repository()->count_pending_checkable() );'
	 *
	 * @since 1.2.0
	 * @return int
	 */
	public function count_pending_checkable(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from $wpdb->prefix.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- PENDING_CHECKABLE_WHERE is a literal constant containing only a %s placeholder.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The sniff cannot see the placeholder inside the constant.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE " . self::PENDING_CHECKABLE_WHERE,
				Url::STATUS_PENDING
			)
		);
		// phpcs:enable
	}

	/**
	 * Count URLs by status.
	 *
	 * @since 1.0.0
	 * @param string|null $status Optional status to filter by.
	 * @return int
	 */
	public function count( ?string $status = null ): int {
		global $wpdb;

		if ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get status counts across every URL row.
	 *
	 * Counts URL rows directly, with no join to links and no ignored filter, so
	 * this does NOT match what any screen displays -- LinkStats::status_counts()
	 * is the number users see. Kept for diagnostics: a difference between the two
	 * is exactly the population of orphaned or ignored URLs.
	 *
	 * DEBUG: compare against `wp yoko-lc counts` when investigating a discrepancy.
	 *
	 * @since 1.0.0
	 * @return array<string, int>
	 */
	public function get_status_counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$counts = array_fill_keys( Url::STATUSES, 0 );

		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Mark URL as ignored.
	 *
	 * @since 1.0.0
	 * @param int         $id     URL ID.
	 * @param string|null $reason Optional reason.
	 * @return bool
	 */
	public function mark_ignored( int $id, ?string $reason = null ): bool {
		global $wpdb;

		// Ignored URLs drop out of every count, so the cached figures are now wrong.
		LinkStats::flush();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			array(
				'is_ignored'    => 1,
				'ignore_reason' => $reason,
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Unmark URL as ignored.
	 *
	 * @since 1.0.0
	 * @param int $id URL ID.
	 * @return bool
	 */
	public function unmark_ignored( int $id ): bool {
		global $wpdb;

		LinkStats::flush();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			array(
				'is_ignored'    => 0,
				'ignore_reason' => null,
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
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
			if ( is_int( $value ) || in_array( $key, array( 'is_internal', 'is_ignored', 'check_count', 'redirect_count', 'response_time', 'http_code' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
