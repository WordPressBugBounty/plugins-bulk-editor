<?php
if (!defined('ABSPATH')) {
    exit;
}

global $WPBE;

$wpbe_link_allowed = array(
    'a' => array('href' => true, 'title' => true, 'target' => true, 'class' => true, 'style' => true),
    'b' => array('style' => true),
);
?>
<h4><?php esc_html_e('Help', 'bulk-editor') ?></h4>


<div class="wpbe_alert">
    <?php	
    printf(
		/* translators: 1: documentation link, 2: translations link, 3: FAQ link, 4: support link */
		esc_html__('The plugin has next info: %1$s, %2$s, %3$s. Also if you have troubles you can %4$s!', 'bulk-editor'), wp_kses(WPBE_HELPER::draw_link(array(
                'href' => 'https://bulk-editor.pro/documentation/',
                'title' => esc_html__('documentation', 'bulk-editor'),
                'target' => '_blank'
            )), $wpbe_link_allowed), wp_kses(WPBE_HELPER::draw_link(array(
                'href' => 'https://bulk-editor.pro/translations/',
                'title' => esc_html__('translations', 'bulk-editor'),
                'target' => '_blank'
            )), $wpbe_link_allowed), wp_kses(WPBE_HELPER::draw_link(array(
                'href' => 'https://bulk-editor.pro/how-to-list/',
                'title' => esc_html__('FAQ', 'bulk-editor'),
                'target' => '_blank'
            )), $wpbe_link_allowed), wp_kses(WPBE_HELPER::draw_link(array(
                'href' => 'https://pluginus.net/support/forum/wpbe-wordpress-posts-bulk-editor-professional/',
                'title' => '<b style="color: orange;">' . esc_html__('ask for support here', 'bulk-editor') . '</b>',
                'style' => 'text-decoration: none;',
                'target' => '_blank'
    )), $wpbe_link_allowed));
    ?>&nbsp;
    <?php if ($WPBE->show_notes) : ?>
        <span class="wpbe_set_attention wpbe_set_attention_diff"><?php            
            printf(
				/* translators: %s: link to versions comparison */
				esc_html__('Current version of the plugin is FREE. See the difference between FREE and PREMIUM versions %s', 'bulk-editor'), wp_kses(WPBE_HELPER::draw_link(array(
                        'href' => 'https://bulk-editor.pro/downloads/',
                        'title' => esc_html__('here', 'bulk-editor'),
                        'target' => '_blank'
            )), $wpbe_link_allowed));
            ?></span>
    <?php endif; ?>
</div>

<h4><?php esc_html_e('Some little hints', 'bulk-editor') ?>:</h4>

<ul>
    <li><span class="icon-right"></span>&nbsp;<?php esc_html_e('If to click on an empty space of the black wp-admin bar, it will get you back to the top of the page', 'bulk-editor') ?></li>


    <li><span class="icon-right"></span>&nbsp;<?php        
        printf(
			/* translators: %s: link to cyr-to-lat plugin */
			esc_html__('If your site is on the Russian language you should install %s for the correct working of WOLF with Cyrillic', 'bulk-editor'), wp_kses(WPBE_HELPER::draw_link(array(
                    'href' => 'https://ru.wordpress.org/plugins/cyr3lat/',
                    'title' => esc_html__('this plugin', 'bulk-editor'),
                    'target' => '_blank'
        )), $wpbe_link_allowed))
        ?>
    </li>


    <li><span class="icon-right"></span>&nbsp;<?php        
        printf(
			/* translators: %s: link to binded editing howto */
			esc_html__('How to set the same value for some posts on the same time - %s', 'bulk-editor'), wp_kses(WPBE_HELPER::draw_link(array(
                    'href' => 'https://bulk-editor.pro/howto/how-to-set-the-same-value-for-some-posts-on-the-same-time/',
                    'title' => esc_html__('binded editing', 'bulk-editor'),
                    'target' => '_blank'
        )), $wpbe_link_allowed))
        ?>
    </li>
    
    
     <li><span class="icon-right"></span>&nbsp;<?php        
        printf(
			/* translators: %s: link to gallery field howto */
			esc_html__('How to use - %s', 'bulk-editor'), wp_kses(WPBE_HELPER::draw_link(array(
                    'href' => 'https://bulk-editor.pro/how-to-use-field-gallery/',
                    'title' => esc_html__('meta field Gallery', 'bulk-editor'),
                    'target' => '_blank'
        )), $wpbe_link_allowed))
        ?>
    </li>

    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('Search by posts slugs, which are in non-latin symbols does not work because in the data base they are keeps in the encoded format!', 'bulk-editor') ?>
    </li>


    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('Click ID of the post in the Posts Editor to see it on the site front.', 'bulk-editor') ?>
    </li>


    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('Use Enter keyboard button in the Posts Editor for moving to the next posts row cell with saving of changes while edit textinputs. Use arrow Up or arrow Down keyboard buttons in the Posts Editor for moving to the next/previous posts row without saving of changes.', 'bulk-editor') ?>
    </li>

    <li>
        <span class="icon-right"></span>&nbsp;<?php esc_html_e('To select range of posts using checkboxes - select first post, press SHIFT key on your PC keyboard and click last post.', 'bulk-editor') ?>
    </li>

</ul>


<div class="wpbe_alert">
        <?php        
        printf(
			/* translators: %s: link to reviews page */
			esc_html__('If you like WOLF %s about what you liked and what you want to see in future versions of the plugin', 'bulk-editor'), wp_kses(WPBE_HELPER::draw_link([
                    'href' => 'https://wordpress.org/support/plugin/bulk-editor/reviews/#new-post',
                    'target' => '_blank',
                    'title' => esc_html__('write us feedback please', 'bulk-editor'),
        ]), $wpbe_link_allowed));
        ?>&nbsp;<?php        
        printf(
			/* translators: %s: link to support forum */
			esc_html__('If you have an idea you can %s', 'bulk-editor'), wp_kses(WPBE_HELPER::draw_link([
                    'href' => 'https://pluginus.net/support/forum/wpbe-wordpress-posts-bulk-editor-professional/',
                    'target' => '_blank',
                    'title' => esc_html__('share it with us here', 'bulk-editor'),
        ]), $wpbe_link_allowed));
        ?>
 
</div>

<br>

<iframe width="560" height="315" src="https://www.youtube.com/embed/xQISdAtm2KA" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>

<br>

<h4><?php esc_html_e('Requirements', 'bulk-editor') ?>:</h4>
<ul>
    <li><span class="icon-right"></span>&nbsp;<?php esc_html_e('Recommended min RAM', 'bulk-editor') ?>: 1024 MB</li>
    <li><span class="icon-right"></span>&nbsp;<?php esc_html_e('Minimal PHP version is', 'bulk-editor') ?>: PHP 7.4</li>
    <li><span class="icon-right"></span>&nbsp;<?php esc_html_e('Recommended PHP version', 'bulk-editor') ?>: 8.x.x</li>
</ul><br />

<div class="clear"></div>