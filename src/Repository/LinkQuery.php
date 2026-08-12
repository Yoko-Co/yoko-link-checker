<?php
/**
 * Link query filter object.
 *
 * Holds the filters behind every links view -- status, search, ignored -- and
 * emits the SQL WHERE clause for them exactly once. The row query and the count
 * query take the same LinkQuery, which is what stops the list table's "N items"
 * from disagreeing with the rows it actually shows: there is no second place to
 * build a predicate and forget one.
 *
 * @package YokoLinkChecker
 * @since   1.2.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Repository;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Model\Url;

/**
 * Immutable-ish description of "which links are we talking about".
 *
 * @since 1.2.0
 */
final class LinkQuery {

	/**
	 * Columns that may be sorted on, mapped to their real SQL expression.
	 *
	 * Interpolating an ORDER BY is only safe because the value is looked up in
	 * this map rather than passed through -- an unknown key falls back rather
	 * than reaching the query.
	 *
	 * @var array<string, string>
	 */
	private const ORDERBY_MAP = array(
		'url'          => 'u.url',
		'status'       => 'u.status',
		'http_code'    => 'u.http_code',
		'last_checked' => 'u.last_checked',
		'post_id'      => 'l.source_id',
	);

	/**
	 * Status to filter by. Null or 'all' means every status.
	 *
	 * @var string|null
	 */
	public ?string $status;

	/**
	 * Free-text search across URL and anchor text.
	 *
	 * @var string
	 */
	public string $search;

	/**
	 * Whether to show ignored URLs *instead of* active ones.
	 *
	 * Ignored is a view, not an additive filter: when true the query returns
	 * only ignored URLs, so the view's count and its rows always agree.
	 *
	 * @var bool
	 */
	public bool $ignored_only;

	/**
	 * Sort column key (a key of self::ORDERBY_MAP).
	 *
	 * @var string
	 */
	public string $orderby;

	/**
	 * Sort direction.
	 *
	 * @var string
	 */
	public string $order;

	/**
	 * Page number, 1-based.
	 *
	 * @var int
	 */
	public int $page;

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	public int $per_page;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @param string|null $status       Status filter, or null/'all' for every status.
	 * @param string      $search       Search term.
	 * @param bool        $ignored_only Show only ignored URLs.
	 * @param string      $orderby      Sort column key.
	 * @param string      $order        ASC or DESC.
	 * @param int         $page         1-based page number.
	 * @param int         $per_page     Rows per page.
	 */
	public function __construct(
		?string $status = null,
		string $search = '',
		bool $ignored_only = false,
		string $orderby = 'last_checked',
		string $order = 'DESC',
		int $page = 1,
		int $per_page = 20
	) {
		$this->status       = $status;
		$this->search       = $search;
		$this->ignored_only = $ignored_only;
		$this->orderby      = isset( self::ORDERBY_MAP[ $orderby ] ) ? $orderby : 'last_checked';
		$this->order        = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$this->page         = max( 1, $page );
		$this->per_page     = max( 1, $per_page );
	}

	/**
	 * Build from request parameters.
	 *
	 * Every caller that reads $_GET for this screen goes through here, so the
	 * sanitizing rules live in one place.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $params   Raw request parameters (typically $_GET).
	 * @param int                  $per_page Rows per page for this screen.
	 * @return self
	 */
	public static function from_request( array $params, int $per_page = 20 ): self {
		$status = isset( $params['status'] ) ? sanitize_key( (string) $params['status'] ) : Url::STATUS_BROKEN;

		if ( 'all' !== $status && ! in_array( $status, Url::STATUSES, true ) ) {
			$status = Url::STATUS_BROKEN;
		}

		return new self(
			'all' === $status ? null : $status,
			isset( $params['s'] ) ? sanitize_text_field( wp_unslash( (string) $params['s'] ) ) : '',
			! empty( $params['ignored'] ),
			isset( $params['orderby'] ) ? sanitize_key( (string) $params['orderby'] ) : 'last_checked',
			isset( $params['order'] ) ? sanitize_key( (string) $params['order'] ) : 'DESC',
			isset( $params['paged'] ) ? absint( $params['paged'] ) : 1,
			$per_page
		);
	}

	/**
	 * Copy of this query with the status predicate removed.
	 *
	 * Used to count every status in one pass for the filter tabs while keeping
	 * the search and ignored filters the user actually set.
	 *
	 * @since 1.2.0
	 * @return self
	 */
	public function without_status(): self {
		return new self(
			null,
			$this->search,
			$this->ignored_only,
			$this->orderby,
			$this->order,
			$this->page,
			$this->per_page
		);
	}

	/**
	 * Copy of this query filtered to a specific status.
	 *
	 * @since 1.2.0
	 * @param string|null $status Status, or null for all.
	 * @return self
	 */
	public function with_status( ?string $status ): self {
		return new self(
			$status,
			$this->search,
			$this->ignored_only,
			$this->orderby,
			$this->order,
			$this->page,
			$this->per_page
		);
	}

	/**
	 * Build the WHERE fragment and its parameters.
	 *
	 * The returned fragment always begins with " AND " and always contains an
	 * is_ignored predicate -- never omitted, which is how the old dashboard and
	 * export counters silently disagreed with the list table.
	 *
	 * @since 1.2.0
	 * @param \wpdb $wpdb WordPress database object, for esc_like().
	 * @return array{0: string, 1: array<int, mixed>} SQL fragment and its params.
	 */
	public function to_where( \wpdb $wpdb ): array {
		$sql    = '';
		$params = array();

		if ( null !== $this->status && 'all' !== $this->status ) {
			$sql     .= ' AND u.status = %s';
			$params[] = $this->status;
		}

		if ( '' !== $this->search ) {
			$like     = '%' . $wpdb->esc_like( $this->search ) . '%';
			$sql     .= ' AND (u.url LIKE %s OR l.anchor_text LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$sql     .= ' AND u.is_ignored = %d';
		$params[] = $this->ignored_only ? 1 : 0;

		return array( $sql, $params );
	}

	/**
	 * Whitelisted ORDER BY expression.
	 *
	 * @since 1.2.0
	 * @return string SQL fragment, e.g. "u.last_checked DESC".
	 */
	public function to_order_sql(): string {
		return self::ORDERBY_MAP[ $this->orderby ] . ' ' . $this->order;
	}

	/**
	 * Round-trip back to query args for links that must preserve this view.
	 *
	 * Used by the export button and the filter tabs so a click carries the
	 * user's current filters instead of silently resetting them.
	 *
	 * @since 1.2.0
	 * @return array<string, string>
	 */
	public function to_query_args(): array {
		$args = array( 'status' => $this->status ?? 'all' );

		if ( '' !== $this->search ) {
			$args['s'] = $this->search;
		}

		if ( $this->ignored_only ) {
			$args['ignored'] = '1';
		}

		return $args;
	}

	/**
	 * Stable key for per-request memoization.
	 *
	 * Pagination and sorting are excluded: they change which rows come back,
	 * never how many match.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function cache_key(): string {
		return implode( '|', array( $this->status ?? 'all', $this->search, $this->ignored_only ? '1' : '0' ) );
	}
}
