<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Creating posts.
 *
 * The rest of this connection edits what is already on the site. This adds
 * the missing half - a post described in a sentence becomes a real one, of
 * whichever post type the user means.
 *
 * Two rules shape everything here.
 *
 * New posts are created as drafts, always. A post assembled over several calls
 * is incomplete while it is being built, and a half finished page on a live
 * site is worse than no page at all. The owner looks it over and publishes it
 * himself - or asks for it to be published, which is one wpbe_update_post -
 * and that is also the moment he notices anything the assistant got wrong.
 *
 * Nothing is written without a preview. The plan is shown first, every value
 * checked the way an edit of the same field would check it, and only a yes
 * given after it creates anything.
 */
final class WPBE_MCP_TOOL_CREATE extends WPBE_MCP_TOOL {

	public function tools() {

		$shared = array(
			'post_type' => array(
				'type'        => 'string',
				'description' => 'The post type to create, from wpbe_post_types.',
			),
			'title'     => array(
				'type'        => 'string',
				'description' => 'The title, as a visitor would see it.',
			),
			'content'   => array(
				'type'        => 'string',
				'description' => 'The text of the post. Write it only if the user gave one or asked for one - do not invent copy for his site.',
			),
			'excerpt'   => array( 'type' => 'string' ),
			'slug'      => array(
				'type'        => 'string',
				'description' => 'Optional. WordPress builds one from the title, which is usually what the user wants.',
			),
			'parent'    => array(
				'type'        => 'integer',
				'description' => 'For a hierarchical type (pages, for one): the id of the parent post of the same type.',
			),
			'terms'     => array(
				'type'        => 'object',
				'description' => 'Taxonomy name to term ids - {"category":[3],"post_tag":[7,9]}. Ids, not names: wpbe_list_terms finds them, and a typo in a name would quietly create a new term rather than using the one meant. Only taxonomies of this post type.',
			),
			'meta'      => array(
				'type'        => 'object',
				'description' => 'Custom fields WOLF knows for this post type, field key to value - the meta fields wpbe_list_fields lists.',
			),
			'thumbnail' => array(
				'type'        => 'integer',
				'description' => 'Attachment id of an image in the media library, as the featured image. Only for post types that support thumbnails.',
			),
		);

		return array(

			'wpbe_create_preview' => array(
				'name'        => 'wpbe_create_preview',
				'sector'      => 'posts',
				'description' => 'Shows what would be created without creating it: the post with every value checked, its terms by name and its featured image. Always run this first and read it out.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => $shared,
					'required'   => array( 'post_type', 'title' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),

			'wpbe_create_post' => array(
				'name'        => 'wpbe_create_post',
				'sector'      => 'posts',
				'description' => 'Creates the post described, always as a draft. Requires confirmed true, given after the user has seen wpbe_create_preview and agreed to it. The post does not appear on the site until it is published - say that when you report what was made, and offer to publish it with wpbe_update_post (post_status publish) if the user wants it live.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$shared,
						array(
							'confirmed' => array(
								'type'        => 'boolean',
								'description' => 'Set only after the user has seen the preview and said yes to it.',
							),
						)
					),
					'required'   => array( 'post_type', 'title' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function call( $name, $args ) {

		switch ( $name ) {
			case 'wpbe_create_preview':
				return $this->preview( $args );
			case 'wpbe_create_post':
				return $this->create( $args );
		}

		return new WP_Error( 'wpbe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function preview( $args ) {

		$plan = $this->plan( $args );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// the preview writes nothing, but it says now if the real thing would
		// be refused by the allowance, rather than after the user has agreed
		$quota = $this->mcp->quota_allows( 1 );

		if ( is_wp_error( $quota ) ) {
			$plan['warnings'][] = array(
				'code' => 'write_quota',
				'text' => $quota->get_error_message(),
			);
		}

		return array(
			'will_create' => $plan['summary'],
			'post'        => $plan['show'],
			'warnings'    => $plan['warnings'],
			'note'        => 'Nothing was created. Read this out, then call wpbe_create_post with the same arguments and confirmed true once the user agrees. The post will be a draft: it is not on the site until it is published.',
		);
	}

	private function create( $args ) {

		if ( empty( $args['confirmed'] ) ) {
			return new WP_Error(
				'wpbe_mcp_not_confirmed',
				'Creating needs an explicit yes from the user, given after he has seen wpbe_create_preview. Nothing was created. Show him the plan first.'
			);
		}

		$plan = $this->plan( $args );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// a new post draws on the same allowance as an edit does: a loop of
		// creations is a bulk operation by other means
		$quota = $this->mcp->quota_allows( 1 );

		if ( is_wp_error( $quota ) ) {
			return $quota;
		}

		$post_id = wp_insert_post( wp_slash( $plan['post'] ), true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'wpbe_mcp_create_failed', 'WordPress did not create the post: ' . $post_id->get_error_message() );
		}

		foreach ( $plan['terms'] as $taxonomy => $ids ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy, false );
		}

		foreach ( $plan['meta'] as $meta_key => $value ) {
			update_post_meta( $post_id, $meta_key, $value );
		}

		if ( $plan['thumbnail'] ) {
			set_post_thumbnail( $post_id, $plan['thumbnail'] );
		}

		$this->mcp->quota_spend( 1 );
		$this->mcp->clear_caches_for( array( $post_id ) );

		return array(
			'id'           => $post_id,
			'post_type'    => $plan['post']['post_type'],
			'title'        => $this->post_label( $post_id ),
			'status'       => get_post_status( $post_id ),
			'edit_url'     => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'preview_url'  => get_preview_post_link( $post_id ),
			'publish_with' => 'wpbe_update_post with post_id ' . $post_id . ', field post_status, value publish',
			'warnings'     => $plan['warnings'],
			'note'         => 'Created as a draft, so it is not on the site yet. Tell the user what was made, give him the edit link so he can look it over, and offer to publish it from here - one call to wpbe_update_post setting post_status to publish. Do not publish on your own initiative: a draft costs nothing, a wrong page on a live site costs a visitor.',
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// the plan, shared by the preview and the real thing so they cannot disagree

	private function plan( $args ) {

		$used = $this->mcp->use_post_type( isset( $args['post_type'] ) ? $args['post_type'] : '' );

		if ( is_wp_error( $used ) ) {
			return $used;
		}

		$post_type = $this->settings()->current_post_type;
		$object    = get_post_type_object( $post_type );
		$personal  = WPBE_MCP_BOOT::personal_user_id();

		// a personal key creates only what its user may create by hand
		if ( $personal && ( ! current_user_can( $object->cap->create_posts ) || ! current_user_can( $object->cap->edit_posts ) ) ) {
			return new WP_Error( 'wpbe_mcp_access_denied', 'Your WordPress account may not create ' . $post_type . ' posts. Nothing was created.' );
		}

		// media come in through the upload tools, with a file
		if ( 'attachment' === $post_type ) {
			return new WP_Error( 'wpbe_mcp_create_media', 'A media file is not created from text: it comes with a file. Use wpbe_upload_link, wpbe_upload_image or wpbe_import_image instead.' );
		}

		$title = isset( $args['title'] ) && is_scalar( $args['title'] ) ? trim( sanitize_text_field( (string) $args['title'] ) ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'wpbe_mcp_no_title', 'A new post needs a title.' );
		}

		$warnings = array();
		$show     = array( 'title' => $title );
		$post     = array(
			'post_type'   => $post_type,
			'post_title'  => $title,
			'post_status' => 'draft',
			// a personal key creates as its user; the site-wide key has no user,
			// and a post with no author shows up nowhere a person looks for it,
			// so it goes to the site's first administrator
			'post_author' => $personal ? $personal : $this->site_author(),
		);

		// the same field rules as an edit: closed in the free build is closed
		// here too, and a field of another post type does not exist
		$columns = array(
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
		);

		foreach ( $columns as $arg => $field ) {

			if ( ! isset( $args[ $arg ] ) || '' === $args[ $arg ] ) {
				continue;
			}

			if ( ! is_scalar( $args[ $arg ] ) ) {
				return new WP_Error( 'wpbe_mcp_bad_value', $arg . ' takes text.' );
			}

			$problem = $this->field_problem( $field );

			if ( $problem ) {
				return $problem;
			}

			$value = 'post_name' === $field ? sanitize_title( (string) $args[ $arg ] ) : wp_kses_post( (string) $args[ $arg ] );

			$post[ $field ] = $value;
			$show[ $arg ]   = 'post_content' === $field && mb_strlen( $value ) > 300 ? mb_substr( $value, 0, 300 ) . '...' : $value;
		}

		if ( ! empty( $args['parent'] ) ) {

			if ( ! is_post_type_hierarchical( $post_type ) ) {
				return new WP_Error( 'wpbe_mcp_bad_parent', $post_type . ' is not hierarchical, so a post of it has no parent.' );
			}

			$problem = $this->field_problem( 'post_parent' );

			if ( $problem ) {
				return $problem;
			}

			$parent = get_post( intval( $args['parent'] ) );

			if ( ! $parent || $parent->post_type !== $post_type ) {
				return new WP_Error( 'wpbe_mcp_bad_parent', intval( $args['parent'] ) . ' is not a ' . $post_type . ' that could be the parent.' );
			}

			$post['post_parent'] = intval( $parent->ID );
			$show['parent']      = $this->post_label( $parent->ID );
		}

		// terms: ids of the taxonomies of this type, nothing else
		$terms = array();

		if ( ! empty( $args['terms'] ) ) {

			if ( ! is_array( $args['terms'] ) || wp_is_numeric_array( $args['terms'] ) ) {
				return new WP_Error( 'wpbe_mcp_bad_value', 'terms takes an object of taxonomy name to term ids: {"category":[3]}.' );
			}

			foreach ( $args['terms'] as $taxonomy => $ids ) {

				$taxonomy = sanitize_text_field( (string) $taxonomy );

				if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
					return new WP_Error( 'wpbe_mcp_no_taxonomy', 'There is no taxonomy called ' . $taxonomy . ' for ' . $post_type . '. wpbe_post_types lists the taxonomies of each type.' );
				}

				$problem = $this->field_problem( $taxonomy );

				if ( $problem ) {
					return $problem;
				}

				$named = array();

				foreach ( (array) $ids as $id ) {

					$term = is_numeric( $id ) ? get_term( intval( $id ), $taxonomy ) : null;

					if ( ! $term || is_wp_error( $term ) ) {
						return new WP_Error( 'wpbe_mcp_no_term', 'Nothing was created: ' . ( is_scalar( $id ) ? sanitize_text_field( (string) $id ) : '?' ) . ' is not a term id of ' . $taxonomy . '. Terms are given by id - wpbe_list_terms lists them.' );
					}

					$terms[ $taxonomy ][] = intval( $term->term_id );
					$named[]              = WPBE_MCP::plain_name( $term->name );
				}

				$show['terms'][ $taxonomy ] = $named;
			}
		}

		// custom fields: the meta fields WOLF knows for this type, checked as
		// an edit of them would be
		$meta   = array();
		$fields = $this->settings()->get_fields();

		if ( ! empty( $args['meta'] ) ) {

			if ( ! is_array( $args['meta'] ) || wp_is_numeric_array( $args['meta'] ) ) {
				return new WP_Error( 'wpbe_mcp_bad_value', 'meta takes an object of field key to value.' );
			}

			foreach ( $args['meta'] as $key => $value ) {

				$key = sanitize_text_field( (string) $key );

				if ( ! isset( $fields[ $key ] ) || 'meta' !== $fields[ $key ]['field_type'] || '_thumbnail_id' === $key || 'sticky_posts' === $key ) {
					return new WP_Error( 'wpbe_mcp_bad_field', $key . ' is not a custom field WOLF knows for ' . $post_type . '. The meta fields of wpbe_list_fields are the ones there are; the featured image has its own argument, thumbnail.' );
				}

				$problem = $this->field_problem( $key );

				if ( $problem ) {
					return $problem;
				}

				// the same preparation an edit of the field gets, on a post that
				// does not exist yet - so no macro reads another post
				$prepared = $this->mcp->prepare_value( 0, $key, $value );

				if ( is_wp_error( $prepared ) ) {
					return $prepared;
				}

				$meta[ $fields[ $key ]['meta_key'] ] = $prepared;
				$show['meta'][ $key ]                = $prepared;
			}
		}

		$thumbnail = 0;

		if ( ! empty( $args['thumbnail'] ) ) {

			if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
				return new WP_Error( 'wpbe_mcp_no_thumbnail_support', $post_type . ' has no featured image on this site - its type does not support thumbnails. Nothing was created; leave thumbnail out.' );
			}

			$problem = $this->field_problem( '_thumbnail_id' );

			if ( $problem ) {
				return $problem;
			}

			$thumbnail = intval( $args['thumbnail'] );

			if ( 'attachment' !== get_post_type( $thumbnail ) || 0 !== strpos( (string) get_post_mime_type( $thumbnail ), 'image/' ) ) {
				return new WP_Error( 'wpbe_mcp_not_an_image', 'Nothing was created: ' . $thumbnail . ' is not an image in the media library. wpbe_media_list gives the ids.' );
			}

			$show['thumbnail'] = array(
				'id'   => $thumbnail,
				'file' => basename( (string) get_attached_file( $thumbnail ) ),
			);
		}

		// a duplicate title is not an error, but it is almost always a repeated
		// request rather than a second post
		$existing = get_posts(
			array(
				'post_type'              => $post_type,
				'title'                  => $title,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( $existing ) {
			$warnings[] = array(
				'code' => 'title_already_used',
				'text' => 'A ' . $post_type . ' called "' . $title . '" already exists, id ' . intval( $existing[0] ) . '. Check with the user whether he meant to create a second one or to edit that one.',
			);
		}

		return array(
			'post'      => $post,
			'terms'     => $terms,
			'meta'      => $meta,
			'thumbnail' => $thumbnail,
			'show'      => $show,
			'warnings'  => $warnings,
			'summary'   => 'One ' . $post_type . ' draft, "' . $title . '"',
		);
	}

	/**
	 * A field the plan writes, checked as any write of it is checked. A field
	 * WOLF does not list for this type at all (an excerpt on a type without
	 * one) is simply not a field of it.
	 */
	private function field_problem( $field ) {
		return $this->mcp->field_write_problem( $field );
	}

	/**
	 * The author of a post the site-wide key creates: the site's first
	 * administrator, the person the key belongs to.
	 */
	private function site_author() {
		return WPBE_MCP_BOOT::site_owner_id();
	}
}
