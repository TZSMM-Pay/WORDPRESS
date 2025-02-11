<?php
/*
 * Plugin Name: TZSMM Pay Gateway
 * Plugin URI: https://tzsmmpay.com
 * Description: A seamless and secure payment gateway integration for WooCommerce using TZSMM Pay.
 * Author: TZSMM Pay
 * Version: 1.2.3
 * Requires at least: 5.2
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tzsmm-pay-gateway
 * Contributors: tzsmmpay
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

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
            $this->method_title = __('TZSMM Pay', 'tzsmm-pay-gateway');
            $this->method_description = __('Secure and fast payments via TZSMM Pay.', 'tzsmm-pay-gateway');

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
            add_action('woocommerce_api_' . strtolower($this->id), [$this, 'handle_webhook']);
        }

        /**
         * Define the admin settings fields for the gateway
         */
        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', 'tzsmm-pay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable TZSMM Pay Gateway', 'tzsmm-pay-gateway'),
                    'default' => 'no',
                ],
                'title' => [
                    'title' => __('Title', 'tzsmm-pay-gateway'),
                    'type' => 'text',
                    'description' => __('The title displayed at checkout.', 'tzsmm-pay-gateway'),
                    'default' => __('TZSMM Pay', 'tzsmm-pay-gateway'),
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('Description', 'tzsmm-pay-gateway'),
                    'type' => 'textarea',
                    'description' => __('The description displayed at checkout.', 'tzsmm-pay-gateway'),
                    'default' => __('Pay securely via TZSMM Pay.', 'tzsmm-pay-gateway'),
                    'desc_tip' => true,
                ],
                'apikey' => [
                    'title' => __('API Key', 'tzsmm-pay-gateway'),
                    'type' => 'password',
                    'description' => __('Your TZSMM Pay API key.', 'tzsmm-pay-gateway'),
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
                'callback_url' => WC()->api_request_url(strtolower($this->id)),
                'success_url' => $this->get_return_url($order),
                'cancel_url' => wc_get_checkout_url(),
                'cus_number' => sanitize_text_field($order_id),
            ];

            $response = $this->send_request_to_tzsmmpay($data);

            if (isset($response['payment_url'])) {
                return [
                    'result' => 'success',
                    'redirect' => esc_url($response['payment_url']),
                ];
            }

            wc_add_notice(__('Payment error: Could not process payment. Please try again.', 'tzsmm-pay-gateway'), 'error');
            return ['result' => 'fail'];
        }

        /**
         * Send payment request to TZSMM Pay
         */
        private function send_request_to_tzsmmpay($data)
        {
            $args = [
                'body' => http_build_query($data),
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'timeout' => 45,
            ];

            $response = wp_remote_post('https://tzsmmpay.com/api/payment/create', $args);

            if (is_wp_error($response)) {
                printf(
                    /* translators: %s: Error message */
                    esc_html__('Connection error: %s', 'tzsmm-pay-gateway'),
                    esc_html($response->get_error_message())
                );
                return ['error' => $response->get_error_message()];
            }

            return json_decode(wp_remote_retrieve_body($response), true);
        }

        /**
         * Handle webhook for payment verification
         */
        public function handle_webhook()
        {
            $payload = wp_unslash($_POST);

            if (empty($payload['cus_number']) || empty($payload['status'])) {
                status_header(400);
                echo "Error: Missing required parameters.";
                exit;
            }

            $order_id = sanitize_text_field($payload['cus_number']);
            $order = wc_get_order($order_id);

            if (!$order) {
                status_header(404);
                echo "Error: Order not found.";
                exit;
            }

            if ($payload['status'] === 'Completed') {
                $verification = $this->verify_payment($payload['trx_id']);

                if ($verification['status'] === 'Completed') {
                    $order->payment_complete();
                    $order->add_order_note(__('Payment verified and received via TZSMM Pay.', 'tzsmm-pay-gateway'));
                } else {
                    $order->update_status('failed', __('Payment verification failed.', 'tzsmm-pay-gateway'));
                    echo "Error: Verification failed.";
                    exit;
                }
            } else {
                $order->update_status('failed', __('Payment failed via TZSMM Pay.', 'tzsmm-pay-gateway'));
                echo "Error: Payment failed.";
                exit;
            }

            status_header(200);
            echo "Success";
            exit;
        }

        /**
         * Verify the payment with TZSMM Pay API
         */
        private function verify_payment($payment_id)
        {
            $query_string = http_build_query(['api_key' => $this->apikey, 'trx_id' => sanitize_text_field($payment_id)]);
            $response = wp_remote_get('https://tzsmmpay.com/api/payment/verify?' . $query_string);

            if (is_wp_error($response)) {
                return ['status' => 'error', 'message' => $response->get_error_message()];
            }

            return json_decode(wp_remote_retrieve_body($response), true);
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_TZSMM_Pay_Gateway';
        return $gateways;
    });
}
