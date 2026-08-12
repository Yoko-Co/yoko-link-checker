<?php
/**
 * Link Repository.
 *
 * Handles CRUD operations for the yoko_lc_links table, which stores one row per
 * link *occurrence* -- the same URL in twelve posts is twelve rows here and one
 * row in the urls table. Counting lives in LinkStats, not here, so that the
 * distinction is made in exactly one place.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Repository;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Model\Link;
use YokoLinkChecker\Model\Url;

/**
 * Link repository for database operations.
 *
 * @since 1.0.0
 */
final class LinkRepository {

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * URLs table name.
	 *
	 * @var string
	 */
	private string $urls_table;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table      = $wpdb->prefix . 'yoko_lc_links';
		$this->urls_table = $wpdb->prefix . 'yoko_lc_urls';
	}

	/**
	 * Find link by ID.
	 *
	 * @since 1.0.0
	 * @param int $id Link ID.
	 * @return Link|null
	 */
	public function find( int $id ): ?Link {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		return $row ? Link::from_row( $row ) : null;
	}

	/**
	 * Find existing link by unique key.
	 *
	 * @since 1.0.0
	 * @param int    $url_id       URL ID.
	 * @param int    $source_id    Source post ID.
	 * @param string $source_type  Source post type.
	 * @param string $source_field Source field.
	 * @return Link|null
	 */
	public function find_existing( int $url_id, int $source_id, string $source_type, string $source_field ): ?Link {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} 
				WHERE url_id = %d AND source_id = %d AND source_type = %s AND source_field = %s",
				$url_id,
				$source_id,
				$source_type,
				$source_field
			)
		);
		// phpcs:enable

		return $row ? Link::from_row( $row ) : null;
	}

	/**
	 * Find existing links for multiple tuples in a single query.
	 *
	 * Returns existing links keyed by a composite key of
	 * "url_id:source_id:source_type:source_field".
	 *
	 * @since 1.0.11
	 * @param array<array{url_id: int, source_id: int, source_type: string, source_field: string}> $criteria Array of lookup tuples.
	 * @return array<string, Link> Links keyed by composite key.
	 */
	public function find_existing_batch( array $criteria ): array {
		if ( empty( $criteria ) ) {
			return array();
		}

		global $wpdb;

		// Build OR conditions for each tuple.
		$conditions = array();
		$params     = array();
		foreach ( $criteria as $tuple ) {
			$conditions[] = '(url_id = %d AND source_id = %d AND source_type = %s AND source_field = %s)';
			$params[]     = $tuple['url_id'];
			$params[]     = $tuple['source_id'];
			$params[]     = $tuple['source_type'];
			$params[]     = $tuple['source_field'];
		}

		$where = implode( ' OR ', $conditions );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where uses placeholders built above.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built dynamically.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE {$where}",
				...$params
			)
		);
		// phpcs:enable

		$result = array();
		foreach ( $rows as $row ) {
			$link = Link::from_row( $row );
			$key  = $link->url_id . ':' . $link->source_id . ':' . $link->source_type . ':' . $link->source_field;

			$result[ $key ] = $link;
		}

		return $result;
	}

	/**
	 * Insert a new link.
	 *
	 * @since 1.0.0
	 * @param Link $link Link entity.
	 * @return Link|null Link with ID populated, or null on failure.
	 */
	public function insert( Link $link ): ?Link {
		global $wpdb;

		$now = current_time( 'mysql' );

		if ( empty( $link->created_at ) ) {
			$link->created_at = $now;
		}
		$link->updated_at = $now;

		$data = $link->to_row();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table,
			$data,
			$this->get_format( $data )
		);

		if ( false === $result ) {
			return null;
		}

		$link->id = (int) $wpdb->insert_id;

		return $link;
	}

	/**
	 * Update an existing link.
	 *
	 * @since 1.0.0
	 * @param Link $link Link entity.
	 * @return bool Whether update succeeded.
	 */
	public function update( Link $link ): bool {
		global $wpdb;

		if ( null === $link->id ) {
			return false;
		}

		$link->updated_at = current_time( 'mysql' );
		$data             = $link->to_row();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $link->id ),
			$this->get_format( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Count total links.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get links with URL data for list table display.
	 *
	 * Returns flat stdClass objects for direct use in WP_List_Table. The WHERE
	 * clause comes from the same LinkQuery that LinkStats::count_links() uses,
	 * so the rows shown and the "N items" count can never describe different
	 * sets -- the search-term-ignored-by-the-counter bug is structurally gone.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Takes a LinkQuery instead of a loose args array.
	 * @param LinkQuery $query Filters, sort and pagination.
	 * @return array<\stdClass>
	 */
	public function get_links_with_urls( LinkQuery $query ): array {
		global $wpdb;

		list( $where, $params ) = $query->to_where( $wpdb );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from $wpdb->prefix; ORDER BY comes from LinkQuery's allow-list.
		$sql = "SELECT
				l.id as link_id,
				l.source_id as post_id,
				l.source_type,
				l.source_field,
				l.anchor_text,
				l.link_context,
				u.id as url_id,
				u.url,
				u.url_normalized,
				u.status,
				u.http_code,
				u.final_url,
				u.error_type,
				u.error_message,
				u.is_internal,
				u.last_checked,
				u.is_ignored as ignored,
				u.response_time
				FROM {$this->table} l
				JOIN {$this->urls_table} u ON l.url_id = u.id
				WHERE 1=1{$where}
				ORDER BY " . $query->to_order_sql() . '
				LIMIT %d OFFSET %d';
		// phpcs:enable

		$params[] = $query->per_page;
		$params[] = ( $query->page - 1 ) * $query->per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholders come from LinkQuery::to_where().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable

		return $rows ? $rows : array();
	}

	/**
	 * Delete every link occurrence recorded for a source.
	 *
	 * Called when a post is permanently deleted so its links stop counting.
	 *
	 * @since 1.2.0
	 * @param int         $source_id   Source post ID.
	 * @param string|null $source_type Restrict to one source type, or null for all.
	 * @return int Number of rows deleted.
	 */
	public function delete_by_source( int $source_id, ?string $source_type = null ): int {
		global $wpdb;

		LinkStats::flush();

		$sql    = "DELETE FROM {$this->table} WHERE source_id = %d";
		$params = array( $source_id );

		if ( null !== $source_type ) {
			$sql     .= ' AND source_type = %s';
			$params[] = $source_type;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from $wpdb->prefix.
		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable
	}

	/**
	 * Delete link rows for a source that were not seen in the latest scan.
	 *
	 * Catches links removed from a post's content, which no deletion hook can
	 * ever see. Passing an empty $keep_ids removes all of the source's links,
	 * which is the correct behaviour when a post no longer contains any.
	 *
	 * @since 1.2.0
	 * @param int        $source_id   Source post ID.
	 * @param string     $source_type Source post type.
	 * @param array<int> $keep_ids    Link IDs still present in the content.
	 * @return int Number of rows deleted.
	 */
	public function delete_stale_for_source( int $source_id, string $source_type, array $keep_ids ): int {
		global $wpdb;

		$sql    = "DELETE FROM {$this->table} WHERE source_id = %d AND source_type = %s";
		$params = array( $source_id, $source_type );

		$keep_ids = array_values( array_unique( array_map( 'intval', $keep_ids ) ) );

		if ( ! empty( $keep_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $keep_ids ), '%d' ) );
			$sql         .= " AND id NOT IN ({$placeholders})";
			$params       = array_merge( $params, $keep_ids );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from $wpdb->prefix; placeholders built above.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable
	}

	/**
	 * Delete link rows whose source post no longer exists.
	 *
	 * DEBUG: `wp yoko-lc prune` calls this; `wp yoko-lc verify` reports the count.
	 *
	 * @since 1.2.0
	 * @return int Number of rows deleted.
	 */
	public function delete_orphans(): int {
		global $wpdb;

		LinkStats::flush();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives from $wpdb->prefix.
		return (int) $wpdb->query(
			"DELETE l FROM {$this->table} l
			 LEFT JOIN {$wpdb->posts} p ON l.source_id = p.ID
			 WHERE p.ID IS NULL"
		);
		// phpcs:enable
	}

	/**
	 * Stream links for CSV export using chunked queries.
	 *
	 * Uses keyset (cursor-based) pagination and yields rows one at a time via
	 * a PHP generator, ensuring constant memory usage and O(n) query performance
	 * regardless of dataset size.
	 *
	 * @since 1.0.9
	 * @since 1.0.10 Switched from LIMIT/OFFSET to keyset pagination for O(n) performance.
	 * @since 1.2.0 Applies the caller's LinkQuery so the file matches the screen it
	 *              was exported from, and joins posts on ID alone -- the old join
	 *              also required source_type = 'post', which blanked the source
	 *              columns for every page and custom post type.
	 * @param LinkQuery $query      Filters to apply.
	 * @param int       $chunk_size Number of rows to fetch per database query. Default 1000.
	 * @return \Generator<int, \stdClass> Yields stdClass row objects with source_url populated.
	 */
	public function stream_for_export( LinkQuery $query, int $chunk_size = 1000 ): \Generator {
		global $wpdb;

		$chunk_size             = max( 1, $chunk_size );
		$last_id                = 0;
		list( $where, $filter ) = $query->to_where( $wpdb );

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from $wpdb->prefix.
			$sql = "SELECT
					l.id,
					u.url,
					u.status,
					u.http_code,
					u.error_message,
					u.last_checked,
					l.anchor_text as link_text,
					l.source_id,
					l.source_type,
					p.post_title,
					p.post_type
				FROM {$this->table} l
				JOIN {$this->urls_table} u ON l.url_id = u.id
				LEFT JOIN {$wpdb->posts} p ON l.source_id = p.ID
				WHERE l.id > %d{$where}
				ORDER BY l.id ASC
				LIMIT %d";
			// phpcs:enable

			// Keyset cursor comes first in the SQL, so it leads the parameter list.
			$params = array_merge( array( $last_id ), $filter, array( $chunk_size ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholders come from LinkQuery::to_where().
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
			// phpcs:enable

			if ( empty( $rows ) ) {
				break;
			}

			// Prime post caches in a single query to avoid N+1 get_permalink() calls.
			$post_ids = array();
			foreach ( $rows as $row ) {
				if ( null !== $row->post_title ) {
					$post_ids[] = (int) $row->source_id;
				}
			}
			if ( ! empty( $post_ids ) ) {
				_prime_post_caches( array_unique( $post_ids ), true, false );
			}

			foreach ( $rows as $row ) {
				// A null post_title means the join found no post -- the source is either
				// deleted or not a post at all, so there is no permalink to offer.
				$row->source_url = null === $row->post_title ? '' : (string) get_permalink( (int) $row->source_id );
				yield $row;
			}

			$last_id    = (int) end( $rows )->id;
			$rows_count = count( $rows );
		} while ( $rows_count === $chunk_size );
	}

	/**
	 * Get recent broken links with source and post data.
	 *
	 * Returns broken URLs joined with one representative link occurrence, plus
	 * how many occurrences each URL has -- the dashboard leads with unique URLs,
	 * so it has to say how much work each one actually represents.
	 *
	 * @since 1.0.8
	 * @since 1.2.0 Excludes ignored URLs (they are hidden everywhere else, so
	 *              listing them here contradicted the Reports page this links to),
	 *              picks the representative occurrence deterministically instead
	 *              of relying on an unordered LIMIT 1, and counts occurrences.
	 * @param int $limit Maximum broken URLs to return.
	 * @return array<array<string, mixed>>
	 */
	public function get_recent_broken( int $limit = 10 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from $wpdb->prefix.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.id, u.url, u.http_code, u.error_message, u.last_checked,
				        l.source_id, l.source_type, l.anchor_text,
				        p.post_title,
				        (SELECT COUNT(*) FROM {$this->table} lc WHERE lc.url_id = u.id) AS occurrences
				 FROM {$this->urls_table} u
				 JOIN {$this->table} l ON l.id = (
				     SELECT l2.id FROM {$this->table} l2 WHERE l2.url_id = u.id ORDER BY l2.id ASC LIMIT 1
				 )
				 LEFT JOIN {$wpdb->posts} p ON l.source_id = p.ID
				 WHERE u.status = %s AND u.is_ignored = 0
				 ORDER BY u.last_checked DESC
				 LIMIT %d",
				Url::STATUS_BROKEN,
				$limit
			)
		);
		// phpcs:enable

		$broken = array();

		foreach ( $results as $row ) {
			$broken[] = array(
				'id'            => (int) $row->id,
				'url'           => $row->url,
				'http_code'     => (int) $row->http_code,
				'error_message' => $row->error_message,
				'last_checked'  => $row->last_checked,
				'source_id'     => (int) $row->source_id,
				'source_type'   => $row->source_type ?? '',
				// Null when the source post is gone; the template renders a dash.
				'post_title'    => $row->post_title ?? '',
				'anchor_text'   => $row->anchor_text,
				'occurrences'   => (int) $row->occurrences,
			);
		}

		return $broken;
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
			if ( is_int( $value ) || in_array( $key, array( 'url_id', 'source_id', 'link_position' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
