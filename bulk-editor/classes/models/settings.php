<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

final class WPBE_SETTINGS {

    public $active_fields = array();
    public $current_post_type = 'post';
    public $per_page = 10;
    public $editable = array();
    public $default_sort_by = 'ID';
    public $default_sort = 'desc';
    public $show_admin_bar_menu_btn = 1;
    public $show_thumbnail_preview = 1;
    public $nums_round_decimals = 2;
    public $load_switchers = 1; //instead of checkboxes will be beauty switchers, but its will take more time for the table redrawing
    public $autocomplete_max_elem_count = 10;
    public $no_order = array();
    private $options_key = 'wpbe_options_';
    public $current_user_role = 'administrator';
    public $quick_search_fieds = "";
    public $add_more_per_page = '';
    public $diferrent_setings_per_post = 1;
    public $override_switcher_fieds = "";
    public $use_wp_editor = 0;
    // option keys applied by the last apply_post_type_state(), so a switch to
    // another post type can put them back to their defaults first
    private $applied_option_keys = array();
    // fields per post type, see get_fields(); cleared for a type on switch
    private static $fields_cache = array();
    private static $tax_fields_cache = array();
    private static $role_fields_cache = array();

    public function __construct() {

        // An MCP request never reads or writes the stored current post type: that
        // is the screen of a person working in the table, and an agent switching
        // types would switch it under him. The MCP layer picks the type per call
        // with switch_post_type() instead.
        $is_mcp = defined('WPBE_MCP_REQUEST') && WPBE_MCP_REQUEST;

        $this->options_key .= apply_filters('wpbe_settings_key_options', get_current_user_id());
        //+++
        $this->add_more_per_page = apply_filters('wpbe_set_per_page_values', $this->add_more_per_page);

        if (!$is_mcp) {
            $post_type = (new WPBE_STORAGE())->get_val('wpbe_current_post_type_' . get_current_user_id());
            if (!empty($post_type) AND post_type_exists($post_type)) {
                $this->current_post_type = $post_type;
            }
        }

        if (WPBE_HELPER::get_site_separate_settings()) {
            $this->options_key .= $this->current_post_type;
        }    //+++

        $user = wp_get_current_user();
        $role = (array) $user->roles;
        // a request with no logged in user (the MCP shop key) has no role at all
        $this->current_user_role = isset($role[0]) ? $role[0] : '';

        //***
        //to avoid prohibited post types visibility for site editors
        if (!in_array($this->current_user_role, apply_filters('wpbe_permit_special_roles', ['administrator']))) {
            $allowed_post_types = explode(',', WPBE_HELPER::get_site_editors_post_types());
            if (!in_array($this->current_post_type, $allowed_post_types)) {
                $this->current_post_type = $allowed_post_types[0];
                if (!$is_mcp) {
                    (new WPBE_STORAGE())->set_val('wpbe_current_post_type_' . get_current_user_id(), $this->current_post_type);
                }
            }
        }

        //***

        $this->apply_post_type_state();
    }

    /**
     * Makes this very object work with another post type, inside one request.
     *
     * The same object rather than a new one: every extension holds the settings
     * it got from init_vars(), and a fresh WPBE_SETTINGS would leave history,
     * bulk, meta and filters on the old type. Nothing is written to storage and
     * the site editors' type list is not applied here - the MCP layer decides
     * which types an identity may use (WPBE_MCP::use_post_type()).
     *
     * @param string $post_type a registered post type
     * @return bool false when the type does not exist
     */
    public function switch_post_type($post_type) {

        if (!post_type_exists($post_type)) {
            return false;
        }

        // fields of the target type are built again: a list cached for it while
        // the object was still being constructed may hold another type's meta
        unset(self::$fields_cache[$post_type], self::$tax_fields_cache[$post_type], self::$role_fields_cache[$post_type]);

        $this->current_post_type = $post_type;

        $this->options_key = 'wpbe_options_' . apply_filters('wpbe_settings_key_options', get_current_user_id());
        if (WPBE_HELPER::get_site_separate_settings()) {
            $this->options_key .= $this->current_post_type;
        }

        // options of the previous type go back to the class defaults before the
        // new type's options are read, as for a freshly constructed object
        $defaults = (new ReflectionClass($this))->getDefaultProperties();
        foreach ($this->applied_option_keys as $key) {
            if (array_key_exists($key, $defaults)) {
                $this->$key = $defaults[$key];
            }
        }

        $this->apply_post_type_state();

        do_action('wpbe_post_type_switched', $post_type, $this);

        return true;
    }

    /**
     * Everything the constructor derives from the current post type: the active
     * fields, the editable and unsortable columns, and the saved options.
     */
    private function apply_post_type_state() {

        global $WPBE;

        $this->active_fields = array();
        $this->editable = array();
        $this->no_order = array();
        $this->applied_option_keys = array();

        $this->init_fields();

        //***

        $counter = 0;
        foreach ($this->active_fields as $f) {
            if ($f['editable']) {
                $this->editable[] = $counter;
            }

            if (!$f['order']) {
                $this->no_order[] = $counter;
            }

            $counter++;
        }

        $this->no_order[] = count($this->active_fields);

        //***
        //init options values
        $options = $this->get_options();
        if (!empty($options)) {
            if (!empty($options['options']) AND is_array($options['options'])) {
                foreach ($options['options'] as $key => $v) {
                    if (!is_null($v)) {
                        $this->$key = $v;
                        $this->applied_option_keys[] = $key;
                    }
                }
            }
        }

        //***
        if (intval($this->per_page) < 1) {
            $this->per_page = 10;
        }
        //max per page to avoid 500 error on weak servers
        if (intval($this->per_page) > 100) {
            $this->per_page = 100;
        }

        if ($WPBE->show_notes) {
            if (intval($this->per_page) > 10) {
                $this->per_page = 10;
            }
        }
    }

    public function get_options() {
        return get_option($this->options_key);
    }

    public function update_options($options) {
        update_option($this->options_key, $options);
    }

    public function get_fields($use_roles = true) {
        global $WPBE;
        //lets cache it as it uses many times - per post type, as one request can
        //work with several of them (MCP), and each has its own taxonomies and meta
        $pt = $this->current_post_type;
        $res = isset(self::$fields_cache[$pt]) ? self::$fields_cache[$pt] : array();

        if (empty($res)/* AND $use_cache */) {
            $fields = wpbe_get_fields();

            //***
            //get all posts taxonomies
            $taxonomy_objects = get_object_taxonomies($this->current_post_type, 'objects');
            unset($taxonomy_objects['post_type']);
            $tax_fileds = isset(self::$tax_fields_cache[$pt]) ? self::$tax_fields_cache[$pt] : array(); //cached per post type
            if (!empty($taxonomy_objects)) {

                if (empty($tax_fileds)) {
                    $counter = 0;
                    foreach ($taxonomy_objects as $t) {
                        /*
                          if (substr($t->name, 0, 3) === 'pa_') {
                          continue;
                          }
                         */

                        if ($WPBE->show_notes) {
                            $direct = FALSE;
                            if ($t->name === 'category' OR $counter === 0) {
                                $direct = TRUE;
                            }
                        } else {
                            $direct = TRUE;
                        }
                        $tax_fileds[$t->name] = array(
                            'show' => 0,
                            'title' => ucfirst(trim(str_replace('Post ', '', $t->label))),
                            'field_type' => 'taxonomy',
                            'taxonomy' => $t->name,
                            'type' => 'array',
                            'editable' => TRUE,
                            'edit_view' => 'popup',
                            'order' => FALSE,
                            'direct' => $direct,
                            //'prohibit_post_types' => array(),
                            'site_editor_visibility' => 1
                        );

                        $counter++;
                    }

                    self::$tax_fields_cache[$pt] = $tax_fileds;
                }

                $fields = array_merge($fields, $tax_fileds);
            }

            //***

            $options = $this->get_options();

            //apply saved options
            if (!empty($options)) {
                if (isset($options['fields']) AND !empty($options['fields']) AND is_array($options['fields'])) {

                    foreach ($options['fields'] as $key => $v) {

                        if (!isset($fields[$key])) {
                            continue; //key was removed or renamed
                        }

                        //***
                        if (!isset($v['show'])) {
                            $v['show'] = 0;
                        }
                        $fields[$key]['show'] = intval($v['show']);

                        if (isset($v['site_editor_visibility'])) {//because for site editor its doesn exists
                            if (!in_array($key, array('__checker', 'ID'))) {//this fields must be always visible!!
                                if (in_array($this->current_user_role, apply_filters('wpbe_permit_special_roles', ['administrator']))) {
                                    $fields[$key]['site_editor_visibility'] = intval($v['site_editor_visibility']);
                                }
                            }
                        }

                        if (isset($v['title'])) {
                            $title = wp_strip_all_tags(trim($v['title']));
                            if (!empty($title)) {
                                if (!isset($fields[$key]['title_static'])) {
                                    $fields[$key]['title'] = $title;
                                }
                            }
                        } else {
                            $fields[$key]['title'] = '_';
                        }
                    }


                    //***

                    foreach ($options['fields'] as $key => $v) {

                        if (!isset($fields[$key])) {
                            continue; //key was removed or renamed
                        }

                        //***

                        $res[$key] = $fields[$key];
                    }

                    //if in the future will be added new fields
                    $diff = array_diff(array_keys($fields), array_keys($res));
                    if (!empty($diff)) {
                        foreach ($diff as $fk) {
                            $res[$fk] = $fields[$fk];
                        }
                    }
                }
            } else {
                $res = $fields;
                //lets init options
                $options = array();
                $options['fields'] = array();

                foreach ($fields as $key => $f) {
                    $options['fields'][$key]['show'] = $f['show'];
                    $options['fields'][$key]['title'] = $f['title'];
                    /*
                      if ($f['show']) {
                      $columns_activated[] = $key;
                      }
                     *
                     */
                }


                $this->update_options($options);
            }

            //***
            self::$fields_cache[$pt] = $res;
        }


        //lets check restricions
        if (!in_array($this->current_user_role, apply_filters('wpbe_permit_special_roles', ['administrator'])) AND $use_roles) {

            $ff = isset(self::$role_fields_cache[$pt]) ? self::$role_fields_cache[$pt] : array();

            if (empty($ff)) {
                $ff = $res;
                //for correct filtering posts manager needs all fields
                $visibility = $this->get_site_editor_visibility();
                if (is_array($visibility) AND !empty($visibility)) {
                    foreach ($visibility as $key => $is) {
                        if (intval($is) === 0) {
                            unset($ff[$key]);
                        }
                    }
                }

                self::$role_fields_cache[$pt] = $ff;
            }

            return $ff;
        }


        //****
        return $res;
    }

    public function get_site_editor_visibility() {
        static $visibility = array();

        if (empty($visibility)) {
            $visibility = get_option('wpbe_site_editor_visibility', true);
        }

        return $visibility;
    }

    private function init_fields() {

        $fields = $this->get_fields();

        foreach ($fields as $key => $f) {
            if ($f['show']) {
                $this->active_fields[$key] = $f;
            }
        }
    }

    public function get_fields_keys() {
        return array_keys($this->active_fields);
    }

    //by which column are posts sorted after page loading
    public function get_default_sortby_col_num() {

        $col_num = 0;
        if (empty($this->default_sort_by)) {
            $this->default_sort_by = 'ID';
        }
        $keys = $this->get_fields_keys();

        if (!empty($keys)) {
            foreach ($keys as $counter => $key) {
                if ($key == $this->default_sort_by) {
                    $col_num = $counter;
                    break;
                }
            }
        }

        return $col_num;
    }

    public function get_total_settings() {

        $default_sort_by = $this->active_fields;
        if (!empty($default_sort_by)) {
            foreach ($default_sort_by as $key => $f) {
                if (!$f['order']) {
                    unset($default_sort_by[$key]);
                }
            }
        } else {
            $default_sort_by = array();
        }

        //***

        $data = array();
        $data['default_sort_by'] = $default_sort_by;
        $settings = wpbe_get_total_settings($data);
        foreach ($settings as $key => $sett) {
            $settings[$key]['value'] = $this->$key;
        }

        return $settings;
    }

}
