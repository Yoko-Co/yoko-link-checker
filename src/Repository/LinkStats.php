<?php
/**
 * Canonical counts for every screen.
 *
 * Before this class the dashboard, the Reports table and the CSV each ran their
 * own COUNT with a different unit and a different set of filters, so the three
 * numbers disagreed on any site that reused a link or ignored one. Every count
 * shown anywhere in the plugin now comes from here.
 *
 * Every query joins the links table, so "a URL" means "a URL still linked from
 * somewhere". That is what makes the two units reconcile -- zero occurrences
 * means zero URLs -- and it drops orphaned URL rows out of reporting for free.
 *
 * DEBUG: `wp yoko-lc counts` prints everything this class returns;
 * `wp yoko-lc verify` asserts the totals add up.
 *
 * @package YokoLinkChecker
 * @since   1.2.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregate counts over links joined to their URLs.
 *
 * @since 1.2.0
 */
final class LinkStats {

	/**
	 * Links table name.
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
	 * Per-request memo of status_counts() results, keyed by LinkQuery::cache_key().
	 *
	 * The Reports screen asks for the same counts twice (once for the filter
	 * tabs, once for pagination). Deliberately not a transient: an ignore or a
	 * rescan must be reflected on the very next page load.
	 *
	 * @var array<string, StatusCounts>
	 */
	private array $memo = array();

	/**
	 * Transient holding the dashboard's unfiltered counts.
	 */
	private const CACHE_KEY = 'yoko_lc_status_counts';

	/**
	 * How long the cached dashboard counts survive without an explicit flush.
	 *
	 * A backstop, not the mechanism: every seam that changes the numbers calls
	 * flush(). The TTL only covers a write path nobody remembered to wire up.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table      = $wpdb->prefix . 'yoko_lc_links';
		$this->urls_table = $wpdb->prefix . 'yoko_lc_urls';
	}

	/**
	 * Count link occurrences matching a query.
	 *
	 * This is the number the Reports list table shows as "N items", because the
	 * rows it paginates are occurrences.
	 *
	 * @since 1.2.0
	 * @param LinkQuery $query Filters to apply.
	 * @return int
	 */
	public function count_links( LinkQuery $query ): int {
		return $this->count( $query, 'COUNT(*)' );
	}

	/**
	 * Count unique URLs matching a query.
	 *
	 * This is the number the dashboard leads with, because a URL is fixed once
	 * however many posts link to it.
	 *
	 * @since 1.2.0
	 * @param LinkQuery $query Filters to apply.
	 * @return int
	 */
	public function count_urls( LinkQuery $query ): int {
		return $this->count( $query, 'COUNT(DISTINCT u.id)' );
	}

	/**
	 * Count every status in both units in a single query.
	 *
	 * The query's own status filter is ignored here by design -- the caller
	 * wants all statuses under the same search/ignored conditions.
	 *
	 * @since 1.2.0
	 * @param LinkQuery $query Filters to apply (status is dropped).
	 * @return StatusCounts
	 */
	public function status_counts( LinkQuery $query ): StatusCounts {
		global $wpdb;

		$query = $query->without_status();
		$key   = $query->cache_key();

		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		// The unfiltered case is the dashboard's, and it is identical on every
		// load. It is also the most expensive shape here -- a full join of the
		// links table with COUNT(DISTINCT) on top -- where the query it replaced
		// was an index-only scan of the much smaller urls table. On a site with
		// millions of link rows that difference is felt on every page load, so
		// this one result is cached, with every seam that changes the numbers
		// calling flush() rather than waiting for a TTL to lapse.
		$is_dashboard_query = '' === $query->search && ! $query->ignored_only;

		if ( $is_dashboard_query ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				$this->memo[ $key ] = new StatusCounts( $cached['urls'], $cached['links'] );

				return $this->memo[ $key ];
			}
		}

		list( $where, $params ) = $query->to_where( $wpdb );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from $wpdb->prefix.
		$sql = "SELECT u.status, COUNT(*) AS link_count, COUNT(DISTINCT u.id) AS url_count
				FROM {$this->table} l
				JOIN {$this->urls_table} u ON l.url_id = u.id
				WHERE 1=1{$where}
				GROUP BY u.status";
		// phpcs:enable

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholders come from LinkQuery::to_where().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable

		$this->memo[ $key ] = StatusCounts::from_rows( $rows ? $rows : array() );

		if ( $is_dashboard_query ) {
			set_transient( self::CACHE_KEY, $this->memo[ $key ]->to_array(), self::CACHE_TTL );
		}

		return $this->memo[ $key ];
	}

	/**
	 * Discard the cached dashboard counts.
	 *
	 * Called from every seam that changes what the counts would say, so an
	 * ignore, a rescan or a prune is reflected on the very next page load rather
	 * than whenever a TTL happens to lapse.
	 *
	 * DEBUG: wp transient delete yoko_lc_status_counts does the same by hand.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Count unique URLs currently ignored.
	 *
	 * Shown on the dashboard so a number that dropped after someone ignored a
	 * link has a visible explanation rather than looking like data loss.
	 *
	 * @since 1.2.0
	 * @return int
	 */
	public function count_ignored_urls(): int {
		return $this->count_urls( new LinkQuery( null, '', true ) );
	}

	/**
	 * Count link rows whose source post no longer exists.
	 *
	 * DEBUG: a non-zero result here is what makes occurrence counts drift above
	 * URL counts over time. `wp yoko-lc prune` clears them.
	 *
	 * @since 1.2.0
	 * @return int
	 */
	public function count_orphan_links(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from $wpdb->prefix.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$this->table} l
			 LEFT JOIN {$wpdb->posts} p ON l.source_id = p.ID
			 WHERE p.ID IS NULL"
		);
		// phpcs:enable
	}

	/**
	 * Run an aggregate over the links/urls join with a query's filters applied.
	 *
	 * @since 1.2.0
	 * @param LinkQuery $query     Filters to apply.
	 * @param string    $aggregate SQL aggregate expression, e.g. 'COUNT(*)'.
	 * @return int
	 */
	private function count( LinkQuery $query, string $aggregate ): int {
		global $wpdb;

		list( $where, $params ) = $query->to_where( $wpdb );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from $wpdb->prefix; $aggregate is a caller-supplied literal.
		$sql = "SELECT {$aggregate}
				FROM {$this->table} l
				JOIN {$this->urls_table} u ON l.url_id = u.id
				WHERE 1=1{$where}";
		// phpcs:enable

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholders come from LinkQuery::to_where().
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable
	}
}
