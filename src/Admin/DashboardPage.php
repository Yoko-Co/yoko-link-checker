<?php
/**
 * Dashboard Page class.
 *
 * Handles the main admin dashboard display.
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
use YokoLinkChecker\Repository\ScanRepository;
use YokoLinkChecker\Repository\StatusCounts;
use YokoLinkChecker\Scanner\ScanOrchestrator;
use YokoLinkChecker\Model\Url;

/**
 * Dashboard page class.
 *
 * @since 1.0.0
 */
class DashboardPage {

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
	 * Scan repository instance.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scan_repository;

	/**
	 * Scan orchestrator instance.
	 *
	 * @var ScanOrchestrator
	 */
	private ScanOrchestrator $scan_orchestrator;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Takes LinkStats in place of UrlRepository.
	 * @param LinkRepository   $link_repository   Link repository.
	 * @param LinkStats        $link_stats        Link statistics service.
	 * @param ScanRepository   $scan_repository   Scan repository.
	 * @param ScanOrchestrator $scan_orchestrator Scan orchestrator.
	 */
	public function __construct(
		LinkRepository $link_repository,
		LinkStats $link_stats,
		ScanRepository $scan_repository,
		ScanOrchestrator $scan_orchestrator
	) {
		$this->link_repository   = $link_repository;
		$this->link_stats        = $link_stats;
		$this->scan_repository   = $scan_repository;
		$this->scan_orchestrator = $scan_orchestrator;
	}

	/**
	 * Render the dashboard page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render(): void {
		// One query for every status in both units, shared by the cards and the
		// breakdown -- the same source the Reports tab counts from.
		$counts = $this->link_stats->status_counts( new LinkQuery() );

		$stats            = $this->get_stats( $counts );
		$scan_status      = $this->scan_orchestrator->get_status();
		$recent_broken    = $this->link_repository->get_recent_broken();
		$status_breakdown = $this->get_status_breakdown( $counts );

		include YOKO_LC_PLUGIN_DIR . 'templates/admin/dashboard.php';
	}

	/**
	 * Assemble the dashboard's headline figures.
	 *
	 * Every card carries both units: unique URLs (the problem count -- a URL is
	 * fixed once) and link occurrences (the work count -- one per place someone
	 * has to edit). Showing only one of the two is what made this screen appear
	 * to disagree with the Reports tab, which necessarily counts occurrences.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Dual-unit, ignored-aware, and includes the needs-review group
	 *              so the visible cards sum to the total.
	 * @param StatusCounts $counts Canonical counts.
	 * @return array<string, mixed>
	 */
	private function get_stats( StatusCounts $counts ): array {
		$cards = array();

		foreach ( array( Url::STATUS_BROKEN, Url::STATUS_WARNING, Url::STATUS_REDIRECT, Url::STATUS_VALID, Url::STATUS_PENDING ) as $status ) {
			$cards[ $status ] = array(
				'label'  => Url::label_for( $status ),
				'urls'   => $counts->urls( $status ),
				'links'  => $counts->links( $status ),
				'status' => $status,
			);
		}

		// Blocked, timeout and error share one card. They are real results but
		// rarely mean "dead link", so folding them into Broken would inflate the
		// one number people act on; leaving them off entirely (the old behaviour)
		// made the cards fail to add up to the total.
		$cards['needs_review'] = array(
			'label'  => __( 'Needs Review', 'yoko-link-checker' ),
			'urls'   => $counts->group_urls( 'needs_review' ),
			'links'  => $counts->group_links( 'needs_review' ),
			'status' => null,
			'parts'  => array_map(
				fn( string $status ) => array(
					'status' => $status,
					'label'  => Url::label_for( $status ),
					'urls'   => $counts->urls( $status ),
				),
				Url::STATUS_GROUPS['needs_review']
			),
		);

		return array(
			'total_urls'   => $counts->total_urls(),
			'total_links'  => $counts->total_links(),
			'cards'        => $cards,
			'ignored_urls' => $this->link_stats->count_ignored_urls(),
			'total_scans'  => $this->scan_repository->count_all(),
			'last_scan'    => $this->scan_repository->get_last_completed(),
		);
	}

	/**
	 * Get status breakdown for the chart.
	 *
	 * Iterates Url::STATUSES rather than a hand-written list, which is what
	 * previously left the 'error' status out of every dashboard view.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Covers every status; counts unique URLs.
	 * @param StatusCounts $counts Canonical counts.
	 * @return array<array<string, mixed>>
	 */
	private function get_status_breakdown( StatusCounts $counts ): array {
		$colors = array(
			Url::STATUS_VALID    => '#4caf50',
			Url::STATUS_BROKEN   => '#f44336',
			Url::STATUS_WARNING  => '#ff9800',
			Url::STATUS_REDIRECT => '#2196f3',
			Url::STATUS_BLOCKED  => '#9c27b0',
			Url::STATUS_TIMEOUT  => '#795548',
			Url::STATUS_ERROR    => '#607d8b',
			Url::STATUS_PENDING  => '#9e9e9e',
		);

		$breakdown = array();

		foreach ( $colors as $status => $color ) {
			$count = $counts->urls( $status );

			if ( $count > 0 ) {
				$breakdown[] = array(
					'status' => $status,
					'label'  => Url::label_for( $status ),
					'count'  => $count,
					'links'  => $counts->links( $status ),
					'color'  => $color,
				);
			}
		}

		return $breakdown;
	}

	/**
	 * Get formatted last scan time.
	 *
	 * @since 1.0.0
	 * @param \YokoLinkChecker\Model\Scan|null $scan Scan model.
	 * @return string
	 */
	public function format_last_scan( $scan ): string {
		if ( ! $scan || ! $scan->completed_at ) {
			return __( 'Never', 'yoko-link-checker' );
		}

		$timestamp = strtotime( $scan->completed_at );

		if ( false === $timestamp ) {
			return __( 'Unknown', 'yoko-link-checker' );
		}

		return sprintf(
			/* translators: %s: human-readable time difference */
			__( '%s ago', 'yoko-link-checker' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * Get scan duration.
	 *
	 * @since 1.0.0
	 * @param \YokoLinkChecker\Model\Scan|null $scan Scan model.
	 * @return string
	 */
	public function format_scan_duration( $scan ): string {
		if ( ! $scan || ! $scan->started_at || ! $scan->completed_at ) {
			return '—';
		}

		$start = strtotime( $scan->started_at );
		$end   = strtotime( $scan->completed_at );

		if ( false === $start || false === $end ) {
			return "\xE2\x80\x94"; // em-dash.
		}

		$diff = $end - $start;

		if ( $diff < 60 ) {
			/* translators: %d: number of seconds */
			return sprintf( _n( '%d second', '%d seconds', $diff, 'yoko-link-checker' ), $diff );
		}

		$minutes = floor( $diff / 60 );
		$seconds = $diff % 60;

		if ( $minutes < 60 ) {
			/* translators: 1: number of minutes, 2: number of seconds */
			return sprintf( __( '%1$dm %2$ds', 'yoko-link-checker' ), $minutes, $seconds );
		}

		$hours   = floor( $minutes / 60 );
		$minutes = $minutes % 60;

		/* translators: 1: number of hours, 2: number of minutes */
		return sprintf( __( '%1$dh %2$dm', 'yoko-link-checker' ), $hours, $minutes );
	}
}
