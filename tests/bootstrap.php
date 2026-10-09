<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests run without a database. WordPress functions are stubbed per test
 * with Brain Monkey; the core classes the checker leans on (WP_Error, WP_Http
 * and the bundled Requests library) are loaded for real from the
 * johnpbloch/wordpress-core dev dependency, so WP_Http::block_request() under
 * test is core's own code rather than a copy of it.
 *
 * @package YokoLinkChecker
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

require_once $root . '/vendor/autoload.php';

define( 'ABSPATH', $root . '/vendor/johnpbloch/wordpress-core/' );
define( 'WPINC', 'wp-includes' );

require_once ABSPATH . WPINC . '/class-wp-error.php';
require_once ABSPATH . WPINC . '/class-wp-http.php';
