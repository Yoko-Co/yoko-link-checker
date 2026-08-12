<?php
/**
 * Reading back the datetimes this plugin stores.
 *
 * Every datetime column the plugin writes (last_checked, started_at,
 * completed_at, first_seen, created_at, updated_at) is written with
 * current_time( 'mysql' ), which returns the time in the SITE's timezone.
 * WordPress sets PHP's default timezone to UTC, so passing those strings
 * straight to strtotime() reads site-local time as if it were UTC and lands
 * the result off by the site's UTC offset -- in the same direction, every time.
 *
 * The visible symptom was a dashboard that reported "Last scan: 4 hours ago"
 * immediately after a scan on a UTC-4 site, and 28 hours for a scan a day old.
 * Scan *duration* looked correct throughout, because subtracting two equally
 * wrong timestamps cancels the offset out.
 *
 * Anything reading a stored datetime goes through here.
 *
 * DEBUG: wp eval 'var_dump( \YokoLinkChecker\Util\StoredTime::to_timestamp( current_time( "mysql" ) ) - time() );'
 * That must print approximately 0 on a correctly configured site, whatever
 * timezone it is set to. Before this class it printed the UTC offset in seconds.
 *
 * @package YokoLinkChecker
 * @since   1.2.0
 */

declare(strict_types=1);

namespace YokoLinkChecker\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Converts the plugin's stored datetimes into real timestamps.
 *
 * @since 1.2.0
 */
final class StoredTime {

	/**
	 * Convert a stored site-local MySQL datetime to a UTC timestamp.
	 *
	 * The returned value is directly comparable with time().
	 *
	 * @since 1.2.0
	 * @param string|null $datetime Datetime as written by current_time( 'mysql' ).
	 * @return int|null UTC timestamp, or null when there is nothing usable to convert.
	 */
	public static function to_timestamp( ?string $datetime ): ?int {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return null;
		}

		// get_gmt_from_date() reads the string in the site's timezone and returns
		// the UTC equivalent, which strtotime() then parses correctly.
		$timestamp = strtotime( get_gmt_from_date( $datetime ) );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Render a stored datetime as "5 minutes ago".
	 *
	 * @since 1.2.0
	 * @param string|null $datetime Datetime as written by current_time( 'mysql' ).
	 * @return string|null Translated relative time, or null when unparseable.
	 */
	public static function time_ago( ?string $datetime ): ?string {
		$timestamp = self::to_timestamp( $datetime );

		if ( null === $timestamp ) {
			return null;
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "5 minutes" */
			__( '%s ago', 'yoko-link-checker' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * Render a stored datetime in the site's configured date and time format.
	 *
	 * @since 1.2.0
	 * @param string|null $datetime Datetime as written by current_time( 'mysql' ).
	 * @return string Formatted date, or an empty string when unparseable.
	 */
	public static function format( ?string $datetime ): string {
		$timestamp = self::to_timestamp( $datetime );

		if ( null === $timestamp ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
