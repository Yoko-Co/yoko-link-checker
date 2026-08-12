<?php
/**
 * HTTP Client wrapper.
 *
 * Wraps the WordPress HTTP API with the SSRF protection this plugin needs: it
 * fetches URLs harvested from post content, so every request target is
 * attacker-influenceable by anyone who can get a link into a post.
 *
 * Redirects are followed by hand rather than by the transport, because the
 * transport only validates the URL it was handed -- an innocuous external URL
 * that 302s to a link-local address would otherwise be fetched. WordPress 7.0.3
 * fixed exactly that class of bug in core's own URL validation; going around
 * core's validator means we have to do the same work ourselves.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Checker;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * HTTP client for URL checking.
 *
 * @since 1.0.0
 */
final class HttpClient {

	/**
	 * Default request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Maximum redirects to follow.
	 *
	 * @var int
	 */
	private int $max_redirects;

	/**
	 * User agent string.
	 *
	 * @var string
	 */
	private string $user_agent;

	/**
	 * Whether to verify SSL certificates.
	 *
	 * @var bool
	 */
	private bool $verify_ssl;

	/**
	 * Connection timeout in seconds.
	 *
	 * @var int
	 */
	private int $connect_timeout;

	/**
	 * Schemes this client will fetch.
	 *
	 * The normalizer already drops mailto:/javascript:/data:, but nothing there
	 * stops file://, gopher:// or dict://. Allow-listing here means the client
	 * doesn't depend on transport behaviour to be safe.
	 */
	private const ALLOWED_SCHEMES = array( 'http', 'https' );

	/**
	 * How long a resolved host stays cached, in seconds.
	 *
	 * WHY: the SSRF check and the request that follows it resolve the host
	 * independently, so a host whose DNS record flips between the two can be
	 * validated as public and then fetched as private (DNS rebinding). Pinning
	 * the validated IP into the connection is the complete fix and is out of
	 * reach through the WordPress HTTP API; a short cache keeps the window
	 * small instead of leaving a resolution cached for the whole process.
	 */
	private const DNS_CACHE_TTL = 30;

	/**
	 * Resolved hosts, as host => array{ip: string, expires: int}.
	 *
	 * @var array<string, array{ip: string, expires: int}>
	 */
	private static array $dns_cache = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param int    $timeout         Request timeout in seconds.
	 * @param int    $max_redirects   Maximum number of redirects to follow.
	 * @param string $user_agent      User agent string.
	 * @param bool   $verify_ssl      Whether to verify SSL certificates.
	 * @param int    $connect_timeout Connection timeout in seconds.
	 */
	public function __construct(
		int $timeout = 8,
		int $max_redirects = 3,
		string $user_agent = '',
		bool $verify_ssl = true,
		int $connect_timeout = 5
	) {
		$this->timeout         = $timeout;
		$this->connect_timeout = $connect_timeout;
		$this->max_redirects   = $max_redirects;
		$this->user_agent      = $user_agent ? $user_agent : $this->get_default_user_agent();
		$this->verify_ssl      = $verify_ssl;
	}

	/**
	 * Perform a HEAD request.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return array{response: array|WP_Error, time: int}
	 */
	public function head( string $url ): array {
		return $this->request( 'HEAD', $url );
	}

	/**
	 * Perform a GET request.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return array{response: array|WP_Error, time: int}
	 */
	public function get( string $url ): array {
		return $this->request( 'GET', $url );
	}

	/**
	 * Perform a request, validating every redirect hop before following it.
	 *
	 * The transport is told not to follow redirects at all; each hop comes back
	 * here, gets the same SSRF gate as the original URL, and is only then
	 * fetched. This is the difference between checking the URL a user wrote and
	 * checking the URL we actually connect to.
	 *
	 * DEBUG: point a test post at a URL that 302s to http://127.0.0.1/ and watch
	 * for "[yoko-link-checker] SSRF" in debug.log -- the hop must be refused.
	 *
	 * @since 1.2.0
	 * @param string $method HTTP method, HEAD or GET.
	 * @param string $url    URL to check.
	 * @return array{response: array|WP_Error, time: int}
	 */
	private function request( string $method, string $url ): array {
		$start          = microtime( true );
		$current_url    = $url;
		$redirect_count = 0;
		$response       = null;

		$args                = $this->get_request_args();
		$args['redirection'] = 0;
		$args['method']      = $method;

		do {
			$ssrf_error = $this->check_ssrf( $current_url );

			if ( $ssrf_error ) {
				return array(
					'response' => $ssrf_error,
					'time'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
				);
			}

			// Add core's validator on top of ours wherever it cannot change the
			// verdict for a legitimate link. See get_request_args().
			$args['reject_unsafe_urls'] = $this->has_standard_port( $current_url );

			$response = wp_remote_request( $current_url, $args );

			if ( is_wp_error( $response ) ) {
				break;
			}

			$location = $this->get_redirect_target( $response, $current_url );

			if ( null === $location ) {
				break;
			}

			++$redirect_count;

			if ( $redirect_count > $this->max_redirects ) {
				$response = new WP_Error(
					'ylc_too_many_redirects',
					__( 'Too many redirects.', 'yoko-link-checker' )
				);
				break;
			}

			$current_url = $location;
		} while ( true );

		$time = (int) round( ( microtime( true ) - $start ) * 1000 );

		// Following redirects by hand means the response carries no redirect
		// history, so record what we did for get_final_url()/count_redirects().
		if ( ! is_wp_error( $response ) ) {
			$response['ylc_final_url']      = $current_url;
			$response['ylc_redirect_count'] = $redirect_count;
		}

		return array(
			'response' => $response,
			'time'     => $time,
		);
	}

	/**
	 * Whether a URL uses a port wp_http_validate_url() permits.
	 *
	 * Core allows only 80, 443 and 8080 (plus the implicit default). Anywhere
	 * else, enabling reject_unsafe_urls would fail the request for a reason that
	 * has nothing to do with whether the link works.
	 *
	 * @since 1.2.0
	 * @param string $url URL to inspect.
	 * @return bool
	 */
	private function has_standard_port( string $url ): bool {
		$port = wp_parse_url( $url, PHP_URL_PORT );

		return null === $port || false === $port || in_array( (int) $port, array( 80, 443, 8080 ), true );
	}

	/**
	 * Get the absolute URL a response redirects to, if any.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $response Response array.
	 * @param string               $base_url URL the response came from.
	 * @return string|null Absolute redirect target, or null when not a redirect.
	 */
	private function get_redirect_target( array $response, string $base_url ): ?string {
		$code = $this->get_response_code( $response );

		if ( null === $code || $code < 300 || $code >= 400 ) {
			return null;
		}

		$headers  = $this->get_headers( $response );
		$location = $headers['location'] ?? '';

		if ( is_array( $location ) ) {
			$location = end( $location );
		}

		if ( '' === (string) $location ) {
			return null;
		}

		// Location may legitimately be relative; resolve it before validating.
		$absolute = \WP_Http::make_absolute_url( (string) $location, $base_url );

		return '' === $absolute ? null : $absolute;
	}

	/**
	 * Get request arguments.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public function get_request_args(): array {
		$args = array(
			'timeout'            => $this->timeout,
			'connect_timeout'    => $this->connect_timeout,
			'redirection'        => $this->max_redirects,
			'user-agent'         => $this->user_agent,
			'sslverify'          => $this->verify_ssl,
			'blocking'           => true,
			// Core's own URL validator, hardened against link-local targets in
			// WordPress 7.0.3. Our check_ssrf() is the primary gate; this is a
			// maintained second layer. Enabled per-request in request(), because
			// wp_http_validate_url() also rejects ports outside 80/443/8080 --
			// switching it on unconditionally would report a perfectly good link
			// on, say, :8443 as broken, which is a false positive in the one
			// number this plugin exists to get right.
			'reject_unsafe_urls' => false,
			'headers'            => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.5',
				'Connection'      => 'close',
			),
		);

		/**
		 * Filters HTTP request arguments.
		 *
		 * @since 1.0.0
		 * @param array $args Request arguments.
		 */
		return apply_filters( 'yoko_lc_http_request_args', $args );
	}

	/**
	 * Get default user agent.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private function get_default_user_agent(): string {
		$version = defined( 'YOKO_LC_VERSION' ) ? YOKO_LC_VERSION : '1.0.0';

		// Site owners who see this in their logs should be able to reach the site
		// making the request, so identify the site rather than a placeholder.
		return "YokoLinkChecker/{$version} (Yoko Link Checker; +" . home_url( '/' ) . ')';
	}

	/**
	 * Get response code.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed>|WP_Error $response Response.
	 * @return int|null
	 */
	public function get_response_code( $response ): ?int {
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		return $code ? (int) $code : null;
	}

	/**
	 * Get response headers.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed>|WP_Error $response Response.
	 * @return array<string, string>
	 */
	public function get_headers( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$headers = wp_remote_retrieve_headers( $response );

		if ( $headers instanceof \Requests_Utility_CaseInsensitiveDictionary || $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary ) {
			return $headers->getAll();
		}

		return is_array( $headers ) ? $headers : array();
	}

	/**
	 * Get final URL after redirects.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed>|WP_Error $response    Response.
	 * @param string                        $original_url Original URL.
	 * @return string
	 */
	public function get_final_url( $response, string $original_url ): string {
		if ( is_wp_error( $response ) ) {
			return $original_url;
		}

		// Set by request(), which follows redirects itself so it can validate
		// each hop -- authoritative when present.
		if ( ! empty( $response['ylc_final_url'] ) ) {
			return (string) $response['ylc_final_url'];
		}

		// Check for redirect history.
		$http_response = $response['http_response'] ?? null;

		if ( $http_response && method_exists( $http_response, 'get_response_object' ) ) {
			$response_obj = $http_response->get_response_object();
			if ( $response_obj && isset( $response_obj->url ) ) {
				return $response_obj->url;
			}
		}

		// Look for Location header (shouldn't be present if followed).
		$headers = $this->get_headers( $response );

		// If we got here with a redirect code, use Location.
		$code = $this->get_response_code( $response );
		if ( $code >= 300 && $code < 400 && ! empty( $headers['location'] ) ) {
			return $headers['location'];
		}

		return $original_url;
	}

	/**
	 * Count redirects from response.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed>|WP_Error $response Response.
	 * @return int
	 */
	public function count_redirects( $response ): int {
		if ( is_wp_error( $response ) ) {
			return 0;
		}

		// Set by request(); see get_final_url().
		if ( isset( $response['ylc_redirect_count'] ) ) {
			return (int) $response['ylc_redirect_count'];
		}

		$http_response = $response['http_response'] ?? null;

		if ( $http_response && method_exists( $http_response, 'get_response_object' ) ) {
			$response_obj = $http_response->get_response_object();
			if ( $response_obj && isset( $response_obj->history ) ) {
				return count( $response_obj->history );
			}
		}

		return 0;
	}

	/**
	 * Extract error type from WP_Error.
	 *
	 * @since 1.0.0
	 * @param WP_Error $error Error object.
	 * @return string
	 */
	public function get_error_type( WP_Error $error ): string {
		$code    = $error->get_error_code();
		$message = $error->get_error_message();

		// Map common error patterns.
		if ( 'http_request_failed' === $code ) {
			return self::classify_error( $message );
		}

		return $code ? $code : 'unknown_error';
	}

	/**
	 * Classify an error message string into an error type.
	 *
	 * This is a shared classification method that can be used by any component
	 * that needs to categorise HTTP error messages, including parallel request
	 * handlers that do not have a WP_Error object.
	 *
	 * @since 1.0.11
	 * @param string $message Error message to classify.
	 * @return string Error type identifier.
	 */
	public static function classify_error( string $message ): string {
		$message_lower = strtolower( $message );

		if ( str_contains( $message_lower, 'ssl' ) || str_contains( $message_lower, 'certificate' ) ) {
			return 'ssl_error';
		}
		if ( str_contains( $message_lower, 'resolve' ) || str_contains( $message_lower, 'dns' ) ) {
			return 'dns_error';
		}
		if ( str_contains( $message_lower, 'timed out' ) || str_contains( $message_lower, 'timeout' ) ) {
			return 'timeout';
		}
		if ( str_contains( $message_lower, 'connection' ) || str_contains( $message_lower, 'refused' ) ) {
			return 'connection_error';
		}

		return 'http_request_failed';
	}

	/**
	 * Validate a URL against SSRF protection rules.
	 *
	 * Public wrapper around check_ssrf() for use by other components
	 * that make HTTP requests outside of this client (e.g. parallel
	 * requests via the Requests library).
	 *
	 * @since 1.0.10
	 * @param string $url URL to validate.
	 * @return WP_Error|null WP_Error if the URL is blocked, null if allowed.
	 */
	public function validate_url_ssrf( string $url ): ?WP_Error {
		return $this->check_ssrf( $url );
	}

	/**
	 * Check if a URL is blocked by SSRF protection.
	 *
	 * Returns a WP_Error if the URL points to a private/reserved IP range
	 * and the filter does not allow it. Returns null if the request may proceed.
	 *
	 * @since 1.0.9
	 * @param string $url URL to check.
	 * @return WP_Error|null Error if blocked, null if allowed.
	 */
	private function check_ssrf( string $url ): ?WP_Error {
		/**
		 * EXTENSION POINT: filters whether to allow requests to private/reserved
		 * IP ranges.
		 *
		 * Returning true disables SSRF protection for the URL entirely, so this
		 * is a development convenience -- for sites that resolve to a private
		 * address locally -- and not something to enable in production.
		 *
		 * @since 1.0.9
		 * @param bool   $allow Whether to allow private URLs. Default false.
		 * @param string $url   The URL being checked.
		 */
		if ( apply_filters( 'yoko_lc_allow_private_urls', false, $url ) ) {
			return null;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( ! in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) {
			return $this->blocked( 'ylc_ssrf_scheme', __( 'Only http and https URLs are checked.', 'yoko-link-checker' ), $url );
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return $this->blocked( 'ylc_ssrf_no_host', __( 'URL has no host to check.', 'yoko-link-checker' ), $url );
		}

		$ip = $this->resolve_host( $host );

		if ( null === $ip ) {
			// Fail closed. Treating an unresolvable host as safe (the previous
			// behaviour) meant anything DNS refused to answer for was fetched.
			return $this->blocked( 'ylc_ssrf_unresolved', __( 'Host could not be resolved.', 'yoko-link-checker' ), $url );
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return $this->blocked( 'ylc_ssrf_blocked', __( 'Request to private/reserved IP range blocked.', 'yoko-link-checker' ), $url );
		}

		return null;
	}

	/**
	 * Build a blocked-request error and log why.
	 *
	 * @since 1.2.0
	 * @param string $code    Stable, greppable error code.
	 * @param string $message Human-readable reason.
	 * @param string $url     URL that was blocked.
	 * @return WP_Error
	 */
	private function blocked( string $code, string $message, string $url ): WP_Error {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[yoko-link-checker] SSRF %1$s blocked %2$s', $code, $url ) );
		}

		return new WP_Error( $code, $message );
	}

	/**
	 * Resolve a URL host to an IP address.
	 *
	 * Handles bracketed IPv6 literals, which previously slipped through: "[::1]"
	 * fails FILTER_VALIDATE_IP, was handed to gethostbyname() unchanged, came
	 * back unchanged, and was read as "DNS failed, allow it".
	 *
	 * @since 1.2.0
	 * @param string $host Host from the URL.
	 * @return string|null IP address, or null when it cannot be resolved.
	 */
	private function resolve_host( string $host ): ?string {
		// wp_parse_url() keeps the brackets on IPv6 literals; the filters don't want them.
		$candidate = trim( $host, '[]' );

		if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $candidate;
		}

		$now = time();

		if ( isset( self::$dns_cache[ $host ] ) && self::$dns_cache[ $host ]['expires'] > $now ) {
			// An empty cached IP is a remembered resolution failure.
			return '' === self::$dns_cache[ $host ]['ip'] ? null : self::$dns_cache[ $host ]['ip'];
		}

		$resolved = gethostbyname( $host );

		// gethostbyname() returns its argument unchanged on failure, and only
		// speaks IPv4 -- anything it hands back that isn't an IP is a failure.
		if ( $resolved === $host || ! filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
			self::$dns_cache[ $host ] = array(
				'ip'      => '',
				'expires' => $now + self::DNS_CACHE_TTL,
			);

			return null;
		}

		self::$dns_cache[ $host ] = array(
			'ip'      => $resolved,
			'expires' => $now + self::DNS_CACHE_TTL,
		);

		return $resolved;
	}
}
