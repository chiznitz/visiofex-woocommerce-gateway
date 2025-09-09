<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VisioFex_Reports {
    private $api_base = 'https://api.konacash.com/v1/';
    private $secret_key;

    public function __construct( $secret_key = '' ) {
        // Get secret key from gateway settings if not provided
        if ( empty( $secret_key ) ) {
            $gateway_options = get_option( 'woocommerce_visiofex_settings', array() );
            $this->secret_key = $gateway_options['secret_key'] ?? '';
        } else {
            $this->secret_key = $secret_key;
        }
        
        // Debug logging
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->info( 'VisioFex_Reports instantiated. API key set: ' . ( empty( $this->secret_key ) ? 'NO' : 'YES' ), array( 'source' => 'visiofex' ) );
        }
        
        add_action( 'admin_menu', array( $this, 'register_admin_page' ), 99 );
        add_action( 'admin_post_vxf_clear_cache', array( $this, 'clear_cache_action' ) );
        
        // Temporary admin notice to confirm class is loaded
        add_action( 'admin_notices', function() {
            if ( current_user_can( 'manage_woocommerce' ) ) {
                echo '<div class="notice notice-info is-dismissible"><p><strong>VisioFex Reports:</strong> Reports class loaded successfully! API Key: ' . ( empty( $this->secret_key ) ? 'NOT SET' : 'SET' ) . '</p></div>';
            }
        } );
    }

    private function api_request( $method, $path, $body = null ) {
        $url = untrailingslashit( $this->api_base ) . '/' . ltrim( $path, '/' );
        $args = array(
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => array(
                'X-API-KEY'    => $this->secret_key,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ),
        );
        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }

        // Add debug info for empty secret key
        if ( empty( $this->secret_key ) ) {
            return new WP_Error( 'missing_api_key', 'No API key configured. Please check VisioFex gateway settings.' );
        }

        $resp = wp_remote_request( $url, $args );
        if ( is_wp_error( $resp ) ) {
            return new WP_Error( 'http_error', $resp->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'api_error', sprintf( 'HTTP %s: %s (URL: %s)', $code, $body, $url ) );
        }

        return $data;
    }

    public function fetch_transactions( $page = 1, $limit = 100, $force = false ) {
        $cache_key = "vxf_transactions_{$page}_{$limit}";
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $path = "transactions/list?page=" . intval( $page ) . "&limit=" . intval( $limit );
        $res  = $this->api_request( 'GET', $path );
        if ( is_wp_error( $res ) ) {
            return $res;
        }

        set_transient( $cache_key, $res, HOUR_IN_SECONDS );
        return $res;
    }

    public function fetch_all_transactions( $limit_per_page = 250, $max_pages = 20, $force = false ) {
        $cache_key = "vxf_all_transactions_{$limit_per_page}_{$max_pages}";
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $all_transactions = array();
        $page = 1;
        $total_fetched = 0;

        // Debug logging
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->info( "Starting pagination fetch: limit_per_page={$limit_per_page}, max_pages={$max_pages}", array( 'source' => 'visiofex' ) );
        }

        while ( $page <= $max_pages ) {
            $response = $this->fetch_transactions( $page, $limit_per_page, $force );
            
            if ( is_wp_error( $response ) ) {
                // Log error but return what we have so far
                if ( function_exists( 'wc_get_logger' ) ) {
                    $logger = wc_get_logger();
                    $logger->error( "Error fetching page {$page}: " . $response->get_error_message(), array( 'source' => 'visiofex' ) );
                }
                break;
            }

            $transactions = $response['data']['transactions'] ?? array();
            $count = count( $transactions );
            
            if ( $count === 0 ) {
                // No more results
                break;
            }

            $all_transactions = array_merge( $all_transactions, $transactions );
            $total_fetched += $count;

            // Debug logging
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                $logger->info( "Fetched page {$page}: {$count} transactions (total: {$total_fetched})", array( 'source' => 'visiofex' ) );
            }

            // If we got fewer results than requested, we've reached the end
            if ( $count < $limit_per_page ) {
                break;
            }

            $page++;
        }

        // Cache the combined result
        $result = array(
            'transactions' => $all_transactions,
            'total_fetched' => $total_fetched,
            'pages_fetched' => $page - 1,
            'may_have_more' => ( $page > $max_pages ) // Hit our safety limit
        );

        set_transient( $cache_key, $result, HOUR_IN_SECONDS );
        
        // Final debug log
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->info( "Pagination complete: {$total_fetched} transactions across " . ($page - 1) . " pages", array( 'source' => 'visiofex' ) );
        }

        return $result;
    }

    public function fetch_daily_report( $start_date, $end_date, $force = false ) {
        $cache_key = "vxf_daily_{$start_date}_{$end_date}";
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $path = "accounting/vendor/report/daily?startDate=" . rawurlencode( $start_date ) . "&endDate=" . rawurlencode( $end_date );
        $res  = $this->api_request( 'GET', $path );

        if ( is_wp_error( $res ) ) {
            return $res;
        }

        set_transient( $cache_key, $res, HOUR_IN_SECONDS );
        return $res;
    }

    public function build_daily_summary_from_transactions( array $transactions ) {
        $days = array();
        foreach ( $transactions as $t ) {
            if ( empty( $t['createdAt'] ) ) {
                continue;
            }
            $d = substr( $t['createdAt'], 0, 10 );
            if ( ! isset( $days[ $d ] ) ) {
                $days[ $d ] = array(
                    'count'       => 0,
                    'gross'       => 0.0,
                    'platformFee' => 0.0,
                    'netProfit'   => 0.0,
                );
            }
            $days[ $d ]['count']++;
            $days[ $d ]['gross']       += floatval( $t['amount'] );
            $days[ $d ]['platformFee'] += floatval( $t['platformFee'] ?? 0 );
            $days[ $d ]['netProfit']   += floatval( $t['netProfit'] ?? 0 );
        }
        krsort( $days );
        return $days;
    }

    public function register_admin_page() {
        // Debug logging
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->info( 'VisioFex_Reports register_admin_page called. WooCommerce loaded: ' . ( class_exists( 'WooCommerce' ) ? 'YES' : 'NO' ), array( 'source' => 'visiofex' ) );
        }
        
        // Only add menu if WooCommerce is available
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        
        $page = add_submenu_page(
            'woocommerce',
            'VisioFex Reports',
            'VisioFex Reports',
            'manage_woocommerce',
            'visiofex-reports',
            array( $this, 'render_admin_page' )
        );
        
        // Log if page was added
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->info( 'VisioFex Reports menu added. Page hook: ' . ( $page ?: 'FAILED' ), array( 'source' => 'visiofex' ) );
        }
    }

    public function clear_cache_action() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied' );
        }
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vxf_%' OR option_name LIKE '_transient_timeout_vxf_%'" );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=visiofex-reports' ) );
        exit;
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Access denied' );
        }

        // Handle daily summary action
        $show_today = isset( $_GET['daily_summary'] ) && $_GET['daily_summary'] === '1';
        if ( $show_today ) {
            // Use yesterday to today range for daily summary since API requires start < end
            $start = date( 'Y-m-d', strtotime( '-1 day' ) );
            $end = date( 'Y-m-d' );
        } else {
            $start = isset( $_GET['start'] ) ? sanitize_text_field( $_GET['start'] ) : date( 'Y-m-d', strtotime( '-7 days' ) );
            $end   = isset( $_GET['end'] ) ? sanitize_text_field( $_GET['end'] ) : date( 'Y-m-d' );
        }

        echo '<div class="wrap"><h1>VisioFex Reports</h1>';
        
        // Show current secret key status for debugging
        echo '<div class="notice notice-info"><p><strong>Debug Info:</strong> Secret Key: ' . ( empty( $this->secret_key ) ? 'NOT SET' : 'SET (' . substr( $this->secret_key, 0, 6 ) . '...)' ) . '</p></div>';
        
        // Form with date range and daily summary button
        echo '<form method="get" class="vxf-report-form" style="margin-bottom: 20px;">';
        echo '<input type="hidden" name="page" value="visiofex-reports">';
        echo '<label>Start: <input name="start" type="date" value="' . esc_attr( $start ) . '"></label> ';
        echo '<label>End: <input name="end" type="date" value="' . esc_attr( $end ) . '"></label> ';
        echo '<button type="submit" class="button button-primary">Refresh</button> ';
        echo '</form>';
        
        echo '<form method="get" style="display: inline-block; margin-right: 10px;">';
        echo '<input type="hidden" name="page" value="visiofex-reports">';
        echo '<input type="hidden" name="daily_summary" value="1">';
        echo '<button type="submit" class="button button-secondary">Today\'s Summary</button>';
        echo '</form>';
        
        echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vxf_clear_cache' ), 'vxf-clear' ) ) . '">Clear Cache</a>';

        echo '<hr style="margin: 20px 0;">';

        // Show date range being queried
        if ( $show_today ) {
            echo '<h2>Recent Summary (Yesterday & Today)</h2>';
        } else {
            echo '<h2>Report from ' . esc_html( $start ) . ' to ' . esc_html( $end ) . '</h2>';
        }

        $daily = $this->fetch_daily_report( $start, $end );
        if ( is_wp_error( $daily ) ) {
            echo '<p><strong>Error fetching daily report:</strong> ' . esc_html( $daily->get_error_message() ) . '</p>';
            echo '<p><em>Falling back to transaction list with pagination...</em></p>';
            
            // Use pagination to get all transactions
            $tx_result = $this->fetch_all_transactions( 250, 20 ); // Up to 5000 transactions max
            if ( is_wp_error( $tx_result ) ) {
                echo '<p><strong>Error fetching transactions:</strong> ' . esc_html( $tx_result->get_error_message() ) . '</p>';
                echo '</div>';
                return;
            }
            
            $transactions = $tx_result['transactions'];
            
            // Show pagination info
            echo '<div class="notice notice-info" style="padding: 10px; margin: 10px 0;"><p>';
            echo '<strong>Transaction Data:</strong> Fetched ' . esc_html( $tx_result['total_fetched'] ) . ' transactions';
            echo ' across ' . esc_html( $tx_result['pages_fetched'] ) . ' page(s).';
            if ( $tx_result['may_have_more'] ) {
                echo ' <em>Note: There may be additional transactions not shown (hit pagination limit).</em>';
            }
            echo '</p></div>';
            
            // Filter transactions for the requested date range if using fallback
            if ( $show_today ) {
                $today = date( 'Y-m-d' );
                $yesterday = date( 'Y-m-d', strtotime( '-1 day' ) );
                $transactions = array_filter( $transactions, function( $t ) use ( $today, $yesterday ) {
                    if ( empty( $t['createdAt'] ) ) return false;
                    $tx_date = substr( $t['createdAt'], 0, 10 );
                    return $tx_date === $today || $tx_date === $yesterday;
                });
            } else {
                // Filter by date range
                $transactions = array_filter( $transactions, function( $t ) use ( $start, $end ) {
                    if ( empty( $t['createdAt'] ) ) return false;
                    $tx_date = substr( $t['createdAt'], 0, 10 );
                    return $tx_date >= $start && $tx_date <= $end;
                });
            }
            
            $summary = $this->build_daily_summary_from_transactions( $transactions );
        } else {
            $summary = array();
            // daily endpoint returns totals per day or an overall object — adapt to response shape
            if ( isset( $daily['data'] ) && ! empty( $daily['data'] ) ) {
                // If API returns aggregated totals only, show them as single block
                $display_date = $show_today ? 'Recent Days' : $start;
                $summary[ $display_date ] = array(
                    'count'       => $daily['data']['total_transaction_count'] ?? 0,
                    'gross'       => floatval( $daily['data']['total_daily_revenue'] ?? 0 ),
                    'platformFee' => floatval( $daily['data']['total_platform_fees'] ?? 0 ),
                    'netProfit'   => floatval( $daily['data']['net_profit'] ?? 0 ),
                );
            }
        }

        echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Transactions</th><th>Gross</th><th>Platform Fees</th><th>Net Profit</th></tr></thead><tbody>';
        foreach ( $summary as $day => $data ) {
            echo '<tr>';
            echo '<td>' . esc_html( $day ) . '</td>';
            echo '<td>' . esc_html( intval( $data['count'] ?? 0 ) ) . '</td>';
            echo '<td>' . esc_html( number_format( floatval( $data['gross'] ?? 0 ), 2 ) ) . '</td>';
            echo '<td>' . esc_html( number_format( floatval( $data['platformFee'] ?? 0 ), 2 ) ) . '</td>';
            echo '<td>' . esc_html( number_format( floatval( $data['netProfit'] ?? 0 ), 2 ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
