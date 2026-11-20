<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Base for an extended tool pack.
 *
 * Everything the MCP server can do beyond bulk editing lives in a file dropped
 * into ext/mcp/tools/. Nothing registers it: the loader scans the folder, and
 * the only convention is that the file name is the class name -
 * taxonomy.php holds WPBE_MCP_TOOL_TAXONOMY, delete.php holds
 * WPBE_MCP_TOOL_DELETE. Same rule the plugin already uses for its extensions,
 * so there is one thing to remember rather than two.
 *
 * Packs may write - terms, posts, images all do. What they cannot rely on is
 * WOLF history: it covers post fields changed through the bulk editor's own
 * tools, and nothing else. So every writing pack shows a preview and asks for
 * confirmed before it touches anything, and says plainly in its answer how -
 * or whether - the change can be undone: the trash, the previous values, or
 * not at all.
 */
abstract class WPBE_MCP_TOOL {

	/**
	 * @var WPBE_MCP
	 */
	protected $mcp;

	public function __construct( $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Tool definitions keyed by tool name, in the same shape the core catalogue
	 * uses: name, sector, description, inputSchema, annotations.
	 *
	 * Write the description for a reader who has never seen this site - it is
	 * the only thing an agent has to decide whether to call the tool at all.
	 *
	 * @return array
	 */
	abstract public function tools();

	/**
	 * Runs one of them. Return an array, or a WP_Error whose message is aimed at
	 * the person rather than at a developer.
	 *
	 * @param string $name
	 * @param array  $args
	 * @return array|WP_Error
	 */
	abstract public function call( $name, $args );

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// posts

	/**
	 * Selection or explicit ids, checked the way every core tool checks them -
	 * one post type, available to this identity, own posts only for an Author
	 * Area role, edit_post for a personal key (WPBE_MCP::ids_from()). The
	 * settings are switched to that type before the ids come back.
	 *
	 * @return array|WP_Error array( 'ids' => int[], 'post_type' => string )
	 */
	protected function ids( $args ) {
		return $this->mcp->ids_from( $args );
	}

	/**
	 * One post id, checked like ids() and with the settings switched to its
	 * type, so the field definitions read next are the right ones.
	 *
	 * @return string|WP_Error the post type
	 */
	protected function one_post( $post_id ) {
		return $this->mcp->use_post_of( $post_id );
	}

	protected function posts() {
		return $this->mcp->posts;
	}

	protected function settings() {
		return $this->mcp->settings;
	}

	/**
	 * For a write that lands in another sector's data - new terms from a
	 * post tool, a post changed by a media tool: the permission map has to
	 * allow that sector too. Ask before writing anything.
	 *
	 * @param string $sector the other sector, e.g. taxonomy.
	 * @param bool   $write  true when write access is needed.
	 * @param string $what   what the call does there, e.g. "creates new terms".
	 * @return true|WP_Error
	 */
	protected function require_access( $sector, $write, $what ) {
		return $this->mcp->require_access( $sector, $write, $what );
	}

	/**
	 * A post name a human recognises: its title as plain text, or the type and
	 * id when it has none - a draft created a minute ago often does not.
	 */
	protected function post_label( $post_id ) {

		$post = get_post( intval( $post_id ) );

		if ( ! $post ) {
			return '#' . intval( $post_id );
		}

		$title = WPBE_MCP::plain_name( $post->post_title );

		return '' !== trim( $title ) ? $title : '(' . $post->post_type . ' #' . intval( $post_id ) . ', no title)';
	}

	/**
	 * Existing terms of a taxonomy that look like the value asked for: the same
	 * word shortened (Repair / Repairs) or spelled a little differently
	 * (Colour / Color). A hint for previews only - nothing is matched or merged
	 * automatically.
	 *
	 * @param string $value    the value the caller asked for
	 * @param string $taxonomy taxonomy name
	 * @return string[] up to five existing term names
	 */
	protected function similar_terms( $value, $taxonomy ) {

		static $cache = array();

		if ( ! isset( $cache[ $taxonomy ] ) ) {
			$names              = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'names',
				)
			);
			$cache[ $taxonomy ] = is_wp_error( $names ) ? array() : array_map( array( 'WPBE_MCP', 'plain_name' ), $names );
		}

		// mbstring is almost always there, but WordPress does not polyfill
		// mb_strtolower() - without it Latin values still compare correctly
		$lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';

		$asked   = $lower( trim( (string) $value ) );
		$similar = array();

		foreach ( $cache[ $taxonomy ] as $name ) {

			$have = $lower( (string) $name );

			if ( '' === $asked || $have === $asked ) {
				continue;
			}

			// one is the beginning of the other
			$prefix = 0 === strpos( $have, $asked ) || 0 === strpos( $asked, $have );

			// a letter or two apart, only for real words
			$close = mb_strlen( $asked ) >= 4 && mb_strlen( $have ) >= 4 && levenshtein( $asked, $have ) <= 2;

			if ( $prefix || $close ) {
				$similar[] = $name;
			}
		}

		return array_slice( $similar, 0, 5 );
	}

	protected function placeholders( $values, $type = '%s' ) {
		return implode( ',', array_fill( 0, count( $values ), $type ) );
	}
}
