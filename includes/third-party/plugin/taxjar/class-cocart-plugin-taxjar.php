<?php
/**
 * Handles support for TaxJar plugin.
 *
 * @author   Sébastien Dumont
 * @category Classes
 * @package  CoCart\Third Party\Plugin
 * @since    2.9.0
 * @license  GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CoCart_Plugin_TaxJar' ) ) {

	class CoCart_Plugin_TaxJar {

		/**
		 * Constructor.
		 *
		 * @access public
		 */
		public function __construct() {
			if ( class_exists( 'WC_Taxjar' ) && version_compare( WC_Taxjar::$version, '3.2.5', '=>' ) ) {
				add_filter( 'taxjar_should_calculate_cart_tax', array( $this, 'maybe_calculate_tax' ) );
			}
		}

		/**
		 * Returns true to allow TaxJar to calculate totals 
		 * when CoCart API is requested.
		 *
		 * @access public
		 * @return bool
		 */
		public function maybe_calculate_tax( $should_calculate ) {
			if ( CoCart_Helpers::is_rest_api_request() ) {
				$should_calculate = true;
			}

			return $should_calculate;
		}

	} // END class.

} // END if class exists.

return new CoCart_Plugin_TaxJar();