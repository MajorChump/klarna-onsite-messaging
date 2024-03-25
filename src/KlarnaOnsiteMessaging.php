<?php
namespace Krokedil\KlarnaOnsiteMessaging;

use Krokedil\KlarnaOnsiteMessaging\Pages\Product;
use Krokedil\KlarnaOnsiteMessaging\Pages\Cart;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KOSM_VERSION', '0.0.1' );

/**
 * The orchestrator class.
 */
class KlarnaOnsiteMessaging {
	/**
	 * The internal settings state.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * Class constructor.
	 *
	 * @param array $settings Any existing KOSM settings.
	 */
	public function __construct( $settings ) {
		$this->settings = new Settings( $settings );
		$page           = new Product( $this->settings );
		$cart           = new Cart( $this->settings );
		$shortcode      = new Shortcode();

		add_action( 'widgets_init', array( $this, 'init_widget' ) );

		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_filter( 'script_loader_tag', array( $this, 'add_data_attributes' ), 10, 2 );
		}
	}

	/**
	 * Register the widget.
	 *
	 * @return void
	 */
	public function init_widget() {
		register_widget( new Widget() );
	}

	/**
	 * Add data- attributes to <script> tag.
	 *
	 * @param string $tag The <script> tag for the enqueued script.
	 * @param string $handle The script’s registered handle.
	 * @return string
	 */
	public function add_data_attributes( $tag, $handle ) {
		if ( 'klarna_onsite_messaging_sdk' !== $handle ) {
			return $tag;
		}

		$environment    = 'yes' === $this->settings->get( 'onsite_messaging_test_mode' ) ? 'playground' : 'production';
		$data_client_id = apply_filters( 'kosm_data_client_id', $this->settings->get( 'data_client_id' ) );
		$tag            = str_replace( ' src', ' async src', $tag );
		$tag            = str_replace( '></script>', " data-environment={$environment} data-client-id='{$data_client_id}'></script>", $tag );

		return $tag;
	}

	/**
	 * Enqueue KOSM and library scripts.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		global $post;

		$has_shortcode = ( ! empty( $post ) && has_shortcode( $post->post_content, 'onsite_messaging' ) );
		if ( ! ( is_product() || is_cart() || ! $has_shortcode ) ) {
			return;
		}

		$region        = 'eu-library';
		$base_location = wc_get_base_location();
		if ( is_array( $base_location ) && isset( $base_location['country'] ) ) {
			if ( in_array( $base_location['country'], array( 'US', 'CA' ) ) ) {
				$region = 'na-library';
			} elseif ( in_array( $base_location['country'], array( 'AU', 'NZ' ) ) ) {
				$region = 'oc-library';
			}
		}
		$region = apply_filters( 'kosm_region_library', $region );

		if ( ! empty( $this->settings->get( 'data_client_id' ) ) ) {
			// phpcs:ignore -- The version is managed by Klarna.
			wp_register_script( 'klarna_onsite_messaging_sdk', 'https://js.klarna.com/web-sdk/v1/klarna.js', array(), false );
		}

		$script_path = plugin_dir_url( __FILE__ ) . 'assets/js/klarna-onsite-messaging.js';
		wp_register_script( 'klarna_onsite_messaging', $script_path, array( 'jquery', 'klarna_onsite_messaging_sdk' ), KOSM_VERSION, true );

		$localize = array(
			'ajaxurl'            => admin_url( 'admin-ajax.php' ),
			'get_cart_total_url' => \WC_AJAX::get_endpoint( 'kosm_get_cart_total' ),
		);

		if ( isset( $_GET['osmDebug'] ) ) {
			$localize['debug_info'] = array(
				'product'       => is_product(),
				'cart'          => is_cart(),
				'shortcode'     => $has_shortcode,
				'data_client'   => ! ( empty( $this->settings->get( 'data_client_id' ) ) ),
				'locale'        => Utility::get_locale_from_currency(),
				'currency'      => get_woocommerce_currency(),
				'library'       => ( wp_scripts() )->registered['klarna_onsite_messaging_sdk']->src ?? $region,
				'base_location' => $base_location['country'],
			);

			$product = Utility::get_product();
			if ( ! empty( $product ) ) {
				$type                                   = $product->get_type();
				$localize['debug_info']['product_type'] = $type;
				if ( method_exists( $product, 'get_available_variations' ) ) {
					foreach ( $product->get_available_variations() as $variation ) {
						$attribute                                   = wc_get_var( $variation['attributes'] );
						$localize['debug_info']['default_variation'] = reset( $attribute );
						break;
					}
				}
			}
		}

		wp_localize_script(
			'klarna_onsite_messaging',
			'klarna_onsite_messaging_params',
			$localize
		);

		if ( ! empty( $this->settings->get( 'data_client_id' ) ) ) {
			wp_enqueue_script( 'klarna_onsite_messaging_sdk' );
		}

		wp_enqueue_script( 'klarna_onsite_messaging' );
	}
}
