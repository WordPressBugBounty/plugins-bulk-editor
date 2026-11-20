<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

final class WPBE_HISTORY extends WPBE_EXT {

	protected $slug = 'history'; //unique
	private $table = 'wpbe_history'; //1 field key operations
	private $table_bulk = 'wpbe_history_bulk'; //bulk operations heads
	// Version of the two tables. 2 added via_mcp: whether a row was written
	// through an AI agent (1) or by hand (0).
	const SCHEMA_VERSION = 2;
	const SCHEMA_OPTION = 'wpbe_history_schema';
	// The shop-wide MCP key's author id as rows before the schema upgrade
	// carry it. Literal on purpose: the MCP extension is loaded after this
	// one, so its class does not exist yet when the upgrade runs.
	const SHOP_AGENT_ID = -777;

	// install_tables() already ran in this request
	private $tables_ready = false;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . $this->table;
		$this->table_bulk = $wpdb->prefix . $this->table_bulk;

		// before any hook below can write a row with the new column
		$this->maybe_upgrade_schema();

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
			// an administrator clears everybody's history of this post type, and is told so
			lang.<?php echo esc_attr($this->slug) ?>.clear_confirm = <?php echo wp_json_encode($this->sees_everything() ? esc_html__('This clears the history of all users for this post type, including the AI agent rows. It cannot be undone. Continue?', 'bulk-editor') : esc_html__('This clears your own history for this post type. It cannot be undone. Continue?', 'bulk-editor')); ?>;
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
  `via_mcp` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'written through an AI agent?',
  PRIMARY KEY (id),
  INDEX `post_id` (`post_id`),
  INDEX `bulk_key` (`bulk_key`),
  KEY `user_id` (`user_id`)
) {$charset_collate}";

		dbDelta($sql1);

		if (!empty($wpdb->last_error)) {
			$this->table_error($sql1);
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
  `via_mcp` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'written through an AI agent?',
  PRIMARY KEY (id),
  INDEX `bulk_key` (`bulk_key`),
  KEY `user_id` (`user_id`)
) {$charset_collate}";

		dbDelta($sql2);

		if (!empty($wpdb->last_error)) {
			$this->table_error($sql2);
		}

		// created with the current definition: nothing left to upgrade
		update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, true);
	}

	// The notice install_tables() always printed. Only on the plugin screen: the
	// tables are also created by the first write of an MCP request on a fresh
	// site, and HTML in a JSON answer breaks the answer.
	private function table_error($sql) {

		if (class_exists('WPBE_MCP') && WPBE_MCP::is_request()) {
			return;
		}

		global $wpdb;
		?>
		<div class="error notice">
			<p class="description"><?php esc_html_e("WOLF cannot create the database table! Make sure that your mysql user has the CREATE privilege! Do it manually using your host panel phpmyadmin!", 'bulk-editor') ?></p>
			<code><?php echo esc_html($sql) ?></code>
			<?php
			echo esc_html($wpdb->last_error);
			?>
		</div>
		<?php
	}

	//~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// schema upgrade
	//
	// dbDelta never runs on a table that is already there (install_tables()
	// returns first), so a site that installed an older version keeps its old
	// columns. The upgrade runs once per schema version: one autoloaded option
	// read on every later load, nothing else - the owner has no channel to be
	// told about it, so it has to happen by itself on update. Each ALTER is
	// guarded by a look at the columns, so a table created with the new
	// definition, or a second request racing the first, is left alone.

	private function maybe_upgrade_schema() {

		if (intval(get_option(self::SCHEMA_OPTION, 1)) >= self::SCHEMA_VERSION) {
			return;
		}

		global $wpdb;

		$tables = array();

		foreach (array($this->table, $this->table_bulk) as $table) {

			// not created yet: install_tables() creates it with the column
			if (!$this->table_exists($table)) {
				continue;
			}

			$tables[] = $table;

			if ($this->column_exists($table, 'via_mcp')) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name only, a one-time upgrade
			if (false === $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `via_mcp` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'written through an AI agent?'")) {
				// not marked done: the next load tries again
				return;
			}
		}

		// rows from before the column: everything was by hand, except what the
		// shop-wide key's agent wrote under its own id
		foreach ($tables as $table) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
			$wpdb->query($wpdb->prepare("UPDATE `{$table}` SET via_mcp = 1 WHERE user_id = %d AND via_mcp = 0", self::SHOP_AGENT_ID));
		}

		update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, true);
	}

	private function table_exists($table) {
		global $wpdb;
		return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
	}

	private function column_exists($table, $column) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
		return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
	}

	// the tables are created when the History tab is first opened; a write that
	// comes before that (the MCP server on a fresh install) creates them itself,
	// checked once per request
	private function ensure_tables() {
		if (!$this->tables_ready) {
			$this->install_tables();
			$this->tables_ready = true;
		}
	}

	//~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// ownership of the history rows
	//
	// Every row carries its author (user_id) and whether it was written
	// through an AI agent (via_mcp). By hand, the author is the current user.
	// Through MCP it is the user behind a personal key, or the shop-wide
	// key's own negative id - never colliding with a real user.
	//
	// Who sees what is decided in one place, scope(): an administrator sees
	// every row of every author - the shop-wide key is his, so it sees
	// everything too - and anybody else only his own rows, by hand or through
	// his own agent. Every read, rollback, delete and clear below goes through
	// it, so no query can drift from the rule. The post type stays a separate
	// condition, as before: the History tab shows the type the table shows.

	// author id for the rows this request writes
	private function uid() {

		if (class_exists('WPBE_MCP') && WPBE_MCP::is_request()) {
			return WPBE_MCP::user_id();
		}

		return get_current_user_id();
	}

	// 1 when this request writes through an AI agent
	private function via_mcp() {
		return (class_exists('WPBE_MCP') && WPBE_MCP::is_request()) ? 1 : 0;
	}

	// the shop-wide key's author id, whoever is acting now
	private function mcp_uid() {
		return class_exists('WPBE_MCP') ? WPBE_MCP::shop_user_id() : self::SHOP_AGENT_ID;
	}

	/**
	 * Whether whoever is acting now sees the history of everyone: an
	 * administrator by hand or through his own personal key, or the
	 * shop-wide key, which belongs to the administrator.
	 */
	public function sees_everything() {

		if (class_exists('WPBE_MCP') && WPBE_MCP::is_request() && !WPBE_MCP_BOOT::personal_user_id()) {
			return true;
		}

		return WPBE_MCP_BOOT::is_administrator();
	}

	/**
	 * The one rule of who sees which rows, as a SQL condition and its
	 * arguments for $wpdb->prepare(). Works on both tables.
	 *
	 * @return array sql, args
	 */
	private function scope() {

		if ($this->sees_everything()) {
			return array(
				'sql' => '1=1',
				'args' => array(),
			);
		}

		return array(
			'sql' => 'user_id = %d',
			'args' => array($this->uid()),
		);
	}

	/**
	 * $wpdb->prepare() when there is something to prepare. The administrator
	 * scope has no placeholder, and prepare() refuses a query without one.
	 */
	private function prepare($sql, $args) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only, filled here
		return empty($args) ? $sql : $wpdb->prepare($sql, $args);
	}

	// Every query from here on carries the condition from scope(): fixed SQL
	// with placeholders only, filled by $wpdb->prepare() together with the
	// query's own arguments. Table and column names are the plugin's own.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	public function get_history() {
		global $wpdb;
		global $WPBE;

		$scope = $this->scope();
		$args = array_merge($scope['args'], array($WPBE->settings->current_post_type));

		if ($WPBE->show_notes) {
			$this->prune_free_build();
		}

		//+++
		$solo = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE bulk_key IS NULL AND {$scope['sql']} AND post_type=%s ORDER BY mod_date DESC, id DESC",
				$args
			), ARRAY_A);

		$bulk = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$this->table_bulk} WHERE {$scope['sql']} AND post_type=%s ORDER BY started DESC, id DESC",
				$args
			), ARRAY_A);

		// one list, newest first. Sorted rather than keyed by time: two rows
		// written in the same second - easy once an administrator sees every
		// author - must both stay in the list
		$history = array_merge((array) $solo, (array) $bulk);

		usort($history, function ($a, $b) {
			$ta = intval(isset($a['field_key']) ? $a['mod_date'] : $a['started']);
			$tb = intval(isset($b['field_key']) ? $b['mod_date'] : $b['started']);

			if ($ta !== $tb) {
				return $tb - $ta;
			}

			return intval($b['id']) - intval($a['id']);
		});

		// the free version rolls back the last two operations - of each author
		if ($WPBE->show_notes) {

			$per_author = array();

			foreach ($history as $key => $row) {

				$author = intval($row['user_id']);

				$per_author[$author] = isset($per_author[$author]) ? $per_author[$author] + 1 : 1;

				if ($per_author[$author] > 2) {
					unset($history[$key]);
				}
			}

			$history = array_values($history);
		}

		return $this->with_author_labels($history);
	}

	/**
	 * The free version keeps the last 2 solo and the last 2 bulk operations -
	 * per author, and only of the authors the current user owns: himself,
	 * and for an administrator also the shop-wide key, which is his. Opening
	 * the tab never deletes another user's rows.
	 */
	private function prune_free_build() {

		$authors = array($this->uid());

		if ($this->sees_everything()) {
			$authors[] = $this->mcp_uid();
		}

		foreach (array_unique(array_map('intval', $authors)) as $author) {
			$this->prune_author($author);
		}
	}

	// A write in the free version prunes too, after the request: an agent never
	// opens the History tab, and its rows would otherwise pile up unbounded.
	private function prune_after_request() {
		global $WPBE;

		if (empty($WPBE->show_notes)) {
			return;
		}

		if (!has_action('shutdown', array($this, 'prune_on_shutdown'))) {
			add_action('shutdown', array($this, 'prune_on_shutdown'));
		}
	}

	public function prune_on_shutdown() {
		$this->prune_author($this->uid());
	}

	// The rule WOLF always had, per author: the two newest solo rows and the two
	// newest bulk operations of the current post type stay, the author's other
	// rows go - of every post type, as before.
	private function prune_author($author) {
		global $wpdb;
		global $WPBE;

		$author = intval($author);
		$post_type = $WPBE->settings->current_post_type;

		$solo = $wpdb->get_col($wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE bulk_key IS NULL AND user_id=%d AND post_type=%s ORDER BY mod_date DESC LIMIT 2",
				$author,
				$post_type
			));

		if (!empty($solo)) {
			$keep = implode(',', array_map('intval', $solo));
			$wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE user_id=%d AND bulk_key IS NULL AND id NOT IN ({$keep})", $author));
		}

		$bulk = $wpdb->get_col($wpdb->prepare(
				"SELECT id FROM {$this->table_bulk} WHERE user_id=%d AND post_type=%s ORDER BY started DESC LIMIT 2",
				$author,
				$post_type
			));

		if (!empty($bulk)) {
			$keep = implode(',', array_map('intval', $bulk));
			$wpdb->query($wpdb->prepare("DELETE FROM {$this->table_bulk} WHERE user_id=%d AND id NOT IN ({$keep})", $author));
		}
	}

	/**
	 * Adds 'author' to every row: the display name by hand, "Name (AI agent)"
	 * through a personal key, "AI agent" for the shop-wide key, "user #ID"
	 * for a user who no longer exists. Names are read in one query for the
	 * whole list, never per row.
	 */
	private function with_author_labels($rows) {

		$ids = array();

		foreach ($rows as $row) {
			$id = intval($row['user_id']);
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}

		$names = array();

		if (!empty($ids)) {
			foreach (get_users(array(
				'include' => array_values($ids),
				'fields' => array('ID', 'display_name'),
			)) as $user) {
				$names[intval($user->ID)] = $user->display_name;
			}
		}

		foreach ($rows as $key => $row) {
			$rows[$key]['author'] = $this->author_label(intval($row['user_id']), !empty($row['via_mcp']), $names);
		}

		return $rows;
	}

	private function author_label($user_id, $via_mcp, $names) {

		if ($user_id === $this->mcp_uid()) {
			return __('AI agent', 'bulk-editor');
		}

		/* translators: %d: id of a user who no longer exists */
		$name = isset($names[$user_id]) ? $names[$user_id] : sprintf(__('user #%d', 'bulk-editor'), $user_id);

		/* translators: %s: name of the user whose AI agent made the change */
		return $via_mcp ? sprintf(__('%s (AI agent)', 'bulk-editor'), $name) : $name;
	}

	public function start_bulk($bulk_key) {
		global $wpdb;
		global $WPBE;
		$this->ensure_tables();
		$wpbe_bulk = $this->storage->get_val('wpbe_bulk_' . strtolower($bulk_key));
		$wpdb->insert($this->table_bulk, array(
			'bulk_key' => $bulk_key,
			'started' => current_time('timestamp', FALSE),
			'set_of_keys' => !empty($wpbe_bulk['is']) ? json_encode(array_keys($wpbe_bulk['is'])) : '',
			'user_id' => $this->uid(),
			'post_type' => $WPBE->settings->current_post_type,
			'via_mcp' => $this->via_mcp(),
		));

		$this->prune_after_request();
	}

	public function count_bulked_posts($bulk_key, $posts_count, $sign = '+') {
		global $wpdb;
		global $WPBE;
		$sign = ($sign === '-') ? '-' : '+';
		$wpdb->query($wpdb->prepare(
				"UPDATE {$this->table_bulk} SET posts_count = posts_count {$sign} %d WHERE bulk_key = %s AND user_id=%d AND post_type=%s",
				$posts_count,
				$bulk_key,
				$this->uid(),
				$WPBE->settings->current_post_type
			));
	}

	public function finish_bulk($bulk_key) {
		global $wpdb;
		$wpdb->update($this->table_bulk, array(
			'state' => 'completed',
			'finished' => time(),
			), array('bulk_key' => $bulk_key, 'user_id' => $this->uid()));
	}

	//for hook wpbe_before_update_page_field
	public function write($field_key, $post_id, $post_parent = 0) {
		global $wpdb;
		global $WPBE;

		if (empty($field_key) OR empty($post_id)) {
			return;
		}

		$this->ensure_tables();

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
				'user_id' => $this->uid(),
				'post_type' => $WPBE->settings->current_post_type,
				'via_mcp' => $this->via_mcp(),
			));
		} catch (Exception $e) {
			// silent - do not output errors to the screen
		}

		$this->prune_after_request();

		//return $wpdb->insert_id;
	}

	/**
	 * Whether a solo row exists within what the current user may see, for the
	 * current post type.
	 */
	private function solo_in_scope($id) {
		global $wpdb;
		global $WPBE;

		$scope = $this->scope();

		return (bool) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE id = %d AND {$scope['sql']} AND post_type=%s",
					array_merge(array(intval($id)), $scope['args'], array($WPBE->settings->current_post_type))
		));
	}

	/**
	 * Whether a bulk operation - its rows or its head - exists within what
	 * the current user may see, for the current post type.
	 */
	private function bulk_in_scope($bulk_key) {
		global $wpdb;
		global $WPBE;

		$scope = $this->scope();
		$args = array_merge(array($bulk_key), $scope['args'], array($WPBE->settings->current_post_type));

		if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_bulk} WHERE bulk_key = %s AND {$scope['sql']} AND post_type=%s", $args))) {
			return true;
		}

		return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']} AND post_type=%s", $args));
	}

	/**
	 * Server side refusal of a row or operation outside the user's scope.
	 * The buttons are only drawn for rows he can see; this is what stops a
	 * request built by hand.
	 */
	private function refuse() {
		wp_send_json_error(
			array('message' => esc_html__('This history entry is not yours. Only an administrator can roll back or delete the changes of other users.', 'bulk-editor')),
			403
		);
	}

	//removing 1 row of data from the history
	private function delete($table, $id, $field = 'id') {
		global $wpdb;

		// $field comes from internal calls only, but it is interpolated into the
		// statement, so it stays whitelisted rather than trusted
		$field = in_array($field, array('id', 'bulk_key'), true) ? $field : 'id';
		$scope = $this->scope();

		$wpdb->query($wpdb->prepare(
				"DELETE FROM {$table} WHERE {$field} = %s AND {$scope['sql']}",
				array_merge(array($id), $scope['args'])
		));
	}

	private function revert($id) {
		global $wpdb;
		global $WPBE;

		remove_all_actions('wpbe_before_update_page_field');

		$scope = $this->scope();
		$solo = $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id=%d AND {$scope['sql']} AND post_type=%s",
				array_merge(array(intval($id)), $scope['args'], array($WPBE->settings->current_post_type))
			), ARRAY_A);

		if (!empty($solo)) {

			// a field this user can no longer see is not in his list; the
			// write below refuses it as before, without a notice on the way
			$fields = $this->settings->get_fields();
			$field_type = isset($fields[$solo['field_key']]['field_type']) ? $fields[$solo['field_key']]['field_type'] : '';

			switch ($field_type) {
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

		// exactly what this user can see of this post type: everybody's rows for
		// an administrator, his own rows for anybody else
		$scope = $this->scope();
		$args = array_merge($scope['args'], array($WPBE->settings->current_post_type));

		$wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE {$scope['sql']} AND post_type=%s", $args));
		$wpdb->query($wpdb->prepare("DELETE FROM {$this->table_bulk} WHERE {$scope['sql']} AND post_type=%s", $args));
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

		$id = intval($_REQUEST['id']);

		if (!$this->solo_in_scope($id)) {
			$this->refuse();
		}

		$this->revert($id);

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
		$bulk_key = sanitize_text_field(wp_unslash($_REQUEST['bulk_key']));

		if (!$this->bulk_in_scope($bulk_key)) {
			$this->refuse();
		}

		$scope = $this->scope();
		wp_die(esc_html($wpdb->get_var($wpdb->prepare(
						"SELECT COUNT(*) FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']} AND post_type=%s",
						array_merge(array($bulk_key), $scope['args'], array($WPBE->settings->current_post_type))
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

		if (!$this->bulk_in_scope($bulk_key)) {
			$this->refuse();
		}

		$scope = $this->scope();
		$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE bulk_key=%s AND {$scope['sql']} AND post_type=%s LIMIT %d",
				array_merge(array($bulk_key), $scope['args'], array($WPBE->settings->current_post_type, $limit))
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

		$id = intval($_REQUEST['id']);

		if (!$this->solo_in_scope($id)) {
			$this->refuse();
		}

		$this->delete($this->table, $id);
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

		if (!$this->bulk_in_scope($bulk_key)) {
			$this->refuse();
		}

		$this->delete($this->table, $bulk_key, 'bulk_key');
		$this->delete($this->table_bulk, $bulk_key, 'bulk_key');
		exit;
	}

	//~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// public entry points for the MCP extension: the ajax handlers above
	// cannot be reused because they verify a nonce that a REST request never
	// has. The same scope applies - to the acting identity of the request.
	// The post type of an operation is its own (the head row's), not the
	// current one: an agent rolls back what it changed, whichever type it was.

	/**
	 * The latest bulk operations the acting identity may see, each with its
	 * author label.
	 *
	 * @param int    $limit
	 * @param string $post_type '' for every type
	 */
	public function bulk_operations($limit = 20, $post_type = '') {
		global $wpdb;

		$this->ensure_tables();

		$scope = $this->scope();
		$sql = $scope['sql'];
		$args = $scope['args'];

		if ('' !== $post_type) {
			$sql .= ' AND post_type = %s';
			$args[] = $post_type;
		}

		$args[] = max(1, intval($limit));

		$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$this->table_bulk} WHERE {$sql} ORDER BY started DESC, id DESC LIMIT %d",
				$args
			), ARRAY_A);

		return $this->with_author_labels((array) $rows);
	}

	/**
	 * The head row of a bulk operation within the scope, or null. Its
	 * post_type is the type the operation ran on.
	 */
	public function bulk_head($bulk_key) {
		global $wpdb;

		$this->ensure_tables();

		$scope = $this->scope();

		$row = $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM {$this->table_bulk} WHERE bulk_key = %s AND {$scope['sql']} ORDER BY id DESC LIMIT 1",
				array_merge(array($bulk_key), $scope['args'])
			), ARRAY_A);

		return empty($row) ? null : $row;
	}

	/**
	 * The post type the rows of a bulk operation were written under - the
	 * head's, or the rows' own when the head is gone (pruned, or deleted).
	 */
	public function bulk_post_type($bulk_key) {
		global $wpdb;

		$head = $this->bulk_head($bulk_key);

		if ($head) {
			return (string) $head['post_type'];
		}

		$scope = $this->scope();

		return (string) $wpdb->get_var($wpdb->prepare(
					"SELECT post_type FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']} LIMIT 1",
					array_merge(array($bulk_key), $scope['args'])
		));
	}

	/**
	 * How many revertible rows of a bulk operation the acting identity may
	 * roll back.
	 */
	public function count_bulk_rows($bulk_key) {
		global $wpdb;

		$this->ensure_tables();

		$scope = $this->scope();

		return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']}", array_merge(array($bulk_key), $scope['args']))));
	}

	/**
	 * The posts a bulk operation touched, within the same scope.
	 *
	 * @return int[]
	 */
	public function bulk_post_ids($bulk_key) {
		global $wpdb;

		$scope = $this->scope();

		return array_map('intval', (array) $wpdb->get_col(
				$wpdb->prepare("SELECT DISTINCT post_id FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']}", array_merge(array($bulk_key), $scope['args']))
		));
	}

	/**
	 * Reverts up to $limit rows of a bulk operation within the scope, and
	 * removes its head once nothing is left to revert - as the History tab
	 * does, so the operation does not linger in the list as an empty entry.
	 * The caller has switched the settings to the operation's post type
	 * (bulk_post_type()): revert() reads the rows of the current type.
	 *
	 * @return int rows reverted
	 */
	public function revert_bulk_portion($bulk_key, $limit = 200) {
		global $wpdb;
		global $WPBE;

		$scope = $this->scope();

		$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE bulk_key = %s AND {$scope['sql']} AND post_type = %s LIMIT %d",
				array_merge(array($bulk_key), $scope['args'], array($WPBE->settings->current_post_type, intval($limit)))
			), ARRAY_A);

		$before = $this->count_bulk_rows($bulk_key);

		foreach ((array) $rows as $r) {
			$this->revert($r['id']);
		}

		$n = $before - $this->count_bulk_rows($bulk_key);

		if ($n > 0 && 0 === $this->count_bulk_rows($bulk_key)) {
			$this->delete($this->table_bulk, $bulk_key, 'bulk_key');
		}

		return $n;
	}

	// phpcs:enable
}
