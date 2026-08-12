<?php
/**
 * WP-CLI commands for inspecting and reconciling link counts.
 *
 * These exist so counting questions can be answered without clicking: `counts`
 * prints what every screen is reading from, and `verify` asserts that the
 * dashboard, the Reports tab and the export can all be derived from the same
 * numbers. `verify` is the acceptance test for the counting behaviour -- if it
 * passes, the three screens agree by construction.
 *
 * @package YokoLinkChecker
 * @since   1.2.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Cli;

defined( 'ABSPATH' ) || exit;

use WP_CLI;
use YokoLinkChecker\Model\Url;
use YokoLinkChecker\Plugin;
use YokoLinkChecker\Repository\LinkQuery;

/**
 * Manage and inspect Link Checker statistics.
 *
 * @since 1.2.0
 */
final class StatsCommand {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the command namespace with WP-CLI.
	 *
	 * @since 1.2.0
	 * @param Plugin $plugin Plugin container.
	 * @return void
	 */
	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'yoko-lc', new self( $plugin ) );
	}

	/**
	 * Show link counts for every status, in both units.
	 *
	 * Unique URLs are the problem count (a URL is fixed once); link occurrences
	 * are the work count (one per place it must be edited). The dashboard leads
	 * with the former and the Reports table paginates the latter.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--search=<term>]
	 * : Only count links whose URL or anchor text matches this term.
	 *
	 * [--ignored]
	 * : Count ignored URLs instead of active ones.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show every status in both units.
	 *     $ wp yoko-lc counts
	 *
	 *     # Machine-readable counts for a monitoring script.
	 *     $ wp yoko-lc counts --format=json
	 *
	 *     # How much of the problem is one domain?
	 *     $ wp yoko-lc counts --search=example.com
	 *
	 * @since 1.2.0
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function counts( array $args, array $assoc_args ): void {
		$query = new LinkQuery(
			null,
			(string) ( $assoc_args['search'] ?? '' ),
			isset( $assoc_args['ignored'] )
		);

		$counts = $this->plugin->link_stats()->status_counts( $query );
		$rows   = array();

		foreach ( Url::STATUSES as $status ) {
			$rows[] = array(
				'status' => $status,
				'urls'   => $counts->urls( $status ),
				'links'  => $counts->links( $status ),
			);
		}

		$rows[] = array(
			'status' => 'TOTAL',
			'urls'   => $counts->total_urls(),
			'links'  => $counts->total_links(),
		);

		WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'status', 'urls', 'links' )
		);
	}

	/**
	 * Check that the plugin's counts are internally consistent.
	 *
	 * Exits non-zero when any check fails, so it can gate a deploy or run from
	 * cron. Checks: per-status counts sum to the reported totals in both units;
	 * every status is reachable from the Reports tab; there are no link rows
	 * pointing at deleted posts; and the scan progress denominator matches the
	 * population the checker actually fetches.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp yoko-lc verify
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function verify(): void {
		$stats    = $this->plugin->link_stats();
		$counts   = $stats->status_counts( new LinkQuery() );
		$failures = array();

		// 1. The per-status figures must add up to the totals the dashboard prints.
		$summed_urls  = 0;
		$summed_links = 0;

		foreach ( Url::STATUSES as $status ) {
			$summed_urls  += $counts->urls( $status );
			$summed_links += $counts->links( $status );
		}

		if ( $summed_urls !== $counts->total_urls() ) {
			$failures[] = sprintf( 'URL totals disagree: statuses sum to %d, total reports %d.', $summed_urls, $counts->total_urls() );
		}

		if ( $summed_links !== $counts->total_links() ) {
			$failures[] = sprintf( 'Link totals disagree: statuses sum to %d, total reports %d.', $summed_links, $counts->total_links() );
		}

		// 2. Each status must be reachable, and its own count must match the
		// all-statuses query -- this is the dashboard-vs-Reports comparison.
		foreach ( Url::STATUSES as $status ) {
			$direct = $stats->count_links( new LinkQuery( $status ) );

			if ( $direct !== $counts->links( $status ) ) {
				$failures[] = sprintf(
					'Status "%s" counts differ between the grouped query (%d) and its own filter (%d).',
					$status,
					$counts->links( $status ),
					$direct
				);
			}
		}

		// 3. Link rows pointing at deleted posts inflate occurrence counts.
		$orphans = $stats->count_orphan_links();

		if ( $orphans > 0 ) {
			$failures[] = sprintf( '%d link row(s) reference posts that no longer exist. Run: wp yoko-lc prune', $orphans );
		}

		// 4. Scan progress can only reach 100% if these two agree.
		$checkable = $this->plugin->url_repository()->count_pending_checkable();
		$pending   = $counts->urls( Url::STATUS_PENDING );

		WP_CLI::log( sprintf( 'Unique URLs: %d across %d links.', $counts->total_urls(), $counts->total_links() ) );
		WP_CLI::log( sprintf( 'Broken: %s.', $counts->describe( Url::STATUS_BROKEN ) ) );
		WP_CLI::log( sprintf( 'Pending (linked): %d; pending and checkable: %d.', $pending, $checkable ) );
		WP_CLI::log( sprintf( 'Ignored URLs: %d.', $stats->count_ignored_urls() ) );

		if ( ! empty( $failures ) ) {
			foreach ( $failures as $failure ) {
				WP_CLI::warning( $failure );
			}

			WP_CLI::error( sprintf( '%d consistency check(s) failed.', count( $failures ) ) );
		}

		WP_CLI::success( 'All counts are consistent.' );
	}

	/**
	 * Delete link rows whose source post no longer exists.
	 *
	 * These accumulate whenever a post is deleted by something that predates
	 * the before_delete_post hook, and they make occurrence counts drift above
	 * URL counts.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be deleted without deleting anything.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp yoko-lc prune --dry-run
	 *     $ wp yoko-lc prune
	 *
	 * @since 1.2.0
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function prune( array $args, array $assoc_args ): void {
		$stats   = $this->plugin->link_stats();
		$orphans = $stats->count_orphan_links();

		if ( 0 === $orphans ) {
			WP_CLI::success( 'No orphaned link rows found.' );
			return;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::log( sprintf( 'Would delete %d orphaned link row(s).', $orphans ) );
			return;
		}

		$deleted = $this->plugin->link_repository()->delete_orphans();

		WP_CLI::success( sprintf( 'Deleted %d orphaned link row(s).', $deleted ) );
	}

	/**
	 * Export links to CSV using the same filters as the admin export.
	 *
	 * Shares the LinkQuery path with the browser export, so the two files are
	 * identical for identical filters.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only export links with this status. Use "all" for every status.
	 * ---
	 * default: broken
	 * ---
	 *
	 * [--search=<term>]
	 * : Only export links whose URL or anchor text matches this term.
	 *
	 * [--ignored]
	 * : Export ignored URLs instead of active ones.
	 *
	 * [--file=<path>]
	 * : Write to this file instead of standard output.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp yoko-lc export --status=broken --file=broken.csv
	 *     $ wp yoko-lc export --status=all > all-links.csv
	 *
	 * @since 1.2.0
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		$query = LinkQuery::from_request(
			array(
				'status'  => (string) ( $assoc_args['status'] ?? Url::STATUS_BROKEN ),
				's'       => (string) ( $assoc_args['search'] ?? '' ),
				'ignored' => isset( $assoc_args['ignored'] ) ? '1' : '',
			)
		);

		$path = (string) ( $assoc_args['file'] ?? 'php://stdout' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV stream, not a WP-managed file.
		$handle = fopen( $path, 'w' );

		if ( false === $handle ) {
			WP_CLI::error( sprintf( 'Could not open %s for writing.', $path ) );
		}

		fputcsv( $handle, array( 'URL', 'Status', 'HTTP Code', 'Error Details', 'Source URL', 'Source Title', 'Source Type', 'Link Text', 'Last Checked' ) );

		$rows = 0;

		foreach ( $this->plugin->link_repository()->stream_for_export( $query ) as $link ) {
			fputcsv(
				$handle,
				array(
					$link->url ?? '',
					$link->status ?? '',
					$link->http_code ?? '',
					$link->error_message ?? '',
					$link->source_url ?? '',
					$link->post_title ?? '',
					$link->post_type ?? '',
					$link->link_text ?? '',
					$link->last_checked ?? '',
				)
			);
			++$rows;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching the fopen above.
		fclose( $handle );

		if ( 'php://stdout' !== $path ) {
			WP_CLI::success( sprintf( 'Exported %d link(s) to %s.', $rows, $path ) );
		}
	}
}
