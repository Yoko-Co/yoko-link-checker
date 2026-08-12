<?php
/**
 * Batch Processor class.
 *
 * Handles batch processing of posts for link extraction
 * and URLs for status checking.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Scanner;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Extractor\ExtractorRegistry;
use YokoLinkChecker\Repository\UrlRepository;
use YokoLinkChecker\Repository\LinkRepository;
use YokoLinkChecker\Repository\ScanRepository;
use YokoLinkChecker\Checker\StatusClassifier;
use YokoLinkChecker\Checker\UrlChecker;
use YokoLinkChecker\Model\Link;
use YokoLinkChecker\Model\Scan;
use YokoLinkChecker\Model\Url;
use YokoLinkChecker\Util\Logger;
use WP_Post;

/**
 * Batch processor for scan operations.
 *
 * @since 1.0.0
 */
class BatchProcessor {

	/**
	 * Content discovery instance.
	 *
	 * @var ContentDiscovery
	 */
	private ContentDiscovery $content_discovery;

	/**
	 * Extractor registry instance.
	 *
	 * @var ExtractorRegistry
	 */
	private ExtractorRegistry $extractor_registry;

	/**
	 * URL repository instance.
	 *
	 * @var UrlRepository
	 */
	private UrlRepository $url_repository;

	/**
	 * Link repository instance.
	 *
	 * @var LinkRepository
	 */
	private LinkRepository $link_repository;

	/**
	 * Scan repository instance.
	 *
	 * @var ScanRepository
	 */
	private ScanRepository $scan_repository;

	/**
	 * URL checker instance.
	 *
	 * @var UrlChecker
	 */
	private UrlChecker $url_checker;

	/**
	 * Status classifier instance.
	 *
	 * @var StatusClassifier
	 */
	private StatusClassifier $classifier;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param ContentDiscovery  $content_discovery  Content discovery instance.
	 * @param ExtractorRegistry $extractor_registry Extractor registry instance.
	 * @param UrlRepository     $url_repository     URL repository instance.
	 * @param LinkRepository    $link_repository    Link repository instance.
	 * @param ScanRepository    $scan_repository    Scan repository instance.
	 * @param UrlChecker        $url_checker        URL checker instance.
	 * @param StatusClassifier  $classifier         Status classifier instance.
	 */
	public function __construct(
		ContentDiscovery $content_discovery,
		ExtractorRegistry $extractor_registry,
		UrlRepository $url_repository,
		LinkRepository $link_repository,
		ScanRepository $scan_repository,
		UrlChecker $url_checker,
		StatusClassifier $classifier
	) {
		$this->content_discovery  = $content_discovery;
		$this->extractor_registry = $extractor_registry;
		$this->url_repository     = $url_repository;
		$this->link_repository    = $link_repository;
		$this->scan_repository    = $scan_repository;
		$this->url_checker        = $url_checker;
		$this->classifier         = $classifier;
	}

	/**
	 * Process a batch of posts for link discovery.
	 *
	 * @since 1.0.0
	 * @param int $scan_id    Scan ID.
	 * @param int $after_id   Process posts after this ID.
	 * @param int $batch_size Number of posts to process.
	 * @return ScanState Current state after processing.
	 */
	public function process_discovery_batch( int $scan_id, int $after_id = 0, int $batch_size = 50 ): ScanState {
		Logger::debug(
			'process_discovery_batch',
			array(
				'scan_id'    => $scan_id,
				'after_id'   => $after_id,
				'batch_size' => $batch_size,
			)
		);

		$posts   = $this->content_discovery->get_batch( $after_id, $batch_size );
		$last_id = $after_id;

		// Use the total from the scan record instead of re-counting on every batch.
		$scan        = $this->scan_repository->find( $scan_id );
		$total_posts = $scan ? $scan->total_posts : 0;

		Logger::debug(
			'process_discovery_batch - got posts',
			array(
				'count'       => count( $posts ),
				'total_posts' => $total_posts,
			)
		);

		foreach ( $posts as $post ) {
			$this->process_post( $scan_id, $post );
			$last_id = $post->ID;
		}

		$processed_count = count( $posts );
		$complete        = $processed_count < $batch_size;

		Logger::debug(
			'process_discovery_batch - results',
			array(
				'processed_count' => $processed_count,
				'complete'        => $complete,
			)
		);

		// Update scan state (re-fetch scan if not already loaded to get current processed_posts).
		if ( ! $scan ) {
			$scan = $this->scan_repository->find( $scan_id );
		}
		if ( $scan ) {
			$new_processed = $scan->processed_posts + $processed_count;
			Logger::debug( 'process_discovery_batch - updating progress', array( 'new_processed' => $new_processed ) );
			$this->scan_repository->update_progress(
				$scan,
				Scan::PHASE_DISCOVERY,
				$new_processed,
				$last_id
			);

			return ScanState::discovery(
				$total_posts,
				$new_processed,
				$last_id,
				$complete,
				$processed_count
			);
		}

		return ScanState::discovery( $total_posts, 0, $last_id, $complete, $processed_count );
	}

	/**
	 * Process a single post for link extraction.
	 *
	 * Uses batch queries to avoid N+1 database lookups: all extracted URLs
	 * are normalised and hashed up-front, then fetched in bulk from the
	 * urls table. Existing links are likewise checked in a single query.
	 *
	 * @since 1.0.0
	 * @since 1.0.11 Optimised with batch URL and link lookups.
	 * @param int     $scan_id Scan ID.
	 * @param WP_Post $post    Post object.
	 * @return int Number of new links inserted.
	 */
	private function process_post( int $scan_id, WP_Post $post ): int {
		/**
		 * Filters whether to skip scanning a post.
		 *
		 * @since 1.0.0
		 * @param bool    $skip Whether to skip.
		 * @param WP_Post $post Post object.
		 */
		if ( apply_filters( 'yoko_lc_skip_post', false, $post ) ) {
			return 0;
		}

		$extracted_links = $this->extractor_registry->extract_from_post( $post );

		if ( empty( $extracted_links ) ) {
			// The post had links before and has none now -- drop the stale rows,
			// or they keep inflating occurrence counts forever. Emptying a post's
			// content is the common case here, so this path matters.
			$this->link_repository->delete_stale_for_source( $post->ID, $post->post_type, array() );

			/** This action is documented below. */
			do_action( 'yoko_lc_post_processed', $post, 0, $scan_id );
			return 0;
		}

		$normalizer = $this->url_repository->get_normalizer();

		// --- Phase 1: normalise every extracted URL and compute hashes. ---
		$prepared = array(); // Index-keyed array of { extracted, normalized, is_internal, hash }.
		$hashes   = array();

		foreach ( $extracted_links as $index => $extracted ) {
			$raw_url    = trim( $extracted->url );
			$normalized = $normalizer->normalize( $raw_url );

			if ( empty( $normalized ) ) {
				continue;
			}

			$is_internal = $normalizer->is_internal( $normalized );
			$url_hash    = $normalizer->hash( $normalized );

			$prepared[ $index ] = array(
				'extracted'   => $extracted,
				'raw_url'     => $raw_url,
				'normalized'  => $normalized,
				'is_internal' => $is_internal,
				'hash'        => $url_hash,
			);

			$hashes[ $url_hash ] = $url_hash;
		}

		if ( empty( $prepared ) ) {
			// Everything extracted normalised away (mailto:, anchors, and so on),
			// so this post contributes no links -- same cleanup as above.
			$this->link_repository->delete_stale_for_source( $post->ID, $post->post_type, array() );

			/** This action is documented below. */
			do_action( 'yoko_lc_post_processed', $post, 0, $scan_id );
			return 0;
		}

		// --- Phase 2: batch-fetch existing URL records by hash. ---
		$existing_urls = $this->url_repository->find_by_hashes( array_values( $hashes ) );

		// Create any URLs that don't exist yet, and build a hash-to-Url map.
		$url_map = $existing_urls; // keyed by url_hash.

		foreach ( $prepared as &$item ) {
			$hash = $item['hash'];

			if ( isset( $url_map[ $hash ] ) ) {
				$item['url'] = $url_map[ $hash ];
				continue;
			}

			// URL not in DB yet -- create it via the repository (handles race conditions).
			$url = $this->url_repository->find_or_create(
				$item['raw_url'],
				$item['normalized'],
				$item['is_internal']
			);

			if ( $url && $url->id ) {
				$url_map[ $hash ] = $url;
				$item['url']      = $url;
			} else {
				$item['url'] = null;
			}
		}
		unset( $item );

		// --- Phase 3: batch-fetch existing links for this source post. ---
		$link_criteria = array();
		foreach ( $prepared as $item ) {
			if ( ! $item['url'] || ! $item['url']->id ) {
				continue;
			}

			$link_criteria[] = array(
				'url_id'       => $item['url']->id,
				'source_id'    => $post->ID,
				'source_type'  => $post->post_type,
				'source_field' => $item['extracted']->field,
			);
		}

		$existing_links = $this->link_repository->find_existing_batch( $link_criteria );

		// --- Phase 4: insert or update links. ---
		$link_count = 0;

		// Link rows still present in this post's content. Anything belonging to
		// the post but absent from this list was removed from the content since
		// the last scan, and is deleted below.
		$keep_ids = array();

		foreach ( $prepared as $item ) {
			$url = $item['url'];

			if ( ! $url || ! $url->id ) {
				continue;
			}

			$extracted = $item['extracted'];
			$link_key  = $url->id . ':' . $post->ID . ':' . $post->post_type . ':' . $extracted->field;

			if ( isset( $existing_links[ $link_key ] ) ) {
				// Update existing link.
				$existing               = $existing_links[ $link_key ];
				$existing->anchor_text  = $extracted->text;
				$existing->link_context = $extracted->context;
				$existing->updated_at   = current_time( 'mysql' );
				$this->link_repository->update( $existing );

				if ( $existing->id ) {
					$keep_ids[] = $existing->id;
				}
				continue;
			}

			// Create new link occurrence.
			$link                = new Link();
			$link->url_id        = $url->id;
			$link->source_id     = $post->ID;
			$link->source_type   = $post->post_type;
			$link->source_field  = $extracted->field;
			$link->anchor_text   = $extracted->text;
			$link->link_context  = $extracted->context;
			$link->link_position = $extracted->position;
			$link->created_at    = current_time( 'mysql' );
			$link->updated_at    = current_time( 'mysql' );

			$inserted = $this->link_repository->insert( $link );

			if ( $inserted && $inserted->id ) {
				++$link_count;
				$keep_ids[] = $inserted->id;
			}
		}

		// --- Phase 5: drop links that are no longer in this post's content. ---
		// DEBUG: edit a post to remove a link, rescan, then `wp yoko-lc counts` --
		// the link total must fall while the URL total stays put if the URL is
		// still linked elsewhere.
		$this->link_repository->delete_stale_for_source( $post->ID, $post->post_type, $keep_ids );

		/**
		 * Fires after a post has been processed for links.
		 *
		 * @since 1.0.0
		 * @param WP_Post $post       Post object.
		 * @param int     $link_count Number of links found.
		 * @param int     $scan_id    Scan ID.
		 */
		do_action( 'yoko_lc_post_processed', $post, $link_count, $scan_id );

		return $link_count;
	}

	/**
	 * Process a batch of URLs for status checking.
	 *
	 * @since 1.0.0
	 * @param int $scan_id    Scan ID.
	 * @param int $after_id   Process URLs after this ID.
	 * @param int $batch_size Number of URLs to check.
	 * @return ScanState Current state after processing.
	 */
	public function process_checking_batch( int $scan_id, int $after_id = 0, int $batch_size = 10 ): ScanState {
		$urls       = $this->url_repository->get_pending( $batch_size, $after_id );
		$total_urls = $this->url_repository->count_pending_checkable();
		$last_id    = $after_id;

		// Separate URLs into external and internal arrays.
		$external_urls = array();
		$internal_urls = array();
		foreach ( $urls as $url ) {
			if ( $url->is_internal ) {
				$internal_urls[] = $url;
			} else {
				$external_urls[ $url->url ] = $url;
			}
		}

		$actual_checked = 0;

		// Process external URLs in parallel via check_batch().
		if ( ! empty( $external_urls ) ) {
			$url_strings = array_keys( $external_urls );
			$results     = $this->url_checker->check_batch( $url_strings );

			if ( is_array( $results ) && ! empty( $results ) ) {
				foreach ( $external_urls as $url_string => $url ) {
					if ( isset( $results[ $url_string ] ) ) {
						$result = $results[ $url_string ];

						$url->status         = $result->status;
						$url->http_code      = $result->http_code;
						$url->final_url      = $result->final_url;
						$url->redirect_count = $result->redirect_count;
						$url->response_time  = $result->response_time;
						$url->error_type     = $result->error_type;
						$url->error_message  = $result->error_message;
						$url->last_checked   = current_time( 'mysql' );
						$url->check_count    = ( $url->check_count ?? 0 ) + 1;

						try {
							$this->url_repository->update( $url );
							++$actual_checked;

							/** This action is documented in BatchProcessor::check_url() */
							do_action( 'yoko_lc_url_checked', $url, $result );
						} catch ( \Throwable $e ) {
							Logger::error(
								'Failed to update URL after parallel check',
								array(
									'url_id' => $url->id,
									'error'  => $e->getMessage(),
								)
							);
						}
					} else {
						// No result for this URL; fall back to sequential check.
						try {
							$this->check_url( $url );
							++$actual_checked;
						} catch ( \Throwable $e ) {
							Logger::error(
								'URL check failed',
								array(
									'url_id' => $url->id,
									'error'  => $e->getMessage(),
								)
							);
							$this->mark_url_error( $url, $e->getMessage() );
							++$actual_checked;
						}
					}
					$last_id = $url->id;
				}
			} else {
				// Parallel failed entirely, fall back to sequential.
				foreach ( $external_urls as $url ) {
					try {
						$this->check_url( $url );
						++$actual_checked;
					} catch ( \Throwable $e ) {
						Logger::error(
							'URL check failed',
							array(
								'url_id' => $url->id,
								'error'  => $e->getMessage(),
							)
						);
						$this->mark_url_error( $url, $e->getMessage() );
						++$actual_checked;
					}
					$last_id = $url->id;
				}
			}
		}

		// Process internal URLs sequentially (they use WordPress functions).
		foreach ( $internal_urls as $url ) {
			try {
				$this->check_url( $url );
				++$actual_checked;
			} catch ( \Throwable $e ) {
				Logger::error(
					'URL check failed',
					array(
						'url_id' => $url->id,
						'error'  => $e->getMessage(),
					)
				);
				$this->mark_url_error( $url, $e->getMessage() );
				++$actual_checked;
			}
			$last_id = $url->id;
		}

		$checked_count = $actual_checked;
		$complete      = $checked_count < $batch_size;

		// Update scan state.
		$scan = $this->scan_repository->find( $scan_id );
		if ( $scan ) {
			$new_checked = $scan->checked_urls + $checked_count;
			$this->scan_repository->update_progress(
				$scan,
				Scan::PHASE_CHECKING,
				$new_checked,
				$last_id
			);

			// Get total pending at scan start if available.
			$total_to_check = $total_urls + $new_checked;

			return ScanState::checking(
				$total_to_check,
				$new_checked,
				$last_id,
				$complete,
				$checked_count
			);
		}

		return ScanState::checking( $total_urls, 0, $last_id, $complete, $checked_count );
	}

	/**
	 * Check a single URL and update its status.
	 *
	 * @since 1.0.0
	 * @param Url $url URL model.
	 * @return void
	 */
	private function check_url( Url $url ): void {
		/**
		 * Filters whether to skip checking a URL.
		 *
		 * @since 1.0.0
		 * @param bool $skip Whether to skip.
		 * @param Url  $url  URL model.
		 */
		if ( apply_filters( 'yoko_lc_skip_url_check', false, $url ) ) {
			$url->status       = Url::STATUS_VALID;
			$url->last_checked = current_time( 'mysql' );
			$this->url_repository->update( $url );
			return;
		}

		// For internal URLs, check via WordPress functions instead of HTTP.
		// This avoids self-referential HTTP requests that can deadlock PHP workers.
		if ( $url->is_internal ) {
			$this->check_internal_url( $url );
			return;
		}

		$result = $this->url_checker->check( $url->url );

		// Update the URL model with the results.
		$url->status         = $result->status;
		$url->http_code      = $result->http_code;
		$url->final_url      = $result->final_url;
		$url->redirect_count = $result->redirect_count;
		$url->error_type     = $result->error_type;
		$url->error_message  = $result->error_message;
		$url->response_time  = $result->response_time;
		$url->last_checked   = current_time( 'mysql' );
		$url->check_count    = ( $url->check_count ?? 0 ) + 1;

		$this->url_repository->update( $url );

		/**
		 * Fires after a URL has been checked.
		 *
		 * @since 1.0.0
		 * @param Url                              $url    URL model.
		 * @param \YokoLinkChecker\Checker\CheckResult $result Check result.
		 */
		do_action( 'yoko_lc_url_checked', $url, $result );
	}

	/**
	 * Mark a URL as errored when an exception is caught during checking.
	 *
	 * Centralises the error-marking logic so that both sequential and parallel
	 * fallback paths handle failures consistently.
	 *
	 * @since 1.0.9
	 * @param Url    $url           URL model.
	 * @param string $error_message Error message from the exception.
	 * @return void
	 */
	private function mark_url_error( Url $url, string $error_message ): void {
		$url->status        = Url::STATUS_ERROR;
		$url->error_message = substr( $error_message, 0, 255 );
		$url->last_checked  = current_time( 'mysql' );
		$url->check_count   = ( $url->check_count ?? 0 ) + 1;

		try {
			$this->url_repository->update( $url );
		} catch ( \Throwable $update_error ) {
			Logger::error(
				'Failed to update URL after error',
				array(
					'url_id' => $url->id,
					'error'  => $update_error->getMessage(),
				)
			);
		}
	}

	/**
	 * Check an internal URL using WordPress functions instead of HTTP.
	 *
	 * This avoids self-referential HTTP requests that can deadlock PHP workers
	 * on limited-resource hosts.
	 *
	 * @since 1.0.2
	 * @param Url $url URL model.
	 * @return void
	 */
	private function check_internal_url( Url $url ): void {
		$url->last_checked = current_time( 'mysql' );
		$url->check_count  = ( $url->check_count ?? 0 ) + 1;

		// Try to resolve the URL to a post ID using WordPress.
		$post_id = url_to_postid( $url->url );

		if ( $post_id > 0 ) {
			// URL resolves to a valid post/page.
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status ) {
				$url->status    = Url::STATUS_VALID;
				$url->http_code = 200;
			} else {
				// Post exists but isn't published (draft, trash, etc.).
				$url->status        = Url::STATUS_BROKEN;
				$url->http_code     = 404;
				$url->error_message = __( 'Post exists but is not published.', 'yoko-link-checker' );
			}
			$this->url_repository->update( $url );
			return;
		}

		// Check if it's a homepage or front page URL.
		$home_url = home_url( '/' );
		if ( trailingslashit( $url->url ) === $home_url || untrailingslashit( $url->url ) === untrailingslashit( $home_url ) ) {
			$url->status    = Url::STATUS_VALID;
			$url->http_code = 200;
			$this->url_repository->update( $url );
			return;
		}

		// Check if it's a term (category, tag, taxonomy) archive.
		$path = wp_parse_url( $url->url, PHP_URL_PATH );
		if ( $path ) {
			$path = trim( $path, '/' );
			// Check common archive patterns.
			$term = get_term_by( 'slug', basename( $path ), 'category' );
			if ( ! $term ) {
				$term = get_term_by( 'slug', basename( $path ), 'post_tag' );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$url->status    = Url::STATUS_VALID;
				$url->http_code = 200;
				$this->url_repository->update( $url );
				return;
			}
		}

		// Check attachments by URL — only for paths that look like media files.
		// attachment_url_to_postid() performs an expensive LIKE query, so guard it.
		$media_path = wp_parse_url( $url->url, PHP_URL_PATH );
		if ( $media_path && preg_match( '/\.(jpe?g|png|gif|webp|svg|pdf|mp[34]|mov|avi|docx?|xlsx?|pptx?|zip)$/i', $media_path ) ) {
			$attachment_id = attachment_url_to_postid( $url->url );
			if ( $attachment_id > 0 ) {
				$url->status    = Url::STATUS_VALID;
				$url->http_code = 200;
				$this->url_repository->update( $url );
				return;
			}
		}

		// WordPress functions couldn't verify the URL.
		// Fall back to HTTP request to get actual status.
		// This handles custom routes, plugin pages, archive pages, etc.
		$this->check_internal_url_via_http( $url );
	}

	/**
	 * Check internal URL via HTTP request as fallback.
	 *
	 * Used when WordPress functions can't verify the URL -- custom routes,
	 * plugin pages, archives. Goes through UrlChecker like every other request
	 * so there is one SSRF gate in the plugin rather than two; this used to be a
	 * separate hand-rolled wp_remote_head() call with SSL verification disabled
	 * and no per-hop redirect validation.
	 *
	 * @since 1.0.8
	 * @since 1.2.0 Routed through UrlChecker/HttpClient.
	 * @param Url $url URL model.
	 * @return void
	 */
	private function check_internal_url_via_http( Url $url ): void {
		// This site's own host frequently resolves to a private address (local
		// dev, containers, load balancers), which the SSRF gate blocks by design.
		// Allow it for this one request, and only for our own host.
		$allow_own_host = static function ( bool $allow, string $candidate ): bool {
			$candidate_host = wp_parse_url( $candidate, PHP_URL_HOST );
			$site_host      = wp_parse_url( home_url(), PHP_URL_HOST );

			return ( $candidate_host && $candidate_host === $site_host ) ? true : $allow;
		};

		// WP SEAM: yoko_lc_allow_private_urls -- our own filter, attached only for
		// the duration of this call so the exemption cannot leak to other URLs.
		add_filter( 'yoko_lc_allow_private_urls', $allow_own_host, 10, 2 );

		try {
			$result = $this->url_checker->check( $url->url );
		} finally {
			remove_filter( 'yoko_lc_allow_private_urls', $allow_own_host, 10 );
		}

		$url->status         = $result->status;
		$url->http_code      = $result->http_code;
		$url->final_url      = $result->final_url;
		$url->redirect_count = $result->redirect_count;
		$url->error_type     = $result->error_type;
		$url->error_message  = $result->error_message;
		$url->response_time  = $result->response_time;

		$this->url_repository->update( $url );
	}

	/**
	 * Recheck a specific URL.
	 *
	 * @since 1.0.0
	 * @param int $url_id URL ID.
	 * @return bool Whether recheck was successful.
	 */
	public function recheck_url( int $url_id ): bool {
		$url = $this->url_repository->find( $url_id );

		if ( ! $url ) {
			return false;
		}

		$this->check_url( $url );

		return true;
	}
}
