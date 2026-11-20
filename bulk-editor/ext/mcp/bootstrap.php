<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Bootstrap for the MCP extension.
 *
 * Everything else about MCP lives in mcp.php as an ordinary WPBE extension.
 * This file exists for one reason only, and it is a WordPress ordering problem
 * rather than a design choice:
 *
 *   WPBE::init() is hooked on 'init'. REST routes have to be registered on
 *   'rest_api_init', which fires later. Worse, on a REST request the constant
 *   REST_REQUEST is defined during parse_request - that is, AFTER 'init' - so
 *   at the moment WPBE::init() runs it sees neither is_admin() nor REST and
 *   returns immediately. An extension constructed inside init() can therefore
 *   never register a REST route on a REST request: by then nothing has been
 *   constructed at all.
 *
 * So this file is required from the plugin root, hooks 'rest_api_init' itself,
 * and inside that hook brings WPBE up and hands the request to the extension.
 * Roughly twenty lines of plumbing; no plugin logic lives here.
 */
final class WPBE_MCP_BOOT {

	// Set by permission() once, and only, when the caller presented the
	// site's key or a valid personal key. The dispatcher reads it again before
	// running anything but a handshake, so a flaw in the gate cannot turn into
	// full access by itself.
	private static $authenticated = false;

	// The WordPress user a personal key belongs to, once permission() has
	// accepted that key. 0 for the site-wide key and outside MCP requests.
	private static $personal_user_id = 0;

	// Set by the download and upload routes once their own one time token has
	// been found valid. Lets the plugin come up for that one purpose (see
	// may_boot()) without ever counting as an authenticated MCP call.
	private static $token_authenticated = false;

	// The confirmed assistant connection: one at a time, stored as a hash of
	// the token together with when it was confirmed and last used.
	const CONNECTION_OPTION = 'wpbe_mcp_connection';

	// Personal access. A user the administrator granted MCP access has his own
	// key and his own confirmed connection, both kept in his user meta - the
	// connection under the same name the site-wide one uses as an option.
	const USER_KEY_META        = 'wpbe_mcp_key';
	const USER_CONNECTION_META = 'wpbe_mcp_connection';

	// wpbe_ + 40 lowercase hex + - + user id. The id only says where to look;
	// the whole key is still compared with the stored one.
	const PERSONAL_KEY_PATTERN = '/^wpbe_[a-f0-9]{40}-(\d+)$/';

	// This long without a single call and the connection is gone. Measured from
	// the last call rather than from the start, so long work never breaks in
	// the middle - only a connection nobody is using expires.
	const CONNECTION_IDLE = 3600;

	// Four groups of eight letters and digits, upper and lower case: about 190
	// bits, far past guessing, and still something a person can paste.
	const TOKEN_PATTERN = '/^[A-Za-z0-9]{8}(-[A-Za-z0-9]{8}){3}$/';

	const NS = 'wpbe/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// the confirm and disconnect buttons on the settings screen
		add_action( 'wp_ajax_wpbe_mcp_confirm_connection', array( __CLASS__, 'ajax_confirm_connection' ) );
		add_action( 'wp_ajax_wpbe_mcp_drop_connection', array( __CLASS__, 'ajax_drop_connection' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_connection_script' ) );

		// personal access: the grant list, personal keys and the settings
		// blocks and buttons that go with them
		require_once WPBE_PATH . 'ext/mcp/access.php';
		WPBE_MCP_ACCESS::init();
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// who is who

	/**
	 * Whether a user counts as an administrator of WOLF: he can manage the
	 * site, or his role is one the plugin treats as administrator. The one
	 * place this is decided - history visibility, the grant list and every
	 * administrator-only button ask here.
	 *
	 * @param int|null $user_id null for the current user.
	 * @return bool
	 */
	public static function is_administrator( $user_id = null ) {

		$user_id = null === $user_id ? get_current_user_id() : intval( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		return (bool) array_intersect(
			(array) $user->roles,
			(array) apply_filters( 'wpbe_permit_special_roles', array( 'administrator' ) )
		);
	}

	/**
	 * The user a personal key was accepted for on this request, or 0 when the
	 * site-wide key is in use (or this is not an MCP request at all).
	 */
	public static function personal_user_id() {
		return self::$personal_user_id;
	}

	/**
	 * User ids the administrator granted MCP access, from the global option.
	 * Empty by default: out of the box nobody but the site-wide key gets in.
	 *
	 * @return int[]
	 */
	public static function granted_users() {

		$ids = self::option( 'mcp_users', array() );

		if ( ! is_array( $ids ) ) {
			$ids = ( '' === trim( (string) $ids ) ) ? array() : explode( ',', (string) $ids );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Whether this user may work through a personal key right now: on the
	 * grant list, existing, and still able to work with WOLF. Asked on
	 * every request, so taking any of the three away cuts him off at once.
	 */
	public static function may_use_personal_key( $user_id ) {

		$user_id = absint( $user_id );

		if ( ! $user_id || ! in_array( $user_id, self::granted_users(), true ) ) {
			return false;
		}

		$user = get_userdata( $user_id );

		// the same test the WOLF screen asks before it opens at all
		return $user && WPBE_HELPER::can_manage_data( $user_id );
	}

	/**
	 * The personal key of a user, issued on first use when $create is true.
	 */
	public static function personal_key( $user_id, $create = false ) {

		$key = (string) get_user_meta( $user_id, self::USER_KEY_META, true );

		if ( '' === $key && $create ) {
			$key = self::regenerate_personal_key( $user_id );
		}

		return $key;
	}

	/**
	 * Issues a new personal key. The confirmed connection goes with the old
	 * key, so a session opened with it cannot outlive it.
	 */
	public static function regenerate_personal_key( $user_id ) {

		$user_id = absint( $user_id );
		$key     = 'wpbe_' . bin2hex( random_bytes( 20 ) ) . '-' . $user_id;

		update_user_meta( $user_id, self::USER_KEY_META, $key );
		delete_user_meta( $user_id, self::USER_CONNECTION_META );

		return $key;
	}

	/**
	 * Forgets everything personal about a user: key and connection. Used when
	 * the administrator takes him off the grant list.
	 */
	public static function forget_personal_access( $user_id ) {
		delete_user_meta( $user_id, self::USER_KEY_META );
		delete_user_meta( $user_id, self::USER_CONNECTION_META );
	}

	/**
	 * The user a presented key belongs to, or 0. The id at the end of the key
	 * only tells us whose meta to read; what authenticates is the whole key,
	 * compared with the stored one in constant time.
	 *
	 * The work done before the comparison is the same for every id - granted
	 * or not, with a key or without one. Asking the grant list first would
	 * answer sooner for an id without access, and the time an answer takes
	 * would tell a stranger which users have MCP access. So the stored key is
	 * always read and always compared, and the grant list and the capability
	 * are asked only once the whole key has matched, which only its owner can
	 * make happen.
	 */
	private static function personal_key_owner( $given ) {

		if ( ! preg_match( self::PERSONAL_KEY_PATTERN, $given, $m ) ) {
			return 0;
		}

		$user_id = absint( $m[1] );
		$stored  = (string) get_user_meta( $user_id, self::USER_KEY_META, true );

		// An id written with leading zeros ("-007") is never a real key, and
		// the stored key would be shorter than what was presented - which
		// hash_equals answers faster. It takes the dummy path instead.
		$has_key = '' !== $stored && (string) $user_id === $m[1];

		// No key to compare with: a fixed dummy of the same length as the
		// presented key, so the comparison costs the same. 'x' is not a hex
		// digit, so the dummy never equals a presented key - and $has_key
		// refuses it regardless.
		$known = $has_key ? $stored : str_repeat( 'x', strlen( $given ) );

		if ( ! hash_equals( $known, $given ) || ! $has_key ) {
			return 0;
		}

		if ( ! self::may_use_personal_key( $user_id ) ) {
			return 0;
		}

		return $user_id;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// two-factor connection
	//
	// The key is the first factor and lives in the assistant's settings, where
	// it can leak - a screenshot, a shared config, a stolen laptop. With this
	// mode on, the key alone does nothing. The assistant asks the server for a
	// token, the owner pastes it into the settings screen, and only that token
	// works, only for as long as it is in use. Confirming needs a logged-in
	// administrator, which whoever holds a leaked key does not have.

	/**
	 * The idle limit as people read it, taken from CONNECTION_IDLE, so every
	 * message says what the code actually does - change the constant and the
	 * texts follow. Seconds, and minutes alongside once it is a minute or more.
	 */
	public static function idle_text() {

		$seconds = self::idle_seconds();

		if ( $seconds < 60 ) {
			return $seconds . ' seconds';
		}

		return $seconds . ' seconds (' . round( $seconds / 60, 1 ) . ' minutes)';
	}

	/**
	 * How long a connection may stay idle, in seconds. CONNECTION_IDLE unless
	 * a site shortens or lengthens it with the wpbe_mcp_connection_idle
	 * filter - the same limit for the site-wide connection and for every
	 * personal one.
	 */
	public static function idle_seconds() {
		return max( 1, intval( apply_filters( 'wpbe_mcp_connection_idle', self::CONNECTION_IDLE ) ) );
	}

	/**
	 * Whether the site requires a confirmed connection on top of the key.
	 */
	public static function two_factor_on() {
		return (bool) self::option( 'mcp_2fa' );
	}

	/**
	 * Whether this request needs a confirmed connection. Always for a
	 * personal key, whatever the site setting says; for the site-wide key it
	 * follows the setting, as before.
	 */
	public static function two_factor_required() {
		return self::personal_user_id() > 0 || self::two_factor_on();
	}

	/**
	 * Whose connection a call is about: the given user, or - when nothing is
	 * given - whoever is acting on this request. 0 is the site-wide
	 * connection kept in the option.
	 */
	private static function connection_owner( $owner = null ) {
		return null === $owner ? self::personal_user_id() : absint( $owner );
	}

	private static function read_connection( $owner ) {
		return $owner ? get_user_meta( $owner, self::USER_CONNECTION_META, true ) : get_option( self::CONNECTION_OPTION );
	}

	private static function write_connection( $owner, $data ) {

		if ( $owner ) {
			update_user_meta( $owner, self::USER_CONNECTION_META, $data );
		} else {
			update_option( self::CONNECTION_OPTION, $data, false );
		}
	}

	private static function forget_connection( $owner ) {

		if ( $owner ) {
			delete_user_meta( $owner, self::USER_CONNECTION_META );
		} else {
			delete_option( self::CONNECTION_OPTION );
		}
	}

	/**
	 * Stores a confirmed token for this owner: one connection per owner, so
	 * confirming a new one ends the old.
	 */
	public static function confirm_connection( $owner, $token ) {

		$now = time();

		self::write_connection(
			$owner,
			array(
				'hash'      => hash( 'sha256', $token ),
				'confirmed' => $now,
				'last'      => $now,
			)
		);
	}

	/**
	 * The stored connection of an owner while it is still alive, else null.
	 */
	public static function live_connection( $owner ) {

		$stored = self::read_connection( absint( $owner ) );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
			return null;
		}

		if ( time() - intval( $stored['last'] ) > self::idle_seconds() ) {
			return null;
		}

		return $stored;
	}

	/**
	 * A fresh token. Nothing is stored: it only starts to mean something when
	 * an administrator confirms it on the settings screen.
	 */
	public static function new_token() {

		$chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$max    = strlen( $chars ) - 1;
		$groups = array();

		for ( $g = 0; $g < 4; $g++ ) {

			$group = '';

			for ( $i = 0; $i < 8; $i++ ) {
				$group .= $chars[ random_int( 0, $max ) ];
			}

			$groups[] = $group;
		}

		return implode( '-', $groups );
	}

	/**
	 * Whether this token is the confirmed, still living connection. On
	 * success the idle clock starts again.
	 *
	 * The owner is whoever is acting on this request unless given: a personal
	 * key is checked against that user's own connection only, the site-wide
	 * key against the shared one - so a token confirmed by one user is
	 * refused with any other key.
	 *
	 * @param string   $token the token the caller presented.
	 * @param int|null $owner user id, 0 for the site-wide connection, null for
	 *                        the acting identity.
	 * @return true|string true, or 'none', 'expired' or 'mismatch'.
	 */
	public static function check_connection( $token, $owner = null ) {

		$owner  = self::connection_owner( $owner );
		$stored = self::read_connection( $owner );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
			return 'none';
		}

		$now  = time();
		$idle = self::idle_seconds();

		if ( $now - intval( $stored['last'] ) > $idle ) {
			self::forget_connection( $owner );
			return 'expired';
		}

		$token = trim( (string) $token );

		if ( '' === $token || ! hash_equals( (string) $stored['hash'], hash( 'sha256', $token ) ) ) {
			return 'mismatch';
		}

		// written at most once a minute: the clock only needs to know the
		// connection is alive, not count every call. A shortened idle limit
		// shortens the interval with it, or the clock would never be
		// written before the connection runs out.
		if ( $now - intval( $stored['last'] ) > min( 60, max( 1, intval( $idle / 4 ) ) ) ) {
			$stored['last'] = $now;
			self::write_connection( $owner, $stored );
		}

		return true;
	}

	/**
	 * Ends a connection - by default the one of whoever is acting on this
	 * request. The next call with the old token is refused.
	 *
	 * @param int|null $owner user id, 0 for the site-wide connection, null for
	 *                        the acting identity.
	 */
	public static function drop_connection( $owner = null ) {
		self::forget_connection( self::connection_owner( $owner ) );
	}

	/**
	 * The state of a personal connection as the settings screen shows it.
	 *
	 * @return array connected (bool) and text.
	 */
	public static function personal_state( $user_id ) {

		$stored = self::live_connection( absint( $user_id ) );

		if ( ! $stored ) {
			return array(
				'connected' => false,
				'text'      => __( 'Not connected', 'bulk-editor' ),
			);
		}

		$minutes = intval( floor( max( 0, time() - intval( $stored['last'] ) ) / 60 ) );

		return array(
			'connected' => true,
			'text'      => sprintf(
				/* translators: %d: minutes since the assistant last used the connection */
				__( 'Connected, last activity %d min ago', 'bulk-editor' ),
				$minutes
			),
		);
	}

	/**
	 * Confirm button on the settings screen.
	 */
	public static function ajax_confirm_connection() {

		check_ajax_referer( 'wpbe_mcp_connection', 'nonce' );

		// administrators only: whoever can confirm a connection hands out
		// the whole site to an assistant
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Only an administrator can confirm a connection.', 'bulk-editor' ) ), 403 );
		}

		$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';

		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'That is not a connection token. Copy the whole token the assistant gave you - four groups of eight characters separated by dashes.', 'bulk-editor' ) ) );
		}

		$now = time();

		// one connection at a time: confirming a new one ends the old, so two
		// assistants never work on the same site at once
		update_option(
			self::CONNECTION_OPTION,
			array(
				'hash'      => hash( 'sha256', $token ),
				'confirmed' => $now,
				'last'      => $now,
			),
			false
		);

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Connected. Go back to the assistant and tell it you have confirmed.', 'bulk-editor' ),
				'connected' => true,
				'state'     => self::connection_state_text(),
			)
		);
	}

	/**
	 * Disconnect button on the settings screen.
	 */
	public static function ajax_drop_connection() {

		check_ajax_referer( 'wpbe_mcp_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Only an administrator can end a connection.', 'bulk-editor' ) ), 403 );
		}

		self::drop_connection();

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Disconnected.', 'bulk-editor' ),
				'connected' => false,
				'state'     => self::connection_state_text(),
			)
		);
	}

	/**
	 * The script behind the two buttons, on the WOLF screen only.
	 */
	public static function enqueue_connection_script() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen we are on, nothing is changed
		if ( ! isset( $_GET['page'] ) || 'wpbe' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'wpbe-mcp-connection',
			WPBE_LINK . 'ext/mcp/assets/connection.js',
			array( 'jquery' ),
			WPBE_VERSION,
			true
		);

		wp_localize_script(
			'wpbe-mcp-connection',
			'wpbeMcpConnection',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpbe_mcp_connection' ),
				'working'    => esc_html__( 'Working...', 'bulk-editor' ),
				'disconnect' => esc_html__( 'Disconnect', 'bulk-editor' ),
				'failed'  => esc_html__( 'Something went wrong. Reload the page and try again.', 'bulk-editor' ),
			)
		);
	}

	/**
	 * The state line of the panel, as plain text. Shared by the page and the
	 * AJAX answers, so the line reads the same before and after a click.
	 */
	public static function connection_state_text() {

		$stored = get_option( self::CONNECTION_OPTION );
		$alive  = is_array( $stored ) && ! empty( $stored['hash'] ) && ( time() - intval( $stored['last'] ) <= self::idle_seconds() );

		if ( ! $alive ) {
			return __( 'No assistant is connected.', 'bulk-editor' );
		}

		return sprintf(
			/* translators: 1: time the connection was confirmed, 2: time it expires if left idle */
			__( 'An assistant is connected since %1$s. If it stays idle, the connection ends at %2$s.', 'bulk-editor' ),
			wp_date( get_option( 'time_format' ), intval( $stored['confirmed'] ) ),
			wp_date( get_option( 'time_format' ), intval( $stored['last'] ) + self::idle_seconds() )
		);
	}

	/**
	 * The block under the two-factor setting: a warning while the mode is
	 * off, the confirm field and the current connection while it is on.
	 * Printed by the settings screen, administrators only.
	 */
	public static function render_connection_panel() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::two_factor_on() ) {
			?>
			<div class="wpbe-mcp-2fa-warning" style="margin-top: 8px; padding: 10px 12px; background: #fdecea; border-left: 4px solid #d63638; color: #8a1f11;">
				<?php
				printf(
					/* translators: %s: how long a connection may stay idle, e.g. "3600 seconds (60 minutes)" */
					esc_html__( 'Recommended: turn on two-factor connection. Without it, anyone who gets hold of the MCP key - from a screenshot, a shared config or a stolen laptop - can work with your site. With it, every assistant session also needs a token that you confirm here yourself, and that expires after %s without use.', 'bulk-editor' ),
					esc_html( self::idle_text() )
				);
				?>
			</div>
			<?php
			return;
		}

		$stored = get_option( self::CONNECTION_OPTION );
		$alive  = is_array( $stored ) && ! empty( $stored['hash'] ) && ( time() - intval( $stored['last'] ) <= self::idle_seconds() );
		?>
		<div class="wpbe-mcp-connection" style="margin-top: 8px;">

			<p class="wpbe-mcp-connection-state" style="margin: 0 0 8px;">
				<span class="wpbe-mcp-state-text"><?php echo esc_html( self::connection_state_text() ); ?></span>
				<?php if ( $alive ) : ?>
					<button type="button" class="button wpbe-mcp-drop"><?php esc_html_e( 'Disconnect', 'bulk-editor' ); ?></button>
				<?php endif; ?>
			</p>

			<label style="display: block; margin-bottom: 4px;"><?php esc_html_e( 'Confirm assistant connection - paste the token the assistant gave you:', 'bulk-editor' ); ?></label>
			<input type="text" class="wpbe-mcp-token" autocomplete="off" spellcheck="false" style="width: 60%; font-family: monospace;" />
			<button type="button" class="button button-primary wpbe-mcp-confirm" style="width: 100%;"><?php esc_html_e( 'Confirm connection', 'bulk-editor' ); ?></button>
			<span class="wpbe-mcp-message" style="margin-left: 8px;"></span>
		</div>
		<?php
	}

	public static function register_routes() {
		
		// Off by default, and off means the route does not exist. A site that
		// never connects an assistant should not carry an endpoint at all: the
		// safest code is the code that is not reachable.
		if ( ! self::option( 'mcp_enabled' ) ) {
			return;
		}

		register_rest_route(
			self::NS,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				// some clients probe the endpoint with GET before opening a session
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_get' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
		
		
		// Download link for a generated export. Its own token authenticates it,
		// because a browser click cannot send a header - and the site's key was
		// deliberately kept out of URLs. The token maps to one file and expires
		// in fifteen minutes.
		register_rest_route(
			self::NS,
			'/mcp/export/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve_export' ),
				'permission_callback' => '__return_true',
			)
		);

		// The upload page and the handler behind it. A photograph on somebody's
		// desktop cannot be reached by a chat or an API, and encoding one as
		// text costs more than the conversation around it - so the shop opens a
		// door for fifteen minutes and lets the browser do what it is good at.
		// The token in the path is the credential: single purpose, expiring,
		// and tied to one post when a post was named.
		register_rest_route(
			self::NS,
			'/mcp/upload/(?P<token>[a-f0-9]{32})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'upload_page' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'upload_receive' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}
	
	/**
	 * 403 rather than 401, deliberately.
	 *
	 * 401 is the literally correct code for a missing credential, but in MCP it
	 * is also the signal that starts OAuth discovery: the client fetches the
	 * resource metadata document, looks for an authorization server, finds
	 * neither - because this server has none, it authenticates with a static
	 * key - and reports that the server is broken. The connector dialog does
	 * the same thing visually, offering an OAuth section instead of the header
	 * field the user actually needs. 403 says plainly "your credential is not
	 * accepted" and starts no discovery.
	 */
	public static function permission( $request ) {

		// No exemptions. Every request, the handshake included, has to carry
		// the key: without it the endpoint says nothing about itself - not its
		// name, not its version, not how it works. Letting a handshake through
		// unauthenticated is what once let a crafted body pass the gate as
		// "initialize" and run as a batch; with no exemption there is nothing
		// for such a body to pretend to be.
		self::$authenticated    = false;
		self::$personal_user_id = 0;

		$expected = trim( (string) self::option( 'mcp_key' ) );
		$given    = self::key_from_request( $request );

		// hash_equals rather than == : a plain comparison leaks the key one
		// character at a time to anyone willing to measure the response
		if ( '' !== $expected && '' !== $given && hash_equals( $expected, $given ) ) {
			self::$authenticated = true;
			return true;
		}

		// A personal key: the request runs as that user from here on, before
		// the plugin initialises, so his role and his field visibility apply
		// exactly as they do on the screen. Everything is checked again on
		// every request - grant list, capability and the whole key.
		$user_id = self::personal_key_owner( $given );

		if ( $user_id ) {
			self::$authenticated    = true;
			self::$personal_user_id = $user_id;
			wp_set_current_user( $user_id );
			return true;
		}

		// Refused. The answers are the ones this endpoint always gave, and
		// they say nothing about whether a personal key format was recognised.
		if ( '' === $expected ) {
			return new WP_Error(
				'wpbe_mcp_no_key',
				'No MCP key is configured on this site. Open the plugin settings once to have one issued.',
				array( 'status' => 403 )
			);
		}

		return new WP_Error(
			'wpbe_mcp_bad_key',
			'Wrong or missing MCP key. Send it as "Authorization: Bearer <key>" or in the X-WPBE-KEY header.',
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether a decoded body is a JSON-RPC batch.
	 *
	 * The one place this is decided. The gate no longer needs it - it lets
	 * nothing through without the key, batch or not - but anything that ever
	 * has to tell the two apart again should call this rather than write its
	 * own test: a gate and a dispatcher reading one body two different ways is
	 * how a body with a top-level "initialize" and a numeric key "0" once
	 * passed as a handshake and ran as a batch.
	 */
	public static function is_batch( $body ) {
		return is_array( $body ) && isset( $body[0] );
	}

	/**
	 * Whether this request presented the site's key. False for a handshake,
	 * which is let through without one.
	 */
	public static function authenticated() {
		return self::$authenticated;
	}

	/**
	 * Whether WPBE::init() may come up on this REST request although nobody is
	 * logged in: the key was accepted, or a download or upload token was. The
	 * dispatcher still asks authenticated() alone, so a token never opens a
	 * tool.
	 */
	public static function may_boot() {
		return self::$authenticated || self::$token_authenticated;
	}

	/**
	 * The key the caller presented.
	 *
	 * Headers only. A secret in a URL is written down everywhere the URL goes -
	 * the web server's access log, the browser history, a Referer header on the
	 * way out, and every proxy and CDN in between. A header appears in none of
	 * them, and every client worth connecting can send one.
	 */
	private static function key_from_request( $request ) {

		// Authorization: Bearer <key> - the standard spelling, and the one most
		// connector interfaces offer in their header list
		$auth = (string) $request->get_header( 'authorization' );

		if ( '' === $auth ) {
			// some Apache setups drop the header before PHP sees it and leave a
			// rewritten copy behind instead
			$auth = isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '';
		}

		if ( '' !== $auth && 0 === stripos( $auth, 'bearer ' ) ) {
			return trim( substr( $auth, 7 ) );
		}

		return trim( (string) $request->get_header( 'x_wpbe_key' ) );
	}

	/**
	 * The person the site-wide key belongs to: the site's first
	 * administrator. Author of the drafts it creates, owner of the columns
	 * it reads.
	 */
	public static function site_owner_id() {

		static $id = null;

		if ( null === $id ) {
			$admins = get_users(
				array(
					'role'    => 'administrator',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'number'  => 1,
					'fields'  => 'ID',
				)
			);
			$id     = $admins ? intval( $admins[0] ) : 0;
		}

		return $id;
	}

	/**
	 * Brings the plugin up inside the REST request and returns the extension
	 * instance, or a WP_Error explaining exactly what is missing. The error text
	 * is deliberately specific: this is the one place where a wrong integration
	 * shows up, and "something went wrong" would cost an hour of guessing.
	 */
	private static function ext() {
		
		if ( ! defined( 'WPBE_MCP_REQUEST' ) ) {
			define( 'WPBE_MCP_REQUEST', true );
		}

		global $WPBE;

		if ( ! isset( $WPBE ) || ! is_object( $WPBE ) ) {
			return new WP_Error( 'wpbe_mcp_no_plugin', 'WOLF is not loaded.' );
		}

		// The two filters below exist for the site-wide key only: it has no
		// WordPress user, and without them WOLF would treat it as nobody. A
		// personal key runs as a real user (set in permission()), so it gets
		// exactly that user's role and field visibility - never more.
		if ( ! self::personal_user_id() ) {

			// The columns of the editor are kept per user, and the site-wide key
			// has none: it read a set of its own, wpbe_options_0, that no screen
			// can change - a CSV of the price the owner shows on his screen was
			// refused, with nowhere to switch it on. The owner's own columns,
			// the same administrator who becomes the author of its drafts.
			add_filter(
				'wpbe_settings_key_options',
				function ( $user_id ) {
					return $user_id ? $user_id : WPBE_MCP_BOOT::site_owner_id();
				}
			);

			// Without a logged in user WPBE_SETTINGS resolves an empty role. Not in
			// this list, it is treated as a site editor: the per field check then
			// reads the wpbe_site_editor_visibility option - which is not an array
			// until somebody saves the settings screen - and the type is swapped
			// for the first of the site editors' types. The result is a silent
			// 'forbidden' from every write. Added per request.
			add_filter(
				'wpbe_permit_special_roles',
				function ( $roles ) {
					$roles[] = '';
					return $roles;
				}
			);

			// Scoped to what an MCP request may touch, rather than blanket true.
			// The models ask this filter per field, and answering yes to everything
			// meant a leaked key inherited the whole plugin - including fields an
			// administrator hides from site editors on purpose.
			add_filter(
				'wpbe_user_can_edit',
				function ( $can, $field_key = '' ) {

					if ( ! defined( 'WPBE_MCP_REQUEST' ) ) {
						return $can;
					}

					return ! in_array(
						$field_key,
						apply_filters(
							'wpbe_mcp_never_editable',
							array( 'post_author', 'ID', '__checker' )
						),
						true
					);
				},
				99,
				2
			);
		}

		if ( is_null( $WPBE->posts ) ) {
			$WPBE->init();
		}

		if ( is_null( $WPBE->posts ) ) {
			return new WP_Error(
				'wpbe_mcp_not_inited',
				'WOLF did not initialise for this request. Check is_should_init() in index.php: it must return true when REQUEST_URI contains wpbe/v1/.'
			);
		}

		if ( ! isset( $WPBE->mcp ) || is_null( $WPBE->mcp ) ) {
			return new WP_Error(
				'wpbe_mcp_ext_missing',
				'The mcp extension was not loaded. Add "mcp" to the $ext array in index.php and declare a public $mcp property on the WPBE class.'
			);
		}

		return $WPBE->mcp;
	}

	public static function handle( $request ) {

		$ext = self::ext();

		if ( is_wp_error( $ext ) ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32603,
						'message' => $ext->get_error_message(),
					),
				),
				500
			);
		}

		return $ext->handle( $request );
	}

	public static function handle_get() {

		// The protocol list lives in the extension, which exists only once
		// the plugin is up. Without this a GET probe died with "Class
		// WPBE_MCP not found" - a fatal error for every client that probes.
		$ext = self::ext();

		return new WP_REST_Response(
			array(
				'server'   => 'WOLF bulk editor MCP',
				'version'  => defined( 'WPBE_VERSION' ) ? WPBE_VERSION : '',
				'protocol' => is_wp_error( $ext ) ? '' : WPBE_MCP::PROTOCOLS[0],
				'note'     => 'This endpoint speaks JSON-RPC 2.0 over POST.',
			),
			200
		);
	}
	
	/**
	 * Site wide plugin options, read straight from the database.
	 *
	 * The permission callback runs before the plugin is booted - extensions are
	 * not included yet, WPBE_SETTINGS does not exist, and nothing that walks
	 * the settings objects is available. Anything the gate needs must therefore
	 * come from here, not from the settings model. Any future site wide option
	 * the endpoint has to check goes through this one function.
	 */
	public static function options() {

		static $cache = null;

		if ( is_null( $cache ) ) {
			$cache = get_option( 'wpbe_options_global' );
			$cache = is_array( $cache ) ? $cache : array();
		}

		return $cache;
	}

	public static function option( $key, $default = '' ) {

		$options = self::options();

		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Saves the MCP block of the settings screen into the site wide option.
	 *
	 * WOLF keeps its settings per user, so these live in their own row,
	 * wpbe_options_global, which permission() reads before the plugin is up.
	 * Called from wpbe_save_options() in its administrator-only branch, and
	 * asks again here: only an administrator renders the block, so only his
	 * save may write it. The block marks itself as rendered - a form without
	 * the marker changes nothing, and a form with it is taken as it stands:
	 * a cleared key is a deliberate clear (a new key is issued on the next
	 * page load), an emptied user list takes everybody's personal access away.
	 *
	 * @param array $posted wpbe_options_global as the form posted it.
	 */
	public static function save_global_options( $posted ) {

		if ( ! is_array( $posted ) || empty( $posted['__rendered'] ) || ! self::is_administrator() ) {
			return;
		}

		$stored = get_option( 'wpbe_options_global' );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['mcp_enabled'] = ! empty( $posted['mcp_enabled'] ) ? 1 : 0;
		$stored['mcp_2fa']     = ! empty( $posted['mcp_2fa'] ) ? 1 : 0;
		$stored['mcp_key']     = isset( $posted['mcp_key'] ) ? trim( sanitize_text_field( wp_unslash( $posted['mcp_key'] ) ) ) : '';
		// cleaned to existing user ids by WPBE_MCP_ACCESS::sanitize_global()
		$stored['mcp_users'] = isset( $posted['mcp_users'] ) ? (array) $posted['mcp_users'] : array();

		update_option( 'wpbe_options_global', $stored, false );
	}

	/**
	 * The style attribute of an MCP settings block while the server is off:
	 * drawn hidden rather than left out, so its fields still go with the form
	 * and a save made with the server off changes nothing in them. Straight
	 * from the database, as render_settings() reads it.
	 */
	public static function hidden_while_off() {

		$global = get_option( 'wpbe_options_global' );

		return ( is_array( $global ) && ! empty( $global['mcp_enabled'] ) ) ? '' : ' style="display: none;"';
	}

	/**
	 * The MCP block of the Settings tab, administrators only: enable, key with
	 * the endpoint address, two-factor connection with its panel. Drawn in the
	 * markup of the neighbouring WOLF settings. The grant list and the
	 * personal block follow through the wpbe_general_settings_after action
	 * (WPBE_MCP_ACCESS).
	 */
	public static function render_settings() {

		if ( ! self::is_administrator() ) {
			return;
		}

		// straight from the database: options() may hold the value from before
		// WPBE_MCP::ensure_key() issued a key on this very page load
		$global = get_option( 'wpbe_options_global' );
		$global = is_array( $global ) ? $global : array();

		$enabled = ! empty( $global['mcp_enabled'] );
		$two_fa  = ! empty( $global['mcp_2fa'] );
		$key     = isset( $global['mcp_key'] ) ? (string) $global['mcp_key'] : '';
		$yes_no  = array(
			0 => esc_html__( 'No', 'bulk-editor' ),
			1 => esc_html__( 'Yes', 'bulk-editor' ),
		);
		?>
		<input type="hidden" name="wpbe_options_global[__rendered]" value="1" />

		<div class="wpbe-control-section">
			<h5><?php esc_html_e( 'Enable the MCP server', 'bulk-editor' ); ?></h5>
			<div class="wpbe-control-container">
				<div class="wpbe-control">
					<div class="select-wrap">
						<select name="wpbe_options_global[mcp_enabled]" id="wpbe_mcp_enabled">
							<?php foreach ( $yes_no as $k => $title ) : ?>
								<option <?php selected( intval( $enabled ) === $k ); ?> value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php
					// The state as saved, said in words: an owner who switched on the
					// two-factor select - the one marked recommended - took the server
					// for running, while its address answered 404 to every assistant.
					// Both lines are on the page and the select shows one of them:
					// choosing No on a running server says at once what it means.
					if ( $enabled ) :
						?>
						<p class="wpbe-mcp-on-note" style="margin: 6px 0 0; color: #008a20; font-weight: bold;"><?php esc_html_e( 'The MCP server is on: assistants can connect at the address below.', 'bulk-editor' ); ?></p>
					<?php endif; ?>
					<p class="wpbe-mcp-off-note" style="margin: 6px 0 0; color: #d63638; font-weight: bold;<?php echo $enabled ? ' display: none;' : ''; ?>"><?php esc_html_e( 'The MCP server is off and no assistant can connect. Choose Yes here: the key and the other MCP settings appear below, and Save switches the server on.', 'bulk-editor' ); ?></p>
					<script>
						// While the server is off the rest of the MCP settings is hidden,
						// not left out: the fields still go with the form, so a save made
						// with the server off keeps the key, the two-factor choice and the
						// grant list as they are. Choosing Yes shows them at once.
						(function () {
							var select = document.getElementById('wpbe_mcp_enabled');
							if (!select) {
								return;
							}
							select.addEventListener('change', function () {
								var on = '1' === select.value;
								document.querySelectorAll('.wpbe-mcp-when-on').forEach(function (el) {
									el.style.display = on ? '' : 'none';
								});
								document.querySelectorAll('.wpbe-mcp-off-note').forEach(function (el) {
									el.style.display = on ? 'none' : '';
								});
								document.querySelectorAll('.wpbe-mcp-on-note').forEach(function (el) {
									el.style.display = on ? '' : 'none';
								});
							});
						})();
					</script>
				</div>
				<div class="wpbe-description">
					<p class="description"><?php esc_html_e( 'Off by default. The MCP server lets an AI assistant read and edit the posts of this site over a REST endpoint. While it is off, the endpoint is not registered at all and nothing can reach it. Turn it on only when you are about to connect an assistant, and turn it back off if you stop using one.', 'bulk-editor' ); ?></p>
				</div>
			</div>
		</div>

		<div class="wpbe-control-section wpbe-mcp-when-on"<?php echo self::hidden_while_off(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute ?>>
			<h5><?php esc_html_e( 'MCP secret key', 'bulk-editor' ); ?></h5>
			<div class="wpbe-control-container">
				<div class="wpbe-control">
					<input type="text" name="wpbe_options_global[mcp_key]" value="<?php echo esc_attr( $key ); ?>" autocomplete="off" spellcheck="false" style="font-family: monospace;" />
					<div style="margin-top: 6px; font-size: 11px;">
						<?php esc_html_e( 'Address for the assistant:', 'bulk-editor' ); ?>
						<code style="user-select: all;"><?php echo esc_url( rest_url( 'wpbe/v1/mcp' ) ); ?></code>
						<?php if ( ! $enabled ) : ?>
							<br /><span style="color: #d63638;"><?php esc_html_e( 'Not active: the MCP server is switched off above.', 'bulk-editor' ); ?></span>
						<?php endif; ?>
						<?php
						// claude.ai probes a new connector without the key first, gets
						// the 403 of permission() and shows "Couldn't check the server"
						// with a 403 badge - which reads as broken to anyone not told
						?>
						<br /><span style="display: inline-block; margin-top: 6px;"><?php esc_html_e( 'Adding it in claude.ai: its server check will say "Couldn\'t check the server" with 403. That is expected for a server that signs in with a key - press "Continue anyway" and add the header Authorization with the value "Bearer" followed by a space and the key.', 'bulk-editor' ); ?></span>
						<br /><a href="https://bulk-editor.pro/document/mcp-server/" target="_blank" class="button button-primary" style="margin-top: 6px;"><span class="icon-book"></span>&nbsp;<?php esc_html_e( 'How to connect an AI assistant', 'bulk-editor' ); ?></a>
					</div>
				</div>
				<div class="wpbe-description">
					<p class="description"><?php esc_html_e( 'Lets an AI assistant work with the posts of this site through a chat: find them, change them in bulk, roll changes back. Give the assistant the address below and this key as a request header named Authorization, with the value "Bearer" followed by a space and the key. Some clients call it an API key header instead - X-WPBE-KEY works the same way. The key is never accepted in the address itself, because anything written into a URL ends up in server logs, browser history and every proxy along the way. Clear this field and press Save to issue a new key: the old one stops working at once and every connected assistant has to be reconnected.', 'bulk-editor' ); ?></p>
				</div>
			</div>
		</div>

		<div class="wpbe-control-section wpbe-mcp-when-on"<?php echo self::hidden_while_off(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute ?>>
			<h5><?php esc_html_e( 'MCP two-factor connection', 'bulk-editor' ); ?> <span style="color: #d63638; font-weight: bold;"><?php esc_html_e( 'recommended', 'bulk-editor' ); ?></span></h5>
			<div class="wpbe-control-container">
				<div class="wpbe-control">
					<div class="select-wrap">
						<select name="wpbe_options_global[mcp_2fa]">
							<?php foreach ( $yes_no as $k => $title ) : ?>
								<option <?php selected( intval( $two_fa ) === $k ); ?> value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php if ( ! $enabled ) : ?>
						<p style="margin: 6px 0 0; color: #d63638;"><?php esc_html_e( 'This does not switch the MCP server on - that is "Enable the MCP server" above. Two-factor connection takes effect once the server is on.', 'bulk-editor' ); ?></p>
					<?php endif; ?>
					<?php self::render_connection_panel(); ?>
				</div>
				<div class="wpbe-description">
					<p class="description">
						<?php
						printf(
							/* translators: %s: how long a connection may stay idle */
							esc_html__( 'Off by default, recommended. With it on, the MCP key alone is not enough: every assistant session starts by showing you a token, you paste it below and confirm, and only then can the assistant work. The connection ends by itself after %s without activity, so a leaked key is useless without you. One assistant is connected at a time.', 'bulk-editor' ),
							esc_html( self::idle_text() )
						);
						?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Streams one generated export file, once.
	 *
	 * The token is the credential here, so there is no key check: it is single
	 * purpose, expires on its own, and names exactly one file. The file name is
	 * taken from the transient rather than from the URL, so nothing the caller
	 * sends can point at another path. The ticket and the file are gone after
	 * the first download: the link is a hand-over, not a place to keep files.
	 */
	public static function serve_export( $request ) {

		$token = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'token' ) );
		$name  = '' !== $token ? get_transient( 'wpbe_mcp_export_' . $token ) : false;

		if ( ! $name ) {
			return new WP_Error( 'wpbe_mcp_export_expired', 'This download link has expired or was already used. Ask for the export again.', array( 'status' => 404 ) );
		}

		// used up before anything is sent, so two clicks cannot both get it
		delete_transient( 'wpbe_mcp_export_' . $token );

		require_once WPBE_PATH . 'ext/mcp/tool.php';
		require_once WPBE_PATH . 'ext/mcp/tools/export.php';

		$folder = WPBE_MCP_TOOL_EXPORT::folder();
		$file   = $folder . basename( (string) $name );

		if ( empty( $folder ) || ! file_exists( $file ) ) {
			return new WP_Error( 'wpbe_mcp_export_gone', 'That export file is no longer on the server.', array( 'status' => 404 ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . basename( (string) $name ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file );
		wp_delete_file( $file );
		exit;
	}

	/**
	 * The page a user drops photographs onto.
	 *
	 * The gap this closes: an image on somebody's desktop cannot be reached by
	 * a chat or an API, and encoding one as text costs more than the whole
	 * conversation around it. So the site opens a door for fifteen minutes and
	 * lets the browser do what browsers are good at.
	 *
	 * Deliberately one self-contained page with no assets - it has to work on a
	 * phone, on a site whose theme is broken, and fifteen minutes after the
	 * link was pasted into a message.
	 */
	public static function upload_page( $request ) {

		$token = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'token' ) );
		$data  = get_transient( 'wpbe_mcp_upload_' . $token );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( ! is_array( $data ) ) {
			echo '<!doctype html><meta charset="utf-8"><title>Link expired</title>'
				. '<div style="font:16px/1.6 system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem">'
				. '<h1 style="font-size:20px">This upload link has expired</h1>'
				. '<p>Upload links last fifteen minutes. Ask your assistant for a new one - anything already uploaded is safe in the media library.</p>'
				. '</div>';
			exit;
		}

		$post_title = $data['post_id'] ? get_the_title( intval( $data['post_id'] ) ) : '';

		?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php esc_html_e( 'Upload images', 'bulk-editor' ); ?></title>
<style>
	body { font: 16px/1.6 system-ui, -apple-system, sans-serif; max-width: 34rem; margin: 3rem auto; padding: 0 1rem; color: #23282d; }
	h1 { font-size: 20px; margin: 0 0 .25rem; }
	.sub { color: #6b7280; margin: 0 0 1.5rem; }
	#drop { border: 2px dashed #b9c4e6; border-radius: 10px; padding: 2.5rem 1rem; text-align: center; background: #f6f8fe; cursor: pointer; }
	#drop.over { border-color: #2f55d4; background: #eef2fd; }
	#list { margin-top: 1.25rem; padding: 0; list-style: none; }
	#list li { padding: .4rem 0; border-bottom: 1px solid #eceef3; display: flex; justify-content: space-between; gap: 1rem; }
	.ok { color: #1baf7a; } .bad { color: #e34948; }
	small { color: #6b7280; }
</style>

<h1><?php esc_html_e( 'Add images to your site', 'bulk-editor' ); ?></h1>
<p class="sub">
	<?php
	if ( $post_title ) {
		/* translators: %s: post title */
		printf( esc_html__( 'The first one becomes the featured image of %s.', 'bulk-editor' ), '<b>' . esc_html( $post_title ) . '</b>' );
	} else {
		esc_html_e( 'They go into the media library, ready to put on a post.', 'bulk-editor' );
	}
	?>
</p>

<div id="drop">
	<b><?php esc_html_e( 'Drop images here', 'bulk-editor' ); ?></b><br>
	<small><?php esc_html_e( 'or click to choose them', 'bulk-editor' ); ?></small>
	<input type="file" id="file" accept="image/*" multiple hidden>
</div>

<ul id="list"></ul>
<p id="summary" style="font-weight:600;margin-top:1rem"></p>

<script>
( function () {

	var drop = document.getElementById( 'drop' );
	var file = document.getElementById( 'file' );
	var list = document.getElementById( 'list' );
	var summary = document.getElementById( 'summary' );
	var url  = <?php echo wp_json_encode( rest_url( 'wpbe/v1/mcp/upload/' . $token ) ); ?>;
	var maxBytes = <?php echo intval( wp_max_upload_size() ); ?>;

	drop.addEventListener( 'click', function () { file.click(); } );

	[ 'dragenter', 'dragover' ].forEach( function ( e ) {
		drop.addEventListener( e, function ( ev ) { ev.preventDefault(); drop.classList.add( 'over' ); } );
	} );

	[ 'dragleave', 'drop' ].forEach( function ( e ) {
		drop.addEventListener( e, function ( ev ) { ev.preventDefault(); drop.classList.remove( 'over' ); } );
	} );

	drop.addEventListener( 'drop', function ( ev ) { send( ev.dataTransfer.files ); } );
	file.addEventListener( 'change', function () { send( file.files ); } );

	function send( files ) {

		// One at a time, deliberately. Sent in parallel, every request reads the
		// post before any of them has written to it: each sees no featured
		// image, each makes itself the featured one, and the last to finish
		// wins. The same race loses the record of what arrived. A queue is
		// slower by a second and correct.
		var queue = Array.prototype.slice.call( files );
		var total = queue.length;
		var done  = 0;
		var bad   = 0;

		if ( ! total ) {
			return;
		}

		summary.className = '';
		summary.textContent = '0 / ' + total;

		function next() {

			// nothing left: say how it went rather than leaving the last row
			// as the only clue, which on twenty files tells the user nothing
			if ( ! queue.length ) {
				summary.className = bad ? 'bad' : 'ok';
				summary.textContent = bad
					? ( done + ' of ' + total + ' uploaded, ' + bad + ' failed' )
					: ( 'All ' + total + ' uploaded' );
				return;
			}

			var f = queue.shift();
			var n = total - queue.length;

			var row = document.createElement( 'li' );
			var name = document.createElement( 'span' );
			var state = document.createElement( 'span' );

			name.textContent = n + '. ' + f.name;
			state.textContent = 'sending';
			row.appendChild( name );
			row.appendChild( state );
			list.appendChild( row );

			// checked here as well as on the server: a file over the server's
			// body limit never reaches PHP at all - nginx cuts the connection
			// and answers with its own HTML, which is why an oversized upload
			// used to fail with no reason given
			if ( maxBytes && f.size > maxBytes ) {
				++bad;
				state.className = 'bad';
				state.textContent = 'too large (' + Math.round( f.size / 1048576 ) + ' MB, limit ' + Math.round( maxBytes / 1048576 ) + ' MB)';
				summary.textContent = ( done + bad ) + ' / ' + total;
				next();
				return;
			}

			var body = new FormData();
			body.append( 'file', f );

			fetch( url, { method: 'POST', body: body } )
				.then( function ( r ) {
					return r.json().catch( function () {
						// not JSON: the web server answered before PHP did
						return { message: 'rejected by the server (' + r.status + ')' };
					} );
				} )
				.then( function ( j ) {
					if ( j && j.id ) {
						++done;
						state.className = 'ok';
						state.textContent = j.message ? j.message : 'added';
					} else {
						++bad;
						state.className = 'bad';
						state.textContent = ( j && j.message ) ? j.message : 'failed';
					}
				} )
				.catch( function ( e ) {
					++bad;
					state.className = 'bad';
					state.textContent = 'connection lost';
				} )
				.finally( function () {
					summary.textContent = ( done + bad ) + ' / ' + total;
					next();
				} );
		}

		next();
	}
} )();
</script>
		<?php
		exit;
	}

	/**
	 * Receives one file and records it against the token, so the assistant can
	 * ask what arrived rather than searching the library for something new.
	 *
	 * The link carries who asked for it. A link issued through a personal key
	 * works as that user - his capabilities, his Author Area, his history rows -
	 * and stops working the moment he loses his MCP access; one issued through
	 * the site-wide key works as that key.
	 */
	public static function upload_receive( $request ) {

		$token = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'token' ) );
		$data  = '' !== $token ? get_transient( 'wpbe_mcp_upload_' . $token ) : false;

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wpbe_mcp_upload_expired', 'This upload link has expired.', array( 'status' => 403 ) );
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
			return new WP_Error( 'wpbe_mcp_no_file', 'No file arrived.', array( 'status' => 400 ) );
		}

		// A link is fifteen minutes of open upload, and without a ceiling that
		// is fifteen minutes of somebody filling the disk. The size limit comes
		// from what this server already accepts - the smaller of PHP's
		// upload_max_filesize and post_max_size - because a second number
		// invented here would either contradict PHP or be ignored by it.
		$max_files = intval( apply_filters( 'wpbe_mcp_upload_max_files', 20 ) );
		$max_bytes = intval( apply_filters( 'wpbe_mcp_upload_max_bytes', wp_max_upload_size() ) );

		if ( count( $data['uploaded'] ) >= $max_files ) {
			return new WP_REST_Response(
				array( 'message' => 'limit of ' . $max_files . ' reached' ),
				200
			);
		}

		if ( $max_bytes > 0 && intval( $files['file']['size'] ) > $max_bytes ) {
			return new WP_REST_Response(
				array( 'message' => 'too large, limit ' . size_format( $max_bytes ) ),
				200
			);
		}

		$personal = isset( $data['personal'] ) ? absint( $data['personal'] ) : 0;

		if ( $personal ) {

			if ( ! self::may_use_personal_key( $personal ) ) {
				return new WP_Error( 'wpbe_mcp_upload_revoked', 'The user who asked for this link no longer has MCP access.', array( 'status' => 403 ) );
			}

			self::$personal_user_id = $personal;
			wp_set_current_user( $personal );
		}

		self::$token_authenticated = true;

		$ext = self::ext();

		if ( is_wp_error( $ext ) ) {
			return $ext;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$pack = $ext->pack( 'media' );

		if ( ! $pack ) {
			return new WP_Error( 'wpbe_mcp_no_media_pack', 'The media pack is not installed on this site.', array( 'status' => 500 ) );
		}

		// One file at a time per link, whoever sends them: the list of what
		// arrived is read again and written under the link's lock, or files
		// sent at once each wrote a list with only their own name in it (the
		// upload page queues its files; a script need not)
		$lock = $pack::lock( 'upload_' . $token );

		try {
			wp_cache_delete( '_transient_wpbe_mcp_upload_' . $token, 'options' );
			$data = get_transient( 'wpbe_mcp_upload_' . $token );

			if ( ! is_array( $data ) ) {
				return new WP_Error( 'wpbe_mcp_upload_expired', 'This upload link has expired.', array( 'status' => 403 ) );
			}

			if ( count( $data['uploaded'] ) >= $max_files ) {
				return new WP_REST_Response( array( 'message' => 'limit of ' . $max_files . ' reached' ), 200 );
			}

			// the featured image goes to the first file only: a post has one, and
			// every later file would replace the one before
			$post_id = ( intval( $data['post_id'] ) && empty( $data['uploaded'] ) ) ? intval( $data['post_id'] ) : 0;

			$result = $pack->sideload(
				array(
					'name'     => $files['file']['name'],
					'tmp_name' => $files['file']['tmp_name'],
				),
				$post_id
			);

			// answered as a normal body rather than an error: the page shows the
			// message beside the file name, and one rejected image should not look
			// like the whole upload failed
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 200 );
			}

			$data['uploaded'][] = $result['attachment']['id'];
			set_transient( 'wpbe_mcp_upload_' . $token, $data, 900 );
		} finally {
			$pack::unlock( $lock );
		}

		return new WP_REST_Response(
			array(
				'id'      => $result['attachment']['id'],
				'file'    => $result['attachment']['file'],
				'message' => ! empty( $result['attached'] ) ? 'added, featured image' : 'added',
			),
			200
		);
	}
}

WPBE_MCP_BOOT::init();
