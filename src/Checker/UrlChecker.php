<?php
/**
 * URL Checker.
 *
 * Orchestrates URL checking using HTTP client and status classifier.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Checker;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Model\Url;
use YokoLinkChecker\Util\Logger;

/**
 * URL checking service.
 *
 * @since 1.0.0
 */
final class UrlChecker {

	/**
	 * HTTP client instance.
	 *
	 * @var HttpClient
	 */
	private HttpClient $http_client;

	/**
	 * Status classifier instance.
	 *
	 * @var StatusClassifier
	 */
	private StatusClassifier $classifier;

	/**
	 * HTTP methods that don't support HEAD or are problematic.
	 * These sites block HEAD requests or are very slow, so go straight to GET.
	 *
	 * @var array<string>
	 */
	private const HEAD_UNSUPPORTED_PATTERNS = array(
		'linkedin.com',
		'facebook.com',
		'instagram.com',
		'twitter.com',
		'x.com',
		'amazon.com',
		'reddit.com',
		'pinterest.com',
		'tiktok.com',
		'cloudflare.com',
		'medium.com',
		'youtu.be',
		'youtube.com',
		'vimeo.com',
		'bit.ly',
		'goo.gl',
		't.co',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param HttpClient       $http_client HTTP client.
	 * @param StatusClassifier $classifier  Status classifier.
	 */
	public function __construct( HttpClient $http_client, StatusClassifier $classifier ) {
		$this->http_client = $http_client;
		$this->classifier  = $classifier;
	}

	/**
	 * Check a URL.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return CheckResult
	 */
	public function check( string $url ): CheckResult {
		Logger::debug( 'Checking URL', array( 'url' => $url ) );

		// Determine if we should skip HEAD and go straight to GET.
		$use_head = $this->should_use_head( $url );

		if ( $use_head ) {
			$result = $this->check_with_head( $url );

			// If HEAD was inconclusive, try GET.
			if ( $this->should_retry_with_get( $result ) ) {
				Logger::debug( 'HEAD inconclusive, retrying with GET', array( 'url' => $url ) );
				$result = $this->check_with_get( $url );
			}
		} else {
			$result = $this->check_with_get( $url );
		}

		Logger::debug(
			'Check complete',
			array(
				'url'       => $url,
				'status'    => $result->status,
				'http_code' => $result->http_code,
			)
		);

		return $result;
	}

	/**
	 * Check URL using HEAD request.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return CheckResult
	 */
	private function check_with_head( string $url ): CheckResult {
		$result = $this->http_client->head( $url );

		return $this->process_response( $url, $result['response'], $result['time'] );
	}

	/**
	 * Check URL using GET request.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return CheckResult
	 */
	private function check_with_get( string $url ): CheckResult {
		$result = $this->http_client->get( $url );

		return $this->process_response( $url, $result['response'], $result['time'] );
	}

	/**
	 * Process HTTP response into CheckResult.
	 *
	 * @since 1.0.0
	 * @param string          $url           Checked URL.
	 * @param array|\WP_Error $response      HTTP response.
	 * @param int             $response_time Response time in ms.
	 * @return CheckResult
	 */
	private function process_response( string $url, $response, int $response_time ): CheckResult {
		// Handle errors.
		if ( is_wp_error( $response ) ) {
			$error_type    = $this->http_client->get_error_type( $response );
			$error_message = $response->get_error_message();

			$status = $this->classifier->classify(
				null,
				$error_type,
				$error_message,
				$url
			);

			return CheckResult::error(
				$url,
				$status,
				$error_type,
				$error_message,
				null,
				$response_time
			);
		}

		// Process successful response.
		$http_code      = $this->http_client->get_response_code( $response );
		$final_url      = $this->http_client->get_final_url( $response, $url );
		$redirect_count = $this->http_client->count_redirects( $response );
		$headers        = $this->http_client->get_headers( $response );

		$status = $this->classifier->classify(
			$http_code,
			null,
			null,
			$url,
			$final_url
		);

		return new CheckResult(
			$url,
			$status,
			$http_code,
			$final_url !== $url ? $final_url : null,
			$redirect_count,
			$response_time,
			null,
			null,
			$headers
		);
	}

	/**
	 * Determine if HEAD request should be used.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function should_use_head( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return true;
		}

		$host = strtolower( $host );

		// Check against known problematic sites.
		foreach ( self::HEAD_UNSUPPORTED_PATTERNS as $pattern ) {
			if ( str_contains( $host, $pattern ) ) {
				return false;
			}
		}

		/**
		 * Filters whether to use HEAD request for a URL.
		 *
		 * @since 1.0.0
		 * @param bool   $use_head Whether to use HEAD.
		 * @param string $url      The URL being checked.
		 */
		return apply_filters( 'yoko_lc_use_head_request', true, $url );
	}

	/**
	 * Determine if we should retry with GET after HEAD.
	 *
	 * @since 1.0.0
	 * @param CheckResult $result HEAD result.
	 * @return bool
	 */
	private function should_retry_with_get( CheckResult $result ): bool {
		// Retry on 405 Method Not Allowed.
		if ( 405 === $result->http_code ) {
			return true;
		}

		// Retry on blocked/error that might be HEAD-specific.
		if ( Url::STATUS_BLOCKED === $result->status ) {
			return true;
		}

		// Retry on certain error types.
		if ( in_array( $result->error_type, array( 'http_request_failed' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check multiple URLs with parallel HTTP requests.
	 *
	 * URLs are grouped into chunks and checked concurrently using the WordPress
	 * bundled Requests library. Falls back to sequential processing if parallel
	 * requests fail.
	 *
	 * @since 1.0.0
	 * @since 1.0.9 Added parallel request support.
	 * @param array<string> $urls URLs to check.
	 * @return array<string, CheckResult> Results keyed by URL.
	 */
	public function check_batch( array $urls ): array {
		if ( empty( $urls ) ) {
			return array();
		}

		/**
		 * Filters the number of concurrent requests in a parallel batch.
		 *
		 * @since 1.0.9
		 * @param int $batch_size Number of concurrent requests. Default 5.
		 */
		$batch_size = (int) apply_filters( 'yoko_lc_parallel_batch_size', 5 );
		$batch_size = max( 1, min( $batch_size, 10 ) );

		$results = array();
		$chunks  = array_chunk( $urls, $batch_size );

		foreach ( $chunks as $chunk ) {
			$chunk_results = $this->check_batch_parallel( $chunk );

			if ( false === $chunk_results ) {
				Logger::debug( 'Parallel batch failed, falling back to sequential', array( 'urls_count' => count( $chunk ) ) );
				foreach ( $chunk as $url ) {
					$results[ $url ] = $this->check( $url );
					usleep( 100000 );
				}
			} else {
				$results = array_merge( $results, $chunk_results );
			}
		}

		return $results;
	}

	/**
	 * Process a chunk of URLs in parallel using the Requests library.
	 *
	 * @since 1.0.9
	 * @param array<string> $urls URLs to check concurrently.
	 * @return array<string, CheckResult>|false Results keyed by URL, or false on failure.
	 */
	private function check_batch_parallel( array $urls ) {
		try {
			$head_urls = array();
			$get_urls  = array();

			foreach ( $urls as $url ) {
				if ( $this->should_use_head( $url ) ) {
					$head_urls[] = $url;
				} else {
					$get_urls[] = $url;
				}
			}

			$results = array();

			if ( ! empty( $head_urls ) ) {
				$head_results = $this->send_parallel_requests( $head_urls, 'HEAD' );

				if ( false === $head_results ) {
					return false;
				}

				$retry_urls = array();

				foreach ( $head_results as $url => $result ) {
					if ( $this->should_retry_with_get( $result ) ) {
						Logger::debug( 'HEAD inconclusive in parallel batch, queuing GET retry', array( 'url' => $url ) );
						$retry_urls[] = $url;
					} else {
						$results[ $url ] = $result;
					}
				}

				$get_urls = array_merge( $get_urls, $retry_urls );
			}

			if ( ! empty( $get_urls ) ) {
				$get_results = $this->send_parallel_requests( $get_urls, 'GET' );

				if ( false === $get_results ) {
					foreach ( $get_urls as $url ) {
						$results[ $url ] = $this->check_with_get( $url );
						usleep( 100000 );
					}
				} else {
					$results = array_merge( $results, $get_results );
				}
			}

			return $results;
		} catch ( \Throwable $e ) {
			Logger::debug( 'Parallel batch exception', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Send parallel HTTP requests using the WordPress bundled Requests library.
	 *
	 * @since 1.0.9
	 * @param array<string> $urls   URLs to request.
	 * @param string        $method HTTP method ('HEAD' or 'GET').
	 * @return array<string, CheckResult>|false Results keyed by URL, or false on failure.
	 */
	private function send_parallel_requests( array $urls, string $method = 'HEAD' ) {
		$requests_class = null;

		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			$requests_class = '\WpOrg\Requests\Requests';
		} elseif ( class_exists( '\Requests' ) ) {
			$requests_class = '\Requests';
		}

		if ( null === $requests_class ) {
			Logger::debug( 'Requests library not available for parallel requests' );
			return false;
		}

		$wp_args = $this->http_client->get_request_args();

		$headers = array(
			'User-Agent'      => $wp_args['user-agent'] ?? '',
			'Accept'          => $wp_args['headers']['Accept'] ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Language' => $wp_args['headers']['Accept-Language'] ?? 'en-US,en;q=0.5',
			'Connection'      => 'close',
		);

		// Redirects are deliberately NOT followed here. The Requests library would
		// follow them without re-running the SSRF gate, so an external URL that
		// redirects to a private or link-local address would be fetched. Any 3xx
		// seen below is re-checked through HttpClient, which validates every hop.
		$options = array(
			'timeout'          => $wp_args['timeout'] ?? 8,
			'connect_timeout'  => $wp_args['connect_timeout'] ?? 5,
			'follow_redirects' => false,
			'verify'           => $wp_args['sslverify'] ?? true,
		);

		$requests     = array();
		$ssrf_blocked = array();
		$start_time   = microtime( true );

		foreach ( $urls as $url ) {
			$ssrf_error = $this->http_client->validate_url_ssrf( $url );

			if ( null !== $ssrf_error ) {
				Logger::debug( 'SSRF check blocked URL in parallel request', array( 'url' => $url ) );
				$ssrf_blocked[ $url ] = $ssrf_error;
				continue;
			}

			$requests[ $url ] = array(
				'url'     => $url,
				'headers' => $headers,
				'type'    => $method,
				'options' => $options,
			);
		}

		try {
			/**
			 * Results from parallel HTTP requests.
			 *
			 * @var array<string, \WpOrg\Requests\Response|\WpOrg\Requests\Exception> $responses
			 */
			$responses = $requests_class::request_multiple( $requests, $options );
		} catch ( \Throwable $e ) {
			Logger::debug( 'Parallel request_multiple failed', array( 'error' => $e->getMessage() ) );
			return false;
		}

		$results      = array();
		$elapsed_time = (int) round( ( microtime( true ) - $start_time ) * 1000 );
		$time_per_url = count( $urls ) > 0 ? (int) round( $elapsed_time / count( $urls ) ) : 0;

		// Add SSRF-blocked URLs to results.
		foreach ( $ssrf_blocked as $url => $ssrf_error ) {
			$status = $this->classifier->classify(
				null,
				'ssrf_blocked',
				$ssrf_error->get_error_message(),
				$url
			);

			$results[ $url ] = CheckResult::error(
				$url,
				$status,
				'ssrf_blocked',
				$ssrf_error->get_error_message(),
				null,
				0
			);
		}

		foreach ( $urls as $url ) {
			// Skip URLs already handled by SSRF check.
			if ( isset( $ssrf_blocked[ $url ] ) ) {
				continue;
			}

			if ( ! isset( $responses[ $url ] ) ) {
				$results[ $url ] = CheckResult::error(
					$url,
					'broken',
					'parallel_request_failed',
					__( 'No response received from parallel request.', 'yoko-link-checker' ),
					null,
					$time_per_url
				);
				continue;
			}

			$response = $responses[ $url ];

			if ( $response instanceof \Exception ) {
				$error_message = $response->getMessage();
				$error_type    = HttpClient::classify_error( $error_message );

				$status = $this->classifier->classify( null, $error_type, $error_message, $url );

				$results[ $url ] = CheckResult::error(
					$url,
					$status,
					$error_type,
					$error_message,
					null,
					$time_per_url
				);
				continue;
			}

			$http_code = (int) $response->status_code;

			// A redirect needs each hop validated, which only the sequential path
			// does. Costs one extra request per redirecting URL; redirects are a
			// minority of links and correctness here is not optional.
			if ( $http_code >= 300 && $http_code < 400 ) {
				Logger::debug( 'Redirect seen in parallel batch, re-checking with per-hop validation', array( 'url' => $url ) );
				$results[ $url ] = $this->check( $url );
				continue;
			}

			$final_url = $url;
			$redirects = 0;

			if ( ! empty( $response->url ) && $response->url !== $url ) {
				$final_url = $response->url;
			}

			$status = $this->classifier->classify( $http_code, null, null, $url, $final_url );

			$response_headers = array();
			if ( is_array( $response->headers ) ) {
				$response_headers = $response->headers;
			} elseif ( is_object( $response->headers ) && method_exists( $response->headers, 'getAll' ) ) {
				$response_headers = $response->headers->getAll();
			}

			$results[ $url ] = new CheckResult(
				$url,
				$status,
				$http_code,
				$final_url !== $url ? $final_url : null,
				$redirects,
				$time_per_url,
				null,
				null,
				$response_headers
			);
		}

		return $results;
	}
}
