<?php
/*
 * Plugin Name: TZSMM Pay Gateway
 * Plugin URI: https://tzsmmpay.com
 * Description: A seamless and secure payment gateway integration for WooCommerce using TZSMM Pay.
 * Author: TZSMM Pay
 * Author URI: https://tzsmmpay.com
 * Version: 1.2.0
 * Requires at least: 5.2
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tzsmmpay
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
 exit;
}

// Initialize the gateway class when WooCommerce is loaded
add_action('plugins_loaded', 'tzsmmpay_init_gateway_class', 0);

function tzsmmpay_init_gateway_class()
{
 if (!class_exists('WC_Payment_Gateway')) {
  return;
 }

 class WC_TZSMM_Pay_Gateway extends WC_Payment_Gateway
 {
  public function __construct()
  {
   $this->id = 'tzsmmpay';
   $this->icon = plugins_url('assets/logo.png', __FILE__); // Add a logo in the "assets" folder
   $this->has_fields = false;
   $this->method_title = __('TZSMM Pay', 'tzsmmpay');
   $this->method_description = __('Secure and fast payments via TZSMM Pay.', 'tzsmmpay');

   $this->supports = ['products'];

   // Load the settings
   $this->init_form_fields();
   $this->init_settings();

   $this->title = $this->get_option('title');
   $this->description = $this->get_option('description');
   $this->enabled = $this->get_option('enabled');
   $this->apikey = sanitize_text_field($this->get_option('apikey'));

   // Save settings
   add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

   // Handle Webhook
   add_action('woocommerce_api_' . strtolower(get_class($this)), [$this, 'handle_webhook']);
  }

  /**
   * Define the admin settings fields for the gateway
   */
  public function init_form_fields()
  {
   $this->form_fields = [
    'enabled' => [
     'title' => __('Enable/Disable', 'tzsmmpay'),
     'type' => 'checkbox',
     'label' => __('Enable TZSMM Pay Gateway', 'tzsmmpay'),
     'default' => 'no',
    ],
    'title' => [
     'title' => __('Title', 'tzsmmpay'),
     'type' => 'text',
     'description' => __('The title displayed at checkout.', 'tzsmmpay'),
     'default' => __('TZSMM Pay', 'tzsmmpay'),
     'desc_tip' => true,
    ],
    'description' => [
     'title' => __('Description', 'tzsmmpay'),
     'type' => 'textarea',
     'description' => __('The description displayed at checkout.', 'tzsmmpay'),
     'default' => __('Pay securely via TZSMM Pay.', 'tzsmmpay'),
     'desc_tip' => true,
    ],
    'apikey' => [
     'title' => __('API Key', 'tzsmmpay'),
     'type' => 'text',
     'description' => __('Your TZSMM Pay API key.', 'tzsmmpay'),
     'default' => '',
     'desc_tip' => true,
    ],
   ];
  }

  /**
   * Process the payment and redirect to TZSMM Pay
   */
  public function process_payment($order_id)
  {
   $order = wc_get_order($order_id);

   $data = [
    'cus_name' => sanitize_text_field($order->get_billing_first_name()),
    'cus_email' => sanitize_email($order->get_billing_email()),
    'amount' => $order->get_total(),
    'api_key' => $this->apikey,
    'currency' => $order->get_currency(),
    'callback_url' => WC()->api_request_url(strtolower(get_class($this))),
    'success_url' => $this->get_return_url($order),
    'cancel_url' => wc_get_checkout_url(),
    'cus_number' => $order_id,
   ];

   $response = $this->send_request_to_tzsmmpay($data);

   if (isset($response['payment_url'])) {
    return [
     'result' => 'success',
     'redirect' => esc_url($response['payment_url']),
    ];
   }

   wc_add_notice(__('Payment error: Could not process payment. Please try again.' . $response['messages'], 'tzsmmpay'), 'error');
   return ['result' => 'fail'];
  }

  /**
   * Send payment request to TZSMM Pay
   */
  private function send_request_to_tzsmmpay($data)
  {
   // Build the query string from the data array
   $query = http_build_query($data);

   // Prepare the arguments for the request
   $args = [
    'body' => $query,  // Send data as URL parameters
    'headers' => [
     'Content-Type' => 'application/x-www-form-urlencoded',  // Use the appropriate content type for URL-encoded parameters
    ],
    'timeout' => 45,
   ];

   // Send the request via wp_remote_post
   $response = wp_remote_post('https://tzsmmpay.com/api/payment/create', $args);

   // Handle errors if the response is a WP error
   if (is_wp_error($response)) {
    wc_add_notice(__('Connection error: ' . $response->get_error_message(), 'tzsmmpay'), 'error');
    return ['error' => $response->get_error_message()];
   }

   // Return the response body as an associative array
   return json_decode(wp_remote_retrieve_body($response), true);
  }

  /**
   * Handle webhook for payment verification
   */
  public function handle_webhook()
  {


   // Get the webhook payload
   $payload = $_REQUEST;

   // Validate the received payload
   if (empty($payload['cus_number']) || empty($payload['status'])) {
    status_header(400);
    echo "Error: Missing required parameters (cus_number or status)";
    exit;
   }

   // Sanitize the order ID from the payload
   $order_id = sanitize_text_field($payload['cus_number']);
   $order = wc_get_order($order_id);

   // Check if the order exists
   if (!$order) {
    status_header(404);
    echo "Error: Order not found";
    exit;
   }

   // Process payment verification based on the payment status
   if ($payload['status'] === 'Completed') {
    $verification = $this->verify_payment($payload['trx_id']);

    // Check if the verification was successful
    if ($verification['status'] === 'Completed') {
     $order->payment_complete();
     $order->add_order_note(__('Payment verified and received via TZSMM Pay.', 'tzsmmpay'));
    } else {
     // Log failure and update order status
     $order->update_status('failed', __('Payment verification failed.', 'tzsmmpay'));
     echo "Error: Payment verification failed ". json_encode($verification);
     exit;
    }
   } else {
    // Handle cases where payment status is not 'Completed'
    $order->update_status('failed', __('Payment failed via TZSMM Pay.', 'tzsmmpay'));
    echo "Error: Payment failed via TZSMM Pay";
    exit;
   }

   // Send successful response back to TZSMM Pay
   status_header(200);
   echo "Success: Payment processed successfully";
   exit;
  }

  /**
   * Verify the payment with TZSMM Pay API
   */
  private function verify_payment($payment_id)
  {
      // Prepare the data to send to the API as URL parameters
      $data = [
          'api_key' => $this->apikey,
          'trx_id' => sanitize_text_field($payment_id),
      ];
  
      // Build the query string from the $data array
      $query_string = http_build_query($data);
  
      // Send the request with the parameters as part of the URL
      $url = 'https://tzsmmpay.com/api/payment/verify?' . $query_string;
  
      // Send the GET request to verify the payment
      $response = wp_remote_get($url);
  
      // Check for WP errors in the response
      if (is_wp_error($response)) {
          return ['status' => 'error', 'message' => $response->get_error_message()];
      }
  
      // Decode the response from the API
      $response_body = json_decode(wp_remote_retrieve_body($response), true);
  
      // If response is empty or error occurs, return error status
      if (empty($response_body)) {
          return ['status' => 'error', 'message' => 'Invalid response from TZSMM Pay API'];
      }
  
      // Return the response from the verification API
      return $response_body;
  }
  

 }

 // Add the gateway to WooCommerce
 add_filter('woocommerce_payment_gateways', function ($gateways) {
  $gateways[] = 'WC_TZSMM_Pay_Gateway';
  return $gateways;
 });
}
