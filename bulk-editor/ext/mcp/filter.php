<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * The check a wpbe_find_posts filter passes before WOLF's filter engine runs
 * it.
 *
 * The engine was written for the filter form of the editor screen, which can
 * only send what its own inputs hold. It reads the keys it knows, in the shape
 * that form sends, and ignores everything else without a word. Through MCP
 * anything can arrive: a misspelt field, a date as {from, to} where the form
 * sends two flat fields, a select as an object, a range with one end, a zero
 * as a JSON number. The engine dropped such a condition, the query ran without
 * it, and the whole post type came back as if it were the narrowed selection
 * the agent asked for - one bulk edit away from rewriting every post.
 *
 * So every key is checked here against what the engine will really apply:
 * a key it does not apply, or a value in a shape it cannot read, is refused
 * with the key named and the accepted shape shown; what it can apply is
 * handed over in exactly the form the editor screen would send it. Nothing is
 * dropped. This check changes nothing in the engine: the editor screen's own
 * filters do not pass through it.
 *
 * The ranges keep the engine's meaning: a custom meta number is compared with
 * BETWEEN, both ends included; the dates and the menu order / parent ranges
 * are compared with >= and <=, both included too. A missing end is open.
 */
final class WPBE_MCP_FILTER {

	// A missing end of a meta number range: the engine has no open end of its
	// own, it only knows from and to.
	const OPEN_HIGH = '999999999';
	const OPEN_LOW  = '-999999999';

	// half the width of the range that stands for "exactly this number": the
	// engine reads from = to = 0 as "nothing given" and skips the condition
	const STEP = 0.000001;

	const TEXT_BEHAVIORS = array( 'like', 'exact', 'begin', 'end', 'not', 'empty' );

	// custom meta text fields go through a meta query, which knows LIKE, =,
	// != and NOT LIKE - the editor screen sends those, an agent the words the
	// tool description names
	const META_BEHAVIORS = array(
		'like'      => 'LIKE',
		'exact'     => '=',
		'not'       => 'NOT LIKE',
		'empty'     => 'empty',
		'not_empty' => 'not_empty',
		'LIKE'      => 'LIKE',
		'='         => '=',
		'!='        => '!=',
		'NOT LIKE'  => 'NOT LIKE',
	);

	// the post's own date columns the engine ranges over, as {key}_from / _to
	const DATE_COLUMNS = array( 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' );

	/**
	 * @var array WOLF's field definitions for the post type, key => definition
	 */
	private $fields;

	/**
	 * @var string the post type the filter runs on
	 */
	private $post_type;

	/**
	 * @var array the filter as the call gave it
	 */
	private $filter;

	/**
	 * @var string[] the columns switched on in the editor for this type
	 */
	private $active;

	/**
	 * Checks a filter and turns it into the engine's form.
	 *
	 * @param array  $filter    the filter as the call gave it.
	 * @param array  $fields    WOLF's field definitions - the list the engine reads.
	 * @param string $post_type the post type the filter runs on.
	 * @param array  $active    keys of the columns switched on in the editor.
	 * @return array|WP_Error the filter to run, or every problem found in it.
	 */
	public static function check( $filter, $fields, $post_type, $active = array() ) {

		$check = new self( $filter, $fields, $post_type, $active );

		return $check->run();
	}

	private function __construct( $filter, $fields, $post_type, $active ) {
		$this->filter    = (array) $filter;
		$this->fields    = (array) $fields;
		$this->post_type = (string) $post_type;
		$this->active    = array_map( 'strval', (array) $active );
	}

	private function run() {

		$out      = array();
		$problems = array();

		foreach ( $this->filter as $key => $value ) {

			$key    = (string) $key;
			$result = $this->key( $key, $value );

			if ( is_string( $result ) ) {
				$problems[] = $key . ': ' . $result;
				continue;
			}

			foreach ( $result as $engine_key => $engine_value ) {
				$out[ $engine_key ] = $engine_value;
			}
		}

		if ( ! empty( $problems ) ) {
			return new WP_Error(
				'wpbe_mcp_bad_filter',
				'The filter was not run, nothing was selected. ' . implode( ' ', $problems ) . ' Field keys come from wpbe_list_fields for this post type; the description of wpbe_find_posts shows every shape.'
			);
		}

		return $out;
	}

	/**
	 * One key of the filter.
	 *
	 * @return array|string array( engine key => engine value ), or the problem.
	 */
	private function key( $key, $value ) {

		switch ( $key ) {
			case 'taxonomies':
				return $this->taxonomies( $value );
			case 'taxonomies_operators':
				return $this->operators( $value );
			case 'post__in':
				return $this->post_in( $value );
			case 'menu_order_from':
			case 'menu_order_to':
			case 'post_parent_from':
			case 'post_parent_to':
				return $this->from_to_number( $key, $value );
		}

		// the post's own dates: two flat keys, the column name with _from and _to
		if ( preg_match( '/^(post_date|post_date_gmt|post_modified|post_modified_gmt)_(from|to)$/', $key, $m ) ) {
			return $this->column_date( $key, $value, $m[2] );
		}

		if ( isset( $this->fields[ $key ] ) ) {

			$closed = $this->closed_for_type( $key );

			if ( '' !== $closed ) {
				return $closed;
			}

			return $this->field( $key, $value, $this->fields[ $key ] );
		}

		return 'no such filter key for ' . $this->post_type . '. The filter takes the field keys of wpbe_list_fields for this post type, and besides them taxonomies, taxonomies_operators, post__in, post_date_from, post_date_to (and the same for post_date_gmt, post_modified, post_modified_gmt), menu_order_from, menu_order_to, post_parent_from and post_parent_to.';
	}

	/**
	 * A field WOLF shows only for some post types (allow_post_types /
	 * prohibit_post_types) is not filtered on for the others either: on the
	 * screen its filter is not even drawn there.
	 */
	private function closed_for_type( $key ) {

		$f = $this->fields[ $key ];

		if ( isset( $f['allow_post_types'] ) && is_array( $f['allow_post_types'] ) && ! in_array( $this->post_type, $f['allow_post_types'], true ) ) {
			return 'this field exists only for ' . implode( ', ', $f['allow_post_types'] ) . ', not for ' . $this->post_type . '.';
		}

		if ( isset( $f['prohibit_post_types'] ) && is_array( $f['prohibit_post_types'] ) && in_array( $this->post_type, $f['prohibit_post_types'], true ) ) {
			return 'this field does not exist for ' . $this->post_type . '.';
		}

		return '';
	}

	private function field( $key, $value, $f ) {

		$type = isset( $f['field_type'] ) ? $f['field_type'] : '';
		$view = isset( $f['edit_view'] ) ? $f['edit_view'] : '';

		switch ( $key ) {
			case 'post_title':
			case 'post_content':
			case 'post_excerpt':
			case 'post_name':
			case 'post_password':
				return $this->text( $key, $value );

			case 'post_status':
			case 'comment_status':
			case 'ping_status':
			case 'post_mime_type':
				return $this->select( $key, $value, $this->options( $key ) );

			case 'post_author':
				return $this->author( $value );

			case '_thumbnail_id':
				return $this->select( $key, $value, array( 'empty', 'not_empty' ), ' - whether the post has a featured image' );

			case 'sticky_posts':
				return $this->sticky( $value );

			case 'post_date':
			case 'post_date_gmt':
			case 'post_modified':
			case 'post_modified_gmt':
				return 'the date filters as two keys, ' . $key . '_from and ' . $key . '_to, both included: {"' . $key . '_from":"2026-01-01","' . $key . '_to":"2026-01-31"}.';

			case 'menu_order':
			case 'post_parent':
				return 'filters as two keys, ' . $key . '_from and ' . $key . '_to (both included): {"' . $key . '_from":1,"' . $key . '_to":5}.';

			case 'ID':
				return 'ids filter through post__in: {"post__in":{"value":"12,15,20-30"}}.';

			case 'post_type':
				return 'the post type is not a filter - it is the post_type argument of wpbe_find_posts itself.';
		}

		if ( 'taxonomy' === $type ) {
			return 'a taxonomy goes under taxonomies, as term ids from wpbe_list_terms: {"taxonomies":{"' . $key . '":[12]}}.';
		}

		// custom meta fields: the shapes the meta extension's filter inputs send
		if ( 'meta' === $type && ! empty( $f['meta_key'] ) ) {

			if ( in_array( $view, array( 'gallery_popup_editor', 'meta_popup_editor', 'thumbnail' ), true ) ) {
				return 'this field cannot be filtered on.';
			}

			if ( 'switcher' === $view ) {
				return $this->yes_no( $key, $value, '1', 'zero' );
			}

			$data_type = isset( $f['type'] ) ? $f['type'] : '';

			if ( 'calendar' === $view ) {
				// the engine ranges over number and unix meta dates only
				if ( ! in_array( $data_type, array( 'number', 'unix' ), true ) ) {
					return 'this date field cannot be filtered on.';
				}

				// the engine reads such a date through the column definition of
				// the editor table, which only a visible column has - the same
				// reason the screen draws this filter for visible columns only
				if ( ! in_array( $key, $this->active, true ) ) {
					return 'a date field filters only while its column is switched on in the WOLF editor for this post type. Ask the owner to switch it on, or filter on something else.';
				}

				return $this->meta_date_range( $key, $value );
			}

			if ( 'number' === $data_type ) {
				return $this->range( $key, $value );
			}

			return $this->meta_text( $key, $value );
		}

		return 'this field cannot be filtered on.';
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// the shapes

	/**
	 * A text field: {value, behavior}. The behavior defaults to like; value
	 * may be left out for behavior empty only.
	 */
	private function text( $key, $value ) {

		$shape = '{"' . $key . '":{"value":"autumn","behavior":"like"}}, behavior ' . $this->words( self::TEXT_BEHAVIORS, 'or' );

		$parts = $this->value_behavior( $value, $shape );

		if ( is_string( $parts ) ) {
			return $parts;
		}

		list( $text, $behavior ) = $parts;

		$behavior = strtolower( '' === $behavior ? 'like' : $behavior );

		if ( ! in_array( $behavior, self::TEXT_BEHAVIORS, true ) ) {
			return 'behavior "' . $parts[1] . '" is not one this field takes. Use ' . $shape . '.';
		}

		if ( 'empty' === $behavior ) {
			return array(
				$key => array(
					'value'    => '',
					'behavior' => 'empty',
				),
			);
		}

		if ( '' === trim( $text ) ) {
			return 'value is empty - give the text to look for. Use ' . $shape . '.';
		}

		return array(
			$key => array(
				'value'    => $text,
				'behavior' => $behavior,
			),
		);
	}

	/**
	 * A custom meta text field. A meta query cannot say "begins with", so
	 * begin and end are refused rather than run as an exact match.
	 */
	private function meta_text( $key, $value ) {

		$shape = '{"' . $key . '":{"value":"abc","behavior":"like"}}, behavior like, exact, not, empty or not_empty (begin and end work on the post\'s own text fields only)';

		$parts = $this->value_behavior( $value, $shape );

		if ( is_string( $parts ) ) {
			return $parts;
		}

		list( $text, $behavior ) = $parts;

		$given   = '' === $behavior ? 'like' : $behavior;
		$compare = null;

		// the words in lower case, the editor screen's operators as it sends them
		foreach ( array( $given, strtolower( $given ), strtoupper( $given ) ) as $try ) {
			if ( array_key_exists( $try, self::META_BEHAVIORS ) ) {
				$compare = self::META_BEHAVIORS[ $try ];
				break;
			}
		}

		if ( null === $compare ) {
			return 'behavior "' . $given . '" is not one this field takes. Use ' . $shape . '.';
		}

		if ( in_array( $compare, array( 'empty', 'not_empty' ), true ) ) {
			return array(
				$key => array(
					'value'    => '',
					'behavior' => $compare,
				),
			);
		}

		if ( '' === trim( $text ) ) {
			return 'value is empty - give the text to look for. Use ' . $shape . '.';
		}

		return array(
			$key => array(
				'value'    => $text,
				'behavior' => $compare,
			),
		);
	}

	/**
	 * The value and behavior of a text filter, or the problem with its shape.
	 *
	 * @return array|string array( value, behavior ) or the problem.
	 */
	private function value_behavior( $value, $shape ) {

		if ( ! $this->is_object( $value ) ) {
			return 'a text field takes an object, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$extra = array_diff( array_keys( $value ), array( 'value', 'behavior' ) );

		if ( ! empty( $extra ) ) {
			return 'unknown key ' . implode( ', ', $extra ) . ' in it. Use ' . $shape . '.';
		}

		$text     = isset( $value['value'] ) ? $value['value'] : '';
		$behavior = isset( $value['behavior'] ) ? $value['behavior'] : '';

		if ( ! is_scalar( $text ) || is_bool( $text ) || ! is_string( $behavior ) ) {
			return 'value must be text and behavior a word. Use ' . $shape . '.';
		}

		return array( (string) $text, trim( $behavior ) );
	}

	/**
	 * A custom meta number range, {from, to}, both ends included (the engine
	 * compares with BETWEEN). The values go to the engine as text, the way the
	 * editor screen sends them, and a missing end becomes an open one: the
	 * engine reads a missing end as 0.
	 */
	private function range( $key, $value ) {

		$shape = '{"' . $key . '":{"from":1,"to":5}} - both ends included, either end may be left out';

		$ends = $this->range_ends( $value, $shape, false );

		if ( is_string( $ends ) ) {
			return $ends;
		}

		list( $from, $to ) = $ends;

		$text = function ( $n ) {
			return rtrim( rtrim( number_format( $n, 6, '.', '' ), '0' ), '.' );
		};

		// exactly that value: the engine compares from = to with =, except
		// for 0, which it reads as no range at all - so 0 gets the smallest
		// range around it the engine tells apart
		if ( null !== $from && null !== $to && $from === $to && 0.0 === $from ) {
			return array(
				$key => array(
					'from' => $text( -self::STEP ),
					'to'   => $text( self::STEP ),
				),
			);
		}

		return array(
			$key => array(
				'from' => null === $from ? self::OPEN_LOW : $text( $from ),
				'to'   => null === $to ? self::OPEN_HIGH : $text( $to ),
			),
		);
	}

	/**
	 * from / to of a range object as numbers (null for a missing end), or the
	 * problem with the shape.
	 */
	private function range_ends( $value, $shape, $whole ) {

		if ( ! $this->is_object( $value ) ) {
			return 'a range takes an object, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$extra = array_diff( array_keys( $value ), array( 'from', 'to' ) );

		if ( ! empty( $extra ) ) {
			return 'unknown key ' . implode( ', ', $extra ) . ' in it. Use ' . $shape . '.';
		}

		$ends = array();

		foreach ( array( 'from', 'to' ) as $end ) {

			$v = isset( $value[ $end ] ) ? $value[ $end ] : null;

			if ( is_string( $v ) ) {
				$v = trim( $v );
			}

			if ( null === $v || '' === $v ) {
				$ends[ $end ] = null;
				continue;
			}

			if ( is_bool( $v ) || ! is_numeric( $v ) ) {
				return $end . ' must be a number. Use ' . $shape . '.';
			}

			if ( $whole && floor( (float) $v ) !== (float) $v ) {
				return $end . ' must be a whole number. Use ' . $shape . '.';
			}

			$ends[ $end ] = (float) $v;
		}

		if ( null === $ends['from'] && null === $ends['to'] ) {
			return 'give from, to or both. Use ' . $shape . '.';
		}

		if ( null !== $ends['from'] && null !== $ends['to'] && $ends['from'] > $ends['to'] ) {
			return 'from is above to. Use ' . $shape . '.';
		}

		return array( $ends['from'], $ends['to'] );
	}

	/**
	 * A custom meta date, {from, to} as dates. The engine reads each end in
	 * the editor screen's calendar format (2026-1-31-14-30) and turns it into
	 * a timestamp, so that is the form it gets here; both ends included.
	 */
	private function meta_date_range( $key, $value ) {

		$shape = '{"' . $key . '":{"from":"2026-01-01","to":"2026-01-31"}} - dates, both ends included, either end may be left out';

		if ( ! $this->is_object( $value ) ) {
			return 'a date range takes an object, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$extra = array_diff( array_keys( $value ), array( 'from', 'to' ) );

		if ( ! empty( $extra ) || ( ! isset( $value['from'] ) && ! isset( $value['to'] ) ) ) {
			return 'give from, to or both, and nothing else. Use ' . $shape . '.';
		}

		$out = array();

		foreach ( array( 'from', 'to' ) as $end ) {

			if ( ! isset( $value[ $end ] ) || '' === $value[ $end ] ) {
				continue;
			}

			$ts = $this->timestamp( $value[ $end ] );

			if ( null === $ts ) {
				return $end . ' takes a date, not ' . ( is_scalar( $value[ $end ] ) ? '"' . $value[ $end ] . '"' : $this->shape_words( $value[ $end ] ) ) . '. Use ' . $shape . '.';
			}

			// a bare day: from its first minute, to its last
			if ( 'to' === $end && $this->is_bare_day( $value[ $end ] ) ) {
				$ts += DAY_IN_SECONDS - 60;
			}

			$out[ $end ] = gmdate( 'Y-n-j-G-i', $ts );
		}

		// a missing "from" reads as 0 in the engine, which is open anyway; a
		// missing "to" reads as 0 too, which the engine turns into ">= from"
		return array( $key => $out + array( 'from' => '', 'to' => '' ) );
	}

	/**
	 * post_date_from / post_date_to and the other date columns. The engine
	 * compares them with the column as written, so they are written the way
	 * the database holds a date; a bare day counts whole, from its first
	 * second to its last.
	 */
	private function column_date( $key, $value, $end ) {

		$ts = $this->timestamp( $value );

		if ( null === $ts ) {
			return 'takes a date, e.g. 2026-01-31 or 2026-01-31 18:00, not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . ': {"' . $key . '":"2026-01-31"}.';
		}

		if ( 'to' === $end && $this->is_bare_day( $value ) ) {
			$ts += DAY_IN_SECONDS - 1;
		}

		return array( $key => gmdate( 'Y-m-d H:i:s', $ts ) );
	}

	/**
	 * menu_order_from / _to and post_parent_from / _to, both included. The
	 * engine skips a "from" PHP reads as empty, and 0 is one: it goes in as
	 * "-0", which reads as 0 and is not empty. A "to" of 0 or less it skips
	 * whatever it is written as, so that one is refused.
	 */
	private function from_to_number( $key, $value ) {

		if ( ! ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) ) ) {
			return 'takes a whole number, {"' . $key . '":1}, not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . '.';
		}

		$n = intval( $value );

		if ( '_to' === substr( $key, -3 ) && $n <= 0 ) {
			return 'an upper end of 0 or less is not something the filter can apply - it would be left out and the selection would grow. Give a "to" above 0, or only a "from".';
		}

		return array( $key => 0 === $n ? '-0' : (string) $n );
	}

	/**
	 * One of the field's options, as a plain value.
	 */
	private function select( $key, $value, $options, $what = '' ) {

		$shape = '{"' . $key . '":"' . ( isset( $options[0] ) ? $options[0] : '' ) . '"}, one of: ' . $this->words( $options, 'or' ) . $what;

		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return 'takes one of its options as a plain value, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		if ( ! in_array( (string) $value, array_map( 'strval', $options ), true ) ) {
			return '"' . $value . '" is not one of its options. Use ' . $shape . '.';
		}

		return array( $key => (string) $value );
	}

	/**
	 * A yes / no field, as the engine wants it. true and false are read too.
	 */
	private function yes_no( $key, $value, $yes, $no ) {

		$shape = '{"' . $key . '":"yes"} or "no"';

		if ( true === $value || 'yes' === $value || ( is_string( $yes ) && $yes === (string) $value ) || 1 === $value ) {
			return array( $key => $yes );
		}

		if ( false === $value || 'no' === $value || '0' === $value || 0 === $value ) {
			return array( $key => $no );
		}

		return 'takes yes or no, not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . '. Use ' . $shape . '.';
	}

	/**
	 * sticky_posts: yes or no. The engine turns yes into post__in of the
	 * sticky list, and WordPress reads an empty post__in as no condition at
	 * all - so on a site with no sticky posts "the sticky ones" would be every
	 * post. Refused there instead.
	 */
	private function sticky( $value ) {

		$checked = $this->yes_no( 'sticky_posts', $value, 1, 0 );

		if ( is_string( $checked ) ) {
			return $checked;
		}

		$key = 'sticky_posts';
		// WPML keeps one sticky list per language, as the engine reads it
		$lang = apply_filters( 'wpbe_current_language', '' );
		if ( ! empty( $lang ) ) {
			$key .= '_' . $lang;
		}

		$sticky = get_option( $key );

		if ( empty( $sticky ) || ! is_array( $sticky ) ) {
			return 1 === $checked['sticky_posts']
				? 'there are no sticky posts on this site, so nothing matches - the filter was not run, because WordPress would read it as no condition and return every post.'
				: 'there are no sticky posts on this site, so every post is "not sticky" - leave this key out.';
		}

		return $checked;
	}

	private function author( $value ) {

		if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && intval( $value ) > 0 ) {

			if ( ! get_userdata( intval( $value ) ) ) {
				return 'there is no user ' . intval( $value ) . ' on this site.';
			}

			return array( 'post_author' => intval( $value ) );
		}

		return 'takes the id of a user, {"post_author":2}, not ' . ( is_scalar( $value ) ? '"' . $value . '"' : $this->shape_words( $value ) ) . '.';
	}

	/**
	 * post__in: {value: "12,15,20-30"}, ids and ranges of ids.
	 */
	private function post_in( $value ) {

		$shape = '{"post__in":{"value":"12,15,20-30"}}';

		if ( ! $this->is_object( $value ) || ! isset( $value['value'] ) ) {
			return 'takes an object with value, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$extra = array_diff( array_keys( $value ), array( 'value', 'behavior' ) );

		if ( ! empty( $extra ) || ( isset( $value['behavior'] ) && 'exact' !== $value['behavior'] ) ) {
			return 'takes value alone (the editor screen also sends behavior exact). Use ' . $shape . '.';
		}

		$ids = is_int( $value['value'] ) ? (string) $value['value'] : $value['value'];

		if ( ! is_string( $ids ) || '' === trim( $ids ) || ! preg_match( '/^[\d\s,\-]+$/', $ids ) ) {
			return 'value takes ids and ranges of ids, comma separated. Use ' . $shape . '.';
		}

		// the engine lists every id of a range one by one
		foreach ( explode( ',', $ids ) as $part ) {

			if ( substr_count( $part, '-' ) > 0 ) {

				$ends = array_map( 'intval', explode( '-', trim( $part ) ) );

				if ( count( $ends ) === 2 && abs( $ends[1] - $ends[0] ) > WPBE_MCP::SEL_MAX ) {
					return 'the range ' . trim( $part ) . ' spans more than ' . WPBE_MCP::SEL_MAX . ' ids. Use ' . $shape . '.';
				}
			}
		}

		return array( 'post__in' => array( 'value' => $ids ) );
	}

	/**
	 * taxonomies: taxonomy name => a list of term ids. What the terms are is
	 * checked afterwards, where names are also looked up.
	 */
	private function taxonomies( $value ) {

		$shape = '{"taxonomies":{"category":[16,17]}}';

		if ( ! $this->is_object( $value ) || empty( $value ) ) {
			return 'takes an object of taxonomy names, each with a list of term ids, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		foreach ( $value as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) || ! wp_is_numeric_array( $terms ) ) {
				return $taxonomy . ' takes a list of term ids, not ' . $this->shape_words( $terms ) . '. Use ' . $shape . '.';
			}
		}

		return array( 'taxonomies' => $value );
	}

	/**
	 * taxonomies_operators: taxonomy name => IN, AND, NOT IN, EXISTS or NOT
	 * EXISTS. An operator for a taxonomy with no terms under taxonomies is
	 * applied by the engine for EXISTS and NOT EXISTS only - anything else
	 * there would be dropped.
	 */
	private function operators( $value ) {

		$shape = '{"taxonomies":{"category":[3]},"taxonomies_operators":{"category":"AND"}}';

		if ( ! $this->is_object( $value ) ) {
			return 'takes an object of taxonomy name to operator, not ' . $this->shape_words( $value ) . '. Use ' . $shape . '.';
		}

		$with_terms = isset( $this->filter['taxonomies'] ) && is_array( $this->filter['taxonomies'] ) ? $this->filter['taxonomies'] : array();
		$out        = array();

		foreach ( $value as $taxonomy => $operator ) {

			$taxonomy = (string) $taxonomy;

			if ( ! is_string( $operator ) ) {
				return 'the operator for ' . $taxonomy . ' must be a word: IN, AND, NOT IN, EXISTS or NOT EXISTS.';
			}

			if ( isset( $with_terms[ $taxonomy ] ) ) {
				// checked with its terms, where OR is also read as IN
				$out[ $taxonomy ] = $operator;
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $this->post_type, $taxonomy ) ) {
				return 'no taxonomy called ' . $taxonomy . ' for ' . $this->post_type . '. wpbe_taxonomies lists the real names.';
			}

			$operator = strtoupper( trim( $operator ) );

			if ( ! in_array( $operator, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				return 'the operator ' . $operator . ' for ' . $taxonomy . ' needs its terms under taxonomies; alone, only EXISTS and NOT EXISTS apply. Use ' . $shape . '.';
			}

			$out[ $taxonomy ] = $operator;
		}

		return array( 'taxonomies_operators' => $out );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// helpers

	/**
	 * The option values of a select field, as its definition holds them.
	 */
	private function options( $key ) {

		if ( empty( $this->fields[ $key ]['select_options'] ) || ! is_array( $this->fields[ $key ]['select_options'] ) ) {
			return array();
		}

		return array_map( 'strval', array_keys( $this->fields[ $key ]['select_options'] ) );
	}

	/**
	 * A date as a timestamp read as wall-clock time - the way the post's own
	 * date columns and the calendar of the editor screen hold it - or null.
	 * A unix timestamp is taken as given.
	 */
	private function timestamp( $value ) {

		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) && strlen( trim( $value ) ) > 8 ) ) {
			return intval( $value ) > 0 ? intval( $value ) : null;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		// WordPress runs PHP in UTC, so this reads the date and time exactly as
		// written, without moving it by the site's time zone
		$ts = strtotime( trim( $value ) );

		return false === $ts ? null : $ts;
	}

	private function is_bare_day( $value ) {
		return is_string( $value ) && preg_match( '/^\d{4}-\d{1,2}-\d{1,2}$/', trim( $value ) );
	}

	/**
	 * Whether a decoded JSON value was an object: an array with names, or an
	 * empty one (the two cannot be told apart once decoded).
	 */
	private function is_object( $value ) {
		return is_array( $value ) && ( empty( $value ) || ! wp_is_numeric_array( $value ) );
	}

	private function shape_words( $value ) {

		if ( is_array( $value ) ) {
			return ( empty( $value ) || wp_is_numeric_array( $value ) ) ? 'a list' : 'an object';
		}

		if ( is_string( $value ) ) {
			return 'the text "' . ( mb_strlen( $value ) > 40 ? mb_substr( $value, 0, 40 ) . '...' : $value ) . '"';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_null( $value ) ) {
			return 'null';
		}

		return 'the number ' . $value;
	}

	private function words( $items, $last ) {

		$items = array_values( $items );

		if ( count( $items ) < 2 ) {
			return implode( '', $items );
		}

		return implode( ', ', array_slice( $items, 0, -1 ) ) . ' ' . $last . ' ' . end( $items );
	}
}
