<?php
/**
 * Parallel checks honour WordPress's outbound-request controls.
 *
 * @package YokoLinkChecker
 */

declare(strict_types=1);

namespace YokoLinkChecker\Tests\Unit\Checker;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Http;
use YokoLinkChecker\Checker\HttpClient;
use YokoLinkChecker\Checker\StatusClassifier;
use YokoLinkChecker\Checker\UrlChecker;
use YokoLinkChecker\Model\Url;

/**
 * Regression tests for Yoko-Co/yoko-link-checker#3: the parallel round used to
 * call Requests::request_multiple() directly, so pre_http_request and
 * WP_HTTP_BLOCK_EXTERNAL never ran for it.
 *
 * Every URL points at 127.0.0.1:1, where nothing listens. If a request were
 * actually sent it would fail as a connection error; the assertions on the
 * error type are what prove no request went out.
 *
 * The sequential side of each parity check runs core's real
 * WP_Http::request(), so the comparison is against what WordPress does, not
 * against a stand-in. Only blocking scenarios are compared: core never reaches
 * its transport in them, which is what lets it run without a full WordPress.
 */
final class UrlCheckerHttpControlsTest extends TestCase {

	private const URLS = array(
		'http://127.0.0.1:1/first',
		'http://127.0.0.1:1/second',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => $thing instanceof WP_Error
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults = array() ): array => array_merge( $defaults, (array) $args )
		);
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => $response['response']['code'] ?? ''
		);
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static fn( $response ) => $response['headers'] ?? array()
		);

		// The sequential path goes through core's real request pipeline.
		Functions\when( 'wp_remote_request' )->alias(
			static fn( $url, $args ) => ( new WP_Http() )->request( $url, $args )
		);

		// The test URLs are on loopback; let them past the SSRF gate so the
		// outbound-request controls are what decides.
		Filters\expectApplied( 'yoko_lc_allow_private_urls' )->andReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_checker(): UrlChecker {
		return new UrlChecker( new HttpClient( 2, 3, 'YokoLinkChecker/test', true, 1 ), new StatusClassifier() );
	}

	/**
	 * Check every URL on both paths and assert they agree.
	 *
	 * @param string $expected_status     Status both paths must report.
	 * @param string $expected_error_type Error type both paths must report.
	 */
	private function assert_paths_agree( string $expected_status, string $expected_error_type ): void {
		$checker  = $this->make_checker();
		$parallel = $checker->check_batch( self::URLS );

		$this->assertCount( count( self::URLS ), $parallel );

		foreach ( self::URLS as $url ) {
			$sequential = $checker->check( $url );

			$this->assertSame( $expected_status, $parallel[ $url ]->status, 'Parallel status for ' . $url );
			$this->assertSame( $expected_error_type, $parallel[ $url ]->error_type, 'Parallel error type for ' . $url . '; a real request was sent instead.' );
			$this->assertSame( $sequential->status, $parallel[ $url ]->status, 'Sequential and parallel status differ for ' . $url );
			$this->assertSame( $sequential->error_type, $parallel[ $url ]->error_type, 'Sequential and parallel error type differ for ' . $url );
		}
	}

	public function test_pre_http_request_error_stops_every_parallel_request(): void {
		$seen = array();

		Filters\expectApplied( 'pre_http_request' )->andReturnUsing(
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen[] = $args['method'] . ' ' . $url;
				return new WP_Error( 'ylc_test_blocked', 'Outbound HTTP blocked by test.' );
			}
		);

		$this->assert_paths_agree( Url::STATUS_BLOCKED, 'ylc_test_blocked' );

		foreach ( self::URLS as $url ) {
			// Blocked HEAD is retried as GET, exactly as on the sequential path.
			$this->assertContains( 'HEAD ' . $url, $seen );
			$this->assertContains( 'GET ' . $url, $seen );
		}
	}

	public function test_pre_http_request_sees_args_after_http_request_args(): void {
		// A site filter marks requests, and its guardrail only blocks marked
		// ones. Core runs http_request_args first, so it always sees the mark.
		Filters\expectApplied( 'http_request_args' )->andReturnUsing(
			static function ( $args ) {
				$args['headers']['X-Ylc-Test'] = 'deny';
				return $args;
			}
		);
		Filters\expectApplied( 'pre_http_request' )->andReturnUsing(
			static fn( $pre, $args ) => 'deny' === ( $args['headers']['X-Ylc-Test'] ?? '' )
				? new WP_Error( 'ylc_test_blocked', 'Outbound HTTP blocked by test.' )
				: false
		);

		$this->assert_paths_agree( Url::STATUS_BLOCKED, 'ylc_test_blocked' );
	}

	public function test_non_false_pre_http_request_value_is_not_fetched(): void {
		// Off-contract, but core still short-circuits on it.
		Filters\expectApplied( 'pre_http_request' )->andReturn( true );

		$this->assert_paths_agree( Url::STATUS_BLOCKED, 'ylc_request_preempted' );
	}

	public function test_pre_http_request_response_is_used_instead_of_fetching(): void {
		Filters\expectApplied( 'pre_http_request' )->andReturn(
			array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
			)
		);

		$results = $this->make_checker()->check_batch( self::URLS );

		foreach ( self::URLS as $url ) {
			$this->assertSame( Url::STATUS_BROKEN, $results[ $url ]->status );
			$this->assertSame( 404, $results[ $url ]->http_code );
		}
	}

	public function test_pre_http_request_redirect_is_followed_in_the_next_round(): void {
		$origin = 'http://127.0.0.1:1/old';
		$target = 'http://127.0.0.1:1/new';

		Filters\expectApplied( 'pre_http_request' )->andReturnUsing(
			static fn( $pre, $args, $url ) => array(
				'headers'  => $url === $origin ? array( 'location' => '/new' ) : array(),
				'body'     => '',
				'response' => array(
					'code'    => $url === $origin ? 301 : 200,
					'message' => '',
				),
			)
		);

		$result = $this->make_checker()->check_batch( array( $origin ) )[ $origin ];

		$this->assertSame( Url::STATUS_REDIRECT, $result->status );
		$this->assertSame( 200, $result->http_code );
		$this->assertSame( $target, $result->final_url );
		$this->assertSame( 1, $result->redirect_count );
	}

	public function test_blocked_and_fetched_urls_in_one_batch_keep_their_own_results(): void {
		$blocked = self::URLS[0];
		$fetched = self::URLS[1];

		Filters\expectApplied( 'pre_http_request' )->andReturnUsing(
			static fn( $pre, $args, $url ) => $url === $blocked
				? new WP_Error( 'ylc_test_blocked', 'Outbound HTTP blocked by test.' )
				: false
		);

		$results = $this->make_checker()->check_batch( self::URLS );

		$this->assertSame( 'ylc_test_blocked', $results[ $blocked ]->error_type );

		// Nothing listens on port 1, so the one real request is refused.
		$this->assertNotSame( 'ylc_test_blocked', $results[ $fetched ]->error_type );
		$this->assertNotNull( $results[ $fetched ]->error_type );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_http_block_external_blocks_parallel_requests(): void {
		define( 'WP_HTTP_BLOCK_EXTERNAL', true );

		$this->assert_paths_agree( Url::STATUS_BLOCKED, 'http_request_not_executed' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_accessible_hosts_lets_allowlisted_hosts_through(): void {
		define( 'WP_HTTP_BLOCK_EXTERNAL', true );
		define( 'WP_ACCESSIBLE_HOSTS', '127.0.0.1' );

		$results = $this->make_checker()->check_batch( self::URLS );

		foreach ( self::URLS as $url ) {
			// Fetched for real (and refused), not blocked by core.
			$this->assertNotSame( 'http_request_not_executed', $results[ $url ]->error_type );
			$this->assertNotNull( $results[ $url ]->error_type );
		}
	}
}
