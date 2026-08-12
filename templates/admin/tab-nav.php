<?php
/**
 * Tab navigation for the Link Checker screen.
 *
 * The plugin occupies one entry under Tools, so Dashboard/Reports/Settings are
 * tabs rather than menu items. Included at the top of all three page templates.
 * Tabs the current user lacks the capability for are not rendered.
 *
 * @package YokoLinkChecker
 * @since   1.2.0
 */

use YokoLinkChecker\Admin\AdminController;

defined( 'ABSPATH' ) || exit;

$yoko_lc_active_tab = AdminController::current_tab();
?>

<nav class="nav-tab-wrapper wp-clearfix ylc-tab-nav" aria-label="<?php esc_attr_e( 'Link Checker sections', 'yoko-link-checker' ); ?>">
	<?php foreach ( AdminController::visible_tabs() as $yoko_lc_tab => $yoko_lc_label ) : ?>
		<a
			href="<?php echo esc_url( AdminController::page_url( $yoko_lc_tab ) ); ?>"
			class="nav-tab<?php echo $yoko_lc_active_tab === $yoko_lc_tab ? ' nav-tab-active' : ''; ?>"
			<?php echo $yoko_lc_active_tab === $yoko_lc_tab ? ' aria-current="page"' : ''; ?>
		>
			<?php echo esc_html( $yoko_lc_label ); ?>
		</a>
	<?php endforeach; ?>
</nav>
