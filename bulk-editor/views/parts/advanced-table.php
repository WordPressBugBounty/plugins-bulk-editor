<?php
if (!defined('ABSPATH')) {
	exit;
}

global $WPBE;
?>


<div id="wpbe_tools_panel">

    <div class="wpbe_tools_panel_wrapper">

        <a href="#" class="button button-secondary wpbe_tools_panel_full_width_btn icon-resize-horizontal-1" title="<?php echo esc_attr__('Set full width', 'bulk-editor') ?>"></a>
        <a href="#" class="button button-secondary wpbe_tools_panel_profile_btn" title="<?php echo esc_attr__('Columns profiles', 'bulk-editor') ?>"></a>


		<?php do_action('wpbe_tools_panel_buttons') ?>

		<?php if ($WPBE->settings->current_post_type !== 'attachment'): ?>
			<a href="#" class="button button-secondary wpbe_tools_panel_newprod_btn" title="<?php echo esc_attr__('New Post', 'bulk-editor') ?>"></a>
			<a href="#" class="button button-primary wpbe_tools_panel_duplicate_btn" title="<?php echo esc_attr__('Duplicate selected post(s).', 'bulk-editor') ?>" style="display: none;"></a>
		<?php endif; ?>

        <a href="#" class="button button-primary wpbe_tools_panel_delete_btn" title="<?php echo esc_attr__('Delete selected post(s)', 'bulk-editor') ?>" style="display: none;"></a>

        <a href="#" class="button button-primary wpbe_tools_panel_uncheck_all" title="<?php echo esc_attr__('Uncheck all selected posts', 'bulk-editor') ?>" style="display: none;"></a>
        <a href="#" class="button button-secondary wpbe_filter_reset_btn2" title="<?php echo esc_attr__('Reset filters', 'bulk-editor') ?>" style="display: none;"></a>




        <span>
            <!-- next, todo -->
			<?php //echo WPBE_HELPER::draw_advanced_switcher(0, 'wpbe_show_variations', '', array('true' => esc_html__('variations', 'bulk-editor'), 'false' => esc_html__('comments', 'bulk-editor')), array('true' => 1, 'false' => 0), 'js_check_wpbe_show_variations', 'wpbe_show_variations'); ?>
			<?php //echo WPBE_HELPER::draw_tooltip(esc_html__('Bulk editing of the parent posts will be ignored!', 'bulk-editor')) ?>
        </span>&nbsp;

        <span><a href="#" id="wpbe_select_all_vars" class="button" style="display: none;"><?php echo esc_attr__('select all variations', 'bulk-editor') ?></a></span>

		<input type="hidden" id="wpbe_tools_panel_nonce" value="<?php echo esc_attr(wp_create_nonce('wpbe_tools_panel_nonce')); ?>">
		<?php do_action('wpbe_tools_panel_buttons_end') ?>

        <div style="display: none;">
            <a href="#" id="wpbe_scroll_right" class="button" title="<?php echo esc_attr__('Scroll right', 'bulk-editor') ?>" style="display: none;"></a>
            <a href="#" id="wpbe_scroll_left" class="button" title="<?php echo esc_attr__('Scroll left', 'bulk-editor') ?>" style="display: none;"></a>
        </div>

    </div>
</div>


<table id="advanced-table" data-editable="<?php echo esc_attr($table_data['editable']) ?>" data-default-sort-by="<?php echo esc_attr($table_data['default-sort-by']) ?>" data-sort="<?php echo esc_attr($table_data['sort']) ?>" data-no-order="<?php echo esc_attr($table_data['no-order']) ?>" data-additional='' data-extend_per-page="<?php echo esc_attr($table_data['extend_per-page']) ?>" data-start-page="<?php echo esc_attr($table_data['start-page']) ?>" data-per-page="<?php echo esc_attr($table_data['per-page']) ?>" data-fields="<?php echo esc_attr($table_data['fields']) ?>" data-edit-views="<?php echo esc_attr($table_data['edit_views']) ?>" data-edit-sanitize="<?php echo esc_attr($table_data['edit_sanitize']) ?>" class="display table dt-responsive table-striped table-bordered nowrap">
    <thead>
        <tr>
			<?php foreach ($table_labels as $c => $label) : ?>
				<th id="wpbe_col_<?php echo esc_attr($c) ?>">
					<?php echo wp_kses($label['title'], array(
                        'input' => array('type' => true, 'id' => true, 'class' => true, 'onclick' => true, 'value' => true, 'name' => true, 'checked' => true),
                        'label' => array('for' => true, 'class' => true),
                        'span' => array('class' => true),
                        'a' => array('href' => true, 'class' => true),
                    )); ?><?php if (!empty($label['desc']) AND $c > 0) { WPBE_HELPER::draw_tooltip($label['desc']); } ?>
				</th>
			<?php endforeach; ?>
        </tr>
    </thead>
    <tfoot>
        <tr>
			<?php foreach ($table_labels as $label) : ?>
				<th><?php echo wp_kses_post(trim($label['title'])) ?></th>
			<?php endforeach; ?>
        </tr>
    </tfoot>
    <tbody></tbody>
</table>


