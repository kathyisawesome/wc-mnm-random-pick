<?php
/**
 * Plugin Name: WooCommerce Mix and Match - Random Pick
 * Plugin URI: https://woocommerce.com/products/woocommerce-mix-and-match-products/
 * Description: Add a mix and match product to the cart with a random configuration.
 * Version: 1.0.0
 * Author: Kathy Darling
 * Author URI: http://kathyisawesome.com/
 *
 * Text Domain: wc-mnm-random-pick
 * Domain Path: /languages/
 *
 * Requires at least: 6.2.0
 * Tested up to: 6.6.0
 *
 * WC requires at least: 9.0.0
 * WC tested up to: 9.2.0
 *
 * GitHub Plugin URI: https://github.com/kathyisawesome/wc-mnm-random-pick
 * Primary Branch: trunk
 * Release Asset: true
 *
 * Copyright: © 2024 Kathy Darling
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declare Features compatibility
 */
add_action( 'before_woocommerce_init', function() {

    if ( ! class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        return;
    }

    // HPOS (Custom Order tables.
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', plugin_basename( __FILE__ ), true );

    // Cart and Checkout Blocks.
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', plugin_basename( __FILE__ ), true );
} );

/**
 * Localize the plugin.
 */
add_action( 'init', function() {
    load_plugin_textdomain( 'wc-mnm-random-pick', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
} );

/**
 * Add a random pick button to the product page.
 */
function wc_mnm_random_button() {
	global $product;
	
	if ( $product instanceof WC_Product_Mix_and_Match ) {
		echo '<button name="wc-mnm-randomize" type="submit" style="float:none; margin-bottom:10px;" value="'. esc_attr( $product->get_id() ) . '" class="button wc_mnm_random_button_button ' . esc_attr( wp_theme_get_element_class_name( 'button' ) ) . '" >' . esc_html__( 'Pick for me', 'your-textdomain' ) . '</button>';
	}
}
add_action( 'woocommerce_after_add_to_cart_button', 'wc_mnm_random_button' );

/**
 * Add to cart action.
 *
 * Checks for a valid request, does validation (via hooks) and then redirects if valid.
 *
 * @param bool $url (default: false) URL to redirect to.
 */
function wc_mnm_add_random_sort_to_cart( $url = false ) {
	if ( ! isset( $_REQUEST['wc-mnm-randomize'] ) || ! is_numeric( wp_unslash( $_REQUEST['wc-mnm-randomize'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return;
	}

	wc_nocache_headers();

	$product_id        = apply_filters( 'woocommerce_add_to_cart_product_id', absint( wp_unslash( $_REQUEST['wc-mnm-randomize'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$was_added_to_cart = false;
	$adding_to_cart    = wc_get_product( $product_id );

	if ( ! $adding_to_cart || ! $adding_to_cart->is_type( 'mix-and-match' ) ) {
		return;
	}
	
	$quantity          = empty( $_REQUEST['quantity'] ) ? 1 : wc_stock_amount( wp_unslash( $_REQUEST['quantity'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	
	// Generate a random config.
	$config = array();
	$total_qty = 0;

	$child_items = $adding_to_cart->get_child_items();

	$min = $adding_to_cart->get_min_container_size();
	$max = $adding_to_cart->get_max_container_size();

	$target_qty = $max ? ( $min + $max ) / 2 : $min;

	$num_child_items = count( $child_items );

	$target_child_qty = $num_child_items >= $target_qty ? floor($num_child_items / $target_qty) : 1;

	foreach ($child_items as $child_item) {

		if ( ! $child_item->is_in_stock() ) {
			continue;
		}

		$child_product_id = $child_item->get_variation_id() ? $child_item->get_variation_id() : $child_item->get_product_id();
		$child_item_min   = $child_item->get_quantity('min') > 0 ? $child_item->get_quantity('min') : $target_child_qty;
		$child_item_max   = $child_item->get_quantity('max') > $target_child_qty ? $target_child_qty : $child_item->get_quantity('max');
		$child_item_step  = $child_item->get_quantity('step') ?: 1;  // Default step to 1 if not provided.
		
		$debug = array( 
			$child_product_id => array(
				'min'=>$child_item_min,
				'max'=>$child_item_max,
				'step'=> $child_item_step,
			)
		);
		
		// Calculate the minimum and maximum steps
		$min_step_count = (int) ceil($child_item_min / $child_item_step);
		$max_step_count = (int) floor($child_item_max / $child_item_step);

		// Calculate the remaining quantity allowed
		$remaining_qty = $target_qty - $total_qty;
		
		// Adjust the max_step_count if remaining_qty is less than the current max_step_count * step
		$adjusted_max_step_count = (int) floor($remaining_qty / $child_item_step);
		$max_step_count = min($max_step_count, $adjusted_max_step_count);

		// Generate a random step count between the min and max step counts
		$random_step_count = mt_rand($min_step_count, $max_step_count);

		// Calculate the final quantity respecting the step
		$qty = $random_step_count * $child_item_step;

		// Update the total quantity
		$total_qty += $qty;

		// Store the configuration for this child item
		$config[$child_product_id] = array(
			'product_id'   => $child_item->get_product_id(),
			'variation_id' => $child_item->get_variation_id(),
			'quantity'     => $qty,
		);

		// Break the loop if we've reached the target quantity
		if ($total_qty >= $target_qty) {
			break;
		}
	}

	// If we have remaining child items and need to distribute any remaining quantity, do so in a way that respects their steps
	if ($total_qty < $target_qty) {
		foreach ($child_items as $child_item) {
			$child_product_id = $child_item->get_variation_id() ? $child_item->get_variation_id() : $child_item->get_product_id();
			
			// Calculate how much more we can add to this item
			$additional_qty = $target_qty - $total_qty;
			$child_item_step = $child_item->get_quantity('step') ?: 1;

			// Adjust the additional_qty to respect the step
			$additional_qty = min($additional_qty, $child_item_step * (int) floor($additional_qty / $child_item_step));

			if ($additional_qty > 0 && isset($config[$child_product_id])) {
				$config[$child_product_id]['quantity'] += $additional_qty;
				$total_qty += $additional_qty;
			}

			if ($total_qty >= $target_qty) {
				break;
			}
		}
	}

	$cart_item_data = array(
		'mnm_config' => $config,
	);
	
	// Validate against the random config.
	$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, 0, array(), $cart_item_data );

	// Add random config to cart.
	if ( $passed_validation && false !== WC()->cart->add_to_cart( $product_id, $quantity, 0, array(), $cart_item_data ) ) {
		wc_add_to_cart_message( array( $product_id => $quantity ), true );
		$was_added_to_cart = true;
	} else {
		$was_added_to_cart = false;
	}

	// If we added the product to the cart we can now optionally do a redirect.
	if ( $was_added_to_cart && 0 === wc_notice_count( 'error' ) ) {
		$url = apply_filters( 'woocommerce_add_to_cart_redirect', $url, $adding_to_cart );

		if ( $url ) {
			wp_safe_redirect( $url );
			exit;
		} elseif ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}
}
add_action( 'wp_loaded', 'wc_mnm_add_random_sort_to_cart' );