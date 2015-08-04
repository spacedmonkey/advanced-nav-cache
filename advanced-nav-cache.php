<?php
/**
 * Cache wp_nav_menu output in object cache.
 *
 *
 * @package   Advanced_Nav_Cache
 * @author    Jonathan Harris <jon@spacedmonkey.co.uk>
 * @license   GPL-2.0+
 * @link      http://www.jonathandavidharris.co.uk/
 * @copyright 2015 Spacedmonkey
 *
 * @wordpress-plugin
 * Plugin Name:        Advanced Nav Cache
 * Plugin URI:         https://www.github.com/spacedmonkey/advanced-nav-cache
 * Description:        Cache wp_nav_menu output in object cache.
 * Version:            1.0.0
 * Author:             Jonathan Harris
 * Author URI:         http://www.jonathandavidharris.co.uk/
 * License:            GPL-2.0+
 * License URI:        http://www.gnu.org/licenses/gpl-2.0.txt
 * GitHub Plugin URI:  https://www.github.com/spacedmonkey/advanced-nav-cache
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Advanced_Nav_Cache
 */
class Advanced_Nav_Cache {
	/**
	 * @var string
	 */
	var $CACHE_GROUP_PREFIX = 'advanced_nav_cache_';

	// Flag for temp (within one page load) turning invalidations on and off
	// @see dont_clear_advanced_nav_cache()
	// @see do_clear_advanced_nav_cache()
	// Used to prevent invalidation during new comment
	/**
	 * @var bool
	 */
	var $do_flush_cache = true;

	// Flag for preventing multiple invalidations in a row: clean_post_cache() calls itself recursively for post children.
	/**
	 * @var bool
	 */
	var $need_to_flush_cache = true; // Currently disabled

	/* Per cache-clear data */
	/**
	 * @var int
	 */
	var $cache_incr = 0; // Increments the cache group (advanced_nav_cache_0, advanced_nav_cache_1, ...)
	/**
	 * @var string
	 */
	var $cache_group = ''; // CACHE_GROUP_PREFIX . $cache_incr

	/**
	 *
	 */
	function __construct() {
		// Specific to certain Memcached Object Cache plugins
		if ( function_exists( 'wp_cache_add_group_prefix_map' ) ) {
			wp_cache_add_group_prefix_map( $this->CACHE_GROUP_PREFIX, 'advanced_nav_cache' );
		}

		$this->setup_for_blog();

		add_action( 'switch_blog', array( $this, 'setup_for_blog' ), 10, 2 );

		add_action( 'clean_term_cache', array( $this, 'clear_advanced_nav_cache' ) );
		add_action( 'clean_post_cache', array( $this, 'clear_advanced_nav_cache' ) );

		// Don't clear Advanced Post Cache for a new comment - temp core hack
		// http://core.trac.wordpress.org/ticket/15565
		add_action( 'wp_updating_comment_count', array( $this, 'dont_clear_advanced_nav_cache' ) );
		add_action( 'wp_update_comment_count', array( $this, 'do_clear_advanced_nav_cache' ) );

		add_filter( 'pre_wp_nav_menu', array( $this, 'pre_wp_nav_menu' ), 9, 2 );
		add_filter( 'wp_nav_menu', array( $this, 'wp_nav_menu' ), 99, 2 );

	}

	/**
	 * @param bool $new_blog_id
	 * @param bool $previous_blog_id
	 */
	function setup_for_blog( $new_blog_id = false, $previous_blog_id = false ) {
		if ( $new_blog_id && $new_blog_id == $previous_blog_id ) {
			return;
		}

		$this->cache_incr = wp_cache_get( 'advanced_nav_cache', 'cache_incrementors' ); // Get and construct current cache group name
		if ( ! is_numeric( $this->cache_incr ) ) {
			$now = time();
			wp_cache_set( 'advanced_nav_cache', $now, 'cache_incrementors' );
			$this->cache_incr = $now;
		}
		$this->cache_group = $this->CACHE_GROUP_PREFIX . $this->cache_incr;
	}

	/**
	 * @param $output
	 * @param $args
	 *
	 * @return string
	 */
	public function pre_wp_nav_menu( $output, $args ) {
		if ( $this->is_nav_cached_enabled( $args ) ) {
			$cached_value = wp_cache_get( $this->get_key( $args ), $this->cache_group );
			if ( $cached_value !== false ) {
				$output = $cached_value;
			}
		}

		return $output;
	}

	/**
	 * @param $output
	 * @param $args
	 *
	 * @return string
	 */
	public function wp_nav_menu( $output, $args ) {
		if ( $this->is_nav_cached_enabled( $args ) ) {
			$cached_value = wp_cache_get( $this->get_key( $args ), $this->cache_group );
			if ( $cached_value === false ) {
				wp_cache_set( $this->get_key( $args ), $output, $this->cache_group );
			}
		}

		return $output;
	}

	/**
	 * @param $args
	 *
	 * @return string
	 */
	public function get_key( $args ) {
		$query_var = $this->get_query_vars();
		$object_id = $this::make_cache_key( $query_var );
		$flat_args = $this::make_cache_key( $args );
		$cache_key = sprintf( '%s_%s', $object_id, $flat_args );

		return apply_filters('advanced-nav-cache-key', $cache_key, $args, $query_var );
	}

	/**
	 * Per page cache salt. If 404 page, just return the same menu as other 404 pages.
	 * Also, on secondary pages, return the same menu. Better for performance.
	 *
	 * @return string
	 */
	public function get_query_vars() {
		global $wp_query;

		if ( $wp_query->is_404() ) {
			$page_variable = array( 'error_404' );
		} else {
			$page_variable = $wp_query->query_vars;

		}
		$page_variable['paged'] = 0;
		asort( $page_variable );

		return $page_variable;
	}

	/**
	 * @param $key
	 *
	 * @return string
	 */
	static public function make_cache_key( $key ) {
		return md5( serialize( $key ) );
	}

	/**
	 *
	 */
	public function clear_advanced_nav_cache() {
		$this->flush_cache();
	}

	/**
	 *
	 */
	public function do_clear_advanced_nav_cache() {
		$this->do_flush_cache = true;
	}

	/**
	 *
	 */
	public function dont_clear_advanced_nav_cache() {
		$this->do_flush_cache = false;
	}

	/* Advanced Nav Cache API */

	/**
	 * Flushes the cache by incrementing the cache group
	 */
	public function flush_cache() {
		// Cache flushes have been disabled
		if ( ! $this->do_flush_cache ) {
			return;
		}

		// Bail on post preview
		if ( is_admin() && isset( $_POST['wp-preview'] ) && 'dopreview' == $_POST['wp-preview'] ) {
			return;
		}

		// Bail on autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// We already flushed once this page load, and have not put anything into the cache since.
		// OTHER processes may have put something into the cache!  In theory, this could cause stale caches.
		// We do this since clean_post_cache() (which fires the action this method attaches to) is called RECURSIVELY for all descendants.
//		if ( !$this->need_to_flush_cache )
//			return;

		$this->cache_incr = wp_cache_incr( 'advanced_nav_cache', 1, 'cache_incrementors' );
		if ( 10 < strlen( $this->cache_incr ) ) {
			wp_cache_set( 'advanced_nav_cache', 0, 'cache_incrementors' );
			$this->cache_incr = 0;
		}
		$this->cache_group         = $this->CACHE_GROUP_PREFIX . $this->cache_incr;
		$this->need_to_flush_cache = false;
	}

	/**
	 *
	 * @return boolean
	 */
	public function is_nav_cached_enabled( $args = array() ) {
		$enabled = true;

		if ( is_admin() ) {
			$enabled = false;
		}
		if ( is_feed() ) {
			$enabled = false;
		}
		if ( is_preview() ) {
			$enabled = false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			$enabled = false;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$enabled = false;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$enabled = false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			$enabled = false;
		}

		return apply_filters( 'advanced-nav-cache-enabled', $enabled, $args );
	}

}

global $advanced_nav_cache_object;
$advanced_nav_cache_object = new Advanced_Nav_Cache();
