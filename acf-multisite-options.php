<?php
/*
Plugin Name: ACF Multisite Options
Plugin URI: https://owlwatch.com
Description: Allow multisite options pages
Author: Mark Fabrizio
Version: 1.0.0
Author URI: http://owlwatch.com/
*/
namespace ACF\Multisite\Options;

/**
 * This plugin provides functionality for Network level options
 * pages using the normal ACF API
 */
class Plugin
{

	/**
	 * flag used to indiciate when we need to filter options pages
	 * @var boolean
	 */
	protected $_filter_options_pages = false;

	/**
	 * Singleton pattern
	 */
	public static function getInstance()
	{
		static $instance;
		if( !isset( $instance ) ){
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Wait until plugins are loaded so we can ensure ACF is available
	 */
	protected function __construct()
	{
		add_action( 'plugins_loaded', [$this, 'init']);
	}

	/**
	 * Setup the plugin hooks
	 * @return void
	 */
	public function init()
	{
		$acf_admin_options_page = $this->get_acf_admin_options_page();
		if( !$acf_admin_options_page ){
			return;
		}

		// Run the ACF Options admin_menu function on network menu
		add_action( 'network_admin_menu', [$acf_admin_options_page, 'admin_menu'], 99, 0 );

		// Filter out pages by "network" attribute when loading the pages
		// for the admin_menu depending on the context
		add_action( 'admin_menu', [$this, 'before_admin_menu'], 1 );
		add_action( 'network_admin_menu', [$this, 'before_admin_menu'], 1 );
		add_filter( 'acf/get_options_pages', [$this, 'filter_options_pages'] );

		// Filter get/set values to use multisite options if the page_id
		// corresponds to a network page
		add_filter( 'acf/pre_load_value', [$this, 'pre_load_value'], 10, 4 );
		add_filter( 'acf/pre_update_value', [$this, 'pre_update_value'], 10, 4 );

	}

	/**
	 * Sneaky way to get the instantiated "$acf_admin_options_page" object
	 * as it does not have a global reference.
	 *
	 * @return mixed Returns either the page array or false
	 */
	public function get_acf_admin_options_page()
	{
		static $acf_admin_options_page = null;
		if( isset( $acf_admin_options_page ) ){
			return $acf_admin_options_page;
		}
		$acf_admin_options_page = false;
		global $wp_filter;
		if( empty( $wp_filter['admin_menu'] ) || empty( $wp_filter['admin_menu'][99] ) ){
			return $acf_admin_options_page;
		}
		foreach( $wp_filter['admin_menu'][99] as $hook ){
			if( is_array( $hook['function'] ) && get_class( $hook['function'][0] ) === 'acf_admin_options_page' ){
				$acf_admin_options_page = $hook['function'][0];
				break;
			}
		}
		return $acf_admin_options_page;
	}

	/**
	 * Enabling page filtering for the acf_get_options_pages function
	 * when we are in the admin menu
	 * @return void
	 */
	public function before_admin_menu()
	{
		$this->_filter_options_pages = true;
	}

	/**
	 * Filter the options pages depending on the context, network or not network.
	 *
	 * @param  array  $pages ACF options pages
	 * @return array  The filtered pages.
	 */
	public function filter_options_pages( array $pages )
	{
		if( !$this->_filter_options_pages ){
			return $pages;
		}

		$this->_filter_options_pages = false;

		return array_filter( $pages, function( $page ){
			return is_network_admin() ? !empty($page['network']) : empty( $page['network'] );
		});
	}

	/**
	 * Retrieve an options page by its "post_id" attribute
	 *
	 * There could be multiple pages with the same post_id,
	 * but we require that network pages either share a post_id
	 * or each have their own.
	 *
	 * @param  string $post_id the ACF post_id
	 * @return mixed the options page or false if none found
	 */
	public function get_options_page_by_post_id( string $post_id )
	{
		$pages = acf_get_options_pages();
		foreach( $pages as $page ){
			if( !empty( $page['post_id'] ) && $page['post_id'] === $post_id ){
				return $page;
			}
		}
		return null;
	}

	/**
	 * Hook into ACF pre_load_value so we can load the value
	 * with get_site_option if it is a network post_id.
	 *
	 * A lot of this code is duplicated from acf core to allow
	 * for the same processing of values
	 *
	 * @param  mixed      $return    the value to filter
	 * @param  string     $post_id   the acf post_id key
	 * @param  acf_field  $field     the acf field
	 * @return mixed the return value
	 */
	public function pre_load_value( $return, $post_id, $field )
	{
		$page = $this->get_options_page_by_post_id( $post_id );
		if( !$page || empty( $page['network'] ) ){
			return $return;
		}

		// vars
		$cache_key = "get_value/post_id={$post_id}/name={$field['name']}";


		// return early if cache is found
		if( acf_isset_cache($cache_key) ) {
			return acf_get_cache($cache_key);
		}


		// load value
		$value = $this->get_metadata( $post_id, $field['name'] );


		// if value was duplicated, it may now be a serialized string!
		$value = maybe_unserialize( $value );


		// no value? try default_value
		if( $value === null && isset($field['default_value']) ) {
			$value = $field['default_value'];
		}


		/**
		*  Filters the $value after it has been loaded.
		*
		*  @date	28/09/13
		*  @since	5.0.0
		*
		*  @param	mixed $value The value to preview.
		*  @param	string $post_id The post ID for this value.
		*  @param	array $field The field array.
		*/
		$value = apply_filters( "acf/load_value/type={$field['type']}",		$value, $post_id, $field );
		$value = apply_filters( "acf/load_value/name={$field['_name']}",	$value, $post_id, $field );
		$value = apply_filters( "acf/load_value/key={$field['key']}",		$value, $post_id, $field );
		$value = apply_filters( "acf/load_value",							$value, $post_id, $field );


		// update cache
		acf_set_cache($cache_key, $value);


		// return
		return $value;
	}

	/**
	 * Hook into ACF pre_load_value so we can load the value
	 * with get_site_option if it is a network post_id.
	 *
	 * A lot of this code is duplicated from acf core to allow
	 * for the same processing of values
	 *
	 * @param  mixed      $return    the value to filter
	 * @param  mixed      $value     the value we are setting
	 * @param  string     $post_id   the acf post_id key
	 * @param  acf_field  $field     the acf field
	 * @return mixed the return value
	 */
	public function pre_update_value( $return, $value, $post_id, $field )
	{
		$page = $this->get_options_page_by_post_id( $post_id );
		if( !$page || empty( $page['network'] ) ){
			return $return;
		}

		/**
		*  Filters the $value before it is saved.
		*
		*  @date	28/09/13
		*  @since	5.0.0
		*  @since	5.7.6 Added $_value parameter.
		*
		*  @param	mixed $value The value to update.
		*  @param	string $post_id The post ID for this value.
		*  @param	array $field The field array.
		*  @param	mixed $_value The original value before modification.
		*/
		$_value = $value;
		$value = apply_filters( "acf/update_value/type={$field['type']}",	$value, $post_id, $field, $_value );
		$value = apply_filters( "acf/update_value/name={$field['_name']}",	$value, $post_id, $field, $_value );
		$value = apply_filters( "acf/update_value/key={$field['key']}",		$value, $post_id, $field, $_value );
		$value = apply_filters( "acf/update_value",							$value, $post_id, $field, $_value );


		// allow null to delete
		if( $value === null ) {
			return $this->delete_value( $post_id, $field );

		}


		// update value
		$return = $this->update_metadata( $post_id, $field['name'], $value );


		// update reference
		$this->update_metadata( $post_id, $field['name'], $field['key'], true );


		// clear cache
		acf_delete_cache("get_value/post_id={$post_id}/name={$field['name']}");
		acf_delete_cache("format_value/post_id={$post_id}/name={$field['name']}");


		// return
		return $return;
	}
	/**
	 * This is our own version of the acf_delete_value, but we are really
	 * only using it for null values in our update_value function
	 *
	 * @param  string     $post_id   the acf post_id key
	 * @param  acf_field  $field     the acf field
	 * @return mixed the return value
	 */
	public function delete_value( $post_id, $field )
	{
		/**
		*  Fires before a value is deleted.
		*
		*  @date	28/09/13
		*  @since	5.0.0
		*
		*  @param	string $post_id The post ID for this value.
		*  @param	mixed $name The meta name.
		*  @param	array $field The field array.
		*/
		do_action( "acf/delete_value/type={$field['type']}",	$post_id, $field['name'], $field );
		do_action( "acf/delete_value/name={$field['_name']}",	$post_id, $field['name'], $field );
		do_action( "acf/delete_value/key={$field['key']}",		$post_id, $field['name'], $field );
		do_action( "acf/delete_value",							$post_id, $field['name'], $field );

		// delete value
		$return = $this->delete_metadata( $post_id, $field['name'] );


		// delete reference
		$this->delete_metadata( $post_id, $field['name'], true );


		// clear cache
		acf_delete_cache("get_value/post_id={$post_id}/name={$field['name']}");
		acf_delete_cache("format_value/post_id={$post_id}/name={$field['name']}");

		return $return;
	}

	/**
	 * Copied from acf_get_metadata, simply replacing 'get_option' with 'get_site_option'
	 * @param  integer $post_id
	 * @param  string  $name
	 * @param  boolean $hidden
	 */
	public function get_metadata( $post_id = 0, $name = '', $hidden = false ) {

		// vars
		$value = null;
		$prefix = $hidden ? '_' : '';


		// get post_id info
		$info = acf_get_post_id_info($post_id);


		// bail early if no $post_id (acf_form - new_post)
		if( !$info['id'] ) return $value;


		// option
		if( $info['type'] === 'option' ) {

			$name = $prefix . $post_id . '_' . $name;
			$value = get_site_option( $name, null );

		// meta
		} else {

			$name = $prefix . $name;
			$meta = get_metadata( $info['type'], $info['id'], $name, false );

			if( isset($meta[0]) ) {

			 	$value = $meta[0];

		 	}

		}


		// return
		return $value;

	}

	/**
	 * Copied from acf_update_metadata, simply replacing 'update_option' with 'update_site_option'
	 * @param  integer $post_id
	 * @param  string  $name
	 * @param  mixed   $value
	 * @param  boolean $hidden
	 */
	public function update_metadata( $post_id = 0, $name = '', $value = '', $hidden = false )
	{
		// vars
		$return = false;
		$prefix = $hidden ? '_' : '';


		// get post_id info
		$info = acf_get_post_id_info($post_id);


		// bail early if no $post_id (acf_form - new_post)
		if( !$info['id'] ) return $return;


		// option
		if( $info['type'] === 'option' ) {

			$name = $prefix . $post_id . '_' . $name;
			$return = update_site_option( $name, $value );

		// meta
		} else {

			$name = $prefix . $name;
			$return = update_metadata( $info['type'], $info['id'], $name, $value );

		}


		// return
		return $return;
	}

	/**
	 * Copied from acf_delete_metadata, simply replacing 'delete_option' with 'delete_site_option'
	 * @param  integer $post_id
	 * @param  string  $name
	 * @param  boolean $hidden
	 */
	public function delete_metadata( $post_id = 0, $name = '', $hidden = false ) {

		// vars
		$return = false;
		$prefix = $hidden ? '_' : '';


		// get post_id info
		$info = acf_get_post_id_info($post_id);


		// bail early if no $post_id (acf_form - new_post)
		if( !$info['id'] ) return $return;


		// option
		if( $info['type'] === 'option' ) {

			$name = $prefix . $post_id . '_' . $name;
			$return = delete_site_option( $name );

		// meta
		} else {

			$name = $prefix . $name;
			$return = delete_metadata( $info['type'], $info['id'], $name );

		}


		// return
		return $return;

	}

}

// Instantiate our plugin
Plugin::getInstance();