<?php
/**
 * Status classification of blocked requests.
 *
 * @package YokoLinkChecker
 */

declare(strict_types=1);

namespace YokoLinkChecker\Tests\Unit\Checker;

use PHPUnit\Framework\TestCase;
use YokoLinkChecker\Checker\StatusClassifier;
use YokoLinkChecker\Model\Url;

/**
 * Blocked requests are classified by error code, not by English message text.
 */
final class StatusClassifierTest extends TestCase {

	/**
	 * @dataProvider blocked_codes
	 */
	public function test_blocked_codes_classify_as_blocked_in_any_language( string $code ): void {
		// A translated message that contains neither "blocked" nor "refused".
		$status = ( new StatusClassifier() )->classify( null, $code, 'Solicitud HTTP no ejecutada.', 'https://example.org/' );

		$this->assertSame( Url::STATUS_BLOCKED, $status );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function blocked_codes(): array {
		return array(
			'core WP_HTTP_BLOCK_EXTERNAL' => array( 'http_request_not_executed' ),
			'off-contract pre filter'     => array( 'ylc_request_preempted' ),
		);
	}
}
