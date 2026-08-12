<?php
/**
 * Results admin template.
 *
 * Renders the Reports tab: the status filter views and the links list table.
 * The filter tabs and their counts come from LinksListTable::get_views() so the
 * numbers on the tabs and the "N items" count below them share one query.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 *
 * @var string                             $status_filter Current status filter.
 * @var \YokoLinkChecker\Admin\ResultsPage $this          Results page instance.
 */

use YokoLinkChecker\Admin\AdminController;

defined( 'ABSPATH' ) || exit;

$yoko_lc_list_table = $this->get_list_table();
?>

<div class="wrap ylc-results">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Link Checker', 'yoko-link-checker' ); ?></h1>

	<?php require YOKO_LC_PLUGIN_DIR . 'templates/admin/tab-nav.php'; ?>

	<h2><?php esc_html_e( 'Reports', 'yoko-link-checker' ); ?></h2>

	<?php $yoko_lc_list_table->views(); ?>

	<form id="ylc-links-filter" method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( AdminController::MENU_SLUG ); ?>">
		<input type="hidden" name="tab" value="reports">
		<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">

		<?php
		$yoko_lc_list_table->search_box( __( 'Search URLs', 'yoko-link-checker' ), 'ylc-search' );
		$yoko_lc_list_table->display();
		?>
	</form>
</div>
