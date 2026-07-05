<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

final class WPBE_HISTORY extends WPBE_EXT {

	protected $slug = 'history'; //unique
	private $table = 'wpbe_history'; //1 field key operations
	private $table_bulk = 'wpbe_history_bulk'; //bulk operations heads

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . $this->table;
		$this->table_bulk = $wpdb->prefix . $this->table_bulk;

		add_action('wpbe_ext_scripts', array($this, 'wpbe_ext_scripts'), 1);

		//ajax
		add_action('wp_ajax_wpbe_history_revert_post', array($this, 'wpbe_history_revert_post'), 1);
		add_action('wp_ajax_wpbe_history_get_bulk_count', array($this, 'wpbe_history_get_bulk_count'), 1);
		add_action('wp_ajax_wpbe_history_revert_bulk_portion', array($this, 'wpbe_history_revert_bulk_portion'), 1);
		add_action('wp_ajax_wpbe_get_history_list', array($this, 'wpbe_get_history_list'), 1);
		add_action('wp_ajax_wpbe_history_clear', array($this, 'wpbe_history_clear'), 1);
		add_action('wp_ajax_wpbe_history_delete_solo', array($this, 'wpbe_history_delete_solo'), 1);
		add_action('wp_ajax_wpbe_history_delete_bulk', array($this, 'wpbe_history_delete_bulk'), 1);

		//tabs
		$this->add_tab($this->slug, 'panel', esc_html__('History', 'bulk-editor'), 'undo');
		add_action('wpbe_ext_panel_' . $this->slug, array($this, 'wpbe_ext_panel'), 1);

		//hooks
		add_action('wpbe_bulk_started', array($this, 'start_bulk'), 10, 1);
		add_action('wpbe_bulk_going', array($this, 'count_bulked_posts'), 10, 2);
		add_action('wpbe_bulk_finished', array($this, 'finish_bulk'), 10, 1);
		add_action('wpbe_before_update_page_field', array($this, 'write'), 10, 3);
	}

	public function wpbe_ext_scripts() {
		wp_enqueue_script('wpbe_ext_' . $this->slug, $this->get_ext_link() . 'assets/js/' . $this->slug . '.js', [], WPBE_VERSION);
		wp_enqueue_style('wpbe_ext_' . $this->slug, $this->get_ext_link() . 'assets/css/' . $this->slug . '.css', [], WPBE_VERSION);
		?>
		<script>
			lang.<?php echo esc_attr($this->slug) ?> = {};
			lang.<?php echo esc_attr($this->slug) ?>.reverting = '<?php esc_html_e('Reverting', 'bulk-editor') ?> ...';
			lang.<?php echo esc_attr($this->slug) ?>.reverted = '<?php esc_html_e('Reverted!', 'bulk-editor') ?>';
			lang.<?php echo esc_attr($this->slug) ?>.wait_until_finish = '<?php esc_html_e('Wait please while data reverting is going!', 'bulk-editor') ?>';
			lang.<?php echo esc_attr($this->slug) ?>.clearing = '<?php esc_html_e('History clearing ...', 'bulk-editor') ?>';
			lang.<?php echo esc_attr($this->slug) ?>.cleared = '<?php esc_html_e('History is cleared!', 'bulk-editor') ?>';
			lang.<?php echo esc_attr($this->slug) ?>.history_is_going = "<?php esc_html_e('ATTENTION: History operation is going!', 'bulk-editor') ?>";
		</script>
		<?php
	}

	public function wpbe_ext_panel() {
		$data = array();
		$this->install_tables();
		WPBE_HELPER::render_html_e($this->get_ext_path() . 'views/panel.php', $data);
	}

	//install history tables
	private function install_tables() {

		global $wpdb;

		$checktable = $wpdb->query($wpdb->prepare("SHOW TABLES LIKE %s", $this->table));

		if ($checktable) {
			return;
		}

		//***


		$charset_collate = '';

		if (method_exists($wpdb, 'has_cap') AND $wpdb->has_cap('collation')) {
			if (!empty($wpdb->charset)) {
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if (!empty($wpdb->collate)) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}
		}

		//***

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql1 = "CREATE TABLE `{$this->table}` (
  `id` int(12) NOT NULL AUTO_INCREMENT,
  `field_key` varchar(32) NOT NULL,
  `post_id` int(11) NOT NULL,
  `prev_val` text,
  `mod_date` int(11) NOT NULL COMMENT 'modification time',
  `bulk_key` varchar(16) DEFAULT NULL COMMENT 'is changed in the bulk flow?',
  `user_id` int(11) NOT NULL,
  `post_type` varchar(32) NOT NULL DEFAULT 'post',
  PRIMARY KEY (id),
  INDEX `post_id` (`post_id`),
  INDEX `bulk_key` (`bulk_key`),
  KEY `user_id` (`user_id`)
) {$charset_collate}";

		dbDelta($sql1);

		if (!empty($wpdb->last_error)) {
			?>
			<div class="error notice">
				<p class="description"><?php esc_html_e("WOLF cannot create the database table! Make sure that your mysql user has the CREATE privilege! Do it manually using your host panel phpmyadmin!", 'bulk-editor') ?></p>
				<code><?php echo esc_html($sql1) ?></code>
				<?php
				echo esc_html($wpdb->last_error);
				?>
			</div>
			<?php
		}

		//***

		$sql2 = "CREATE TABLE `{$this->table_bulk}` (
  `id` int(12) NOT NULL AUTO_INCREMENT,
  `bulk_key` varchar(16) NOT NULL,
  `state` enum('completed','terminated') NOT NULL DEFAULT 'terminated',
  `started` int(11) DEFAULT NULL,
  `finished` int(11) DEFAULT NULL,
  `posts_count` int(11) DEFAULT '0',
  `set_of_keys` text,
  `user_id` int(11) NOT NULL,
  `post_type` varchar(32) NOT NULL DEFAULT 'post',
  PRIMARY KEY (id),
  INDEX `bulk_key` (`bulk_key`),
  KEY `user_id` (`user_id`)
) {$charset_collate}";

		dbDelta($sql2);

		if (!empty($wpdb->last_error)) {
			?>
			<div class="error notice">
				<p class="description"><?php esc_html_e("WOLF cannot create the database table! Make sure that your mysql user has the CREATE privilege! Do it manually using your host panel phpmyadmin!", 'bulk-editor') ?></p>
				<code><?php echo esc_html($sql2) ?></code>
				<?php
				echo esc_html($wpdb->last_error);
				?>
			</div>
			<?php
		}
	}

	//***

	public function get_history() {
		$history = [];
		global $wpdb;
		global $WPBE;
		$user_id = get_current_user_id();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$solo = $wpdb->get_results($wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE bulk_key IS NULL AND user_id=%d AND post_type=%s ORDER BY mod_date DESC LIMIT 2",
					$user_id,
					$WPBE->settings->current_post_type
				), ARRAY_A);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$bulk = $wpdb->get_results($wpdb->prepare(
					"SELECT * FROM {$this->table_bulk} WHERE user_id=%d AND post_type=%s ORDER BY started DESC LIMIT 2",
					$user_id,
					$WPBE->settings->current_post_type
				), ARRAY_A);

			$bulk_ids = array();
			if (!empty($bulk)) {
				foreach ($bulk as $v) {
					$bulk_ids[] = (int) $v['id'];
				}
				$placeholders = implode(',', array_fill(0, count($bulk_ids), '%d'));
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query($wpdb->prepare(
						"DELETE FROM {$this->table_bulk} WHERE user_id=%d AND id NOT IN ({$placeholders})",
						array_merge(array($user_id), $bulk_ids)
					));
			}


			$solo_ids = array();
			if (!empty($solo)) {
				foreach ($solo as $v) {
					$solo_ids[] = (int) $v['id'];
				}
				$placeholders = implode(',', array_fill(0, count($solo_ids), '%d'));
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query($wpdb->prepare(
						"DELETE FROM {$this->table} WHERE user_id=%d AND bulk_key IS NULL AND id NOT IN ({$placeholders})",
						array_merge(array($user_id), $solo_ids)
					));
			}
		

		//+++
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$solo = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE bulk_key IS NULL AND user_id=%d AND post_type=%s ORDER BY mod_date DESC",
				$user_id,
				$WPBE->settings->current_post_type
			), ARRAY_A);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bulk = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$this->table_bulk} WHERE user_id=%d AND post_type=%s ORDER BY started DESC",
				$user_id,
				$WPBE->settings->current_post_type
			), ARRAY_A);

		//***

		$time_keys = array();
		if (!empty($solo)) {
			foreach ($solo as $key => $value) {
				$time_keys[] = $value['mod_date'];
				$solo[$value['mod_date']] = $value;
				unset($solo[$key]);
			}
		}

		if (!empty($bulk)) {
			foreach ($bulk as $key => $value) {
				$time_keys[] = $value['started'];
				$bulk[$value['started']] = $value;
				unset($bulk[$key]);
			}
		}

		//***

		if (!empty($time_keys)) {
			foreach ($time_keys as $t) {
				if (isset($solo[$t])) {
					$history[$t] = $solo[$t];
				} else {
					$history[$t] = $bulk[$t];
				}
			}

			ksort($history, SORT_NUMERIC);
			$history = array_reverse($history);

				if (count($history) > 2) {
					$history = array_slice($history, 0, 2);
				}
			
		}


		return $history;
	}

	public function start_bulk($bulk_key) {
		global $wpdb;
		global $WPBE;
		$wpbe_bulk = $this->storage->get_val('wpbe_bulk_' . strtolower($bulk_key));
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert($this->table_bulk, array(
			'bulk_key' => $bulk_key,
			'started' => current_time('timestamp', FALSE),
			'set_of_keys' => !empty($wpbe_bulk['is']) ? json_encode(array_keys($wpbe_bulk['is'])) : '',
			'user_id' => get_current_user_id(),
			'post_type' => $WPBE->settings->current_post_type
		));
	}

	public function count_bulked_posts($bulk_key, $posts_count, $sign = '+') {
		global $wpdb;
		global $WPBE;
		$user_id = get_current_user_id();
		$sign = ($sign === '-') ? '-' : '+';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query($wpdb->prepare(
				"UPDATE {$this->table_bulk} SET posts_count = posts_count {$sign} %d WHERE bulk_key = %s AND user_id=%d AND post_type=%s",
				$posts_count,
				$bulk_key,
				$user_id,
				$WPBE->settings->current_post_type
			));
	}

	public function finish_bulk($bulk_key) {
		global $wpdb;
		$wpdb->update($this->table_bulk, array(
			'state' => 'completed',
			'finished' => time(),
			), array('bulk_key' => $bulk_key, 'user_id' => get_current_user_id()));
	}

	//for hook wpbe_before_update_page_field
	public function write($field_key, $post_id, $post_parent = 0) {
		global $wpdb;
		global $WPBE;

		if (empty($field_key) OR empty($post_id)) {
			return;
		}

		//fix for description of one variation
		$field_type = $this->settings->get_fields()[$field_key]['field_type'];

		$prev_val = $this->posts->get_post_field($post_id, $field_key, $post_parent);

		switch ($field_type) {
			case 'taxonomy':
				$tmp = array();
				if (!empty($prev_val)) {
					foreach ($prev_val as $t) {
						$tmp[] = $t->term_id;
					}
				} else {
					$prev_val = '';
				}
				$prev_val = json_encode($tmp);
				break;

			case 'gallery':
			case 'upsells':
				if (!empty($prev_val)) {
					$prev_val = json_encode($prev_val);
				} else {
					$prev_val = '';
				}
				break;
		}

		//for all another cases
		if (is_array($prev_val)) {
			if ($this->settings->get_fields()[$field_key]['edit_view'] == 'meta_popup_editor') {
				$prev_val = json_encode($prev_val, JSON_HEX_QUOT | JSON_HEX_TAG);
			} else {
				$prev_val = json_encode($prev_val);
			}
		}

		//***

		try {
			$wpdb->insert($this->table, array(
				'field_key' => $field_key,
				'post_id' => $post_id,
				'prev_val' => $prev_val,
				'mod_date' => current_time('timestamp', FALSE) + wp_rand(0, 30), //rand - to avoid the same unix time for different DB table rows
				'bulk_key' => isset($_REQUEST['wpbe_bulk_key']) ? $_REQUEST['wpbe_bulk_key'] : NULL,
				'user_id' => get_current_user_id(),
				'post_type' => $WPBE->settings->current_post_type
			));
		} catch (Exception $e) {
			// silent - do not output errors to the screen
		}

		//return $wpdb->insert_id;
	}

	//removing 1 row of data from the history
	private function delete($table, $id, $field = 'id') {
		global $wpdb;
		$wpdb->delete($table, array(
			$field => $id,
			'user_id' => get_current_user_id()
		));
	}

	private function revert($id) {
		global $wpdb;
		global $WPBE;

		remove_all_actions('wpbe_before_update_page_field');

		$user_id = get_current_user_id();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$solo = $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id=%d AND user_id=%d AND post_type=%s",
				$id,
				$user_id,
				$WPBE->settings->current_post_type
			), ARRAY_A);

		if (!empty($solo)) {

			switch ($this->settings->get_fields()[$solo['field_key']]['field_type']) {
				case 'taxonomy':
				case 'gallery':
				case 'upsells':
					if (!empty($solo['prev_val'])) {
						$solo['prev_val'] = json_decode($solo['prev_val']);
					} else {
						$solo['prev_val'] = NULL;
					}
					break;

				case 'meta':
					//for serialized arrays in meta fields
					if (is_string($solo['prev_val']) && is_array(json_decode($solo['prev_val'], true)) && (json_last_error() == JSON_ERROR_NONE)) {
						$solo['prev_val'] = json_decode($solo['prev_val'], true);
					}
					break;
			}

			//fix when reverting to the empty value, for example set null to calendar field as date_on_sale_from
			if (is_null($solo['prev_val'])) {
				$solo['prev_val'] = 0;
			}

			$this->posts->update_page_field($solo['post_id'], $solo['field_key'], $solo['prev_val']);
		}

		$this->delete($this->table, $id);
	}

	private function wipe_history() {
		global $wpdb;
		global $WPBE;

		$wpdb->delete($this->table, array(
			'user_id' => get_current_user_id(),
			'post_type' => $WPBE->settings->current_post_type
		));

		$wpdb->delete($this->table_bulk, array(
			'user_id' => get_current_user_id(),
			'post_type' => $WPBE->settings->current_post_type
		));
	}

	//ajax
	public function wpbe_history_revert_post() {
		if (!WPBE_HELPER::can_manage_data()) {
			wp_die('0');
		}
		if (!isset($_REQUEST['wpbe_history_nonce']) || !wp_verify_nonce($_REQUEST['wpbe_history_nonce'], 'wpbe_history_nonce')) {
			die('Forbidden!');
		}
		//***

		$this->revert(intval($_REQUEST['id']));

		exit;
	}

	//ajax
	public function wpbe_history_get_bulk_count() {

		if (!WPBE_HELPER::can_manage_data()) {
			wp_die('0');
		}

		if (!isset($_REQUEST['wpbe_history_nonce']) || !wp_verify_nonce($_REQUEST['wpbe_history_nonce'], 'wpbe_history_nonce')) {
			die('Forbidden!');
		}

		global $wpdb;
		global $WPBE;
		$user_id = get_current_user_id();
		$bulk_key = sanitize_text_field(wp_unslash($_REQUEST['bulk_key']));
		wp_die(esc_html($wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE bulk_key = %s AND user_id=%d AND post_type=%s",
					$bulk_key, $user_id, $WPBE->settings->current_post_type
				))));
	}

	//ajax
	public function wpbe_history_revert_bulk_portion() {
		if (!WPBE_HELPER::can_manage_data()) {
			wp_die('0');
		}

		global $wpdb;
		global $WPBE;

		//***

		$bulk_key = sanitize_text_field(wp_unslash($_REQUEST['bulk_key']));
		$limit = intval($_REQUEST['limit']);
		$user_id = get_current_user_id();

		$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE bulk_key=%s AND user_id=%d AND post_type=%s LIMIT %d",
				$bulk_key,
				$user_id,
				$WPBE->settings->current_post_type,
				$limit
			), ARRAY_A);

		if (!empty($rows)) {
			foreach ($rows as $r) {
				$this->revert($r['id']);
			}
		}

		//***

		$removed_count = intval($_REQUEST['removed_count']) + $limit;
		$total_count = intval($_REQUEST['total_count']);

		if (($total_count - $removed_count) <= 0) {
			$this->delete($this->table_bulk, $bulk_key, 'bulk_key');
		}

		exit;
	}

	//ajax
	public function wpbe_get_history_list() {
		if (!isset($_REQUEST['wpbe_history_nonce']) || !wp_verify_nonce($_REQUEST['wpbe_history_nonce'], 'wpbe_history_nonce')) {
			die('Forbidden!');
		}

		if (!WPBE_HELPER::can_manage_data()) {
			wp_die('0');
		}

		$data = array();
		$data['history'] = $this->get_history();
		$data['settings_fields'] = $this->settings->get_fields();
		$data['settings_fields_full'] = $this->settings->get_fields(false);
		$data['posts_obj'] = $this->posts;
		WPBE_HELPER::render_html_e($this->get_ext_path() . 'views/list.php', $data);
		exit;
	}

	//ajax
	public function wpbe_history_clear() {
		if (!isset($_REQUEST['wpbe_history_nonce']) || !wp_verify_nonce($_REQUEST['wpbe_history_nonce'], 'wpbe_history_nonce')) {
			die('Forbidden!');
		}

		if (!WPBE_HELPER::can_manage_data()) {
			wp_die('0');
		}

		$this->wipe_history();
		exit;
	}

	//ajax
	public function wpbe_history_delete_solo() {
		if (!isset($_REQUEST['wpbe_history_nonce']) || !wp_verify_nonce($_REQUEST['wpbe_history_nonce'], 'wpbe_history_nonce')) {
			die('Forbidden!');
		}

		if (!WPBE_HELPER::can_manage_data()) {
			wp_die('0');
		}

		$this->delete($this->table, intval($_REQUEST['id']));
		exit;
	}

	//ajax
	public function wpbe_history_delete_bulk() {
		if (!isset($_REQUEST['wpbe_history_nonce']) || !wp_verify_nonce($_REQUEST['wpbe_history_nonce'], 'wpbe_history_nonce')) {
			die('Forbidden!');
		}

		if (!WPBE_HELPER::can_manage_data()) {
			wp_die('0');
		}
		
		$bulk_key = sanitize_key(wp_unslash($_REQUEST['bulk_key']));
		$this->delete($this->table, $bulk_key, 'bulk_key');
		$this->delete($this->table_bulk, $bulk_key, 'bulk_key');
		exit;
	}
}
