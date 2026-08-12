<?php
/**
 * Check Result value object.
 *
 * Represents the result of checking a URL.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Checker;

defined( 'ABSPATH' ) || exit;

/**
 * Check result value object.
 *
 * @since 1.0.0
 */
final class CheckResult {

	/**
	 * The checked URL.
	 *
	 * @var string
	 */
	public string $url;

	/**
	 * Classified status.
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * HTTP status code (if applicable).
	 *
	 * @var int|null
	 */
	public ?int $http_code;

	/**
	 * Final URL after redirects.
	 *
	 * @var string|null
	 */
	public ?string $final_url;

	/**
	 * Number of redirects followed.
	 *
	 * @var int
	 */
	public int $redirect_count;

	/**
	 * Response time in milliseconds.
	 *
	 * @var int|null
	 */
	public ?int $response_time;

	/**
	 * Error type identifier.
	 *
	 * @var string|null
	 */
	public ?string $error_type;

	/**
	 * Error message.
	 *
	 * @var string|null
	 */
	public ?string $error_message;

	/**
	 * Response headers.
	 *
	 * @var array<string, string>
	 */
	public array $headers;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string                $url            The checked URL.
	 * @param string                $status         Classified status.
	 * @param int|null              $http_code      HTTP status code.
	 * @param string|null           $final_url      Final URL after redirects.
	 * @param int                   $redirect_count Number of redirects.
	 * @param int|null              $response_time  Response time in ms.
	 * @param string|null           $error_type     Error type identifier.
	 * @param string|null           $error_message  Error message.
	 * @param array<string, string> $headers Response headers.
	 */
	public function __construct(
		string $url,
		string $status,
		?int $http_code = null,
		?string $final_url = null,
		int $redirect_count = 0,
		?int $response_time = null,
		?string $error_type = null,
		?string $error_message = null,
		array $headers = array()
	) {
		$this->url            = $url;
		$this->status         = $status;
		$this->http_code      = $http_code;
		$this->final_url      = $final_url;
		$this->redirect_count = $redirect_count;
		$this->response_time  = $response_time;
		$this->error_type     = $error_type;
		$this->error_message  = $error_message;
		$this->headers        = $headers;
	}

	/**
	 * Copy this result, reported against a different URL.
	 *
	 * When several links redirect to the same target, that target is fetched
	 * once and its verdict reported against each link that led there -- so the
	 * batch pays for one request, not one per link.
	 *
	 * @since 1.2.0
	 * @param string $url URL to report the result against.
	 * @return self
	 */
	public function with_url( string $url ): self {
		return new self(
			$url,
			$this->status,
			$this->http_code,
			$this->final_url,
			$this->redirect_count,
			$this->response_time,
			$this->error_type,
			$this->error_message,
			$this->headers
		);
	}

	/**
	 * Create an error result.
	 *
	 * @since 1.0.0
	 * @param string   $url           Checked URL.
	 * @param string   $status        Status classification.
	 * @param string   $error_type    Error type.
	 * @param string   $error_message Error message.
	 * @param int|null $http_code     HTTP code if applicable.
	 * @param int|null $response_time Response time.
	 * @return self
	 */
	public static function error(
		string $url,
		string $status,
		string $error_type,
		string $error_message,
		?int $http_code = null,
		?int $response_time = null
	): self {
		return new self(
			$url,
			$status,
			$http_code,
			null,
			0,
			$response_time,
			$error_type,
			$error_message
		);
	}
}
