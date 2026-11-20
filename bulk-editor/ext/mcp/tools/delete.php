<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Deleting posts, and getting them back.
 *
 * The one part of this connection where WOLF's own safety net does not reach.
 * History records the previous value of a field before it is overwritten; a
 * deleted post has no previous value to write down, so wpbe_rollback_bulk
 * cannot bring anything back and nothing here pretends otherwise.
 *
 * What takes its place is the trash, and the rule of the owner: a deletion
 * through this connection can always be undone. Posts are trashed and never
 * destroyed, and wpbe_restore_posts puts them back. Where WordPress has no
 * trash to offer - EMPTY_TRASH_DAYS set to 0 turns wp_trash_post() into a
 * permanent delete, and media without MEDIA_TRASH have no trash screen to come
 * back from - the tool refuses and says that such posts are deleted by hand
 * only. A plugin that hooks pre_trash_post may still decide otherwise; the
 * answer then reports what WordPress actually did, post by post.
 */
final class WPBE_MCP_TOOL_DELETE extends WPBE_MCP_TOOL {

	const MAX_AT_ONCE = 500;

	public function tools() {

		$target = array(
			'selection_id' => array( 'type' => 'string' ),
			'ids'          => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'post_type'    => array(
				'type'        => 'string',
				'description' => 'Optional. Taken from the selection or from the posts; all of them have to be of one type.',
			),
		);

		return array(

			'wpbe_delete_preview' => array(
				'name'        => 'wpbe_delete_preview',
				'sector'      => 'posts',
				'description' => 'What a deletion would remove: the posts by title and status, how many child posts the hierarchical ones have, and whether this post type can go to the trash on this site. Always run this first and read it out - deletion is not in WOLF history, so rollback cannot undo it: posts come back only from the trash.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => $target,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'wpbe_delete_posts' => array(
				'name'        => 'wpbe_delete_posts',
				'sector'      => 'posts',
				'description' => 'Moves the selected posts to the trash, the same as the delete button on the editor screen. They leave the site at once and come back with wpbe_restore_posts or from the Trash in wp-admin. Never deletes for good: where this site has no trash for the type, it refuses. Requires confirm_count equal to the selection size and an explicit yes from the user, asked for after showing wpbe_delete_preview.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$target,
						array(
							'confirm_count' => array(
								'type'        => 'integer',
								'description' => 'Must equal the number of posts in the selection. The same guard as bulk editing: an agent that did not look at the number cannot guess it.',
							),
							'confirmed'     => array(
								'type'        => 'boolean',
								'description' => 'Set only after the user has said yes to deleting this specific set, in his own words, having seen the preview.',
							),
						)
					),
					'required'   => array( 'confirm_count' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => false,
				),
			),

			'wpbe_trash_list' => array(
				'name'        => 'wpbe_trash_list',
				'sector'      => 'posts',
				'description' => 'Posts currently in the trash, newest first, with when they were trashed and what status they will return to - of one post type, or of every type this connection may use. Call this when the user changes his mind about a deletion, or asks what happened to a post he cannot find.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Optional. Only this post type.',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Optional title fragment, for a site with a lot in the trash.',
						),
						'limit'     => array( 'type' => 'integer' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'wpbe_restore_posts' => array(
				'name'        => 'wpbe_restore_posts',
				'sector'      => 'posts',
				'description' => 'Brings posts back from the trash, to the status they had before they were deleted. Use it the moment a user regrets a deletion - the ids are in the answer wpbe_delete_posts gave, and wpbe_trash_list finds them otherwise. Only posts of the types this connection may use come back, and only those this account may edit.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Posts to bring back. Leave it out with all_recent set to restore everything trashed in the last hour.',
						),
						'all_recent' => array(
							'type'        => 'boolean',
							'description' => 'Restore everything trashed within the last hour, of the types this connection may use. For "undo what you just did" when the ids are not to hand.',
						),
						'status'     => array(
							'type'        => 'string',
							'description' => 'Force a status instead of the one each post had before. Rarely needed - by default a published post comes back published and a draft comes back a draft.',
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'wpbe_delete_preview':
				return $this->preview( $args );
			case 'wpbe_delete_posts':
				return $this->delete( $args );
			case 'wpbe_trash_list':
				return $this->trash_list( $args );
			case 'wpbe_restore_posts':
				return $this->restore( $args );
		}

		return new WP_Error( 'wpbe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	/**
	 * Why posts of this type cannot go to the trash here, or ''.
	 *
	 * wp_trash_post() itself answers EMPTY_TRASH_DAYS = 0 with wp_delete_post(
	 * $id, true): the post is gone for good, and the same call that trashes
	 * on one site destroys on another. Media without MEDIA_TRASH have no trash
	 * screen in wp-admin, so a trashed attachment would vanish from every list
	 * the owner can open.
	 */
	public static function no_trash_reason( $post_type ) {

		if ( ! EMPTY_TRASH_DAYS ) {
			return 'This site has the trash switched off (EMPTY_TRASH_DAYS is 0), so WordPress would delete these posts for good. Deleting through this connection is always undoable, so it is refused here: such posts can only be deleted by hand in wp-admin.';
		}

		if ( 'attachment' === $post_type && ! MEDIA_TRASH ) {
			return 'Media on this site have no trash (MEDIA_TRASH is off), so a deleted file could not be brought back from wp-admin. Deleting through this connection is always undoable, so it is refused here: media files can only be deleted by hand in the Media Library.';
		}

		return '';
	}

	/**
	 * A personal key deletes only what its user may delete by hand.
	 */
	private function delete_problem( $ids ) {

		if ( ! WPBE_MCP_BOOT::personal_user_id() ) {
			return null;
		}

		$refused = array();

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'delete_post', intval( $id ) ) ) {
				$refused[] = intval( $id );
			}
		}

		return $refused ? new WP_Error( 'wpbe_mcp_bad_ids', 'Nothing was deleted. Your WordPress account may not delete: ' . implode( ', ', array_slice( $refused, 0, 30 ) ) . '.' ) : null;
	}

	private function preview( $args ) {

		$target = $this->ids( $args );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$ids       = $target['ids'];
		$post_type = $target['post_type'];
		$rows      = array();
		$children  = 0;

		$hierarchical = is_post_type_hierarchical( $post_type );

		foreach ( array_slice( $ids, 0, 100 ) as $post_id ) {

			$kids = $hierarchical ? count(
				get_posts(
					array(
						'post_type'      => $post_type,
						'post_parent'    => $post_id,
						'post_status'    => 'any',
						'fields'         => 'ids',
						'posts_per_page' => -1,
					)
				)
			) : 0;

			$children += $kids;

			$rows[] = array(
				'id'       => intval( $post_id ),
				'title'    => $this->post_label( $post_id ),
				'status'   => get_post_status( $post_id ),
				'children' => $kids,
			);
		}

		$reason = self::no_trash_reason( $post_type );

		return array(
			'post_type'     => $post_type,
			'count'         => count( $ids ),
			// what wpbe_delete_posts takes, said here as find_posts says it,
			// so nobody learns it from a refusal
			'confirm_count' => count( $ids ),
			'can_delete'    => '' === $reason,
			'what_happens'  => '' === $reason
				? 'These posts go to the trash. They leave the site at once and can be brought back with wpbe_restore_posts, or from the Trash in wp-admin.' . ( $children ? ' ' . $children . ' child posts of them stay where they are, with a parent in the trash - say so.' : '' )
				: $reason,
			'children'      => $children,
			'posts'         => $rows,
			'previewed'     => count( $rows ),
			'note'          => '' === $reason
				? 'Read this out before asking for a yes: name a few of the posts, give the total, and say where they can be recovered from. Then call wpbe_delete_posts with the same selection or ids, confirm_count ' . count( $ids ) . ' and confirmed true.'
				: 'Tell the user this cannot be done through this connection, and why. Do not look for another way round.',
		);
	}

	private function delete( $args ) {

		$target = $this->ids( $args );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$ids       = $target['ids'];
		$post_type = $target['post_type'];

		$reason = self::no_trash_reason( $post_type );

		if ( '' !== $reason ) {
			return new WP_Error( 'wpbe_mcp_no_trash', 'Nothing was deleted. ' . $reason );
		}

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'wpbe_mcp_not_confirmed',
				'Deletion needs an explicit yes from the user, and he should have seen wpbe_delete_preview before giving it. Nothing was deleted.'
			);
		}

		$confirm = isset( $args['confirm_count'] ) ? intval( $args['confirm_count'] ) : -1;

		if ( count( $ids ) !== $confirm ) {
			return new WP_Error(
				'wpbe_mcp_confirm_mismatch',
				( $confirm < 0 ? 'confirm_count was not given' : 'confirm_count is ' . $confirm ) . ' but this selection holds ' . count( $ids ) . ' posts. Nothing was deleted. Re-read the count and confirm it - on a deletion this guard matters more than anywhere else.'
			);
		}

		if ( count( $ids ) > self::MAX_AT_ONCE ) {
			return new WP_Error(
				'wpbe_mcp_delete_too_many',
				'That is ' . count( $ids ) . ' posts. Deletion through this connection is capped at ' . self::MAX_AT_ONCE
				. ' at a time - a deliberate limit rather than a technical one, because a mistaken selection of thousands is unpleasant to undo even from the trash. Narrow it, or use the plugin screen where the same operation runs in front of somebody watching.'
			);
		}

		$refused = $this->delete_problem( $ids );

		if ( $refused ) {
			return $refused;
		}

		$done   = array();
		$failed = array();

		foreach ( $ids as $post_id ) {

			$post_id = intval( $post_id );

			// trash rather than destroy, the same as the editor's delete button -
			// and checked afterwards: a plugin on pre_trash_post may have said no
			wp_trash_post( $post_id );

			if ( 'trash' === get_post_status( $post_id ) ) {
				$done[] = $post_id;
			} else {
				$failed[] = $post_id;
			}
		}

		$this->mcp->clear_caches_for( $ids );

		return array(
			'post_type'    => $post_type,
			'deleted'      => count( $done ),
			'ids'          => array_slice( $done, 0, 200 ),
			'failed'       => $failed,
			'recoverable'  => true,
			'restore_with' => 'wpbe_restore_posts with these ids',
			'note'         => count( $done ) . ' posts are in the trash. Keep these ids in the conversation: if the user changes his mind, wpbe_restore_posts brings them straight back. Do not offer wpbe_rollback_bulk - history does not cover deletion.' . ( $failed ? ' ' . count( $failed ) . ' were not moved to the trash - something on the site refused it; name them.' : '' ),
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	/**
	 * The post types the trash list and the restore may reach: the available
	 * ones, or the one named.
	 *
	 * @return string[]|WP_Error
	 */
	private function trash_types( $args ) {

		if ( ! empty( $args['post_type'] ) ) {

			$used = $this->mcp->use_post_type( $args['post_type'] );

			return is_wp_error( $used ) ? $used : array( $this->settings()->current_post_type );
		}

		return array_values( array_keys( $this->mcp->available_post_types() ) );
	}

	private function trash_list( $args ) {

		$types = $this->trash_types( $args );

		if ( is_wp_error( $types ) ) {
			return $types;
		}

		$limit  = isset( $args['limit'] ) ? min( 200, max( 1, intval( $args['limit'] ) ) ) : 50;
		$search = isset( $args['search'] ) && is_scalar( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$query_args = array(
			'post_type'      => $types,
			'post_status'    => 'trash',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			's'              => $search,
		);

		// an Author Area user sees his own posts only, in the trash as well
		$personal = WPBE_MCP_BOOT::personal_user_id();

		if ( $personal && array_intersect( (array) get_userdata( $personal )->roles, (array) apply_filters( 'wpbe_author_area_roles', array() ) ) ) {
			$query_args['author'] = $personal;
		}

		// WP_Query rather than get_posts(): it also says how many there are in
		// all. count alone was the size of the page, and read as "50 in the
		// trash" when there were 72.
		$query = new WP_Query( $query_args );
		$total = intval( $query->found_posts );
		$rows  = array();

		foreach ( $query->posts as $post ) {

			$trashed_at = get_post_meta( $post->ID, '_wp_trash_meta_time', true );

			$rows[] = array(
				'id'          => $post->ID,
				'post_type'   => $post->post_type,
				'title'       => $this->post_label( $post->ID ),
				// site time, as every other date on this connection
				'trashed'     => $trashed_at ? wp_date( 'Y-m-d H:i:s', intval( $trashed_at ) ) : '',
				'minutes_ago' => $trashed_at ? intval( ( time() - intval( $trashed_at ) ) / 60 ) : null,
				'returns_to'  => get_post_meta( $post->ID, '_wp_trash_meta_status', true ),
			);
		}

		return array(
			'count' => count( $rows ),
			'total' => $total,
			'rows'  => $rows,
			'note'  => ( $total > count( $rows ) ? 'Showing the ' . count( $rows ) . ' most recently trashed of ' . $total . ' in the trash - say the total, and pass a larger limit (up to 200) or a search to see more. ' : '' ) . 'returns_to is the status each post will come back as. Restore them with wpbe_restore_posts. Posts stay in the trash until WordPress empties it, which is ' . intval( EMPTY_TRASH_DAYS ) . ' days on this site.',
		);
	}

	private function restore( $args ) {

		$ids = isset( $args['ids'] ) && is_array( $args['ids'] ) ? array_values( array_unique( array_filter( array_map( 'intval', $args['ids'] ) ) ) ) : array();

		// "undo what you just did" without having to hunt for ids
		if ( empty( $ids ) && ! empty( $args['all_recent'] ) ) {

			$recent = get_posts(
				array(
					'post_type'      => array_keys( $this->mcp->available_post_types() ),
					'post_status'    => 'trash',
					'posts_per_page' => self::MAX_AT_ONCE,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_wp_trash_meta_time',
							'value'   => time() - HOUR_IN_SECONDS,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
					),
				)
			);

			$ids = array_map( 'intval', $recent );
		}

		if ( empty( $ids ) ) {
			return new WP_Error(
				'wpbe_mcp_nothing_to_restore',
				'No posts given and nothing trashed in the last hour. Call wpbe_trash_list to see what is in the trash.'
			);
		}

		// Every id checked as every other tool checks ids, per post type: a
		// tool that took any id once restored any post from the trash - a
		// page through a product tool - when only its status was asked. Here
		// the post must be in the trash, of a type this identity may use,
		// owned by an Author Area user and editable by a personal key's user.
		$by_type     = array();
		$not_in_trash = array();

		foreach ( $ids as $post_id ) {

			if ( 'trash' !== get_post_status( $post_id ) ) {
				$not_in_trash[] = $post_id;
				continue;
			}

			$by_type[ get_post_type( $post_id ) ][] = $post_id;
		}

		foreach ( $by_type as $post_type => $type_ids ) {

			$check = $this->mcp->check_ids( $type_ids, $post_type, true );

			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$forced = isset( $args['status'] ) && is_scalar( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';

		if ( '' !== $forced && ! array_key_exists( $forced, WPBE_HELPER::get_post_statuses() ) ) {
			return new WP_Error( 'wpbe_mcp_bad_option', 'Nothing was restored: "' . $forced . '" is not a post status. Allowed: ' . implode( ', ', array_keys( WPBE_HELPER::get_post_statuses() ) ) . '.' );
		}

		$restored = array();

		foreach ( $by_type as $post_type => $type_ids ) {

			foreach ( $type_ids as $post_id ) {

				// The status it had before, saved by WordPress when it was
				// trashed. Since WordPress 5.6 an untrashed post comes back as a
				// draft regardless, so restoring without this leaves a published
				// post invisible on the site - and the user, who asked to undo a
				// deletion, has no reason to suspect that happened.
				$previous = get_post_meta( $post_id, '_wp_trash_meta_status', true );
				$status   = $forced ? $forced : ( $previous ? $previous : 'draft' );

				wp_untrash_post( $post_id );

				// an attachment lives as inherit, not as a status of its own
				if ( 'attachment' !== $post_type ) {
					wp_update_post(
						array(
							'ID'          => $post_id,
							'post_status' => $status,
						)
					);
				}

				$restored[] = array(
					'id'        => $post_id,
					'post_type' => $post_type,
					'title'     => $this->post_label( $post_id ),
					'status'    => get_post_status( $post_id ),
				);
			}
		}

		$this->mcp->clear_caches_for( $ids );

		return array(
			'restored'     => count( $restored ),
			'posts'        => $restored,
			'not_in_trash' => $not_in_trash,
			'note'         => count( $restored ) . ' posts are back, each with the status it had before it was deleted. Anything listed under not_in_trash was not in the trash - it was either never deleted or already restored.',
		);
	}
}
