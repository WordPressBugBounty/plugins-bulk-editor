<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

//as an example for new kind of data field
global $WPBE;

$title = '';
if ($post_id > 0) {
    $post = $WPBE->posts->get_post($post_id);
    $ids = $post->get_upsell_ids();
    $title = $post->get_title();
}

$files_count = count($ids);

if (empty($ids)) {
    ?>
    <div class="wpbe-button" 
		 onclick="wpbe_act_upsells_editor(this)" 
		 id="upsell_ids_<?php echo esc_attr($field_key) ?>_<?php echo esc_attr($post_id) ?>" 
		 data-count="0" 
		 data-post_id="<?php echo esc_html($post_id) ?>" 
		 data-key="<?php echo esc_html($field_key) ?>" 
		 data-terms_ids="" 
		 data-name="<?php echo sprintf(esc_html__('Post: %s', 'bulk-editor'), esc_attr($title)) ?>">
        <?php printf(esc_html__('Posts (%s)', 'bulk-editor'), esc_attr($files_count)) ?>
    </div>
    <?php
} else {
    ?>
    <div class="popup_val_in_tbl wpbe-button" 
		 onclick="wpbe_act_upsells_editor(this)" 
		 id="upsell_ids_<?php echo esc_attr($field_key) ?>_<?php echo esc_attr($post_id) ?>" 
		 data-count="<?php echo esc_html($files_count) ?>" 
		 data-post_id="<?php echo esc_html($post_id) ?>" 
		 data-key="<?php echo esc_html($field_key) ?>" 
		 data-terms_ids="" 
		 data-name="<?php echo sprintf(esc_html__('Post: %s', 'bulk-editor'), esc_attr($title)) ?>">
        <ul>
            <?php foreach ($ids as $prod_id): ?>

                <?php
                $p = $WPBE->posts->get_post($prod_id);

                if (!is_object($p)) {
                    continue;
                }

                $li_data = array(
                    'id' => $prod_id,
                    'title' => $p->get_title(),
                    'link' => $p->get_permalink()
                );

                if (has_post_thumbnail($prod_id)) {
                    $img_src = wp_get_attachment_image_src(get_post_thumbnail_id($prod_id), 'thumbnail');
                    $li_data['thumb'] = $img_src[0];
                } else {
                    $li_data['thumb'] = WPBE_ASSETS_LINK . 'images/not-found.jpg';
                }
                ?>

                <li class="wpbe_li_tag" data-post='<?php echo json_encode($li_data) ?>'>
					#<?php echo esc_attr($prod_id) ?>.<?php echo esc_html($p->get_title()) ?>
				</li>
                <?php endforeach; ?>
        </ul>
    </div>
    <?php
}




