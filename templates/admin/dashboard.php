<?php
/**
 * Dashboard admin template.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 *
 * @var array                                    $stats           Link statistics.
 * @var array|null                               $scan_status     Current scan status.
 * @var array                                    $recent_broken   Recent broken links.
 * @var array                                    $status_breakdown Status breakdown data.
 * @var \YokoLinkChecker\Admin\DashboardPage     $this            Dashboard page instance.
 */

use YokoLinkChecker\Admin\AdminController;
use YokoLinkChecker\Util\StoredTime;

defined( 'ABSPATH' ) || exit;

$yoko_lc_is_scanning = $scan_status && 'running' === $scan_status['status'];
?>

<div class="wrap ylc-dashboard">
	<h1><?php esc_html_e( 'Yoko Link Checker', 'yoko-link-checker' ); ?></h1>

	<?php require YOKO_LC_PLUGIN_DIR . 'templates/admin/tab-nav.php'; ?>

	<!-- Scan Control Section -->
	<div class="ylc-card ylc-scan-control">
		<h2><?php esc_html_e( 'Scan', 'yoko-link-checker' ); ?></h2>
		
		<div class="ylc-notice ylc-notice-info">
			<span class="dashicons dashicons-info"></span>
			<span class="ylc-notice-content">
				<?php esc_html_e( 'Please keep this page open while the scan is running. Navigating away will pause the scan.', 'yoko-link-checker' ); ?>
			</span>
		</div>
		
		<div class="ylc-scan-status" id="ylc-scan-status">
			<?php if ( $yoko_lc_is_scanning ) : ?>
				<div class="ylc-scanning">
					<span class="spinner is-active"></span>
					<span class="ylc-scan-phase">
						<?php
						printf(
							/* translators: %s: scan phase name */
							esc_html__( 'Phase: %s', 'yoko-link-checker' ),
							esc_html( ucfirst( $scan_status['phase'] ) )
						);
						?>
					</span>
					<div class="ylc-progress-bar">
						<div class="ylc-progress-fill" style="width: <?php echo esc_attr( $scan_status['progress'] ); ?>%"></div>
					</div>
					<span class="ylc-progress-text"><?php echo esc_html( round( $scan_status['progress'], 1 ) ); ?>%</span>
				</div>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Last scan:', 'yoko-link-checker' ); ?>
					<strong><?php echo esc_html( $this->format_last_scan( $stats['last_scan'] ) ); ?></strong>
					<?php if ( $stats['last_scan'] ) : ?>
						(<?php echo esc_html( $this->format_scan_duration( $stats['last_scan'] ) ); ?>)
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="ylc-scan-actions">
			<?php if ( $yoko_lc_is_scanning ) : ?>
				<button type="button" class="button ylc-pause-scan" data-scan-id="<?php echo esc_attr( $scan_status['scan_id'] ); ?>">
					<?php esc_html_e( 'Pause', 'yoko-link-checker' ); ?>
				</button>
				<button type="button" class="button ylc-cancel-scan" data-scan-id="<?php echo esc_attr( $scan_status['scan_id'] ); ?>">
					<?php esc_html_e( 'Cancel', 'yoko-link-checker' ); ?>
				</button>
			<?php elseif ( $scan_status && 'paused' === $scan_status['status'] ) : ?>
				<button type="button" class="button button-primary ylc-resume-scan" data-scan-id="<?php echo esc_attr( $scan_status['scan_id'] ); ?>">
					<?php esc_html_e( 'Resume Scan', 'yoko-link-checker' ); ?>
				</button>
				<button type="button" class="button ylc-cancel-scan" data-scan-id="<?php echo esc_attr( $scan_status['scan_id'] ); ?>">
					<?php esc_html_e( 'Cancel', 'yoko-link-checker' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="button button-primary ylc-start-scan">
					<?php esc_html_e( 'Start New Scan', 'yoko-link-checker' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<!--
		Stats Grid. Each card leads with unique URLs and states the link count
		underneath: one broken URL used in twelve posts is 1 URL and 12 links,
		and the Reports tab counts the latter. Saying both is what keeps the two
		screens from looking like they disagree.
	-->
	<div class="ylc-stats-grid">
		<div class="ylc-stat-card ylc-stat-total">
			<div class="ylc-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_urls'] ) ); ?></div>
			<div class="ylc-stat-label"><?php esc_html_e( 'Total URLs', 'yoko-link-checker' ); ?></div>
			<div class="ylc-stat-sub">
				<?php
				printf(
					/* translators: %s: number of link occurrences */
					esc_html( _n( 'across %s link', 'across %s links', $stats['total_links'], 'yoko-link-checker' ) ),
					esc_html( number_format_i18n( $stats['total_links'] ) )
				);
				?>
			</div>
		</div>

		<?php foreach ( $stats['cards'] as $yoko_lc_key => $yoko_lc_card ) : ?>
			<div class="ylc-stat-card ylc-stat-<?php echo esc_attr( str_replace( '_', '-', $yoko_lc_key ) ); ?>">
				<div class="ylc-stat-number"><?php echo esc_html( number_format_i18n( $yoko_lc_card['urls'] ) ); ?></div>
				<div class="ylc-stat-label"><?php echo esc_html( $yoko_lc_card['label'] ); ?></div>
				<div class="ylc-stat-sub">
					<?php
					printf(
						/* translators: %s: number of link occurrences */
						esc_html( _n( 'across %s link', 'across %s links', $yoko_lc_card['links'], 'yoko-link-checker' ) ),
						esc_html( number_format_i18n( $yoko_lc_card['links'] ) )
					);
					?>
				</div>

				<?php if ( ! empty( $yoko_lc_card['parts'] ) ) : ?>
					<div class="ylc-stat-parts">
						<?php foreach ( $yoko_lc_card['parts'] as $yoko_lc_part ) : ?>
							<?php if ( $yoko_lc_part['urls'] > 0 ) : ?>
								<a href="<?php echo esc_url( AdminController::page_url( 'reports', array( 'status' => $yoko_lc_part['status'] ) ) ); ?>">
									<?php echo esc_html( $yoko_lc_part['label'] ); ?>
									<?php echo esc_html( number_format_i18n( $yoko_lc_part['urls'] ) ); ?>
								</a>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $yoko_lc_card['urls'] > 0 && null !== $yoko_lc_card['status'] ) : ?>
					<a href="<?php echo esc_url( AdminController::page_url( 'reports', array( 'status' => $yoko_lc_card['status'] ) ) ); ?>" class="ylc-stat-link">
						<?php esc_html_e( 'View all', 'yoko-link-checker' ); ?> →
					</a>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $stats['ignored_urls'] > 0 ) : ?>
		<p class="ylc-ignored-note description">
			<?php
			printf(
				/* translators: %s: number of ignored URLs */
				esc_html( _n( '%s URL is ignored and excluded from the counts above.', '%s URLs are ignored and excluded from the counts above.', $stats['ignored_urls'], 'yoko-link-checker' ) ),
				esc_html( number_format_i18n( $stats['ignored_urls'] ) )
			);
			?>
			<a href="<?php echo esc_url( AdminController::page_url( 'reports', array( 'ignored' => '1' ) ) ); ?>">
				<?php esc_html_e( 'View ignored URLs', 'yoko-link-checker' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<!-- Recent Broken Links -->
	<?php if ( ! empty( $recent_broken ) ) : ?>
	<div class="ylc-card ylc-recent-broken">
		<h2>
			<?php esc_html_e( 'Recent Broken Links', 'yoko-link-checker' ); ?>
			<a href="<?php echo esc_url( AdminController::page_url( 'reports', array( 'status' => 'broken' ) ) ); ?>" class="ylc-view-all">
				<?php esc_html_e( 'View all', 'yoko-link-checker' ); ?> →
			</a>
		</h2>
		
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'yoko-link-checker' ); ?></th>
					<th><?php esc_html_e( 'Code', 'yoko-link-checker' ); ?></th>
					<th><?php esc_html_e( 'Found in', 'yoko-link-checker' ); ?></th>
					<th><?php esc_html_e( 'Source', 'yoko-link-checker' ); ?></th>
					<th><?php esc_html_e( 'Last Checked', 'yoko-link-checker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $recent_broken as $yoko_lc_link ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( $yoko_lc_link['url'] ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $yoko_lc_link['url'] ); ?>">
							<?php echo esc_html( wp_trim_words( $yoko_lc_link['url'], 8, '...' ) ); ?>
						</a>
					</td>
					<td>
						<span class="ylc-code ylc-code-client-error"><?php echo esc_html( $yoko_lc_link['http_code'] ? $yoko_lc_link['http_code'] : '—' ); ?></span>
					</td>
					<td>
						<?php
						printf(
							/* translators: %s: number of places the URL is linked from */
							esc_html( _n( '%s place', '%s places', $yoko_lc_link['occurrences'], 'yoko-link-checker' ) ),
							esc_html( number_format_i18n( $yoko_lc_link['occurrences'] ) )
						);
						?>
					</td>
					<td>
						<?php if ( $yoko_lc_link['source_id'] && $yoko_lc_link['post_title'] ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( $yoko_lc_link['source_id'] ) ); ?>">
								<?php echo esc_html( wp_trim_words( $yoko_lc_link['post_title'], 5, '...' ) ); ?>
							</a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td>
						<?php
						$yoko_lc_time_ago = StoredTime::time_ago( $yoko_lc_link['last_checked'] );

						if ( null !== $yoko_lc_time_ago ) {
							echo esc_html( $yoko_lc_time_ago );
						} elseif ( empty( $yoko_lc_link['last_checked'] ) ) {
							esc_html_e( 'Never', 'yoko-link-checker' );
						} else {
							esc_html_e( 'Unknown', 'yoko-link-checker' );
						}
						?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php elseif ( $stats['total_urls'] > 0 ) : ?>
	<div class="ylc-card ylc-no-broken">
		<p class="ylc-success-message">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'No broken links found! Your site is in good shape.', 'yoko-link-checker' ); ?>
		</p>
	</div>
	<?php else : ?>
	<div class="ylc-card ylc-no-data">
		<p>
			<?php esc_html_e( 'No links have been scanned yet. Start a scan to check your site for broken links.', 'yoko-link-checker' ); ?>
		</p>
	</div>
	<?php endif; ?>

</div>
