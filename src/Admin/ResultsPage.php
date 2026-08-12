<?php
/**
 * Results Page class.
 *
 * Handles the broken links results list display.
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
use YokoLinkChecker\Repository\UrlRepository;
use YokoLinkChecker\Model\Url;

/**
 * Results page class.
 *
 * @since 1.0.0
 */
class ResultsPage {

	/**
	 * Link repository instance.
	 *
	 * @var LinkRepository
	 */
	private LinkRepository $link_repository;

	/**
	 * URL repository instance.
	 *
	 * @var UrlRepository
	 */
	private UrlRepository $url_repository;

	/**
	 * List table instance.
	 *
	 * @var LinksListTable|null
	 */
	private ?LinksListTable $list_table = null;

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
	 * @param LinkRepository $link_repository Link repository.
	 * @param UrlRepository  $url_repository  URL repository.
	 * @param LinkStats      $link_stats      Link statistics service.
	 */
	public function __construct( LinkRepository $link_repository, UrlRepository $url_repository, LinkStats $link_stats ) {
		$this->link_repository = $link_repository;
		$this->url_repository  = $url_repository;
		$this->link_stats      = $link_stats;
	}

	/**
	 * Register the screen option for rows per page.
	 *
	 * Called from the screen's load hook, which is the only point early enough
	 * for WordPress to persist the user's choice.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function register_screen_options(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Links per page', 'yoko-link-checker' ),
				'default' => 20,
				'option'  => LinksListTable::PER_PAGE_OPTION,
			)
		);
	}

	/**
	 * Handle ignore/un-ignore before the admin page starts rendering.
	 *
	 * Runs on the screen's load hook so the redirect below happens before any
	 * output. Doing this during render emitted a headers-already-sent warning
	 * and left the action in the URL, so a refresh re-fired it.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function maybe_handle_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! isset( $_GET['action'], $_GET['url_id'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		$url_id = absint( $_GET['url_id'] );
		// phpcs:enable

		if ( ! in_array( $action, array( 'ignore', 'unignore' ), true ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, "yoko_lc_ignore_{$url_id}" ) ) {
			wp_die( esc_html__( 'Security check failed.', 'yoko-link-checker' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'yoko_lc_manage_scans' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'yoko-link-checker' ), '', array( 'response' => 403 ) );
		}

		$succeeded = 'ignore' === $action
			? $this->url_repository->mark_ignored( $url_id )
			: $this->url_repository->unmark_ignored( $url_id );

		$redirect_url = remove_query_arg( array( 'action', 'url_id', '_wpnonce' ) );

		if ( ! $succeeded ) {
			// DEBUG: look for ylc_error in the URL, and check the URL row still exists:
			// wp yoko-lc counts, or wp db query "SELECT * FROM wp_yoko_lc_urls WHERE id = <id>".
			$redirect_url = add_query_arg( 'ylc_error', "{$action}_failed", $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render the results page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; LinkQuery sanitizes.
		$query = LinkQuery::from_request( wp_unslash( $_GET ), $this->get_per_page() );

		$this->list_table = new LinksListTable( $this->link_repository, $this->link_stats, $query );
		$this->list_table->prepare_items();

		// Used by the template for the hidden form field that preserves the filter.
		$status_filter = $query->status ?? 'all';

		include YOKO_LC_PLUGIN_DIR . 'templates/admin/results.php';
	}

	/**
	 * Rows per page for the current user.
	 *
	 * @since 1.2.0
	 * @return int
	 */
	private function get_per_page(): int {
		$per_page = (int) get_user_option( LinksListTable::PER_PAGE_OPTION );

		return $per_page > 0 ? $per_page : 20;
	}

	/**
	 * Handle CSV export using streaming for constant memory usage.
	 *
	 * @since 1.0.3
	 * @since 1.0.9 Switched to streaming generator for memory efficiency.
	 * @since 1.2.0 Exports the filters showing on screen instead of the whole table.
	 * @return void
	 */
	public function handle_export(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'yoko_lc_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'yoko-link-checker' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'yoko_lc_view_results' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'yoko-link-checker' ), '', array( 'response' => 403 ) );
		}

		// Same filters the Export button was clicked under -- the old export
		// dumped every row of every status and still called the column "Broken URL".
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$query = LinkQuery::from_request( wp_unslash( $_GET ) );

		// Name the file after what is in it, so it is still identifiable after download.
		$scope    = $query->ignored_only ? 'ignored' : ( $query->status ?? 'all' );
		$filename = 'yoko-link-checker-' . $scope . '-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Disable output buffering to stream directly to the client.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Generic.CodeAnalysis.EmptyStatement.DetectedWhile -- ob_end_clean may warn if no buffer active; intentionally empty loop body.
		while ( @ob_end_clean() ) {
			// Clear all output buffers.
		}

		// Create output stream.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://output for streaming CSV.
		$output = fopen( 'php://output', 'w' );

		// Write UTF-8 BOM for Excel compatibility.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		// Write header row with clear column names for non-technical users.
		fputcsv(
			$output,
			array(
				__( 'URL', 'yoko-link-checker' ),
				__( 'Status', 'yoko-link-checker' ),
				__( 'HTTP Code', 'yoko-link-checker' ),
				__( 'Error Details', 'yoko-link-checker' ),
				__( 'Source URL', 'yoko-link-checker' ),
				__( 'Source Title', 'yoko-link-checker' ),
				__( 'Source Type', 'yoko-link-checker' ),
				__( 'Link Text', 'yoko-link-checker' ),
				__( 'Last Checked', 'yoko-link-checker' ),
			)
		);

		// Stream data rows from the generator -- constant memory regardless of dataset size.
		$row_count = 0;
		foreach ( $this->link_repository->stream_for_export( $query ) as $link ) {
			fputcsv(
				$output,
				array(
					$this->sanitize_csv_value( $link->url ?? '' ),
					$link->status ?? '',
					$link->http_code ?? '',
					$this->sanitize_csv_value( $link->error_message ?? '' ),
					$this->sanitize_csv_value( $link->source_url ?? '' ),
					$this->sanitize_csv_value( $link->post_title ?? '' ),
					$this->sanitize_csv_value( $link->post_type ?? '' ),
					$this->sanitize_csv_value( $link->link_text ?? '' ),
					$link->last_checked ?? '',
				)
			);

			++$row_count;
			if ( 0 === $row_count % 500 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush
				fflush( $output );
			}
		}

		// Final flush to ensure all remaining rows are written.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush
		fflush( $output );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Using php://output stream for CSV export.
		fclose( $output );
		exit;
	}

	/**
	 * Sanitize a value for safe CSV export by escaping formula-triggering characters.
	 *
	 * Prevents CSV formula injection by prefixing dangerous characters with a single quote.
	 *
	 * @since 1.0.10
	 * @param string $value The value to sanitize.
	 * @return string The sanitized value.
	 */
	private function sanitize_csv_value( $value ): string {
		$value = (string) $value;
		if ( isset( $value[0] ) && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Get the list table instance.
	 *
	 * @since 1.0.0
	 * @return LinksListTable|null
	 */
	public function get_list_table(): ?LinksListTable {
		return $this->list_table;
	}
}
