<?php
// Declare HPOS (High-Performance Order Storage) compatibility
// HPOS compatibility: declare plugin compatibility (does not toggle site feature)
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
/**
 * QUICK CONFIG (defaults shown in the Settings page)
 * If you prefer not to hardcode anything, leave these blank and configure in WooCommerce > Settings > Payments > VisioFex Pay.
 */
define('VXF_DEFAULT_TESTMODE', true);
define('VXF_DEFAULT_SECRET_KEY', '');
define('VXF_DEFAULT_VENDOR_ID', '');
/** Your store's public base URL (used to prefill success/return/cancel). */
define('VXF_DEFAULT_STORE_DOMAIN', 'https://yourdomain.com');
// Deprecated settings removed: public key, webhook secret, API base override, checkout mode

/**
 * Plugin Name: VisioFex for WooCommerce
 * Plugin URI:  https://example.com/visiofex-woocommerce
 * Description: VisioFex/KonaCash hosted checkout for WooCommerce with refunds, Blocks support, and easy settings for keys, vendor id, and URLs.
 * Author:      NexaFlow Payments
 * Author URI:  https://nexaflowpayments.com
 * Version:     1.5.6
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.2
 * License:     MIT
 * Text Domain: visiofex-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'VXF_WC_VERSION', '1.5.7' );
define( 'VXF_WC_PLUGIN_FILE', __FILE__ );
define( 'VXF_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VXF_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize Plugin Update Checker for auto-updates from GitHub
 */
require_once VXF_WC_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$vxf_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/chiznitz/visiofex-woocommerce-gateway/',
    __FILE__,
    'visiofex-woocommerce-gateway'
);
$vxf_update_checker->setBranch('master');

// Use the main checker for backward compatibility, but both branches will be checked
// WordPress will show updates from whichever branch has the newer version

// Optional: Set authentication for private repos (not needed for public repos)
// $vxf_update_checker->setAuthentication('your-token-here');

/**
 * Make sure WooCommerce is active.
 */
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>VisioFex for WooCommerce</strong> requires WooCommerce to be active.</p></div>';
        } );
        return;
    }

    /**
     * Register gateway.
     */
    add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
        $gateways[] = 'WC_Gateway_VisioFex';
        return $gateways;
    } );

    /**
     * Gateway class.
     */
    class WC_Gateway_VisioFex extends WC_Payment_Gateway {
        // Declare properties to fix PHP 8.2+ deprecation warnings
        public $testmode;
        public $secret_key;
        public $vendor_id;
        public $store_domain;
        public $api_base;
        public $mode;
        public $logging;
    // Removed $show_logo (logo toggle no longer supported)

        public function __construct() {
            $this->id                 = 'visiofex';
            // Keep basic icon for settings page, we'll enhance the checkout display with get_icon()
            $this->icon = VXF_WC_PLUGIN_URL . 'assets/visiofex-logo.png';
            // No on-page fields; we redirect to hosted checkout
            $this->has_fields = false;
            $this->method_title       = __( 'VisioFex', 'visiofex-woocommerce' );
            $this->method_description = __( 'VisioFex hosted checkout. Accept major cards plus Cash App Pay, and Amazon Pay.', 'visiofex-woocommerce' );
            $this->supports           = array( 'products', 'refunds' );

            $this->init_form_fields();
            $this->init_settings();

            // Settings values with top-of-file defaults as fallback
            $this->enabled        = $this->get_option( 'enabled', 'no' );
            // Use new user‑facing defaults (stored option may override)
            $this->title          = $this->get_option( 'title', __( 'Pay by Credit or Debit Card', 'visiofex-woocommerce' ) );
            $this->description    = $this->get_option( 'description', __( 'Pay securely using your Visa, MasterCard, American Express or Discover. This method also allows Cash App and Pay with Amazon. After payment you will be redirected back to our website and your order will ship out right away.', 'visiofex-woocommerce' ) );
            $this->testmode       = 'yes' === $this->get_option( 'testmode', VXF_DEFAULT_TESTMODE ? 'yes' : 'no' );
            $this->secret_key     = $this->get_option( 'secret_key', VXF_DEFAULT_SECRET_KEY );
            $this->vendor_id      = $this->get_option( 'vendor_id', VXF_DEFAULT_VENDOR_ID );
            $this->store_domain   = rtrim( $this->get_option( 'store_domain', untrailingslashit( home_url() ) ), '/' );
            $this->api_base       = '';
            $this->mode           = 'payment';
            $this->logging        = 'yes' === $this->get_option( 'logging', 'yes' );
            // Logo toggle deprecated; always text + optional card icons

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            // Webhook endpoint disabled for simplified setup
            // add_action( 'woocommerce_api_wc_gateway_visiofex', array( $this, 'handle_webhook' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'vxf_capture_transaction_on_return' ), 10, 1 );

            // Enqueue our custom CSS for enhanced payment icons
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_icons_css' ) );

            // Render description with bold lead in Classic checkout without requiring HTML in settings
            add_filter( 'woocommerce_gateway_description', array( $this, 'filter_gateway_description' ), 10, 2 );
        }

        /**
         * Enqueue custom CSS for enhanced payment icons display
         */
        public function enqueue_payment_icons_css() {
            if ( is_checkout() ) {
                wp_enqueue_style( 
                    'visiofex-payment-icons', 
                    VXF_WC_PLUGIN_URL . 'assets/css/visiofex-payment-icons.css', 
                    array(), 
                    VXF_WC_VERSION 
                );
            }
        }

        public function get_title() {
            // Only show custom title on the checkout page. Elsewhere, return simple identifier.
            if ( function_exists( 'is_checkout' ) && is_checkout() ) {
                $custom_title = $this->get_option( 'title', __( 'Pay by Credit or Debit Card', 'visiofex-woocommerce' ) );
                return apply_filters( 'woocommerce_gateway_title', esc_html( $custom_title ), $this->id );
            }
            // Non-checkout contexts (admin, order pages, emails): simple identifier
            return apply_filters( 'woocommerce_gateway_title', 'visiofex', $this->id );
        }

        /**
         * Override method title to ensure consistent display
         */
        public function get_method_title() {
            // Always return simple "VisioFex" - we don't want "VisioFex Pay" showing anywhere
            return __( 'VisioFex', 'visiofex-woocommerce' );
        }

        /**
         * Override the icon display to show just the card brands, as the logo is now in the title.
         */
        public function get_icon() {
            $icon_html  = '<span class="visiofex-card-icons">';
            $icon_html .= '<img class="visiofex-card-icon" src="' . esc_url( VXF_WC_PLUGIN_URL . 'assets/images/visa.svg' ) . '" alt="Visa" />';
            $icon_html .= '<img class="visiofex-card-icon" src="' . esc_url( VXF_WC_PLUGIN_URL . 'assets/images/mastercard.svg' ) . '" alt="Mastercard" />';
            $icon_html .= '<img class="visiofex-card-icon" src="' . esc_url( VXF_WC_PLUGIN_URL . 'assets/images/amex.svg' ) . '" alt="American Express" />';
            $icon_html .= '<img class="visiofex-card-icon" src="' . esc_url( VXF_WC_PLUGIN_URL . 'assets/images/discover.svg' ) . '" alt="Discover" />';
            $icon_html .= '</span>';
            return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'visiofex-woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable VisioFex Pay', 'visiofex-woocommerce' ),
                    'default' => 'no',
                ),
                'title' => array(
                    'title'       => __( 'Title', 'visiofex-woocommerce' ),
                    'type'        => 'text',
                    'default'     => __( 'Pay by Credit or Debit Card', 'visiofex-woocommerce' ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'visiofex-woocommerce' ),
                    'type'        => 'textarea',
                    'default'     => __( 'Pay securely using your Visa, MasterCard, American Express or Discover. This method also allows Cash App and Pay with Amazon. After payment you will be redirected back to our website and your order will ship out right away.', 'visiofex-woocommerce' ),
                    'description' => __( 'This text will be displayed to customers during checkout.', 'visiofex-woocommerce' ),
                ),
                'testmode' => array(
                    'title'       => __( 'Test mode', 'visiofex-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable test (sandbox) mode', 'visiofex-woocommerce' ),
                    'default'     => VXF_DEFAULT_TESTMODE ? 'yes' : 'no',
                ),
                'secret_key' => array(
                    'title'       => __( 'Secret Key', 'visiofex-woocommerce' ),
                    'type'        => 'password',
                    'default'     => VXF_DEFAULT_SECRET_KEY,
                ),
                'vendor_id' => array(
                    'title'       => __( 'Vendor ID', 'visiofex-woocommerce' ),
                    'type'        => 'text',
                    'default'     => VXF_DEFAULT_VENDOR_ID,
                    'description' => __( 'Provided by VisioFex/KonaCash.', 'visiofex-woocommerce' ),
                ),
                'store_domain' => array(
                    'title'       => __( 'Your Store Domain', 'visiofex-woocommerce' ),
                    'type'        => 'text',
                    'default'     => untrailingslashit( home_url() ),
                    'description' => __( 'Auto-detected from your site. Used for success/return/cancel URLs.', 'visiofex-woocommerce' ),
                    'placeholder' => untrailingslashit( home_url() ),
                ),
                // Removed show_logo setting (logo deprecated)
                'logging' => array(
                    'title'       => __( 'Debug log', 'visiofex-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable logging (WooCommerce > Status > Logs)', 'visiofex-woocommerce' ),
                    'default'     => 'yes',
                ),
            );
        }

        /**
         * Format gateway description for Classic checkout: bold first line, rest normal.
         */
        public function filter_gateway_description( $description, $payment_id ) {
            if ( $payment_id !== $this->id ) {
                return $description;
            }
            
            // Use saved setting, but fall back to the default if it's empty.
            $plain = (string) $this->get_option( 'description' );
            if ( trim( $plain ) === '' ) {
                $plain = $this->form_fields['description']['default'];
            }

            $html  = $this->format_description_html( $plain );
            return $html;
        }

        /**
         * Build safe HTML with <strong> for the first line and normal text for the rest.
         */
        private function format_description_html( $text ) {
            // Strip any HTML tags the user might have entered, as we handle formatting.
            $text  = is_string( $text ) ? wp_strip_all_tags( $text ) : '';
            
            $parts = preg_split( "/\r?\n+/", trim( $text ), 2 );
            
            if ( ! is_array( $parts ) || empty( $parts ) || trim($parts[0]) === '' ) {
                return '';
            }

            $lead = esc_html( trim( $parts[0] ) );
            $rest = isset( $parts[1] ) ? esc_html( trim( $parts[1] ) ) : '';

            $html = '<strong>' . $lead . '</strong>';
            if ( $rest !== '' ) {
                $html .= '<br />' . $rest;
            }
            
            return $html;
        }

        protected function get_api_base() {
            if ( ! empty( $this->api_base ) ) return untrailingslashit( $this->api_base );
            return $this->testmode ? 'https://api.konacash.com/v1' : 'https://api.konacash.com/v1'; // adjust if live differs
        }

        // Change signature
        protected function request( $method, $path, $body = null, $idempotency_key = null ) {
            // Basic rate limiting check
            $rate_limit_key = 'visiofex_api_requests_' . md5( $this->secret_key );
            $current_count = get_transient( $rate_limit_key );
            if ( $current_count === false ) {
                set_transient( $rate_limit_key, 1, 60 ); // 1 minute window
            } else {
                $current_count = intval( $current_count );
                if ( $current_count >= 100 ) { // 100 requests per minute limit
                    $this->log( 'Rate limit exceeded: too many API requests', 'warning' );
                    return array( 'code' => 429, 'body' => array( 'error' => 'Rate limit exceeded' ), 'headers' => array() );
                }
                set_transient( $rate_limit_key, $current_count + 1, 60 );
            }
            
            $this->log( 'API Request - Method: ' . $method . ', Path: ' . $path . ', Has body: ' . ( $body ? 'yes' : 'no' ) );
            
            $headers = array(
                'Content-Type' => 'application/json',
                'X-API-KEY'    => $this->secret_key,
                'User-Agent'   => 'VisioFex-WooCommerce/' . VXF_WC_VERSION,
            );
            if ( $idempotency_key && in_array( strtoupper( $method ), array('POST','PUT','PATCH','DELETE'), true ) ) {
                $headers['Idempotency-Key'] = $idempotency_key;
                $this->log( 'API Request - Using idempotency key: ' . substr( $idempotency_key, 0, 8 ) . '...' );
            }

            // Validate configuration
            if ( empty( $this->secret_key ) ) {
                $this->log( 'API Request warning: No secret key configured', 'warning' );
            }

            $args = array(
                'timeout' => 45,
                'headers' => $headers,
                'method'  => $method,
            );
            if ( $body !== null ) { 
                $args['body'] = wp_json_encode( $body );
                $masked_body = $this->mask_sensitive_data( $body );
                $this->log( 'API Request body (masked): ' . wp_json_encode( $masked_body ) );
            }

            $url = trailingslashit( $this->get_api_base() ) . ltrim( $path, '/' );
            $this->log( 'API Request URL: ' . $url );

            $start_time = microtime( true );
            $res = wp_remote_request( $url, $args );
            $duration = round( ( microtime( true ) - $start_time ) * 1000, 2 );
            
            if ( is_wp_error( $res ) ) {
                $error_message = $res->get_error_message();
                $this->log( 'API Request failed - Error: ' . $error_message . ', Duration: ' . $duration . 'ms', 'error' );
                return array( 'code' => 0, 'body' => null, 'headers' => array() );
            }
            
            $code = wp_remote_retrieve_response_code( $res );
            $response_body = wp_remote_retrieve_body( $res );
            $body_decoded = json_decode( $response_body, true );
            $hdrs = wp_remote_retrieve_headers( $res );
            
            $this->log( 'API Response - Code: ' . $code . ', Duration: ' . $duration . 'ms, Body size: ' . strlen( $response_body ) . ' bytes' );
            
            if ( $code >= 400 ) {
                $masked_response = $this->mask_sensitive_data( $body_decoded );
                $this->log( 'API Error response (masked): ' . wp_json_encode( $masked_response ), 'error' );
            } else {
                // Log successful responses at debug level (less verbose)
                if ( $this->logging ) {
                    $this->log( 'API Success - Response structure: ' . $this->get_response_structure( $body_decoded ), 'debug' );
                }
            }
            
            return array( 'code' => $code, 'body' => $body_decoded, 'headers' => $hdrs );
        }

        /**
         * Get a summary of response structure for logging (without sensitive data)
         */
        private function get_response_structure( $data ) {
            if ( ! is_array( $data ) ) {
                return gettype( $data );
            }
            
            $structure = array();
            foreach ( $data as $key => $value ) {
                if ( is_array( $value ) ) {
                    $structure[$key] = 'array(' . count( $value ) . ')';
                } else {
                    $structure[$key] = gettype( $value );
                }
            }
            
            return wp_json_encode( $structure );
        }

        public function vxf_capture_transaction_on_return( $order_id ) {
            // Input validation
            if ( ! is_numeric( $order_id ) || $order_id <= 0 ) {
                $this->log( 'Transaction capture failed: Invalid order ID format', 'error' );
                return;
            }
            
            $this->log( 'Transaction capture initiated for order #' . $order_id );
            
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_payment_method() !== $this->id ) {
                if ( ! $order ) {
                    $this->log( 'Transaction capture failed: Invalid order #' . $order_id, 'error' );
                } else {
                    $this->log( 'Transaction capture skipped: Order #' . $order_id . ' uses different payment method (' . $order->get_payment_method() . ')' );
                }
                return;
            }

            $this->log( 'Order found - Status: ' . $order->get_status() . ', Total: ' . $order->get_total() );

            // Ensure we have the session id (meta or ?sessionID= on the return URL)
            $session_id = $order->get_meta( '_visiofex_session_id' );
            if ( ! $session_id && isset( $_GET['sessionID'] ) ) {
                $session_id = sanitize_text_field( wp_unslash( $_GET['sessionID'] ) );
                // Validate session ID format (basic check for expected format)
                if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $session_id ) ) {
                    $this->log( 'Invalid session ID format from URL parameter', 'error' );
                    return;
                }
                // Check if this session ID is already used by another order
                if ( $this->is_session_id_used_by_another_order( $session_id, $order->get_id() ) ) {
                    $this->log( 'Session ID from URL already used by another order: ' . $session_id, 'error' );
                    $order->add_order_note( 'VisioFex: Session ID conflict detected. Please contact support.' );
                    return;
                }
                $order->update_meta_data( '_visiofex_session_id', $session_id );
                $this->log( 'Session ID obtained from URL parameter: ' . $session_id );
            }
            
            if ( ! $session_id ) { 
                $this->log( 'Transaction capture failed: Missing session ID', 'error' );
                $order->add_order_note( 'VisioFex: missing session id on return.' ); 
                return; 
            }

            $this->log( 'Starting polling for session: ' . $session_id );

            // Short polling: immediate → 0.5s → 1.5s → 3s (stop as soon as it’s paid)
            foreach ( array(0, 0.5, 1.5, 3.0) as $attempt => $delay ) {
                if ( $delay ) { 
                    usleep( (int) ( $delay * 1e6 ) );
                    $this->log( 'Polling attempt ' . ($attempt + 1) . ' after ' . $delay . 's delay' );
                } else {
                    $this->log( 'Polling attempt 1 (immediate)' );
                }

                $res   = $this->request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
                $body  = is_array( $res['body'] ) ? $res['body'] : array();
                $sess  = $body['data']['session'] ?? array();
                $txn   = $body['data']['transaction'] ?? array();

                $status   = strtolower( $sess['paymentStatus'] ?? '' );
                $txn_id   = $txn['_id'] ?? '';
                $last4    = $txn['paymentMethod']['last4']   ?? '';
                $network  = $txn['paymentMethod']['network'] ?? '';
                $amount_i = $txn['amount'] ?? null;

                $this->log( 'Polling result - Status: ' . ( $status ?: 'none' ) . ', Transaction ID: ' . ( $txn_id ?: 'none' ) . ', Amount: ' . ( $amount_i !== null ? $amount_i : 'none' ) );

                if ( $txn_id ) {
                    $order->update_meta_data( '_visiofex_payment_id', sanitize_text_field( $txn_id ) );
                    if ( $last4 && preg_match( '/^\d{4}$/', $last4 ) ) {   
                        $order->update_meta_data( '_visiofex_last4', sanitize_text_field( $last4 ) ); 
                    }
                    if ( $network && in_array( strtolower( $network ), array( 'visa', 'mastercard', 'amex', 'discover', 'jcb', 'diners' ), true ) ) { 
                        $order->update_meta_data( '_visiofex_network', sanitize_text_field( $network ) ); 
                    }
                    if ( ! is_null( $amount_i ) && is_numeric( $amount_i ) && $amount_i >= 0 ) { 
                        $order->update_meta_data( '_visiofex_tx_amount', floatval( $amount_i ) ); 
                    }
                    
                    $this->log( 'Transaction metadata updated - Payment ID: ' . $txn_id . ', Last4: ' . ( $last4 ?: 'none' ) . ', Network: ' . ( $network ?: 'none' ) );
                }

                if ( in_array( $status, array( 'paid', 'succeeded' ), true ) ) {
                    $this->log( 'Payment successful - Completing order' );
                    $order->payment_complete( $txn_id ?: '' );
                    $order->add_order_note( 'VisioFex: paid (verified via session).' );
                    $order->save();
                    return;
                } else if ( in_array( $status, array( 'failed', 'cancelled', 'expired' ), true ) ) {
                    $this->log( 'Payment failed with status: ' . $status, 'warning' );
                    $order->add_order_note( 'VisioFex: payment ' . $status . ' (verified via session).' );
                    if ( $status === 'failed' ) {
                        $order->update_status( 'failed', 'VisioFex payment failed.' );
                    }
                    $order->save();
                    return;
                }
            }

            $this->log( 'Polling completed - Payment status still pending after 4 attempts', 'warning' );
            $order->add_order_note( 'VisioFex: session not yet paid after return; order left pending.' );
        }

        /** Public: Sync a single order from VisioFex (session/transaction/refunds). */
        public function sync_order_from_visiofex( WC_Order $order ): void {
            // Security validation for admin operations
            if ( is_admin() && ! $this->validate_admin_operation( 'visiofex_sync' ) ) {
                $this->log( 'Sync operation blocked: Security validation failed', 'error' );
                return;
            }
            
            $this->log( 'Starting order sync for order #' . $order->get_id() );
            
            if ( $order->get_payment_method() !== $this->id ) {
                $this->log( 'Order sync skipped: Order #' . $order->get_id() . ' uses different payment method (' . $order->get_payment_method() . ')' );
                return;
            }

            $this->log( 'Order sync validated - Status: ' . $order->get_status() . ', Total: ' . $order->get_total() );

            // 1) Ensure we know the session id
            $session_id = $order->get_meta('_visiofex_session_id');
            if ( ! $session_id && isset($_GET['sessionID']) ) {
                $session_id = sanitize_text_field( wp_unslash($_GET['sessionID']) );
                // Validate session ID format
                if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $session_id ) ) {
                    $this->log( 'Invalid session ID format from URL parameter', 'error' );
                    return;
                }
                // Check if this session ID is already used by another order
                if ( $this->is_session_id_used_by_another_order( $session_id, $order->get_id() ) ) {
                    $this->log( 'Session ID from URL already used by another order: ' . $session_id, 'error' );
                    $order->add_order_note( 'VisioFex: Session ID conflict detected. Please contact support.' );
                    return;
                }
                $order->update_meta_data('_visiofex_session_id', $session_id);
                $this->log( 'Session ID obtained from URL parameter: ' . $session_id );
            }

            if ( ! $session_id ) {
                $this->log( 'Order sync failed: No session ID found', 'warning' );
                return;
            }

            $this->log( 'Syncing with session ID: ' . $session_id );

            // 2) If we have a session, hydrate latest session + transaction
            $session = $tx = array();
            if ( $session_id ) {
                $r  = $this->request('GET', 'checkout/sessions/' . rawurlencode($session_id));
                $session = $r['body']['data']['session'] ?? array();
                $tx      = $r['body']['data']['transaction'] ?? array();
                
                $this->log( 'Session data retrieved - Status: ' . ( $session['paymentStatus'] ?? 'none' ) . ', Has transaction: ' . ( ! empty( $tx ) ? 'yes' : 'no' ) );
            }

            // 3) If no tx yet, try by stored payment_id or by listing transaction directly
            $txn_id = $order->get_meta('_visiofex_payment_id') ?: ($tx['_id'] ?? '');
            $this->log( 'Transaction ID resolution - From order meta: ' . ( $order->get_meta('_visiofex_payment_id') ?: 'none' ) . ', From session: ' . ( $tx['_id'] ?? 'none' ) );
            if ( ! $txn_id && ! empty($session['paymentIntent']) ) {
                // If your API uses paymentIntent as the canonical tx id (you showed it null earlier, so likely not)
                $txn_id = $session['paymentIntent'];
            }
            if ( ! $txn_id && ! empty($session['_id ']) ) {
                // Sometimes there’s a way to filter by session: /transactions?sessionId=/
                $r2 = $this->request('GET', 'transactions?sessionId=' . rawurlencode($session['_id']));
                $list = $r2['body']['data']['transactions'] ?? $r2['body']['data'] ?? array();
                if ( is_array($list) && ! empty($list) && isset($list[0]['_id']) ) {
                    $tx = $list[0];
                    $txn_id = $tx['_id'];
                }
            }
            if ( $txn_id && empty($tx) ) {
                $r3 = $this->request('GET', 'transactions/' . rawurlencode($txn_id));
                $tx = $r3['body']['data']['transaction'] ?? $r3['body']['data'] ?? array();
            }

            // Save handy bits
            if ( $txn_id ) {
                $order->update_meta_data('_visiofex_payment_id', sanitize_text_field($txn_id));
                $this->log( 'Transaction ID saved to order meta: ' . $txn_id );
            }
            $status = strtolower( $session['paymentStatus'] ?? '' );
            if ( in_array($status, array('paid','succeeded'), true) && ! $order->has_status(array('processing','completed')) ) {
                $this->log( 'Payment confirmed - Completing order with status: ' . $status );
                $order->payment_complete( $txn_id ?: '' );
                $order->add_order_note('VisioFex: synced → marked paid.');
            } else {
                $this->log( 'Payment status: ' . ( $status ?: 'none' ) . ', Order status: ' . $order->get_status() );
            }

            // 4) Sync refunds
            $this->log( 'Starting refund sync for order #' . $order->get_id() );
            $refunded_total_api = 0.0;
            $refunds = $tx['refunds'] ?? array(); // if present
            if ( is_array($refunds) && $refunds ) {
                foreach ( $refunds as $r ) {
                    $refunded_total_api += (float) ( $r['amount'] ?? 0 );
                }
            } else {
                // Try common shapes
                if ( isset($tx['refundedAmount']) ) {
                    $refunded_total_api = (float) $tx['refundedAmount'];
                } else if ( $txn_id ) {
                    // Last resort: GET /refunds?transactionId=...
                    $r4 = $this->request('GET', 'refunds?transactionId=' . rawurlencode($txn_id));
                    $refunds_list = $r4['body']['data']['refunds'] ?? $r4['body']['data'] ?? array();
                    if ( is_array($refunds_list) ) {
                        foreach ( $refunds_list as $r ) {
                            $refunded_total_api += (float) ( $r['amount'] ?? 0 );
                        }
                    }
                }
            }

            // Already reflected in Woo?
            $already_refunded = (float) wc_format_decimal( $order->get_total_refunded(), 2 );
            $delta = round( $refunded_total_api - $already_refunded, 2 );

            $this->log( 'Refund sync analysis - API refunded: ' . $refunded_total_api . ', WC refunded: ' . $already_refunded . ', Delta: ' . $delta );

            if ( $delta > 0 ) {
                $this->log( 'Creating WooCommerce refund for: ' . wc_price( $delta ) );
                $created = wc_create_refund( array(
                    'order_id'       => $order->get_id(),
                    'amount'         => $delta,
                    'reason'         => 'Synced from VisioFex',
                    'refund_payment' => false,      // do not call gateway again
                    'restock_items'  => true,       // or make this a setting
                ) );
                if ( is_wp_error($created) ) {
                    $this->log( 'Refund creation failed: ' . $created->get_error_message(), 'error' );
                    $order->add_order_note('VisioFex: sync found ' . wc_price($delta) . ' refunded, but Woo refund creation failed.');
                } else {
                    $this->log( 'Refund created successfully: ' . wc_price( $delta ) );
                    $order->add_order_note('VisioFex: synced refund ' . wc_price($delta) . '.');
                }
            } else if ( $delta < 0 ) {
                $this->log( 'WC has more refunds than API - Delta: ' . $delta, 'warning' );
            }

            $order->update_meta_data('_visiofex_last_synced_at', gmdate('c'));
            $order->save();
            $this->log( 'Order sync completed for order #' . $order->get_id() );
}
        public function is_available() {
            if ( 'yes' !== $this->enabled ) { 
                return false; 
            }
            
            if ( ! is_ssl() && ! $this->testmode ) { 
                return false; 
            }
            
            // Allow showing in test mode even if keys are empty (helps setup); in live require a key
            if ( empty( $this->secret_key ) ) {
                if ( $this->testmode ) { 
                    return true; 
                }
                return false;
            }
            
            return true;
        }

        /**
         * Build comprehensive line items including products, coupons, shipping, fees, and taxes
         */
        private function build_line_items( $order ) {
            $this->log( 'Using simplified single line item approach' );
            
            $order_total = floatval( $order->get_total() );
            $order_id = $order->get_id();
            
            // Create one simple line item with the total order amount
            $store_name = get_bloginfo( 'name' );
            $product_name = $store_name ? $store_name . ' Order Total' : 'Order Total';
            
            $line_items = array(
                array(
                    'productName' => $product_name,
                    'unitPrice'   => number_format( $order_total, 2, '.', '' ),
                    'quantity'    => 1,
                    'type'        => 'order'
                )
            );
            
            $this->log( 'Created single line item - Order #' . $order_id . ': $' . $order_total );
            
            return array(
                'line_items' => $line_items,
                'calculated_total' => $order_total
            );
        }

        public function process_payment( $order_id ) {
            // Input validation
            if ( ! is_numeric( $order_id ) || $order_id <= 0 ) {
                $this->log( 'Payment failed: Invalid order ID format', 'error' );
                wc_add_notice( __( 'Invalid order. Please try again.', 'visiofex-woocommerce' ), 'error' );
                return array( 'result' => 'fail' );
            }
            
            $this->log( 'Starting payment process for order #' . $order_id );
            
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $this->log( 'Invalid order ID: ' . $order_id, 'error' );
                wc_add_notice( __( 'Invalid order. Please try again.', 'visiofex-woocommerce' ), 'error' );
                return array( 'result' => 'fail' );
            }

            // ** THE FIX **: Immediately set the correct title before any other processing.
            $order->set_payment_method_title( 'visiofex' );
            $order->save();

            $this->log( 'Order found - Currency: ' . $order->get_currency() . ', Total: ' . $order->get_total() );

            // Validate gateway configuration
            if ( empty( $this->secret_key ) && ! $this->testmode ) {
                $this->log( 'Gateway misconfiguration: Secret key missing in live mode', 'error' );
                wc_add_notice( __( 'Payment gateway is not properly configured.', 'visiofex-woocommerce' ), 'error' );
                return array( 'result' => 'fail' );
            }

            if ( empty( $this->vendor_id ) ) {
                $this->log( 'Gateway misconfiguration: Vendor ID missing', 'error' );
                wc_add_notice( __( 'Payment gateway is not properly configured.', 'visiofex-woocommerce' ), 'error' );
                return array( 'result' => 'fail' );
            }

            // Build comprehensive lineItems from WC order including products, discounts, shipping, taxes
            $line_item_result = $this->build_line_items( $order );
            $line_items = $line_item_result['line_items'];
            $calculated_total = $line_item_result['calculated_total'];
            $order_total = floatval( $order->get_total() );
            
            $this->log( 'Testing empty line items approach - Line items count: ' . count( $line_items ) . ', WC Total: ' . $order_total );
            
            // Canonical WooCommerce URLs using proper helper methods
            $success = $this->get_return_url( $order );   // ✅ correct thank-you URL (after successful payment)
            $return  = wc_get_checkout_url();              // ✅ checkout page (for "back to store" button)
            $cancel  = $order->get_cancel_order_url();     // ✅ proper cancel URL (with key & id)

            $this->log( 'Generated URLs - Success: ' . $success . ', Return: ' . $return . ', Cancel: ' . $cancel );

            $payload = array(
                'vendor'      => $this->vendor_id ?: null,
                'env'         => ( $this->testmode ? 'test' : 'live' ),
                'mode'        => $this->mode ?: 'payment',
                'currency'    => strtolower( $order->get_currency() ),
                'amount'      => number_format( $order_total, 2, '.', '' ), // Total amount after all discounts/additions
                'lineItems'   => $line_items,
                'successURL'  => $success,
                'returnURL'   => $return,
                'cancelURL'   => $cancel,
                'metadata'    => array(
                    'order_id'       => (string) $order->get_id(),
                    'site_url'       => home_url(),
                    'wc_total'       => $order_total,
                    'calculated_total' => $calculated_total,
                    'coupon_count'   => count( $order->get_coupons() ),
                    'has_shipping'   => count( $order->get_shipping_methods() ) > 0,
                ),
                // You can also send shipping/billing if required by your account
            );

            $this->log( 'API payload prepared - Environment: ' . ( $this->testmode ? 'test' : 'live' ) . ', Amount: ' . $order_total . ', Items: ' . count( $line_items ) . ' (products: ' . count( $order->get_items() ) . ', coupons: ' . count( $order->get_coupons() ) . ')' );

            // Idempotency key recommended
            add_filter( 'http_request_args', function( $args, $url ) {
                if ( ! isset( $args['headers']['Idempotency-Key'] ) ) {
                    $args['headers']['Idempotency-Key'] = wp_generate_uuid4();
                }
                return $args;
            }, 10, 2 );

            // Create hosted checkout session
            $idk = 'create_' . $order->get_id() . '_' . wp_generate_uuid4();
            $this->log( 'Making API request to create checkout session for order #' . $order_id );

            $res = $this->request( 'POST', 'checkout/sessions/create', $payload );
            $code = $res['code']; $body = $res['body'];

            $this->log( 'API response received - Code: ' . $code . ', Has paymentURL: ' . ( ! empty( $body['data']['paymentURL'] ) ? 'yes' : 'no' ) );

            if ( $code >= 200 && $code < 300 && ! empty( $body['data']['paymentURL'] ) ) {
                $session = isset( $body['data']['session'] ) ? $body['data']['session'] : array();
                $request_id = ! empty( $body['data']['requestID'] ) ? sanitize_text_field( $body['data']['requestID'] ) : '';
                $session_id = ! empty( $session['_id'] ) ? sanitize_text_field( $session['_id'] ) : '';
                
                $this->log( 'Session created successfully - RequestID: ' . ( $request_id ?: 'none' ) . ', SessionID: ' . ( $session_id ?: 'none' ) );
                
                // Check if this session ID is already used by another order (extra safety check)
                if ( $session_id && $this->is_session_id_used_by_another_order( $session_id, $order->get_id() ) ) {
                    $this->log( 'Session ID from API already used by another order: ' . $session_id, 'error' );
                    wc_add_notice( __( 'Payment gateway error. Please try again.', 'visiofex-woocommerce' ), 'error' );
                    return array( 'result' => 'fail' );
                }
                
                $order->update_meta_data( '_visiofex_request_id', $request_id );
                $order->update_meta_data( '_visiofex_session_id', $session_id );
                $order->update_meta_data( '_visiofex_api_base', esc_url_raw( $this->get_api_base() ) );
                $order->save();

                $payment_url = esc_url_raw( $body['data']['paymentURL'] );
                $this->log( 'Payment process successful - Redirecting to: ' . $payment_url );

                return array(
                    'result'   => 'success',
                    'redirect' => $payment_url,
                );
            }

            // Log detailed error information
            $error_details = array(
                'code' => $code,
                'body' => $this->mask_sensitive_data( $body ),
                'expected_keys' => array( 'data.paymentURL' )
            );
            $this->log( 'Payment process failed - ' . wp_json_encode( $error_details ), 'error' );

            wc_add_notice( __( 'Could not start VisioFex checkout. Please try again.', 'visiofex-woocommerce' ), 'error' );
            return array( 'result' => 'fail' );
        }

        /** Refund support */
        public function process_refund( $order_id, $amount = null, $reason = '' ) {
            // Input validation
            if ( ! is_numeric( $order_id ) || $order_id <= 0 ) {
                $this->log( 'Refund failed: Invalid order ID format', 'error' );
                return new WP_Error( 'invalid_order_id', 'Invalid order ID format' );
            }
            
            if ( $amount !== null && ( ! is_numeric( $amount ) || $amount <= 0 ) ) {
                $this->log( 'Refund failed: Invalid amount format', 'error' );
                return new WP_Error( 'invalid_amount', 'Invalid refund amount' );
            }
            
            // Sanitize reason
            $reason = sanitize_text_field( $reason );
            
            $this->log( 'Starting refund process for order #' . $order_id . ' - Amount: ' . ( $amount ?: 'N/A' ) . ', Reason: ' . ( $reason ?: 'No reason provided' ) );
            
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $this->log( 'Refund failed: Invalid order ID #' . $order_id, 'error' );
                return new WP_Error( 'invalid_order', 'Order not found' );
            }

            $this->log( 'Order found - Payment method: ' . $order->get_payment_method() . ', Status: ' . $order->get_status() . ', Total: ' . $order->get_total() );

            $payment_id = $order->get_meta( '_visiofex_payment_id' );

            // Check if this order was paid with VisioFex
            if ( $order->get_payment_method() !== $this->id ) {
                $this->log( 'Refund validation failed: Order not paid with VisioFex (method: ' . $order->get_payment_method() . ')', 'error' );
                return new WP_Error( 'invalid_payment_gateway', 'Order was not paid with VisioFex' );
            }

            // Check if gateway is properly configured
            if ( empty( $this->secret_key ) ) {
                $this->log( 'Refund validation failed: Gateway not configured (missing secret key)', 'error' );
                return new WP_Error( 'invalid_payment_gateway', 'VisioFex gateway is not properly configured' );
            }

            if ( empty( $payment_id ) ) {
                $this->log( 'Refund validation failed: Payment ID not found in order meta', 'error' );
                $this->log( 'Order meta keys: ' . implode( ', ', array_keys( $order->get_meta_data() ) ) );
                return new WP_Error( 'missing_payment_id', 'Payment ID not found - order may not be fully processed yet' );
            }

            $this->log( 'Refund validation passed - Payment ID: ' . $payment_id );

            // Enhanced debugging of order refund state
            $order_total = wc_format_decimal( $order->get_total(), 2 );
            $already_refunded = wc_format_decimal( $order->get_total_refunded(), 2 );
            $remaining_refundable = wc_format_decimal( $order_total - $already_refunded, 2 );
            $refund_amount = $amount ? wc_format_decimal( $amount, 2 ) : $remaining_refundable;

            // Debug existing refunds
            $existing_refunds = $order->get_refunds();
            $this->log( 'Refund debugging - Order has ' . count( $existing_refunds ) . ' existing refunds' );
            foreach ( $existing_refunds as $refund ) {
                $this->log( 'Existing refund: ID=' . $refund->get_id() . ', Amount=' . $refund->get_amount() . ', Reason="' . $refund->get_reason() . '"' );
            }

            // Enhanced logging with raw values for debugging
            $this->log( 'Refund amount validation (formatted) - Order total: ' . $order_total . ', Already refunded: ' . $already_refunded . ', Remaining: ' . $refund_amount );
            $this->log( 'Refund amount validation (raw) - Order total: ' . $order->get_total() . ', Already refunded: ' . $order->get_total_refunded() . ', Requested raw: ' . $amount );

            // Check if there are any existing refunds and handle this edge case
            if ( $already_refunded > 0 && count( $existing_refunds ) > 0 ) {
                $this->log( 'Detected existing refunds. This might be a retry of a failed refund attempt.', 'warning' );
                // Allow the refund to proceed if it's for the same amount, as it might be a retry
                if ( abs( $refund_amount - $already_refunded ) < 0.01 ) {
                    $this->log( 'Allowing refund retry for same amount - treating as API-only refund', 'info' );
                    // Skip WooCommerce validation since the refund record already exists
                    // We'll just try to send it to VisioFex API
                } else {
                    $this->log( 'Refund amount mismatch - requested: ' . $refund_amount . ', already refunded: ' . $already_refunded, 'error' );
                }
            }

            // Use a more generous tolerance (0.05) for floating point comparison and round both values
            $tolerance = 0.05;
            $remaining_rounded = round( (float) $remaining_refundable, 2 );
            $refund_rounded = round( (float) $refund_amount, 2 );
            $difference = $refund_rounded - $remaining_rounded;
            
            $this->log( 'Refund validation comparison - Remaining rounded: ' . $remaining_rounded . ', Refund rounded: ' . $refund_rounded . ', Difference: ' . $difference . ', Tolerance: ' . $tolerance );

            // Allow the refund if there are existing refunds and this is likely a retry
            $is_retry = ( $already_refunded > 0 && abs( $refund_amount - $already_refunded ) < 0.01 );
            
            if ( $difference > $tolerance && ! $is_retry ) {
                $this->log( 'Refund validation failed: Amount (' . $refund_amount . ') exceeds remaining refundable amount (' . $remaining_refundable . ') by ' . $difference . ' (tolerance: ' . $tolerance . ')', 'error' );
                return new WP_Error( 'invalid_refund_amount', 'Refund amount exceeds remaining refundable amount' );
            }

            if ( $refund_amount <= 0 ) {
                $this->log( 'Refund validation failed: Invalid refund amount (' . $refund_amount . ')', 'error' );
                return new WP_Error( 'invalid_refund_amount', 'Refund amount must be greater than zero' );
            }

            if ( $is_retry ) {
                $this->log( 'Processing as refund retry - WooCommerce refund already exists, sending to VisioFex API only', 'info' );
            }

            // Validate payment ID format
            if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $payment_id ) ) {
                $this->log( 'Refund validation failed: Invalid payment ID format', 'error' );
                return new WP_Error( 'invalid_payment_id', 'Invalid payment ID format' );
            }

            $payload = array(
                'payment_id' => sanitize_text_field( $payment_id ),
                'amount'     => number_format( $refund_amount, 2, '.', '' ),
                'reason'     => sanitize_text_field( $reason ),
            );

            $this->log( 'Refund payload prepared - Masked: ' . wp_json_encode( $this->mask_sensitive_data( $payload ) ) );

            // Use the known correct refund endpoint
            $endpoint = 'transactions/' . $payment_id . '/refund';
            $this->log( 'Making refund API request to endpoint: ' . $endpoint );
            
            $res = $this->request( 'POST', $endpoint, $payload );
            $code = $res['code'];
            $body = $res['body'];

            $this->log( 'Refund API response - Code: ' . $code . ', Body length: ' . strlen( wp_json_encode( $body ) ) . ' chars' );

            if ( $code >= 200 && $code < 300 ) {
                $this->log( 'Refund processed successfully via VisioFex API' );
                if ( $is_retry ) {
                    $order->add_order_note( sprintf( 'VisioFex refund retry successful: %s. API confirmed refund processing. Reason: %s', wc_price( $refund_amount ), $reason ) );
                } else {
                    $order->add_order_note( sprintf( 'VisioFex refund processed: %s. Reason: %s', wc_price( $refund_amount ), $reason ) );
                }
                return true;
            } else {
                // Log the full response for debugging (with sensitive data masked)
                $masked_body = $this->mask_sensitive_data( $body );
                $this->log( 'Refund failed - Code: ' . $code . ', Body: ' . wp_json_encode( $masked_body ), 'error' );

                // Try different error message formats
                $error_msg = 'Unknown error';
                if ( isset( $body['error'] ) ) {
                    $error_msg = is_array( $body['error'] ) ? wp_json_encode( $body['error'] ) : $body['error'];
                } elseif ( isset( $body['message'] ) ) {
                    $error_msg = $body['message'];
                } elseif ( isset( $body['errors'] ) && is_array( $body['errors'] ) ) {
                    $error_msg = implode( ', ', $body['errors'] );
                } elseif ( is_string( $body ) ) {
                    $error_msg = $body;
                } elseif ( $code === 400 ) {
                    $error_msg = 'Invalid request data';
                } elseif ( $code === 401 ) {
                    $error_msg = 'Authentication failed - check gateway credentials';
                } elseif ( $code === 404 ) {
                    $error_msg = 'Payment not found - transaction may not exist';
                } elseif ( $code === 500 ) {
                    $error_msg = 'Server error - please try again later';
                }

                $this->log( 'Refund error message: ' . $error_msg, 'error' );
                $order->add_order_note( 'VisioFex refund failed: ' . $error_msg );
                
                return new WP_Error( 'refund_failed', 'Refund failed: ' . $error_msg );
            }
        }

    // Webhook endpoint removed in simplified plugin

        /**
         * Validate sensitive operations with proper security checks
         */
        private function validate_admin_operation( $action = 'visiofex_admin' ) {
            // Check if user has proper capabilities
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                $this->log( 'Security: Unauthorized access attempt for ' . $action, 'warning' );
                return false;
            }
            
            // For AJAX requests, verify nonce
            if ( wp_doing_ajax() && ! empty( $_POST['security'] ) ) {
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), $action ) ) {
                    $this->log( 'Security: Invalid nonce for ' . $action, 'warning' );
                    return false;
                }
            }
            
            return true;
        }

        protected function log( $msg, $level = 'info', $context = array() ) {
            if ( ! $this->logging ) return;
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                $context['source'] = 'visiofex';
                $logger->log( $level, $msg, $context );
            }
        }

        /**
         * Mask sensitive data in logs
         */
        private function mask_sensitive_data( $data ) {
            if ( is_string( $data ) ) {
                return $data;
            }
            
            if ( ! is_array( $data ) ) {
                return $data;
            }
            
            $masked = $data;
            $sensitive_keys = array( 'vendor', 'secret_key', 'password', 'token', 'key', 'authorization' );
            
            foreach ( $sensitive_keys as $key ) {
                if ( isset( $masked[$key] ) ) {
                    $value = (string) $masked[$key];
                    if ( strlen( $value ) > 8 ) {
                        $masked[$key] = substr( $value, 0, 4 ) . '****' . substr( $value, -4 );
                    } else {
                        $masked[$key] = '****';
                    }
                }
            }
            
            // Recursively mask nested arrays
            foreach ( $masked as $key => $value ) {
                if ( is_array( $value ) ) {
                    $masked[$key] = $this->mask_sensitive_data( $value );
                }
            }
            
            return $masked;
        }

        /**
         * Check if a session ID is already used by another order
         */
        private function is_session_id_used_by_another_order( $session_id, $exclude_order_id = null ) {
            if ( empty( $session_id ) ) {
                return false;
            }

            $args = array(
                'limit'        => 1,
                'meta_key'     => '_visiofex_session_id',
                'meta_value'   => $session_id,
                'meta_compare' => '=',
                'return'       => 'ids',
            );

            if ( $exclude_order_id ) {
                $args['exclude'] = array( $exclude_order_id );
            }

            $orders = wc_get_orders( $args );
            return ! empty( $orders );
        }
    }

    /**
     * GLOBAL HOOK: Force VisioFex payment method title to be consistent
     * This runs at a high priority to override any other title formatting
     */
    add_filter( 'woocommerce_order_get_payment_method_title', function( $title, $order ) {
        if ( is_object( $order ) && method_exists( $order, 'get_payment_method' ) && $order->get_payment_method() === 'visiofex' ) {
            // Force it to be lowercase 'visiofex' so WooCommerce formats it as "Payment via visiofex"
            return 'visiofex';
        }
        return $title;
    }, 999, 2 ); // Very high priority

    /**
     * GLOBAL HOOK: Also override the order item totals display
     */
    add_filter( 'woocommerce_get_order_item_totals', function( $total_rows, $order ) {
        if ( is_object( $order ) && method_exists( $order, 'get_payment_method' ) && $order->get_payment_method() === 'visiofex' ) {
            if ( isset( $total_rows['payment_method'] ) ) {
                $total_rows['payment_method']['value'] = __( 'Payment via visiofex', 'visiofex-woocommerce' );
            }
        }
        return $total_rows;
    }, 999, 2 ); // Very high priority

    /**
     * Add Settings link on Plugins page.
     */
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
        $url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=visiofex' );
        $links[] = '<a href="' . esc_url( $url ) . '">Settings</a>';
        return $links;
    } );

    /**
     * Enqueue redirect fallback script on checkout pages.
     */
    add_action( 'wp_enqueue_scripts', function() {
        if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
            wp_enqueue_script(
                'visiofex-redirect-fallback',
                VXF_WC_PLUGIN_URL . 'assets/js/visiofex-redirect-fallback.js',
                array(),
                VXF_WC_VERSION,
                true
            );
        }
    } );

    /** WooCommerce Blocks registration (simple; server-side processing handles the charge) */
    add_action( 'woocommerce_blocks_loaded', function() {
        // Static flag to prevent duplicate registration
        static $vxf_blocks_registered = false;
        if ( $vxf_blocks_registered ) {
            return;
        }
        
        if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }
        
        require_once VXF_WC_PLUGIN_DIR . 'includes/class-wc-gateway-visiofex-blocks.php';
        add_action( 'woocommerce_blocks_payment_method_type_registration', function( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
            // Additional check to prevent duplicate registration
            if ( $registry->is_registered( 'visiofex' ) ) {
                return;
            }
            $blocks_instance = new WC_Gateway_VisioFex_Blocks();
            $registry->register( $blocks_instance );
        } );
        
        $vxf_blocks_registered = true;
    } );

    // Fix payment method title when new orders are processed
    add_action( 'woocommerce_checkout_order_processed', function( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order && $order->get_payment_method() === 'visiofex' ) {
            $order->set_payment_method_title( 'visiofex' );
            $order->save();
        }
    }, 5 ); // Changed from 20 to 5 to run earlier

    // Additional hook: Fix title during order creation (even earlier)
    add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
        if ( $order && isset( $data['payment_method'] ) && $data['payment_method'] === 'visiofex' ) {
            $order->set_payment_method_title( 'visiofex' );
        }
    }, 5, 2 );

    // One-time fix for existing orders when plugin loads
    add_action( 'init', function() {
        // This could be implemented if needed for migration
    } );
}, 1 );


register_activation_hook( __FILE__, function() {
    // Set default store domain if not already set
    $existing_settings = get_option( 'woocommerce_visiofex_settings', array() );
    if ( empty( $existing_settings['store_domain'] ) ) {
        $existing_settings['store_domain'] = untrailingslashit( home_url() );
        update_option( 'woocommerce_visiofex_settings', $existing_settings );
    }
} );
register_deactivation_hook( __FILE__, function() {} );

add_action( 'woocommerce_admin_order_data_after_order_details', 'vxf_admin_order_panel', 10, 1 );

if ( ! function_exists( 'vxf_admin_order_panel' ) ) {
function vxf_admin_order_panel( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    // Always expose the gateway for your admin JS
    echo '<input type="hidden" id="vxf_order_gateway" value="' . esc_attr( $order->get_payment_method() ) . '"/>';

    // Only show VisioFex specifics for VisioFex orders
    if ( $order->get_payment_method() !== 'visiofex' ) {
        return;
    }

    $txn_id     = $order->get_meta('_visiofex_payment_id') ?: '—';
    $session_id = $order->get_meta('_visiofex_session_id') ?: '—';

    echo '<p><strong>VisioFex Transaction:</strong> ' . esc_html( $txn_id ) . '</p>';
    echo '<p><strong>VisioFex Session:</strong> ' . esc_html( $session_id ) . '</p>';
    
    // Add refund reason dropdown for VisioFex orders
    ?>
    <script>
    jQuery(function($){
        if ($('#vxf_order_gateway').val() === 'visiofex') {
            
            function addRefundDropdown() {
                var $ta = $('#refund_reason, textarea[name="refund_reason"]');
                
                if ($ta.length && !$('#vxf_refund_reason').length) {
                    var $wrap = $('<p class="form-field vxf-refund-reason-field" style="margin: 10px 0;"></p>');
                    var $label = $('<label for="vxf_refund_reason" style="font-weight: bold;">VisioFex refund reason</label>');
                    var $sel = $('<select id="vxf_refund_reason" name="vxf_refund_reason" style="width: 100%; margin-top: 5px;"></select>');
                    
                    $sel.append('<option value="requested_by_customer">Requested by customer</option>');
                    $sel.append('<option value="duplicate">Duplicate</option>');
                    $sel.append('<option value="fraudulent">Fraudulent</option>');
                    
                    $wrap.append($label).append('<br/>').append($sel);
                    $ta.after($wrap);
                    $ta.prop('readonly', true).attr('placeholder', 'VisioFex requires a predefined reason; use the dropdown below.');
                    
                    var sync = function(){ $ta.val($sel.val()); };
                    $sel.on('change', sync);
                    sync();
                    
                    // Ensure refund amount fields remain editable
                    $('#refund_amount, input[name="refund_amount"], .refund_amount').prop('readonly', false).prop('disabled', false);
                }
            }
            
            // Ensure refund amount field is always editable for VisioFex orders
            function ensureRefundAmountEditable() {
                $('#refund_amount, input[name="refund_amount"], .refund_amount').each(function() {
                    $(this).prop('readonly', false).prop('disabled', false);
                });
            }
            
            // Watch for DOM changes to handle AJAX refreshes after refund failures
            function setupMutationObserver() {
                if (window.MutationObserver) {
                    var observer = new MutationObserver(function(mutations) {
                        var shouldCheck = false;
                        mutations.forEach(function(mutation) {
                            if (mutation.type === 'childList') {
                                mutation.addedNodes.forEach(function(node) {
                                    if (node.nodeType === 1) { // Element node
                                        if ($(node).find('#refund_reason, textarea[name="refund_reason"], #refund_amount, input[name="refund_amount"]').length ||
                                            $(node).is('#refund_reason, textarea[name="refund_reason"], #refund_amount, input[name="refund_amount"]')) {
                                            shouldCheck = true;
                                        }
                                    }
                                });
                            }
                        });
                        if (shouldCheck) {
                            setTimeout(function() {
                                addRefundDropdown();
                                ensureRefundAmountEditable();
                            }, 100);
                        }
                    });
                    
                    // Observe the entire order data area
                    var orderData = $('#order_data, .woocommerce-order-data, body').get(0);
                    if (orderData) {
                        observer.observe(orderData, {
                            childList: true,
                            subtree: true
                        });
                    }
                }
            }
            
            // Handle refund button clicks
            $(document).on('click', '.refund-items, .do-manual-refund, button[name="refund_amount"]', function() {
                setTimeout(function() {
                    addRefundDropdown();
                    ensureRefundAmountEditable();
                }, 100);
                setTimeout(function() {
                    addRefundDropdown();
                    ensureRefundAmountEditable();
                }, 500);
                setTimeout(function() {
                    addRefundDropdown();
                    ensureRefundAmountEditable();
                }, 1000);
            });
            
            // Watch for error messages that might indicate refund failure
            $(document).on('DOMNodeInserted', function(e) {
                var $target = $(e.target);
                if ($target.hasClass('notice') || $target.hasClass('error') || 
                    $target.hasClass('woocommerce-message') || $target.find('.notice, .error, .woocommerce-message').length) {
                    // When error/notice appears, refund UI might be refreshed
                    setTimeout(function() {
                        addRefundDropdown();
                        ensureRefundAmountEditable();
                    }, 500);
                    setTimeout(function() {
                        addRefundDropdown();
                        ensureRefundAmountEditable();
                    }, 1500);
                }
            });
            
            // Periodic check with longer intervals to catch edge cases
            var checkInterval = setInterval(function() {
                if ($('#refund_reason:visible, textarea[name="refund_reason"]:visible').length && !$('#vxf_refund_reason').length) {
                    addRefundDropdown();
                }
                ensureRefundAmountEditable();
            }, 2000);
            
            // Initial setup
            addRefundDropdown();
            ensureRefundAmountEditable();
            setupMutationObserver();
            
            // Stop periodic checking after 5 minutes
            setTimeout(function() {
                clearInterval(checkInterval);
            }, 300000);
        }
    });
    </script>
    <?php
}
}


add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( $screen && $screen->post_type === 'shop_order' ) {
            // Refund dropdown is now handled inline in vxf_admin_order_panel()
            // No external JS file needed
        }
    }
} );

// Add the action to the dropdown in Order actions
add_filter('woocommerce_order_actions', function($actions){
    $actions['vxf_sync_status'] = 'Sync VisioFex status';
    return $actions;
});

// Handle it: fetch gateway instance and call the method above
add_action('woocommerce_order_action_vxf_sync_status', function($order){
    if ( ! $order instanceof WC_Order ) return;
    $pgs = wc()->payment_gateways()->payment_gateways();
    if ( empty($pgs['visiofex']) ) { $order->add_order_note('VisioFex: gateway not available.'); return; }
    /** @var WC_Gateway_VisioFex $gw */
    $gw = $pgs['visiofex'];
    $gw->sync_order_from_visiofex( $order );
});

// After class WC_Gateway_VisioFex definition or near bottom of file add helper if not present
if ( ! function_exists( 'visiofex_batch_auto_sync_orders' ) ) {
    /**
     * Batch auto sync for VisioFex orders lacking a transaction id within configured window.
     * Runs silently on Orders list load. Uses transient lock to avoid repeated API hits.
     */
    function visiofex_batch_auto_sync_orders() {
        // Debug logging to see if function is called at all
        visiofex_log( 'Auto-sync: function called - checking conditions' );
        
        if ( ! is_admin() ) {
            visiofex_log( 'Auto-sync: not admin - exiting' );
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            visiofex_log( 'Auto-sync: user cannot manage woocommerce - exiting' );
            return;
        }
        
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        
        // Support both classic and HPOS orders screens
        $valid_screens = array( 'edit-shop_order', 'woocommerce_page_wc-orders' );
        if ( ! $screen || ! in_array( $screen->id, $valid_screens, true ) ) {
            return;
        }

        // Check and log transient lock status
        $lock_exists = get_transient( 'vxf_auto_sync_lock' );
        if ( $lock_exists ) {
            visiofex_log( 'Auto-sync: locked (transient exists) - exiting' );
            return;
        }
        
        visiofex_log( 'Auto-sync: setting lock and proceeding' );
        set_transient( 'vxf_auto_sync_lock', 1, 15 );

        $hours = (int) get_option( 'visiofex_auto_sync_hours', 24 );
        if ( $hours < 1 ) { $hours = 1; }
        if ( $hours > 48 ) { $hours = 48; }
        $cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );
        
        // $cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 60 ) ); // Uses minutes instead of hours

        // Start log for visibility
        visiofex_log( 'Auto-sync: scan start (window=' . $hours . 'h, cutoff=' . $cutoff_gmt . ')' );

        // Query recent pending/on-hold orders with session id but no transaction id
        $args = array(
            'limit'        => 15, // hard cap per load
            'status'       => array( 'pending','on-hold' ),
            'orderby'      => 'date',
            'order'        => 'DESC',
            'payment_method' => 'visiofex',
            'date_created' => '>' . $cutoff_gmt,
            'return'       => 'objects',
        );
        
        visiofex_log( 'Auto-sync: querying orders with args - ' . wp_json_encode( array_merge( $args, array( 'date_created' => 'cutoff_applied' ) ) ) );
        
        $orders = wc_get_orders( $args );
        
        visiofex_log( 'Auto-sync: found ' . ( is_array( $orders ) ? count( $orders ) : 0 ) . ' total orders matching criteria' );
        
        // Collect eligible orders (session id present, no transaction id yet)
        $eligible = array();
        if ( ! empty( $orders ) ) {
            $used_sessions = array(); // Track sessions already processed
            foreach ( $orders as $order ) {
                $oid = $order->get_id();
                // Use WooCommerce order methods for HPOS compatibility
                $has_txn_id = $order->get_meta( '_visiofex_transaction_id' ) || $order->get_meta( '_visiofex_payment_id' );
                $session_id = $order->get_meta( '_visiofex_session_id' );
                
                visiofex_log( 'Auto-sync: evaluating order #' . $oid . ' - has_txn_id: ' . ( $has_txn_id ? 'yes' : 'no' ) . ', session_id: ' . ( $session_id ? substr( $session_id, 0, 8 ) . '...' : 'none' ) );
                
                if ( $has_txn_id ) { continue; }
                if ( ! $session_id ) { continue; }
                if ( in_array( $session_id, $used_sessions, true ) ) {
                    visiofex_log( 'Auto-sync: skipping order #' . $oid . ' - session ' . $session_id . ' already processed for another order' );
                    continue;
                }
                $eligible[] = array( 'order' => $order, 'session' => $session_id );
                $used_sessions[] = $session_id;
            }
        }

        if ( empty( $eligible ) ) {
            visiofex_log( 'Auto-sync: no eligible pending sessions within last ' . $hours . 'h window.' );
            return;
        }

        // Log summary of what we are about to process
        $log_list = array();
        foreach ( $eligible as $row ) {
            $log_list[] = '#' . $row['order']->get_id() . '(' . $row['session'] . ')';
            if ( count( $log_list ) >= 10 ) break; // limit verbosity
        }
        visiofex_log( 'Auto-sync: found ' . count( $eligible ) . ' eligible order(s) (showing up to 10): ' . implode( ', ', $log_list ) . ' | window=' . $hours . 'h' );

        foreach ( $eligible as $row ) {
            visiofex_auto_sync_single_order( $row['order'], $row['session'] );
        }
    }
}

if ( ! function_exists( 'visiofex_auto_sync_single_order' ) ) {
    /**
     * Sync a single order by its session id (no transaction id yet).
     */
    function visiofex_auto_sync_single_order( WC_Order $order, $session_id ) {
        // Retrieve secret key from gateway settings (matches process_payment usage)
        $settings = get_option( 'woocommerce_visiofex_settings', array() );
        $api_key = isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';
        if ( ! $api_key ) {
            visiofex_log( 'Auto-sync: skipping order #' . $order->get_id() . ' (no secret key configured)' );
            return;
        }

        visiofex_log( 'Auto-sync: checking order #' . $order->get_id() . ' session ' . $session_id );

        $resp = wp_remote_get( "https://api.konacash.com/v1/checkout/sessions/$session_id", array(
            'headers' => array( 'X-API-KEY' => $api_key ),
            'timeout' => 15,
        ) );
        if ( is_wp_error( $resp ) ) return;
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) ) return;
        
        // Parse response structure like the manual sync does
        $sess = $body['data']['session'] ?? array();
        $txn = $body['data']['transaction'] ?? array();
        
        $status = strtolower( $sess['paymentStatus'] ?? '' );
        $txn_id = $txn['_id'] ?? '';
        
        visiofex_log( 'Auto-sync: API response - Status: ' . ( $status ?: 'none' ) . ', Transaction ID: ' . ( $txn_id ?: 'none' ) );

        if ( in_array( $status, array( 'paid','succeeded' ), true ) ) {
            if ( $txn_id ) {
                $order->update_meta_data( '_visiofex_transaction_id', $txn_id );
                $order->update_meta_data( '_visiofex_payment_id', $txn_id ); // Also save as payment_id for consistency
                $order->save();
                
                if ( ! $order->is_paid() ) {
                    $order->payment_complete( $txn_id );
                }
                visiofex_log( 'Auto-sync: order #' . $order->get_id() . ' session ' . $session_id . ' resolved to transaction ' . $txn_id . ' -> marked paid.' );
            } else {
                visiofex_log( 'Auto-sync: order #' . $order->get_id() . ' session ' . $session_id . ' shows paid status but no transaction ID - keeping pending until transaction appears.' );
            }
        } elseif ( in_array( $status, array( 'failed','canceled' ), true ) ) {
            if ( ! $order->has_status( 'failed' ) ) {
                $order->update_status( 'failed', 'VisioFex reported failure (auto sync)' );
            }
            visiofex_log( 'Auto-sync: order #' . $order->get_id() . ' session ' . $session_id . ' status=' . $status . ' -> marked failed.' );
        } else {
            visiofex_log( 'Auto-sync: order #' . $order->get_id() . ' session ' . $session_id . ' still pending (status=' . ( $status ?: 'none' ) . ').' );
        }
    }
}

add_action( 'current_screen', 'visiofex_batch_auto_sync_orders' );

// Add setting field for auto sync window hours inside gateway form_fields hook via filter if needed
add_filter( 'woocommerce_settings_api_form_fields_visiofex', function( $fields ) {
    // Append new field
    $fields['auto_sync_hours'] = array(
        'title'       => __( 'Auto Sync Window (hours)', 'visiofex-woocommerce' ),
        'type'        => 'number',
        'description' => __( 'On Orders page load, pending VisioFex orders with a session but no transaction ID created within this window will be refreshed automatically.', 'visiofex-woocommerce' ),
        'default'     => 24,
        'desc_tip'    => true,
        'custom_attributes' => array(
            'min' => 1,
            'max' => 48,
            'step' => 1,
        ),
    );
    return $fields;
} );

// Persist option manually if gateway does not automatically store custom numeric field name
add_action( 'woocommerce_update_options_payment_gateways_visiofex', function() {
    if ( isset( $_POST['woocommerce_visiofex_auto_sync_hours'] ) ) {
        $raw = intval( $_POST['woocommerce_visiofex_auto_sync_hours'] );
        if ( $raw < 1 ) $raw = 1; if ( $raw > 48 ) $raw = 48;
        update_option( 'visiofex_auto_sync_hours', $raw );
    }
} );

// Provide backward compatible retrieval in constructor (if needed elsewhere) via helper
if ( ! function_exists( 'visiofex_get_auto_sync_hours' ) ) {
    function visiofex_get_auto_sync_hours() {
        $h = (int) get_option( 'visiofex_auto_sync_hours', 24 );
        if ( $h < 1 ) $h = 1; if ( $h > 48 ) $h = 48; return $h;
    }
}

// Logging helper outside the gateway class context
if ( ! function_exists( 'visiofex_logging_enabled' ) ) {
    function visiofex_logging_enabled() : bool {
        $settings = get_option( 'woocommerce_visiofex_settings', array() );
        if ( isset( $settings['logging'] ) ) {
            return $settings['logging'] === 'yes';
        }
        // Fallback: assume enabled by default
        return true;
    }
}

if ( ! function_exists( 'visiofex_log' ) ) {
    function visiofex_log( $message, $level = 'info' ) {
        if ( ! visiofex_logging_enabled() ) return;
        if ( ! function_exists( 'wc_get_logger' ) ) return;
        $logger = wc_get_logger();
        $logger->log( $level, $message, array( 'source' => 'visiofex' ) );
    }
}
