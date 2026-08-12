<?php
/**
 * Links List Table class.
 *
 * Extends WP_List_Table for displaying broken links.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Admin;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Repository\LinkQuery;
use YokoLinkChecker\Repository\LinkRepository;
use YokoLinkChecker\Repository\LinkStats;
use YokoLinkChecker\Model\Url;
use WP_List_Table;

// Load WP_List_Table if not available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Links list table class.
 *
 * @since 1.0.0
 */
class LinksListTable extends WP_List_Table {

	/**
	 * Screen option name for rows per page.
	 */
	public const PER_PAGE_OPTION = 'yoko_lc_links_per_page';

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
	 * The query describing what this table is showing.
	 *
	 * @var LinkQuery
	 */
	private LinkQuery $query;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Takes a LinkQuery and the stats service.
	 * @param LinkRepository $link_repository Link repository.
	 * @param LinkStats      $link_stats      Link statistics service.
	 * @param LinkQuery      $query           Filters for this view.
	 */
	public function __construct( LinkRepository $link_repository, LinkStats $link_stats, LinkQuery $query ) {
		$this->link_repository = $link_repository;
		$this->link_stats      = $link_stats;
		$this->query           = $query;

		parent::__construct(
			array(
				'singular' => __( 'Link', 'yoko-link-checker' ),
				'plural'   => __( 'Links', 'yoko-link-checker' ),
				'ajax'     => true,
			)
		);
	}

	/**
	 * Status filter tabs with per-status counts.
	 *
	 * Counts are link occurrences so the number on a tab matches the "N items"
	 * of the screen it leads to; the title attribute carries the unique-URL
	 * figure, which is the number the dashboard leads with. Both come from the
	 * same memoized query as the pagination total.
	 *
	 * @since 1.2.0
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts = $this->link_stats->status_counts( $this->query );
		$views  = array();

		$views['all'] = $this->build_view(
			'all',
			__( 'All', 'yoko-link-checker' ),
			$counts->total_links(),
			$counts->total_urls(),
			null === $this->query->status && ! $this->query->ignored_only
		);

		foreach ( Url::STATUSES as $status ) {
			$views[ $status ] = $this->build_view(
				$status,
				Url::label_for( $status ),
				$counts->links( $status ),
				$counts->urls( $status ),
				$status === $this->query->status && ! $this->query->ignored_only
			);
		}

		// Ignored is a separate view rather than a status: ignoring flags the URL,
		// so its rows are excluded from every other tab by definition.
		$ignored = $this->link_stats->status_counts( new LinkQuery( null, $this->query->search, true ) );

		$views['ignored'] = sprintf(
			'<a href="%1$s"%2$s title="%3$s">%4$s <span class="count">(%5$s)</span></a>',
			esc_url( AdminController::page_url( 'reports', array( 'ignored' => '1' ) ) ),
			$this->query->ignored_only ? ' class="current" aria-current="page"' : '',
			esc_attr(
				sprintf(
					/* translators: %s: number of unique URLs */
					__( '%s unique URLs', 'yoko-link-checker' ),
					number_format_i18n( $ignored->total_urls() )
				)
			),
			esc_html__( 'Ignored', 'yoko-link-checker' ),
			esc_html( number_format_i18n( $ignored->total_links() ) )
		);

		return $views;
	}

	/**
	 * Build one status filter tab.
	 *
	 * @since 1.2.0
	 * @param string $status  Status slug, or 'all'.
	 * @param string $label   Translated label.
	 * @param int    $links   Link occurrences with this status.
	 * @param int    $urls    Unique URLs with this status.
	 * @param bool   $current Whether this is the active view.
	 * @return string
	 */
	private function build_view( string $status, string $label, int $links, int $urls, bool $current ): string {
		$args = array( 'status' => $status );

		if ( '' !== $this->query->search ) {
			$args['s'] = $this->query->search;
		}

		return sprintf(
			'<a href="%1$s"%2$s title="%3$s">%4$s <span class="count">(%5$s)</span></a>',
			esc_url( AdminController::page_url( 'reports', $args ) ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_attr(
				sprintf(
					/* translators: %s: number of unique URLs */
					__( '%s unique URLs', 'yoko-link-checker' ),
					number_format_i18n( $urls )
				)
			),
			esc_html( $label ),
			esc_html( number_format_i18n( $links ) )
		);
	}

	/**
	 * Get columns.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_columns(): array {
		return array(
			'url'          => __( 'URL', 'yoko-link-checker' ),
			'status'       => __( 'Status', 'yoko-link-checker' ),
			'http_code'    => __( 'Code', 'yoko-link-checker' ),
			'source'       => __( 'Source', 'yoko-link-checker' ),
			'link_text'    => __( 'Link Text', 'yoko-link-checker' ),
			'last_checked' => __( 'Last Checked', 'yoko-link-checker' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_sortable_columns(): array {
		return array(
			'url'          => array( 'url', false ),
			'status'       => array( 'status', false ),
			'http_code'    => array( 'http_code', false ),
			'last_checked' => array( 'last_checked', true ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * Bulk actions are disabled for this MVP to simplify the interface.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_bulk_actions(): array {
		return array();
	}

	/**
	 * Prepare items.
	 *
	 * The rows and the total both come from $this->query, so "N items" always
	 * describes the rows on screen. Previously the count query ignored the
	 * search term, which advertised pages that rendered empty.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Rows and count share one LinkQuery.
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$total_items = $this->link_stats->count_links( $this->query );
		$total_pages = (int) max( 1, ceil( $total_items / $this->query->per_page ) );

		// A stale ?paged= (bookmarked, or left behind by a narrowing filter) would
		// otherwise render an empty table with no explanation.
		$this->query->page = min( $this->get_pagenum(), $total_pages );

		$this->items = $this->link_repository->get_links_with_urls( $this->query );

		// Prime post caches to avoid N+1 get_post() calls in column_source().
		$post_ids = wp_list_pluck( $this->items, 'post_id' );
		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ), true, false );
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $this->query->per_page,
				'total_pages' => $total_pages,
			)
		);
	}

	/**
	 * Column URL.
	 *
	 * @since 1.0.0
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_url( $item ): string {
		$url         = esc_url( $item->url );
		$url_display = esc_html( $this->truncate_url( $item->url, 60 ) );
		$url_id      = (int) $item->url_id;

		// Ignoring flags the URL, not this one occurrence -- the nonce and the
		// label both say url so the effect isn't a surprise.
		$actions_nonce = wp_create_nonce( "yoko_lc_ignore_{$url_id}" );

		$actions = array(
			'view' => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				$url,
				__( 'Visit', 'yoko-link-checker' )
			),
		);

		if ( empty( $item->ignored ) ) {
			$actions['ignore'] = sprintf(
				'<a href="%s" title="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'action'   => 'ignore',
							'url_id'   => $url_id,
							'_wpnonce' => $actions_nonce,
						)
					)
				),
				esc_attr__( 'Hides every occurrence of this URL from reports.', 'yoko-link-checker' ),
				__( 'Ignore this URL', 'yoko-link-checker' )
			);
		} else {
			$actions['unignore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'action'   => 'unignore',
							'url_id'   => $url_id,
							'_wpnonce' => $actions_nonce,
						)
					)
				),
				__( 'Un-ignore this URL', 'yoko-link-checker' )
			);
		}

		$output = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
			$url,
			esc_attr( $item->url ),
			$url_display
		);

		if ( ! empty( $item->ignored ) ) {
			$output .= ' <span class="ylc-ignored-badge">' . esc_html__( 'Ignored', 'yoko-link-checker' ) . '</span>';
		}

		return $output . $this->row_actions( $actions );
	}

	/**
	 * Column status.
	 *
	 * @since 1.0.0
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_status( $item ): string {
		// Status descriptions for tooltips.
		$status_descriptions = array(
			Url::STATUS_WARNING  => __( 'The server returned a response that may indicate a problem. This could be a temporary issue or the site may block automated requests.', 'yoko-link-checker' ),
			Url::STATUS_BLOCKED  => __( 'The request was blocked by the destination server. Many social media sites block automated link checking.', 'yoko-link-checker' ),
			Url::STATUS_TIMEOUT  => __( 'The request timed out waiting for a response. The server may be slow or unreachable.', 'yoko-link-checker' ),
			Url::STATUS_ERROR    => __( 'A connection error occurred. This may be a DNS, SSL, or network issue.', 'yoko-link-checker' ),
			Url::STATUS_REDIRECT => __( 'This URL redirects to a different location. The link still works, but you may want to update it to the final destination.', 'yoko-link-checker' ),
		);

		$label = Url::label_for( $item->status );

		// Build tooltip from description and/or error message.
		$tooltip_parts = array();

		if ( isset( $status_descriptions[ $item->status ] ) ) {
			$tooltip_parts[] = $status_descriptions[ $item->status ];
		}

		if ( ! empty( $item->error_message ) ) {
			$tooltip_parts[] = $item->error_message;
		}

		$tooltip = implode( "\n\n", $tooltip_parts );

		if ( $tooltip ) {
			return sprintf(
				'<span class="ylc-status ylc-status-%s" title="%s">%s</span>',
				esc_attr( $item->status ),
				esc_attr( $tooltip ),
				esc_html( $label )
			);
		}

		return sprintf(
			'<span class="ylc-status ylc-status-%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( $label )
		);
	}

	/**
	 * Column HTTP code.
	 *
	 * @since 1.0.0
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_http_code( $item ): string {
		$code = (int) $item->http_code;

		if ( 0 === $code ) {
			return '<span class="ylc-code ylc-code-unknown">—</span>';
		}

		$class = 'ylc-code';
		if ( $code >= 200 && $code < 300 ) {
			$class .= ' ylc-code-success';
		} elseif ( $code >= 300 && $code < 400 ) {
			$class .= ' ylc-code-redirect';
		} elseif ( $code >= 400 && $code < 500 ) {
			$class .= ' ylc-code-client-error';
		} elseif ( $code >= 500 ) {
			$class .= ' ylc-code-server-error';
		}

		$title = '';
		if ( ! empty( $item->error_message ) ) {
			$title = esc_attr( $item->error_message );
		}

		return sprintf(
			'<span class="%s" title="%s">%d</span>',
			esc_attr( $class ),
			$title,
			$code
		);
	}

	/**
	 * Column source.
	 *
	 * @since 1.0.0
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_source( $item ): string {
		$post_id = (int) $item->post_id;

		if ( ! $post_id ) {
			return '—';
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			// translators: %d is the deleted post ID.
			return sprintf( __( 'Deleted post #%d', 'yoko-link-checker' ), $post_id );
		}

		$edit_link = get_edit_post_link( $post_id );
		$view_link = get_permalink( $post_id );

		if ( null === $edit_link ) {
			$output = esc_html( $this->truncate_text( $post->post_title, 40 ) );
		} else {
			$output = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_link ),
				esc_html( $this->truncate_text( $post->post_title, 40 ) )
			);
		}

		$output .= ' <a href="' . esc_url( $view_link ) . '" target="_blank" class="ylc-view-post" title="' . esc_attr__( 'View', 'yoko-link-checker' ) . '">↗</a>';

		return $output;
	}

	/**
	 * Column link text.
	 *
	 * @since 1.0.0
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_link_text( $item ): string {
		if ( empty( $item->anchor_text ) ) {
			return '<em>' . esc_html__( '(none)', 'yoko-link-checker' ) . '</em>';
		}

		return esc_html( $this->truncate_text( $item->anchor_text, 50 ) );
	}

	/**
	 * Column last checked.
	 *
	 * @since 1.0.0
	 * @param object $item Item.
	 * @return string
	 */
	protected function column_last_checked( $item ): string {
		if ( empty( $item->last_checked ) ) {
			return __( 'Never', 'yoko-link-checker' );
		}

		$timestamp = strtotime( $item->last_checked );

		if ( false === $timestamp ) {
			return __( 'Unknown', 'yoko-link-checker' );
		}

		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
			/* translators: %s: human-readable time difference */
			sprintf( __( '%s ago', 'yoko-link-checker' ), human_time_diff( $timestamp ) )
		);
	}

	/**
	 * Default column handler.
	 *
	 * @since 1.0.0
	 * @param object $item        Item.
	 * @param string $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '—';
	}

	/**
	 * Display when no items.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function no_items(): void {
		if ( '' !== $this->query->search ) {
			esc_html_e( 'No links match your search.', 'yoko-link-checker' );
		} elseif ( Url::STATUS_BROKEN === $this->query->status ) {
			esc_html_e( 'No broken links found. Great job!', 'yoko-link-checker' );
		} else {
			esc_html_e( 'No links found.', 'yoko-link-checker' );
		}
	}

	/**
	 * Extra table nav.
	 *
	 * @since 1.0.0
	 * @param string $which Which navigation (top or bottom).
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// The export carries the current filters so the file matches the screen.
		$export_url = AdminController::page_url(
			'reports',
			array_merge(
				$this->query->to_query_args(),
				array(
					'action'   => 'export',
					'_wpnonce' => wp_create_nonce( 'yoko_lc_export' ),
				)
			)
		);
		?>
		<div class="alignleft actions">
			<a href="<?php echo esc_url( $export_url ); ?>" class="button">
				<?php esc_html_e( 'Export CSV (current filters)', 'yoko-link-checker' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Truncate URL for display.
	 *
	 * @since 1.0.0
	 * @param string $url    URL.
	 * @param int    $length Max length.
	 * @return string
	 */
	private function truncate_url( string $url, int $length ): string {
		if ( strlen( $url ) <= $length ) {
			return $url;
		}

		// Remove protocol for display.
		$display = preg_replace( '#^https?://#', '', $url );

		if ( strlen( $display ) <= $length ) {
			return $display;
		}

		return substr( $display, 0, $length - 3 ) . '...';
	}

	/**
	 * Truncate text for display.
	 *
	 * @since 1.0.0
	 * @param string $text   Text.
	 * @param int    $length Max length.
	 * @return string
	 */
	private function truncate_text( string $text, int $length ): string {
		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, $length - 3 ) . '...';
	}
}
