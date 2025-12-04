<?php
defined( 'ABSPATH' ) || exit;
/*
Plugin Name:  Post Type Archive Links Reloaded
Plugin URI:   https://www.albgonzalez.com
Description:  Adds a MetaBox to Appearance > Menus allowing you to insert archive links of custom post types. Modernized fork of the original plugin by Stephen Harris.
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Version:      2.1
Author:       Alberto González
Author URI:   https://www.albgonzalez.com
Contributors: Stephen Harris, Franz Josef Kaiser, Ryan Urban
License:      GPLv3 or later
License URI:  http://www.gnu.org/licenses/gpl.txt
Text Domain:  hptal-textdomain
Domain Path:  /lang/
*/

add_action( 'plugins_loaded', array( new Post_Type_Archive_Links_Reloaded(), 'init' ) );

class Post_Type_Archive_Links_Reloaded {

    private $inited = false;
    const NONCE = 'hptal_nonce';
    const METABOXID = 'hptal-metabox';
    const METABOXLISTID = 'post-type-archive-checklist';

    protected $cpts = array();

    public function init() {
        if ( $this->inited ) {
            return;
        }

        $this->inited = true;
        $this->enable( dirname( plugin_basename( __FILE__ ) ) );
        $this->cpts = $this->get_queryable_post_types();

        // Metabox & scripts.
        add_action( 'admin_head-nav-menus.php', array( $this, 'add_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'metabox_script' ) );

        // Setup nav menu item output & current classes.
        add_filter( 'wp_setup_nav_menu_item', array( $this, 'setup_archive_item' ) );
        add_filter( 'wp_get_nav_menu_items', array( $this, 'maybe_make_item_current' ), 10, 3 );

        // AJAX: add CPT archive items to a menu.
        add_action( 'wp_ajax_' . self::NONCE, array( $this, 'ajax_add_post_type' ) );
    }

    public function enable( $domain ) {
        load_plugin_textdomain( 'hptal-textdomain', false, $domain . '/lang' );
    }

    /**
     * Get post types whose archives we want to expose in the metabox.
     */
    public function get_queryable_post_types() {

        $post_types = get_post_types(
            array(
                'has_archive'        => true,
                'publicly_queryable' => true,
                'show_in_nav_menus'  => true,
            ),
            'objects'
        );

        // Exclude core internal types.
        $core = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item' );
        foreach ( $core as $c ) {
            if ( isset( $post_types[ $c ] ) ) {
                unset( $post_types[ $c ] );
            }
        }

        /**
         * Filter the default list of CPTs to show in the metabox.
         *
         * @param array $post_types Array of post type objects.
         */
        $post_types = apply_filters( 'post_type_archive_links_post_types', $post_types );

        // Allow explicitly enabled CPTs via show_{$post_type}_archive_in_nav_menus.
        foreach ( get_post_types( array( 'has_archive' => true ), 'objects' ) as $pt ) {
            $show = apply_filters( "show_{$pt->name}_archive_in_nav_menus", null, $pt );
            if ( true === $show && ! isset( $post_types[ $pt->name ] ) ) {
                $post_types[ $pt->name ] = $pt;
            }
        }

        return $post_types;
    }

    /**
     * Add the metabox on Appearance → Menus.
     */
    public function add_meta_box() {
        add_meta_box(
            self::METABOXID,
            __( 'Post Type Archives', 'hptal-textdomain' ),
            array( $this, 'metabox' ),
            'nav-menus',
            'side',
            'low'
        );
    }

    /**
     * Enqueue metabox script on nav-menus.php only.
     */
    public function metabox_script( $hook ) {
        if ( 'nav-menus.php' !== $hook ) {
            return;
        }

        if ( empty( $this->cpts ) ) {
            return;
        }

        wp_register_script(
            'hptal-ajax-script',
            plugins_url( '/metabox.js', __FILE__ ),
            array( 'jquery', 'nav-menu' ),
            false,
            true
        );

        wp_localize_script(
            'hptal-ajax-script',
            'hptal_obj',
            array(
                'ajaxurl'         => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( self::NONCE ),
                'metabox_id'      => self::METABOXID,
                'metabox_list_id' => self::METABOXLISTID,
                'action'          => self::NONCE,
            )
        );

        wp_enqueue_script( 'hptal-ajax-script' );
    }

    /**
     * Metabox HTML output.
     */
    public function metabox() {
        if ( empty( $this->cpts ) ) {
            echo '<p>' . esc_html__( 'No items.', 'hptal-textdomain' ) . '</p>';
            return;
        }

        global $nav_menu_selected_id;

        echo '<ul id="' . esc_attr( self::METABOXLISTID ) . '">';
        foreach ( $this->cpts as $pt ) {
            echo '<li><label>';
            echo '<input type="checkbox" value="' . esc_attr( $pt->name ) . '" /> ';
            echo esc_html( $pt->labels->name );
            echo '</label></li>';
        }
        echo '</ul>';

        echo '<p class="button-controls"><span class="add-to-menu">';
        echo '<input type="submit" ' . disabled( $nav_menu_selected_id, 0, false ) . ' class="button-secondary submit-add-to-menu right" value="' . esc_attr__( 'Add to Menu', 'hptal-textdomain' ) . '" id="submit-post-type-archives" />';
        echo '<span class="spinner"></span>';
        echo '</span></p>';
    }

    /**
     * AJAX: create menu items for selected post type archives.
     */
    public function ajax_add_post_type() {

        // Capability.
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( '-1' );
        }

        // Nonce.
        check_ajax_referer( self::NONCE, 'nonce' );

        $post_types = array();
        if ( isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ) {
            $post_types = array_map( 'sanitize_key', wp_unslash( (array) $_POST['post_types'] ) );
        }

        if ( empty( $post_types ) ) {
            wp_die();
        }

        $menu_id       = isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0;
        $menu_item_ids = array();

        foreach ( $post_types as $pt ) {
            $obj = get_post_type_object( $pt );
            if ( ! $obj ) {
                continue;
            }

            $data = array(
                'menu-item-type'   => 'post_type_archive',
                'menu-item-object' => $obj->name,
                'menu-item-title'  => $obj->labels->name,
                'menu-item-status' => 'publish',
            );

            $menu_item_ids[] = wp_update_nav_menu_item( $menu_id, 0, $data );
        }

        if ( empty( $menu_item_ids ) ) {
            wp_die();
        }

        $items     = wp_get_nav_menu_items( $menu_id );
        $to_print  = array();

        foreach ( $items as $item ) {
            if ( in_array( $item->ID, $menu_item_ids, true ) ) {
                $item->post_type = 'nav_menu_item';
                $item->context   = 'edit';
                $to_print[]      = $item;
            }
        }

        $args = (object) array(
            'walker' => new Walker_Nav_Menu_Edit(),
        );

        echo walk_nav_menu_tree( $to_print, 0, $args );
        wp_die();
    }

    /**
     * Assign URL and label to archive menu items.
     */
    public function setup_archive_item( $menu_item ) {
        if ( 'post_type_archive' !== $menu_item->type ) {
            return $menu_item;
        }

        $menu_item->type_label = __( 'Archive', 'hptal-textdomain' );
        $menu_item->url        = get_post_type_archive_link( $menu_item->object );

        return $menu_item;
    }

    /**
     * Mark the archive link as current when viewing its archive or a single of that CPT.
     */
    public function maybe_make_item_current( $items, $menu, $args ) {
        if ( is_admin() ) {
            return $items;
        }

        foreach ( $items as $key => $item ) {
            if ( 'post_type_archive' !== $item->type ) {
                continue;
            }

            $type      = $item->object;
            $post_type = get_post_type();

            if ( $type === $post_type && ( is_post_type_archive( $type ) || is_singular( $type ) ) ) {
                $items[ $key ]->current = true;
            }
        }

        return $items;
    }
}
