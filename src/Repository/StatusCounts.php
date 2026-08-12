<?php
/**
 * Per-status counts in both units.
 *
 * The plugin counts two different things and users kept comparing them: a
 * unique URL is the *problem* unit (you fix a URL once) and a link occurrence
 * is the *work* unit (each is a place someone must edit). One broken URL used
 * in twelve posts is 1 URL and 12 links. This object carries both so no screen
 * can show one while implying the other.
 *
 * @package YokoLinkChecker
 * @since   1.2.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Repository;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Model\Url;

/**
 * Immutable per-status count set.
 *
 * @since 1.2.0
 */
final class StatusCounts {

	/**
	 * Unique-URL counts keyed by status. Every Url::STATUSES key is present.
	 *
	 * @var array<string, int>
	 */
	private array $urls;

	/**
	 * Link-occurrence counts keyed by status. Every Url::STATUSES key is present.
	 *
	 * @var array<string, int>
	 */
	private array $links;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @param array<string, int> $urls  URL counts by status.
	 * @param array<string, int> $links Link counts by status.
	 */
	public function __construct( array $urls = array(), array $links = array() ) {
		$zero = array_fill_keys( Url::STATUSES, 0 );

		$this->urls  = array_merge( $zero, array_intersect_key( $urls, $zero ) );
		$this->links = array_merge( $zero, array_intersect_key( $links, $zero ) );
	}

	/**
	 * Build from the grouped aggregate query rows.
	 *
	 * @since 1.2.0
	 * @param array<\stdClass> $rows Rows with status, url_count and link_count.
	 * @return self
	 */
	public static function from_rows( array $rows ): self {
		$urls  = array();
		$links = array();

		foreach ( $rows as $row ) {
			$urls[ $row->status ]  = (int) $row->url_count;
			$links[ $row->status ] = (int) $row->link_count;
		}

		return new self( $urls, $links );
	}

	/**
	 * Unique URLs with the given status.
	 *
	 * @since 1.2.0
	 * @param string $status Status slug.
	 * @return int
	 */
	public function urls( string $status ): int {
		return $this->urls[ $status ] ?? 0;
	}

	/**
	 * Link occurrences with the given status.
	 *
	 * @since 1.2.0
	 * @param string $status Status slug.
	 * @return int
	 */
	public function links( string $status ): int {
		return $this->links[ $status ] ?? 0;
	}

	/**
	 * Total unique URLs across every status.
	 *
	 * @since 1.2.0
	 * @return int
	 */
	public function total_urls(): int {
		return array_sum( $this->urls );
	}

	/**
	 * Total link occurrences across every status.
	 *
	 * @since 1.2.0
	 * @return int
	 */
	public function total_links(): int {
		return array_sum( $this->links );
	}

	/**
	 * Unique URLs in a Url::STATUS_GROUPS bucket.
	 *
	 * @since 1.2.0
	 * @param string $group Group key, e.g. 'needs_review'.
	 * @return int
	 */
	public function group_urls( string $group ): int {
		return $this->sum_group( $this->urls, $group );
	}

	/**
	 * Link occurrences in a Url::STATUS_GROUPS bucket.
	 *
	 * @since 1.2.0
	 * @param string $group Group key, e.g. 'needs_review'.
	 * @return int
	 */
	public function group_links( string $group ): int {
		return $this->sum_group( $this->links, $group );
	}

	/**
	 * Sentence describing a status in both units.
	 *
	 * The only place this phrasing is composed, so the dashboard and the
	 * Reports tabs cannot word the same fact two different ways.
	 *
	 * @since 1.2.0
	 * @param string $status Status slug.
	 * @return string Unescaped text; escape at the point of output.
	 */
	public function describe( string $status ): string {
		$urls  = $this->urls( $status );
		$links = $this->links( $status );

		return sprintf(
			/* translators: 1: number of unique URLs, 2: number of link occurrences */
			_n(
				'%1$s URL across %2$s link',
				'%1$s URLs across %2$s links',
				$urls,
				'yoko-link-checker'
			),
			number_format_i18n( $urls ),
			number_format_i18n( $links )
		);
	}

	/**
	 * Sum the statuses belonging to a group.
	 *
	 * @since 1.2.0
	 * @param array<string, int> $counts Counts keyed by status.
	 * @param string             $group  Group key.
	 * @return int
	 */
	private function sum_group( array $counts, string $group ): int {
		$total = 0;

		foreach ( Url::STATUS_GROUPS[ $group ] ?? array() as $status ) {
			$total += $counts[ $status ] ?? 0;
		}

		return $total;
	}
}
