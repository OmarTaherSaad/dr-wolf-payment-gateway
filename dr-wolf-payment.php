<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

function modify_jquery_version()
{
	if (!is_admin()) {
		wp_deregister_script('jquery');
		wp_register_script(
			'jquery',
			'https://ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js',
			false,
			'2.0.s'
		);
		wp_enqueue_script('jquery');
	}
}
add_action('init', 'modify_jquery_version');

/**
 * Plugin Name: WooCommerce Dr Wolf Payment Gateway
 * Description: Accept payments through Dr Wolf payment gateway.
 * Author: Omar Taher Saad
 * Author URI: https://omartahersaad.com
 * Version: 1.4
 */
/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */

add_filter('woocommerce_payment_gateways', 'drwolf_add_gateway_class');
function drwolf_add_gateway_class($gateways)
{
	$gateways[] = 'WC_DrWolf_Gateway';
	return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'drwolf_init_gateway_class');
function drwolf_init_gateway_class()
{

	if (!function_exists('is_plugin_active')) {
		require_once ABSPATH . '/wp-admin/includes/plugin.php';
	}
	if (!is_plugin_active('woocommerce/woocommerce.php')) {
		add_action(
			'admin_notices',
			function () {
				/* translators: 1. URL link. */
				echo '<div class="error"><p><strong>' . sprintf(esc_html__('WooCommerce Dr Wolf Payment Gateway requires WooCommerce to be installed and active. You can download %s here.'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
			}
		);

		return;
	}

	class WC_DrWolf_Gateway extends WC_Payment_Gateway
	{

		/**
		 * Class constructor, more about it in Step 3
		 */
		public function __construct()
		{
			$this->id                 = 'drwolf';
			$this->icon               = apply_filters('woocommerce_drwolf_icon', '');
			$this->has_fields         = false;
			$this->method_title       = "Dr Wolf Gateway";
			$this->method_description = "Recieve payment through Dr.Wolf Payment Gateway.";
			$this->supports = array(
				'products'
			);

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables.
			$this->title        = $this->get_option('title');
			$this->description  = $this->get_option('description');
			$this->enabled = $this->get_option('enabled');
			$this->testmode = 'yes' === $this->get_option('testmode');
			$this->test_key = $this->get_option('test_key');
			$this->live_key = $this->get_option('live_key');


			// Actions.
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_api_drwolf', array($this, 'webhook'));
		}

		/**
		 * Plugin options, we deal with it in Step 3 too
		 */
		public function init_form_fields()
		{

			$this->form_fields = array(
				'enabled' => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable Dr.Wolf Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'Credit Card',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay with your credit card via our payment gateway.',
				),
				'testmode' => array(
					'title'       => 'Test mode',
					'label'       => 'Enable Test Mode',
					'type'        => 'checkbox',
					'description' => 'Place the payment gateway in test mode using test API keys.',
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'user_id' => array(
					'title'       => 'Your Account ID',
					'type'        => 'number',
				),
				'test_key' => array(
					'title'       => 'Test Private Key',
					'type'        => 'password',
				),
				'live_key' => array(
					'title'       => 'Live Private Key',
					'type'        => 'password'
				),
				'callback' => array(
					'title' => 'Callback URL for Dr.Wolf Gateway',
					'type' => 'text',
					'custom_attributes' => array('readonly' => 'readonly'),
					'default' => get_site_url() . '/wc-api/drwolf/'
				)
			);
		}

		/*
		 * We're processing the payments here, everything about it is in Step 5
		 */
		public function process_payment($order_id)
		{
			global $woocommerce;

			// we need it to get any order detailes
			$order = wc_get_order($order_id);


			/*
         	 * Array with parameters for API interaction
        	 */
			$args = array(
				'wp_id' => $order_id,
				'return_url' => $this->get_return_url($order),
				'cancel_url' => $order->get_checkout_payment_url(),
				'key' => $isTest ? $this->get_option('test_key') : $this->get_option('live_key'),
				'currency' => $order->get_currency(),
				'amount_cents' => $order->get_total() * 100,
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'email' => $order->get_billing_email(),
				'phone_number' => $order->get_billing_phone()
			);
			$isTest = (bool)($this->get_option('testmode') == 'yes');
			$userId = $this->get_option('user_id');
			$response = wp_remote_post("http://payment.divagirls.uk/api/users/$userId/create-order", array(
				'method' => 'POST',
				'headers' => array('Content-Type' => 'application/json'),
				'sslverify'   => false,
				'body' => json_encode($args)
			));
			if (!is_wp_error($response)) {
				$body = json_decode($response['body'], true);
				var_dump($response['body']);
				if (!$body['success']) {
					wc_add_notice('Connection error [1]. ' . implode(', ', $body['errors']), 'error');
					return;
				}
				return array(
					'result'   => 'success',
					'redirect' => $body['url'],
				);
			} else {
				wc_add_notice('Connection error [2].', 'error');
				return;
			}
		}

		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		public function webhook()
		{
			header("Access-Control-Allow-Origin: payment.divagirls.uk");
			if (isset($_GET['id'], $_GET['key'], $_GET['paid']) && $_GET['key'] == $this->get_option('live_key') && $_GET['paid']) {
				// we received the payment
				$order = wc_get_order($_GET['id']);
				$order->payment_complete();
				$order->reduce_order_stock();

				// some notes to customer (replace true with false to make it private)
				$order->add_order_note('Hey, your order is paid! Thank you!', true);

				// Empty cart
				$woocommerce->cart->empty_cart();

				//Send Email for disputes
				$user_email = $order->get_billing_email();
				$headers = [];
				$headers[] = 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';
				$headers[] = 'Cc: Payment Gateway <info@drwolfstore.com>';
				$message = "
			Thanks for your purchase from our website!
			If you have any problem with your order, We can help you out [Reship or full refund]
			Please contact us if you need any help, we can solve any issues you might have.
			Please note that your order will appear in your bank statement with a different name but same amount exactly, so don't worry, this is due to the sensitivity of our products :)
			For more help email us on:
			info@drwolfstore.com
			Or call us:
			+1 443 891 6692";
				wp_mail($user_email, "Your Order is Paid! We Can Help You", $message, $headers);
			} else {
				wc_add_notice('Please try again.', 'error');
				return;
			}
			update_option('webhook_debug', $_GET);
		}
	}
}
