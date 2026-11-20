<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Exporting a selection to CSV.
 *
 * The rows are built by the export extension itself rather than assembled here
 * (WPBE_EXPORT::get_post_fields() and get_csv_titles()): taxonomies, meta and
 * featured images have their own rules there, and a second implementation
 * would drift away from what the editor screen produces within a release or
 * two. The user would then have two exports of the same site that disagree.
 *
 * The file does not go where the Export tab puts its own: that folder sits
 * inside the plugin, is reachable from the web under predictable names, and is
 * emptied whenever somebody exports on the screen. It goes into a folder of
 * its own under uploads, closed to the web where the server reads .htaccess,
 * under a random name nobody can guess anyway. It is handed over through a
 * one time link: a random token, good for fifteen minutes and for one
 * download, after which the file is deleted. The site's MCP key never appears
 * in a URL - it was deliberately taken out of there.
 */
final class WPBE_MCP_TOOL_EXPORT extends WPBE_MCP_TOOL {

	const TOKEN_PREFIX = 'wpbe_mcp_export_';
	const TOKEN_TTL    = 900; // seconds a download link stays valid
	const MAX_ROWS     = 20000;
	const FOLDER       = 'wpbe_mcp_exports';

	public function tools() {

		return array(

			'wpbe_export_csv' => array(
				'name'        => 'wpbe_export_csv',
				'sector'      => 'export',
				'description' => 'Exports a selection of posts (one post type) to a CSV file and returns a download link. Columns are the ones switched on in the WOLF editor for that post type, formatted exactly as its own Export tab produces them. Take a selection with wpbe_find_posts first. The link works once, for fifteen minutes, and needs no credentials, so give it to the user as it is; do not try to read the file yourself.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'selection_id' => array( 'type' => 'string' ),
						'ids'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'post_type'    => array(
							'type'        => 'string',
							'description' => 'Optional. Taken from the selection or from the posts.',
						),
						'columns'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Field keys to export, in the order they should appear. Leave it out to export whatever columns are switched on in the editor. Use the keys from wpbe_list_fields, not their titles.',
						),
						'delimiter'    => array(
							'type'        => 'string',
							'description' => 'Column separator, default a comma. Use a semicolon for a spreadsheet that expects one - that is most of continental Europe.',
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);
	}

	public function call( $name, $args ) {

		if ( 'wpbe_export_csv' === $name ) {
			return $this->export_csv( $args );
		}

		return new WP_Error( 'wpbe_mcp_unknown_tool', 'Unknown tool: ' . $name );
	}

	/**
	 * The folder the MCP exports live in, created on first use with the two
	 * files that keep it shut on servers that read them. '' when it cannot be
	 * created.
	 */
	public static function folder() {

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$folder = trailingslashit( $uploads['basedir'] ) . self::FOLDER . '/';

		if ( ! is_dir( $folder ) && ! wp_mkdir_p( $folder ) ) {
			return '';
		}

		if ( ! file_exists( $folder . '.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $folder . '.htaccess', "Require all denied\nDeny from all\n" );
		}

		if ( ! file_exists( $folder . 'index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $folder . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		return $folder;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

	private function export_csv( $args ) {

		global $WPBE;

		if ( ! isset( $WPBE->export ) || is_null( $WPBE->export ) ) {
			return new WP_Error( 'wpbe_mcp_no_export', 'The export extension is not available on this site.' );
		}

		$export = $WPBE->export;

		foreach ( array( 'get_active_fields', 'get_post_fields', 'get_csv_titles' ) as $needed ) {
			if ( ! method_exists( $export, $needed ) ) {
				return new WP_Error(
					'wpbe_mcp_export_too_old',
					'This build of the export extension does not expose ' . $needed . '(), so an export cannot be produced without duplicating its formatting. Update the plugin.'
				);
			}
		}

		// one post type, checked and switched to - the columns are that type's
		$target = $this->ids( $args );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$ids = $target['ids'];

		if ( count( $ids ) > self::MAX_ROWS ) {
			return new WP_Error(
				'wpbe_mcp_export_too_big',
				'That selection is ' . count( $ids ) . ' rows. Exports through this connection are capped at ' . self::MAX_ROWS
				. ' - narrow the selection, or use the Export tab on the plugin screen, which streams in batches and has no limit.'
			);
		}

		$known  = $export->get_active_fields();
		$fields = $known;

		// A narrower set, in the caller's order: a price list reads differently
		// from an inventory sheet even with the same columns. Only columns that
		// are switched on in the editor - an export can narrow what the owner
		// shows, never add what he hides.
		$wanted = isset( $args['columns'] ) && is_array( $args['columns'] ) ? array_map( 'sanitize_text_field', array_filter( $args['columns'], 'is_scalar' ) ) : array();

		if ( ! empty( $wanted ) ) {

			$unknown = array_diff( $wanted, array_keys( $known ) );

			if ( ! empty( $unknown ) ) {
				return new WP_Error(
					'wpbe_mcp_export_unknown_columns',
					'These columns are not switched on in the editor for ' . $target['post_type'] . ', so they cannot be exported: ' . implode( ', ', $unknown )
					. '. Available right now: ' . implode( ', ', array_diff( array_keys( $known ), array( '__checker' ) ) )
				);
			}

			$fields = array();

			foreach ( $wanted as $key ) {
				$fields[ $key ] = $known[ $key ];
			}
		}

		unset( $fields['__checker'] );

		if ( empty( $fields ) ) {
			return new WP_Error( 'wpbe_mcp_no_columns', 'No columns are switched on in the editor for ' . $target['post_type'] . ', so there is nothing to export. Turn some on in the plugin settings first.' );
		}

		$folder = self::folder();

		if ( '' === $folder ) {
			return new WP_Error( 'wpbe_mcp_no_folder', 'The export folder could not be created in the uploads directory of this server.' );
		}

		$this->forget_old_files( $folder );

		$delimiter = isset( $args['delimiter'] ) && is_scalar( $args['delimiter'] ) ? substr( (string) $args['delimiter'], 0, 1 ) : ',';
		$delimiter = ( '' === $delimiter ) ? ',' : $delimiter;

		// a name nobody can guess: the folder may be readable from the web on
		// a server that ignores .htaccess
		$name = 'wolf-export-' . $target['post_type'] . '-' . gmdate( 'Y-m-d' ) . '-' . bin2hex( random_bytes( 16 ) ) . '.csv';
		$file = $folder . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $file, 'w' );

		if ( false === $fp ) {
			return new WP_Error( 'wpbe_mcp_export_write', 'Could not create the export file.' );
		}

		// BOM, so the file opens as UTF-8 in Excel rather than as mojibake -
		// the single most common complaint about any CSV export
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fp, "\xEF\xBB\xBF" );

		$titles = $export->get_csv_titles( $fields );

		fputcsv( $fp, $titles, $delimiter, '"', '\\' );

		$rows = 0;

		foreach ( $ids as $post_id ) {
			fputcsv( $fp, (array) $export->get_post_fields( intval( $post_id ), $fields ), $delimiter, '"', '\\' );
			++$rows;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		$token = bin2hex( random_bytes( 16 ) );

		set_transient( self::TOKEN_PREFIX . $token, $name, self::TOKEN_TTL );

		return array(
			'post_type'    => $target['post_type'],
			'file'         => $name,
			'rows'         => $rows,
			'columns'      => count( $titles ),
			'column_keys'  => array_keys( $fields ),
			'size_kb'      => round( filesize( $file ) / 1024, 1 ),
			'download_url' => rest_url( 'wpbe/v1/mcp/export/' . $token ),
			'expires_in'   => self::TOKEN_TTL,
			'note'         => 'Give the user the link as it is - it carries its own one time token and needs no key, so it opens in any browser. It works for one download within ' . intval( self::TOKEN_TTL / 60 ) . ' minutes; after that, run the export again. Unless specific columns were asked for, these are whichever ones are switched on in the editor for this post type right now.',
		);
	}

	/**
	 * Files nobody came for: their link has run out, so they are only taking
	 * up space - and holding data - on the server.
	 */
	private function forget_old_files( $folder ) {

		foreach ( (array) glob( $folder . 'wolf-export-*.csv' ) as $old ) {
			if ( is_file( $old ) && filemtime( $old ) < time() - self::TOKEN_TTL - 60 ) {
				wp_delete_file( $old );
			}
		}
	}
}
