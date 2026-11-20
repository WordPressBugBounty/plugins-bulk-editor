<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * The media library and the featured image of a post.
 *
 * What this can and cannot do is worth being plain about, because the obvious
 * expectation is the one thing that does not work.
 *
 * An image pasted into a chat cannot be put on the site. The assistant sees a
 * picture, not a file, and has no way to hand the bytes to a server. What it
 * can do is fetch an image from a URL, take one through a short lived upload
 * page, and use images already in the media library.
 *
 * It also cannot show the user his own images. The sandbox that renders replies
 * will not load files from the site's domain, so a gallery cannot be displayed
 * for picking. wpbe_media_list therefore returns names, dates, sizes and a
 * link per image: the owner opens what he needs in another tab and says which
 * ones he wants by name or number. Clumsier than thumbnails, and honest.
 *
 * A featured image is a field of the post (_thumbnail_id) and is written as
 * one: through the same model call as a table edit, so it lands in History
 * and can be rolled back, it is closed where the field is closed, and it
 * draws on the free build's allowance like every other write.
 */
final class WPBE_MCP_TOOL_MEDIA extends WPBE_MCP_TOOL {

	const UPLOAD_PREFIX = 'wpbe_mcp_upload_';
	const UPLOAD_TTL    = 900;

	public function tools() {

		return array(

			'wpbe_media_list' => array(
				'name'        => 'wpbe_media_list',
				'sector'      => 'media',
				'description' => 'Images in the media library, newest first, with id, file name, upload date, size and a link. Use it when the user refers to images he uploaded rather than giving ids - "the photos I put up yesterday" is answered by listing that day and reading the names back. The images themselves cannot be displayed in the conversation, so let him open the links and tell you which ones he means.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'         => array(
							'type'        => 'string',
							'description' => 'Part of a file name or title.',
						),
						'uploaded_after' => array(
							'type'        => 'string',
							'description' => 'Anything strtotime reads - "yesterday", "-7 days", "2026-09-01".',
						),
						'unattached'     => array(
							'type'        => 'boolean',
							'description' => 'Only images not attached to any post yet. Useful right after a batch upload.',
						),
						'limit'          => array( 'type' => 'integer' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'wpbe_post_image' => array(
				'name'        => 'wpbe_post_image',
				'sector'      => 'media',
				'description' => 'The featured image a post has now. Read this before changing it so the user hears what is there. Only for post types that support thumbnails.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'wpbe_set_image' => array(
				'name'        => 'wpbe_set_image',
				'sector'      => 'media',
				'description' => 'Sets the featured image of a post from an image already in the media library, or removes it with 0. Only for post types that support thumbnails. Say what will change before calling it: the previous image is kept in History and can be restored from the History tab in WOLF.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'image_id' => array(
							'type'        => 'integer',
							'description' => 'Attachment id of the image. 0 removes the featured image.',
						),
					),
					'required'   => array( 'post_id', 'image_id' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'wpbe_upload_link' => array(
				'name'        => 'wpbe_upload_link',
				'sector'      => 'media',
				'description' => 'Gives the user a link to a small upload page for this site, where he drags photos from his computer straight into the media library. This is the way to get images off somebody\'s desktop: a picture pasted into a chat cannot be forwarded to a server. The link lasts fifteen minutes and needs no login. With post_id, the first image becomes that post\'s featured image.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Optional. The first image uploaded becomes the featured image of this post.',
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'wpbe_upload_status' => array(
				'name'        => 'wpbe_upload_status',
				'sector'      => 'media',
				'description' => 'What has arrived through an upload link so far. Call it after the user says he has dropped his files - it tells you the ids and names without hunting through the library.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'token' => array( 'type' => 'string' ),
					),
					'required'   => array( 'token' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'wpbe_upload_image' => array(
				'name'        => 'wpbe_upload_image',
				'sector'      => 'media',
				'description' => 'Puts an image into the media library from base64 data. For agents that hold the file themselves - a coding assistant with disk access, a script. In a chat this is the wrong tool: encoding a photograph as text costs more than the whole conversation around it, so offer wpbe_upload_link instead. Refused above one megabyte for the same reason. With post_id the image becomes that post\'s featured image.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'filename' => array(
							'type'        => 'string',
							'description' => 'File name with its extension, which decides the type.',
						),
						'data'     => array(
							'type'        => 'string',
							'description' => 'The file, base64 encoded. A data: prefix is accepted and stripped.',
						),
						'post_id'  => array( 'type' => 'integer' ),
						'alt'      => array( 'type' => 'string' ),
					),
					'required'   => array( 'filename', 'data' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),

			'wpbe_import_image' => array(
				'name'        => 'wpbe_import_image',
				'sector'      => 'media',
				'description' => 'Downloads an image from a URL into the media library and optionally makes it the featured image of a post. Use it when the picture is already online. When the photo is on the user\'s computer, give him wpbe_upload_link instead.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'     => array(
							'type'        => 'string',
							'description' => 'Direct https link to the image file itself, not to a page containing it.',
						),
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Optional. Make it the featured image of this post once downloaded.',
						),
						'title'   => array( 'type' => 'string' ),
						'alt'     => array(
							'type'        => 'string',
							'description' => 'Alt text. Worth setting: it is what a search engine and a screen reader read.',
						),
					),
					'required'   => array( 'url' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'wpbe_media_list':
				return $this->media_list( $args );
			case 'wpbe_post_image':
				return $this->post_image( $args );
			case 'wpbe_set_image':
				return $this->set_image( $args );
			case 'wpbe_import_image':
				return $this->import_image( $args );
			case 'wpbe_upload_link':
				return $this->upload_link( $args );
			case 'wpbe_upload_status':
				return $this->upload_status( $args );
			case 'wpbe_upload_image':
				return $this->upload_image( $args );
		}

		return new WP_Error( 'wpbe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function media_list( $args ) {

		$query = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $args['limit'] ) ? min( 100, max( 1, intval( $args['limit'] ) ) ) : 30,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['search'] ) && is_scalar( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( (string) $args['search'] );
		}

		if ( ! empty( $args['uploaded_after'] ) && is_scalar( $args['uploaded_after'] ) ) {

			$after = strtotime( (string) $args['uploaded_after'] );

			if ( $after ) {
				$query['date_query'] = array( array( 'after' => gmdate( 'Y-m-d H:i:s', $after ) ) );
			}
		}

		// nothing attached yet: what a batch upload leaves behind
		if ( ! empty( $args['unattached'] ) ) {
			$query['post_parent'] = 0;
		}

		$rows = array();

		foreach ( get_posts( $query ) as $post ) {
			$row            = $this->describe( $post->ID );
			$row['uploaded'] = $post->post_date;
			$row['used_by'] = $this->used_by( $post->ID );
			$rows[]         = $row;
		}

		return array(
			'count' => count( $rows ),
			'rows'  => $rows,
			'note'  => 'The images cannot be shown in the conversation - this connection can read the library but the reply cannot display files from the site. Read the names and dates back to the user and let him open the links to check; then he tells you which ids he wants. used_by names the posts already using each image as featured, which is often enough for him to recognise it without opening anything.',
		);
	}

	/**
	 * Which posts already use this image as their featured image. Recognising
	 * "the one on the About page" is easier than recognising IMG_4471.jpg.
	 */
	private function used_by( $attachment_id ) {

		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 5", (string) intval( $attachment_id ) ) );

		$out = array();

		foreach ( (array) $ids as $post_id ) {
			$out[] = $this->post_label( $post_id ) . ' (' . get_post_type( $post_id ) . ' #' . intval( $post_id ) . ')';
		}

		return $out;
	}

	private function describe( $attachment_id ) {

		$meta = wp_get_attachment_metadata( $attachment_id );
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		return array(
			'id'    => intval( $attachment_id ),
			'title' => WPBE_MCP::plain_name( get_the_title( $attachment_id ) ),
			'file'  => $file ? basename( $file ) : '',
			'size'  => ( isset( $meta['width'], $meta['height'] ) ) ? $meta['width'] . 'x' . $meta['height'] : '',
			'url'   => wp_get_attachment_url( $attachment_id ),
			'alt'   => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	private function is_image( $attachment_id ) {

		$attachment_id = intval( $attachment_id );

		return $attachment_id
			&& 'attachment' === get_post_type( $attachment_id )
			&& 0 === strpos( (string) get_post_mime_type( $attachment_id ), 'image/' );
	}

	/**
	 * A post whose featured image this call may change: checked as every post
	 * id is (WPBE_MCP::use_post_of()), its type supporting thumbnails, the
	 * field open to this identity and edition, and - a featured image changes
	 * the post - write access to posts besides media.
	 *
	 * @return true|WP_Error
	 */
	private function may_set_thumbnail( $post_id ) {

		$post_type = $this->one_post( $post_id );

		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
			return new WP_Error( 'wpbe_mcp_no_thumbnail_support', $post_type . ' has no featured image on this site - its type does not support thumbnails. Nothing was changed.' );
		}

		$problem = $this->mcp->field_write_problem( '_thumbnail_id' );

		if ( $problem ) {
			return $problem;
		}

		return $this->require_access( 'posts', true, 'sets the featured image of post #' . intval( $post_id ) );
	}

	/**
	 * The featured image written as the table writes it: through the model,
	 * which records the previous one in History first. One post of the free
	 * build's allowance, checked before and taken after.
	 *
	 * @return true|WP_Error
	 */
	private function write_thumbnail( $post_id, $attachment_id ) {

		$quota = $this->mcp->quota_allows( 1 );

		if ( is_wp_error( $quota ) ) {
			return $quota;
		}

		$this->posts()->update_page_field( intval( $post_id ), '_thumbnail_id', intval( $attachment_id ) );

		// set_post_thumbnail(), which the model calls, does nothing with 0 - the
		// removal is done here, after History has recorded the image that was there
		if ( ! intval( $attachment_id ) ) {
			delete_post_thumbnail( intval( $post_id ) );
		}

		$this->mcp->quota_spend( 1 );
		$this->mcp->clear_caches_for( array( $post_id ) );

		return true;
	}

	private function post_image( $args ) {

		$post_id   = isset( $args['post_id'] ) ? intval( $args['post_id'] ) : 0;
		$post_type = $this->one_post( $post_id );

		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
			return new WP_Error( 'wpbe_mcp_no_thumbnail_support', $post_type . ' has no featured image on this site - its type does not support thumbnails.' );
		}

		$featured = intval( get_post_thumbnail_id( $post_id ) );

		return array(
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'title'     => $this->post_label( $post_id ),
			'featured'  => $featured ? $this->describe( $featured ) : null,
		);
	}

	/**
	 * Featured images of one post are changed one request at a time.
	 *
	 * Each request reads what is there and writes over it: two at once both
	 * read the old state, and the history row of the first is lost under the
	 * second. A MySQL named lock holds the second request until the first has
	 * written - 30 seconds at most.
	 */
	public static function lock( $key ) {
		global $wpdb;

		$name = 'wpbe_mcp_' . md5( (string) $key );
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 30)', $name ) );

		return $name;
	}

	public static function unlock( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	private function set_image( $args ) {

		$post_id  = isset( $args['post_id'] ) ? intval( $args['post_id'] ) : 0;
		$image_id = isset( $args['image_id'] ) ? intval( $args['image_id'] ) : 0;

		$allowed = $this->may_set_thumbnail( $post_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( $image_id && ! $this->is_image( $image_id ) ) {
			return new WP_Error( 'wpbe_mcp_not_an_image', 'Attachment ' . $image_id . ' is not an image in this library. Nothing was changed.' );
		}

		$lock = self::lock( 'post_' . $post_id );

		try {
			$before  = intval( get_post_thumbnail_id( $post_id ) );
			$written = $this->write_thumbnail( $post_id, $image_id );
		} finally {
			self::unlock( $lock );
		}

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$after = intval( get_post_thumbnail_id( $post_id ) );

		return array(
			'post_id'  => $post_id,
			'title'    => $this->post_label( $post_id ),
			'before'   => $before ? $this->describe( $before ) : null,
			'featured' => $after ? $this->describe( $after ) : null,
			'note'     => 'Say what the post has now.' . ( $before ? ' The previous image is in History and can be put back from the History tab in WOLF.' : '' ),
		);
	}

	/**
	 * Refuses addresses that point back inside the network.
	 *
	 * Fetching a URL on request is a door into wherever the server can reach,
	 * and a server can usually reach far more than the internet can: the
	 * metadata service of a cloud host, a database admin panel on localhost, a
	 * router on the office LAN. The caller holds a valid key, so this is not
	 * the first line of defence - but a key is one secret with no expiry, and
	 * a copied one should not come with the site's private network attached.
	 *
	 * Returns an explanation when the address is refused, or an empty string
	 * when it is fine.
	 */
	private static function blocked_host( $url ) {

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return 'That address has no host in it.';
		}

		// a literal address is checked as it stands; a name has to be resolved
		// first, or "internal.example.com" pointing at 10.0.0.5 walks straight
		// past a check on the text
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : array();

		if ( empty( $ips ) ) {

			$resolved = gethostbynamel( $host );
			$ips      = is_array( $resolved ) ? $resolved : array();

			// IPv6 is not covered by gethostbynamel, and an address that only
			// resolves over v6 cannot be checked here at all
			if ( empty( $ips ) ) {
				return 'That host could not be resolved.';
			}
		}

		foreach ( $ips as $ip ) {

			// FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE together cover
			// 10.x, 172.16-31.x, 192.168.x, 127.x, 169.254.x, ::1 and the
			// reserved blocks - which is every way back into the network
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return 'That address points inside the server\'s own network (' . $ip . '), which this tool will not fetch from. Give a public link to the image, or use the upload page instead - wpbe_upload_link.';
			}
		}

		return '';
	}

	private function import_image( $args ) {

		$url = isset( $args['url'] ) && is_scalar( $args['url'] ) ? esc_url_raw( trim( (string) $args['url'] ) ) : '';

		if ( '' === $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'wpbe_mcp_bad_url', 'That is not an http or https address.' );
		}

		$blocked = self::blocked_host( $url );

		if ( $blocked ) {
			return new WP_Error( 'wpbe_mcp_blocked_host', $blocked );
		}

		$post_id = isset( $args['post_id'] ) ? intval( $args['post_id'] ) : 0;

		// checked before the download: the image must not end up in the
		// library for a post this call may not change
		if ( $post_id ) {

			$allowed = $this->may_set_thumbnail( $post_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// download_url follows redirects, so a public address can hand the
		// request straight to a private one. Five is WordPress's own default
		// and the filter is scoped to this request only.
		$limit_redirects = function () {
			return 0;
		};

		add_filter( 'http_request_redirection_count', $limit_redirects, 99 );

		$tmp = download_url( $url, 30 );

		remove_filter( 'http_request_redirection_count', $limit_redirects, 99 );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'wpbe_mcp_download_failed', 'Could not fetch that address: ' . $tmp->get_error_message() );
		}

		return $this->sideload(
			array(
				'name'     => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
				'tmp_name' => $tmp,
			),
			$post_id,
			isset( $args['alt'] ) && is_scalar( $args['alt'] ) ? sanitize_text_field( (string) $args['alt'] ) : '',
			isset( $args['title'] ) && is_scalar( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : null
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// getting images off somebody's computer

	/**
	 * A short lived page the user can drop files onto.
	 *
	 * The token is the credential: single purpose, expiring, and tied to one
	 * post if a post was named. Losing it exposes the ability to add an image
	 * for a quarter of an hour, not the site. It remembers who asked for it, so
	 * the upload acts as that identity (WPBE_MCP_BOOT::upload_receive()).
	 */
	private function upload_link( $args ) {

		$post_id = isset( $args['post_id'] ) ? intval( $args['post_id'] ) : 0;

		// the page puts the first image on this post, so the post has to be
		// writable for whoever asks for the link
		if ( $post_id ) {

			$allowed = $this->may_set_thumbnail( $post_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$token = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::UPLOAD_PREFIX . $token,
			array(
				'post_id'  => $post_id,
				'personal' => WPBE_MCP_BOOT::personal_user_id(),
				'uploaded' => array(),
			),
			self::UPLOAD_TTL
		);

		return array(
			'upload_url' => rest_url( 'wpbe/v1/mcp/upload/' . $token ),
			'token'      => $token,
			'post_id'    => $post_id ? $post_id : null,
			'post'       => $post_id ? $this->post_label( $post_id ) : null,
			'expires_in' => self::UPLOAD_TTL,
			'note'       => 'Give the user the link as it is - it opens a page where he drags his photos in, and it needs no login. Tell him it works for fifteen minutes. When he says he is done, call wpbe_upload_status with the token to see what arrived; do not guess, and do not go looking through the whole media library.',
		);
	}

	private function upload_status( $args ) {

		$token = isset( $args['token'] ) && is_scalar( $args['token'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $args['token'] ) : '';
		$data  = $token ? get_transient( self::UPLOAD_PREFIX . $token ) : false;

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wpbe_mcp_upload_expired',
				'That upload link has expired or was never issued. Ask wpbe_upload_link for a new one - anything already uploaded is safe in the library and wpbe_media_list finds it.'
			);
		}

		$rows = array();

		foreach ( $data['uploaded'] as $attachment_id ) {
			$rows[] = $this->describe( $attachment_id );
		}

		return array(
			'count'   => count( $rows ),
			'images'  => $rows,
			'post_id' => $data['post_id'] ? $data['post_id'] : null,
			'note'    => empty( $rows )
				? 'Nothing has arrived yet. The user may still be choosing files - ask before assuming something went wrong, and do not issue a second link, the first one still works.'
				: 'These are in the library now' . ( $data['post_id'] ? ', and the first one is the featured image of the post' : '' ) . '. Read the file names back so the user can confirm they are the ones he meant.',
		);
	}

	/**
	 * The same thing for an agent that already holds the file.
	 *
	 * Sensible from a script or a coding assistant, wasteful from a chat: the
	 * ceiling is there to make the wrong caller notice before it has spent the
	 * conversation on one photograph.
	 */
	private function upload_image( $args ) {

		$filename = isset( $args['filename'] ) && is_scalar( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';
		$data     = isset( $args['data'] ) && is_scalar( $args['data'] ) ? (string) $args['data'] : '';
		$post_id  = isset( $args['post_id'] ) ? intval( $args['post_id'] ) : 0;

		if ( '' === $filename || '' === $data ) {
			return new WP_Error( 'wpbe_mcp_no_file', 'Both filename and data are needed.' );
		}

		if ( $post_id ) {

			$allowed = $this->may_set_thumbnail( $post_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		// a data: URL is what most callers have to hand
		if ( 0 === strpos( $data, 'data:' ) ) {
			$comma = strpos( $data, ',' );
			$data  = false === $comma ? '' : substr( $data, $comma + 1 );
		}

		$bytes = base64_decode( $data, true );

		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'wpbe_mcp_bad_base64', 'That is not valid base64 data.' );
		}

		if ( strlen( $bytes ) > MB_IN_BYTES ) {
			return new WP_Error(
				'wpbe_mcp_upload_too_big',
				'That file is ' . size_format( strlen( $bytes ) ) . ', and this route is capped at one megabyte because the data travels as text. Use wpbe_upload_link instead: the user drops the file in a browser and it goes straight into the library at any size.'
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $filename );

		if ( ! $tmp ) {
			return new WP_Error( 'wpbe_mcp_tmp_failed', 'Could not create a temporary file.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $bytes );

		return $this->sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			$post_id,
			isset( $args['alt'] ) && is_scalar( $args['alt'] ) ? sanitize_text_field( (string) $args['alt'] ) : ''
		);
	}

	/**
	 * One path into the library for every caller: the upload page, the base64
	 * route and the URL import all end here, so an image is validated and
	 * attached the same way whichever door it came through. With a post, the
	 * image becomes its featured image - after the same checks a direct
	 * wpbe_set_image makes, and through the same write.
	 */
	public function sideload( $file, $post_id = 0, $alt = '', $title = null ) {

		if ( $post_id ) {

			$allowed = $this->may_set_thumbnail( $post_id );

			if ( is_wp_error( $allowed ) ) {
				wp_delete_file( $file['tmp_name'] );
				return $allowed;
			}
		}

		// media_handle_sideload checks the file type itself and refuses
		// anything that is not really an image, whatever the name claimed
		$attachment_id = media_handle_sideload( $file, $post_id, $title );

		if ( is_wp_error( $attachment_id ) ) {

			wp_delete_file( $file['tmp_name'] );

			return new WP_Error( 'wpbe_mcp_sideload_failed', 'The file was not accepted: ' . $attachment_id->get_error_message() );
		}

		if ( ! $this->is_image( $attachment_id ) ) {
			// our own upload of a moment ago, not something the user had
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'wpbe_mcp_not_an_image', 'That file is not an image.' );
		}

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		$attached = false;
		$note     = 'In the library, not on any post yet. wpbe_set_image puts it on one.';

		if ( $post_id ) {

			$lock = self::lock( 'post_' . $post_id );

			try {
				$written = $this->write_thumbnail( $post_id, $attachment_id );
			} finally {
				self::unlock( $lock );
			}

			if ( is_wp_error( $written ) ) {
				// the image stays in the library: only the featured image waits
				$note = 'In the library, but not set as the featured image: ' . $written->get_error_message();
			} else {
				$attached = true;
				$note     = 'In the library and set as the featured image of ' . $this->post_label( $post_id ) . '. The previous one is in History.';
			}
		}

		return array(
			'attachment' => $this->describe( $attachment_id ),
			'post_id'    => $post_id ? $post_id : null,
			'attached'   => $attached,
			'note'       => $note,
		);
	}
}
