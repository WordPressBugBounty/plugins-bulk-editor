<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Personal MCP access.
 *
 * The site-wide key belongs to the administrator and keeps working exactly as
 * before. On top of it the administrator may grant MCP access to named users;
 * each of them gets a personal key and connects as himself, with his own
 * role, his own field visibility and his own two-factor connection.
 *
 * This file holds everything a person sees and clicks for that: the grant
 * list with its user search and status lines (administrators only), the
 * personal block in a granted user's own settings, the AJAX handlers behind
 * the buttons, and the two option hooks that keep the grant list clean and
 * forget the key of anyone taken off it. Authentication itself lives in
 * WPBE_MCP_BOOT::permission().
 */
final class WPBE_MCP_ACCESS {

	const NONCE = 'wpbe_mcp_access';

	// how many users one search may return
	const SEARCH_LIMIT = 20;

	public static function init() {

		// the settings column prints this after its own settings
		add_action( 'wpbe_general_settings_after', array( __CLASS__, 'render' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'wp_ajax_wpbe_mcp_search_users', array( __CLASS__, 'ajax_search_users' ) );
		add_action( 'wp_ajax_wpbe_mcp_drop_user_connection', array( __CLASS__, 'ajax_drop_user_connection' ) );
		add_action( 'wp_ajax_wpbe_mcp_regenerate_key', array( __CLASS__, 'ajax_regenerate_key' ) );
		add_action( 'wp_ajax_wpbe_mcp_confirm_personal', array( __CLASS__, 'ajax_confirm_personal' ) );
		add_action( 'wp_ajax_wpbe_mcp_drop_personal', array( __CLASS__, 'ajax_drop_personal' ) );

		// the grant list is saved with the rest of the global options
		add_filter( 'pre_update_option_wpbe_options_global', array( __CLASS__, 'sanitize_global' ) );
		add_action( 'update_option_wpbe_options_global', array( __CLASS__, 'after_global_update' ), 10, 2 );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// saving the grant list

	/**
	 * A list of existing user ids, whatever shape it arrived in: the form
	 * posts an array of strings, and an emptied select posts nothing at all,
	 * which the settings model stores as an empty string.
	 *
	 * @return int[]
	 */
	public static function clean_ids( $raw ) {

		if ( ! is_array( $raw ) ) {
			$raw = ( '' === trim( (string) $raw ) ) ? array() : explode( ',', (string) $raw );
		}

		$ids = array();

		foreach ( $raw as $id ) {

			$id = absint( $id );

			if ( $id && get_userdata( $id ) ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * pre_update_option_wpbe_options_global: the grant list is stored as a
	 * clean array of user ids, never as whatever the form posted.
	 */
	public static function sanitize_global( $value ) {

		if ( is_array( $value ) && array_key_exists( 'mcp_users', $value ) ) {
			$value['mcp_users'] = self::clean_ids( $value['mcp_users'] );
		}

		return $value;
	}

	/**
	 * update_option_wpbe_options_global: a user taken off the grant list
	 * loses his key and his connection on the same save. A value written
	 * without the list at all changes nothing, so no other writer of the
	 * global option can revoke anybody by accident.
	 */
	public static function after_global_update( $old_value, $value ) {

		if ( ! is_array( $value ) || ! array_key_exists( 'mcp_users', $value ) ) {
			return;
		}

		$before = self::clean_ids( is_array( $old_value ) && isset( $old_value['mcp_users'] ) ? $old_value['mcp_users'] : array() );
		$after  = self::clean_ids( $value['mcp_users'] );

		foreach ( array_diff( $before, $after ) as $user_id ) {
			WPBE_MCP_BOOT::forget_personal_access( $user_id );
		}
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// settings screen

	/**
	 * The script behind the grant list and the personal block, on the plugin
	 * screen for anyone who can open it. The nonce alone opens nothing: every
	 * handler checks who is asking.
	 */
	public static function enqueue() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen we are on, nothing is changed
		if ( ! isset( $_GET['page'] ) || 'wpbe' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! WPBE_HELPER::can_manage_data() ) {
			return;
		}

		wp_enqueue_script(
			'wpbe-mcp-access',
			WPBE_LINK . 'ext/mcp/assets/access.js',
			array( 'jquery' ),
			WPBE_VERSION,
			true
		);

		wp_localize_script(
			'wpbe-mcp-access',
			'wpbeMcpAccess',
			array(
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'placeholder' => esc_html__( 'Type part of a name, login or e-mail', 'bulk-editor' ),
				'noResults'   => esc_html__( 'No matching user who can work with WOLF', 'bulk-editor' ),
				'working'     => esc_html__( 'Working...', 'bulk-editor' ),
				'disconnect'  => esc_html__( 'Disconnect', 'bulk-editor' ),
				'regenerate'  => esc_html__( 'Issue a new key? The old key stops working at once and the assistant using it is disconnected.', 'bulk-editor' ),
				'failed'      => esc_html__( 'Something went wrong. Reload the page and try again.', 'bulk-editor' ),
			)
		);
	}

	/**
	 * Printed at the end of the general settings column: the grant list for
	 * an administrator, the personal block for a granted user. Anybody else
	 * sees nothing new.
	 */
	public static function render() {

		if ( ! WPBE_HELPER::can_manage_data() ) {
			return;
		}

		if ( WPBE_MCP_BOOT::is_administrator() ) {
			self::render_grant_list();
		}

		$me = get_current_user_id();

		if ( WPBE_MCP_BOOT::may_use_personal_key( $me ) ) {
			self::render_personal_block( $me );
		}
	}

	/**
	 * How a user is named in the grant list: display name, then login and
	 * e-mail so two people with the same name can be told apart.
	 */
	private static function user_label( $user ) {
		return sprintf( '%1$s (%2$s, %3$s)', $user->display_name, $user->user_login, $user->user_email );
	}

	/**
	 * One status line of the administrator overview, as plain text.
	 */
	private static function user_state_text( $user_id ) {

		if ( ! WPBE_MCP_BOOT::may_use_personal_key( $user_id ) ) {
			return __( 'cannot connect: this user can no longer work with WOLF', 'bulk-editor' );
		}

		if ( '' === WPBE_MCP_BOOT::personal_key( $user_id ) ) {
			return __( 'no key yet - it is issued when the user opens his settings', 'bulk-editor' );
		}

		$state = WPBE_MCP_BOOT::personal_state( $user_id );

		return $state['text'];
	}

	private static function render_grant_list() {

		$granted = WPBE_MCP_BOOT::granted_users();
		$users   = empty( $granted ) ? array() : get_users(
			array(
				'include' => $granted,
				'orderby' => 'display_name',
			)
		);
		?>
		<div class="wpbe-control-section wpbe-mcp-access-admin wpbe-mcp-when-on"<?php echo WPBE_MCP_BOOT::hidden_while_off(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute ?>>
			<h5><?php esc_html_e( 'MCP access for users', 'bulk-editor' ); ?></h5>
			<div class="wpbe-control-container">
				<div class="wpbe-control">

					<select multiple="multiple" id="wpbe_mcp_users" name="wpbe_options_global[mcp_users][]" data-placeholder="<?php esc_attr_e( 'Type part of a name, login or e-mail', 'bulk-editor' ); ?>" style="width: 100%;">
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( $user->ID ); ?>" selected="selected"><?php echo esc_html( self::user_label( $user ) ); ?></option>
						<?php endforeach; ?>
					</select>

					<?php if ( ! empty( $users ) ) : ?>
						<ul class="wpbe-mcp-users-status" style="margin: 8px 0 0; font-size: 13px;">
							<?php foreach ( $users as $user ) : ?>
								<?php $wpbe_alive = null !== WPBE_MCP_BOOT::live_connection( $user->ID ); ?>
								<li class="wpbe-mcp-user-line" data-user="<?php echo esc_attr( $user->ID ); ?>" style="margin: 0 0 4px;">
									<b><?php echo esc_html( $user->display_name ); ?></b> (<?php echo esc_html( $user->user_login ); ?>):
									<span class="wpbe-mcp-state-text"><?php echo esc_html( self::user_state_text( $user->ID ) ); ?></span>
									<?php if ( $wpbe_alive ) : ?>
										<button type="button" class="button button-small wpbe-mcp-user-drop" data-user="<?php echo esc_attr( $user->ID ); ?>"><?php esc_html_e( 'Disconnect', 'bulk-editor' ); ?></button>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<div class="wpbe-underfield" style="margin-top: 6px; font-size: 10px;">
						<?php esc_html_e( 'Save the settings after changing the list. A user taken off the list loses his key and his connection at once.', 'bulk-editor' ); ?>
					</div>
				</div>
				<div class="wpbe-description">
					<p class="description">
						<?php esc_html_e( 'Users picked here get a personal MCP key in their own settings and connect an AI assistant under their own WordPress account: the assistant can do exactly what they can do by hand, with their role and field visibility, never more. Two-factor connection is always on for personal keys. Only users who can work with WOLF are offered. The site-wide key above is not affected and stays yours.', 'bulk-editor' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_personal_block( $user_id ) {

		$key   = WPBE_MCP_BOOT::personal_key( $user_id, true );
		$state = WPBE_MCP_BOOT::personal_state( $user_id );
		?>
		<div class="wpbe-control-section wpbe-mcp-personal wpbe-mcp-when-on"<?php echo WPBE_MCP_BOOT::hidden_while_off(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute ?>>
			<h5><?php esc_html_e( 'Your MCP access', 'bulk-editor' ); ?></h5>
			<div class="wpbe-control-container">
				<div class="wpbe-control">

					<div style="margin-bottom: 6px;">
						<?php esc_html_e( 'Address for the assistant:', 'bulk-editor' ); ?>
						<code style="user-select: all;"><?php echo esc_url( rest_url( 'wpbe/v1/mcp' ) ); ?></code>
					</div>

					<label style="display: block; margin-bottom: 4px;"><?php esc_html_e( 'Your personal key:', 'bulk-editor' ); ?></label>
					<input type="text" readonly="readonly" class="wpbe-mcp-personal-key" value="<?php echo esc_attr( $key ); ?>" style="width: 100%; font-family: monospace;" />
					<button type="button" class="button wpbe-mcp-regenerate" style="margin-top: 4px;"><?php esc_html_e( 'Regenerate key', 'bulk-editor' ); ?></button>

					<div class="wpbe-underfield" style="margin-top: 6px; font-size: 10px;">
						<?php esc_html_e( 'Give the assistant the address and this key as a request header: Authorization, with the value "Bearer" followed by a space and the key. Two-factor connection is always on for a personal key: every session starts with a token the assistant shows you, which you confirm below.', 'bulk-editor' ); ?>
					</div>

					<p class="wpbe-mcp-personal-state" style="margin: 8px 0;">
						<span class="wpbe-mcp-state-text"><?php echo esc_html( $state['text'] ); ?></span>
						<?php if ( $state['connected'] ) : ?>
							<button type="button" class="button wpbe-mcp-personal-drop"><?php esc_html_e( 'Disconnect', 'bulk-editor' ); ?></button>
						<?php endif; ?>
					</p>

					<label style="display: block; margin-bottom: 4px;"><?php esc_html_e( 'Confirm assistant connection - paste the token the assistant gave you:', 'bulk-editor' ); ?></label>
					<input type="text" class="wpbe-mcp-personal-token" autocomplete="off" spellcheck="false" style="width: 60%; font-family: monospace;" />
					<button type="button" class="button button-primary wpbe-mcp-personal-confirm" style="width: 100%;"><?php esc_html_e( 'Confirm connection', 'bulk-editor' ); ?></button>
					<span class="wpbe-mcp-personal-message" style="margin-left: 8px;"></span>
				</div>
				<div class="wpbe-description">
					<p class="description">
						<?php esc_html_e( 'The administrator gave you MCP access. An assistant connected with your key works as you: it can do what you can do by hand in this plugin, with your role and field visibility. Keep the key private; if it may have leaked, press Regenerate key - the old key and its connection stop working at once.', 'bulk-editor' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// AJAX

	/**
	 * The checks every handler starts with: the nonce, the right to open the
	 * plugin at all, and for administrator buttons the administrator rule.
	 */
	private static function verify( $administrator_only = false ) {

		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! WPBE_HELPER::can_manage_data() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You cannot work with WOLF on this site.', 'bulk-editor' ) ), 403 );
		}

		if ( $administrator_only && ! WPBE_MCP_BOOT::is_administrator() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Only an administrator can do this.', 'bulk-editor' ) ), 403 );
		}
	}

	/**
	 * The checks of the personal buttons: the current user must be on the
	 * grant list right now. Returns his id.
	 */
	private static function verify_personal() {

		self::verify();

		$me = get_current_user_id();

		if ( ! WPBE_MCP_BOOT::may_use_personal_key( $me ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You have no MCP access on this site. Ask the administrator to grant it.', 'bulk-editor' ) ), 403 );
		}

		return $me;
	}

	/**
	 * User search behind the grant list: part of a name, login or e-mail in,
	 * at most twenty users who can work with WOLF out.
	 */
	public static function ajax_search_users() {

		self::verify( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in verify() above
		$term = isset( $_POST['term'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['term'] ) ) ) : '';

		if ( '' === $term ) {
			wp_send_json_success( array( 'users' => array() ) );
		}

		$query = new WP_User_Query(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				// the roles WPBE_HELPER::can_manage_data() lets onto the WOLF screen
				'role__in'       => array_values( array_unique( array_merge( (array) apply_filters( 'wpbe_permit_special_roles', array( 'administrator' ) ), array( 'editor' ), (array) apply_filters( 'wpbe_author_area_roles', array() ) ) ) ),
				'number'         => self::SEARCH_LIMIT,
				'orderby'        => 'display_name',
				'count_total'    => false,
			)
		);

		$out = array();

		foreach ( (array) $query->get_results() as $user ) {

			// asked again per user, by the screen's own test
			if ( ! WPBE_HELPER::can_manage_data( intval( $user->ID ) ) ) {
				continue;
			}

			$out[] = array(
				'id'    => intval( $user->ID ),
				'label' => self::user_label( $user ),
			);
		}

		wp_send_json_success( array( 'users' => $out ) );
	}

	/**
	 * Disconnect button of one line in the administrator overview.
	 */
	public static function ajax_drop_user_connection() {

		self::verify( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in verify() above
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		// 0 would be the site-wide connection, which has its own button
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No user given.', 'bulk-editor' ) ) );
		}

		WPBE_MCP_BOOT::drop_connection( $user_id );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Disconnected.', 'bulk-editor' ),
				'connected' => false,
				'state'     => self::user_state_text( $user_id ),
			)
		);
	}

	/**
	 * Regenerate key: a new personal key for the current user only. Ends his
	 * connection too, so a session opened with the old key dies with it.
	 */
	public static function ajax_regenerate_key() {

		$me = self::verify_personal();
		$key   = WPBE_MCP_BOOT::regenerate_personal_key( $me );
		$state = WPBE_MCP_BOOT::personal_state( $me );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'A new key is issued. The old one no longer works - update it in your assistant.', 'bulk-editor' ),
				'key'       => $key,
				'connected' => $state['connected'],
				'state'     => $state['text'],
			)
		);
	}

	/**
	 * Confirm button of the personal block. The token lands in the current
	 * user's own meta and nowhere else, so it works with his key only.
	 */
	public static function ajax_confirm_personal() {

		$me = self::verify_personal();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in verify() above
		$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';

		if ( ! preg_match( WPBE_MCP_BOOT::TOKEN_PATTERN, $token ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'That is not a connection token. Copy the whole token the assistant gave you - four groups of eight characters separated by dashes.', 'bulk-editor' ) ) );
		}

		WPBE_MCP_BOOT::confirm_connection( $me, $token );

		$state = WPBE_MCP_BOOT::personal_state( $me );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Connected. Go back to the assistant and tell it you have confirmed.', 'bulk-editor' ),
				'connected' => $state['connected'],
				'state'     => $state['text'],
			)
		);
	}

	/**
	 * Disconnect button of the personal block.
	 */
	public static function ajax_drop_personal() {

		$me = self::verify_personal();

		WPBE_MCP_BOOT::drop_connection( $me );

		$state = WPBE_MCP_BOOT::personal_state( $me );

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Disconnected.', 'bulk-editor' ),
				'connected' => $state['connected'],
				'state'     => $state['text'],
			)
		);
	}
}
