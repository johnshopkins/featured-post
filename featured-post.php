<?php
/*************************************************************************
Plugin Name: Featured Post
Plugin URI: https://github.com/WPPress/featured-post
Description: Featured Post Plugin For Wordpress.
Version: 3.5.0
Author: Sovit Tamrakar, Jen Wachter
Author URI: http://wppress.net
 **************************************************************************
Copyright (C) 2010 Sovit Tamrakar(http://ssovit.com)
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
 **************************************************************************/

class Featured_Post
{
    public $post_types       = array();
    private static $instance = null;

    public function __construct()
    {
        add_action('init', array(&$this,
            'init',
        ));
        add_action('admin_init', array(&$this,
            'admin_init',
        ));
        add_action('wp_ajax_toggle-featured-post', array(&$this,
            'admin_ajax',
        ));
        add_action('plugins_loaded', array(&$this,
            'load_featured_textdomain',
        ));
    }
    public static function get_instance()
    {
        if (null == self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function init()
    {
        add_filter('query_vars', array(&$this,
            'query_vars',
        ));
        add_action('pre_get_posts', array(&$this,
            'pre_get_posts',
        ));
    }
    public function admin_init()
    {
        /* Set the new post to 'featured=no' when it's created */
        add_action('new_to_publish', array(&$this,
            'set_not_featured',
        ), 1, 2);
        add_action('draft_to_publish', array(&$this,
            'set_not_featured',
        ), 1, 2);
        add_action('pending_to_publish', array(&$this,
            'set_not_featured',
        ), 1, 2);

        add_filter('current_screen', array(&$this,
            'my_current_screen',
        ));
        add_action('admin_head-edit.php', array(&$this,
            'admin_head',
        ));
        add_filter('pre_get_posts', array(&$this,
            'admin_pre_get_posts',
        ), 1);
        $this->post_types = get_post_types(array(
            '_builtin' => false,
        ), 'names', 'or');
        $this->post_types['post'] = 'post';
        $this->post_types['page'] = 'page';
        ksort($this->post_types);
        $this->post_types = apply_filters('featured_post_types', $this->post_types);

        foreach ($this->post_types as $key => $val) {
            add_filter('manage_edit-' . $key . '_columns', array(&$this,
                'manage_posts_columns',
            ));
            add_action('manage_' . $key . '_posts_custom_column', array(&$this,
                'manage_posts_custom_column',
            ), 10, 2);
        }
        add_action('post_submitbox_misc_actions', array(&$this,
            'edit_screen_featured_ui',
        ));
        add_action('save_post', array(&$this,
            'edit_screen_featured_save',
        ));
    }
    public function add_views_link($views)
    {
        $post_type         = ((isset($_GET['post_type']) && $_GET['post_type'] != "") ? $_GET['post_type'] : 'post');
        $count             = $this->total_featured($post_type);
        $class             = (isset($_GET['post_status']) && $_GET['post_status'] == 'featured') ? "current" : '';
        $views['featured'] = "<a class=\"" . $class . "\" id=\"featured-post-filter\" href=\"edit.php?&post_status=featured&post_type={$post_type}\">" . __('Featured', 'featured-post') . "<span class=\"count\">({$count})</span></a>";
        return $views;
    }
    public function total_featured($post_type = "post")
    {
        $rowQ = new WP_Query(array(
            'post_type'      => $post_type,
            'meta_query'     => array(
                array(
                    'key'   => '_is_featured',
                    'value' => 'yes',
                ),
            ),
            'posts_per_page' => 1,
        ));
        wp_reset_postdata();
        wp_reset_query();
        $rows = $rowQ->found_posts;
        unset($rowQ);
        return $rows;
    }
    public function my_current_screen($screen)
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return $screen;
        }
        $this->post_types = get_post_types(array(
            '_builtin' => false,
        ), 'names', 'or');
        $this->post_types['post'] = 'post';
        $this->post_types['page'] = 'page';
        ksort($this->post_types);
        foreach ($this->post_types as $key => $val) {
            add_filter('views_edit-' . $key, array(&$this,
                'add_views_link',
            ));
        }
        return $screen;
    }
    public function manage_posts_columns($columns)
    {
        global $current_user;
        get_currentuserinfo();
        if (current_user_can('edit_posts', $user_id)) {
            $columns['featured'] = __('Featured', 'featured-post');
        }
        return $columns;
    }
    public function manage_posts_custom_column($column_name, $post_id)
    {
        //echo "here";
        if ($column_name == 'featured') {
            $is_featured = get_post_meta($post_id, '_is_featured', true);
            $class       = "dashicons";
            $text        = "";
            if ($is_featured == "yes") {
                $class .= " dashicons-star-filled";
                $text = "";
            } else {
                $class .= " dashicons-star-empty";
            }
            echo "<a href=\"#!featured-toggle\" class=\"featured-post-toggle {$class}\" data-post-id=\"{$post_id}\">$text</a>";
        }
    }
    public function admin_head()
    {
        echo '<script type="text/javascript">
        jQuery(document).ready(function($){
            $(\'.featured-post-toggle\').on("click",function(e){
                e.preventDefault();
                var _el=$(this);
                var post_id=$(this).attr(\'data-post-id\');
                var data={action:\'toggle-featured-post\',post_id:post_id};
                $.ajax({url:ajaxurl,data:data,type:\'post\',
                    dataType:\'json\',
                    success:function(data){
                    _el.removeClass(\'dashicons-star-filled\').removeClass(\'dashicons-star-empty\');
                    $("#featured-post-filter span.count").text("("+data.total_featured+")");
                    if(data.new_status=="yes"){
                        _el.addClass(\'dashicons-star-filled\');
                    }else{
                        _el.addClass(\'dashicons-star-empty\');
                    }
                    }
                });
            });
        });
        </script>';
    }
    public function admin_ajax()
    {
        header('Content-Type: application/json');
        $post_id     = $_POST['post_id'];
        $is_featured = get_post_meta($post_id, '_is_featured', true);
        $newStatus   = $is_featured == 'yes' ? 'no' : 'yes';
        delete_post_meta($post_id, '_is_featured');
        add_post_meta($post_id, '_is_featured', $newStatus);
        echo json_encode(array(
            'ID'             => $post_id,
            'new_status'     => $newStatus,
            'total_featured' => $this->total_featured(get_post_type($post_id)),
        ));
        die();
    }
    /**
     * set_not_featured()
     *
     * Sets the value of 'featured' to 'no' right after the post creation
     */
    public function set_not_featured($post_id)
    {
        add_post_meta($post_id, '_is_featured', 'no');
    }
    public function admin_pre_get_posts($query)
    {
        global $wp_query;
        if (is_admin() && isset($_GET['post_status']) && $_GET['post_status'] == 'featured') {
            $query->set('meta_key', '_is_featured');
            $query->set('meta_value', 'yes');
        }
        return $query;
    }
    public function query_vars($public_query_vars)
    {
        $public_query_vars[] = 'featured';
        return $public_query_vars;
    }
    public function pre_get_posts($query)
    {
        if (!is_admin()) {
            if ($query->get('featured') == 'yes') {
                $query->set('meta_key', '_is_featured');
                $query->set('meta_value', 'yes');
            }
        }
        return $query;
    }

    public function edit_screen_featured_ui()
    {
        // global $typenow;
        if (is_admin()) {
            //Post types could be defined here ( $typenow == 'post' )
            echo '<div class="misc-pub-section"><span style="color:#999; margin: -2px 2px 0 -1px;" class="dashicons dashicons-star-filled"></span>' . "\n";
            echo '<label for="featured" title="' . esc_attr__('If checked, this is marked as featured.', 'featured-post') . '">' . "\n";
            echo __('Featured?', 'featured-post') . ' <input id="featured"" type="checkbox" value="yes" ' . checked(get_post_meta(get_the_ID(), '_is_featured', true), 'yes', false) . ' name="featured" /></label></div>' . "\n";
        }
    }
    public function edit_screen_featured_save($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['featured'])) {
            update_post_meta($post_id, '_is_featured', esc_attr($_POST['featured']));
        }
    }
    public function load_featured_textdomain()
    {
        load_plugin_textdomain('featured-post', false, dirname(plugin_basename(__FILE__)) . '/langs/');
    }
}
Featured_Post::get_instance();
