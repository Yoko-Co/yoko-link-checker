<?php
/**
 * Status Classifier.
 *
 * Interprets HTTP responses and errors into meaningful status classifications.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Checker;

defined( 'ABSPATH' ) || exit;

use YokoLinkChecker\Model\Url;

/**
 * Status classification service.
 *
 * @since 1.0.0
 */
final class StatusClassifier {

	/**
	 * HTTP codes that indicate success.
	 *
	 * @var array<int>
	 */
	private const SUCCESS_CODES = array( 200, 201, 202, 203, 204, 206, 207, 208 );

	/**
	 * HTTP codes that are definitively broken.
	 *
	 * @var array<int>
	 */
	private const BROKEN_CODES = array( 404, 410, 451, 501 );

	/**
	 * HTTP codes that warrant a warning (may be temporary or false positive).
	 *
	 * @var array<int>
	 */
	private const WARNING_CODES = array( 400, 401, 403, 405, 406, 407, 408, 409, 429, 500, 502, 503, 504 );

	/**
	 * Known false-positive patterns (domain => expected behavior).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const KNOWN_QUIRKS = array(
		'linkedin.com'  => array(
			'codes'  => array( 999 ),
			'status' => 'warning',
			'reason' => 'LinkedIn blocks automated requests',
		),
		'facebook.com'  => array(
			'codes'  => array( 403 ),
			'status' => 'warning',
			'reason' => 'Facebook may block automated requests',
		),
		'instagram.com' => array(
			'codes'  => array( 403 ),
			'status' => 'warning',
			'reason' => 'Instagram may block automated requests',
		),
		'twitter.com'   => array(
			'codes'  => array( 400, 403 ),
			'status' => 'warning',
			'reason' => 'Twitter/X may block automated requests',
		),
		'x.com'         => array(
			'codes'  => array( 400, 403 ),
			'status' => 'warning',
			'reason' => 'Twitter/X may block automated requests',
		),
	);

	/**
	 * Classify a check result.
	 *
	 * @since 1.0.0
	 * @param int|null    $http_code     HTTP status code.
	 * @param string|null $error_type    Error type if error occurred.
	 * @param string|null $error_message Error message.
	 * @param string      $url           The checked URL.
	 * @param string|null $final_url     Final URL after redirects.
	 * @return string Status classification.
	 */
	public function classify(
		?int $http_code,
		?string $error_type,
		?string $error_message,
		string $url,
		?string $final_url = null
	): string {
		// Handle network-level errors first.
		if ( null !== $error_type ) {
			return $this->classify_error( $error_type, $error_message );
		}

		// No HTTP code means something went wrong.
		if ( null === $http_code ) {
			return Url::STATUS_ERROR;
		}

		// Check for known quirky sites first.
		$quirk_status = $this->check_known_quirks( $url, $http_code );
		if ( $quirk_status ) {
			return $quirk_status;
		}

		// Classify by HTTP status code.
		$status = $this->classify_http_code( $http_code );

		// If valid but URL changed, mark as redirect.
		if ( Url::STATUS_VALID === $status && $final_url && $final_url !== $url ) {
			return Url::STATUS_REDIRECT;
		}

		/**
		 * Filters the classified status.
		 *
		 * @since 1.0.0
		 * @param string      $status    Classified status.
		 * @param int|null    $http_code HTTP status code.
		 * @param string      $url       Checked URL.
		 * @param string|null $final_url Final URL after redirects.
		 */
		return apply_filters( 'yoko_lc_classify_status', $status, $http_code, $url, $final_url );
	}

	/**
	 * Classify error type into status.
	 *
	 * @since 1.0.0
	 * @param string      $error_type    Error type.
	 * @param string|null $error_message Error message.
	 * @return string Status.
	 */
	private function classify_error( string $error_type, ?string $error_message ): string {
		switch ( $error_type ) {
			case 'timeout':
				return Url::STATUS_TIMEOUT;

			case 'ssl_error':
			case 'dns_error':
			case 'connection_error':
				return Url::STATUS_ERROR;

			// http_request_not_executed is core's code for WP_HTTP_BLOCK_EXTERNAL.
			// Matched on the code because the message is translated, so the
			// "blocked" hint below would miss it on non-English sites.
			// ylc_request_preempted is HttpClient's code for a pre_http_request
			// filter that stopped a request without returning a response.
			case 'connection_refused':
			case 'blocked':
			case 'http_request_not_executed':
			case 'ylc_request_preempted':
				return Url::STATUS_BLOCKED;

			default:
				// Check message for hints.
				$message = strtolower( $error_message ?? '' );

				if ( str_contains( $message, 'timeout' ) ) {
					return Url::STATUS_TIMEOUT;
				}

				if ( str_contains( $message, 'refused' ) || str_contains( $message, 'blocked' ) ) {
					return Url::STATUS_BLOCKED;
				}

				return Url::STATUS_ERROR;
		}
	}

	/**
	 * Classify HTTP status code.
	 *
	 * @since 1.0.0
	 * @param int $code HTTP status code.
	 * @return string Status.
	 */
	private function classify_http_code( int $code ): string {
		// Success codes.
		if ( in_array( $code, self::SUCCESS_CODES, true ) ) {
			return Url::STATUS_VALID;
		}

		// 2xx range (other success).
		if ( $code >= 200 && $code < 300 ) {
			return Url::STATUS_VALID;
		}

		// 3xx redirects - if we still see them, redirects weren't fully followed.
		if ( $code >= 300 && $code < 400 ) {
			return Url::STATUS_REDIRECT;
		}

		// Definitively broken.
		if ( in_array( $code, self::BROKEN_CODES, true ) ) {
			return Url::STATUS_BROKEN;
		}

		// Warning codes (may be temporary).
		if ( in_array( $code, self::WARNING_CODES, true ) ) {
			return Url::STATUS_WARNING;
		}

		// 4xx range - generally broken.
		if ( $code >= 400 && $code < 500 ) {
			return Url::STATUS_BROKEN;
		}

		// 5xx range - server error, could be temporary.
		if ( $code >= 500 && $code < 600 ) {
			return Url::STATUS_WARNING;
		}

		// Unknown codes.
		return Url::STATUS_ERROR;
	}

	/**
	 * Check for known quirky site behaviors.
	 *
	 * @since 1.0.0
	 * @param string $url       URL to check.
	 * @param int    $http_code HTTP code received.
	 * @return string|null Status override or null.
	 */
	private function check_known_quirks( string $url, int $http_code ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return null;
		}

		$host = strtolower( $host );

		// Remove www prefix for matching.
		$host = preg_replace( '/^www\./', '', $host );

		foreach ( self::KNOWN_QUIRKS as $domain => $quirk ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				if ( in_array( $http_code, $quirk['codes'], true ) ) {
					return $quirk['status'];
				}
			}
		}

		return null;
	}
}
