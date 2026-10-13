<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class TWLD_TransactWorld_Admin_Reconciliation {

    public function __construct() {
    }

    public function render_reconciliation_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TransactWorld Reconciliation', 'transactworld-payment-integration' ); ?></h1>

            <?php
            global $wpdb;
            $twld       = apply_filters( 'twld_database', $wpdb );
            $table_name = $twld->prefix.'twld_trans_forms';
            $pagenum      = isset( $_GET['pagenum'] ) ? absint( $_GET['pagenum'] ) : 1;
            $limit        = 15;
            $offset       = ( $pagenum - 1 ) * $limit;

            $total = $twld->get_var( "SELECT COUNT(`id`) FROM $table_name WHERE order_status = 'authstarted'" );
            $results = $twld->get_results( "SELECT * FROM $table_name WHERE order_status = 'authstarted' LIMIT $offset, $limit" );
            $num_of_pages = ceil( $total / $limit );

            if ( $results ) {
                ?>
                <form method="post" action="">
                    <?php   
                    // Add this line INSIDE the form to generate the nonce field
                    wp_nonce_field( 'twld_reconciliation_action', 'twld_reconciliation_nonce' );
                    ?>
                    <table class="wp-list-table widefat fixed striped table-view-list">
                        <thead>
                            <tr>
                                <th scope="col" id="cb" class="manage-column column-cb check-column">
                                    <input type="checkbox" id="cb-select-all-1">
                                </th>
                                <th scope="col"><?php esc_html_e( 'Order Number', 'transactworld-payment-integration' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Order Description', 'transactworld-payment-integration' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Order Time', 'transactworld-payment-integration' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Tracking ID', 'transactworld-payment-integration' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Order Status', 'transactworld-payment-integration' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $results as $order ) :
                                $order_id = esc_html($order->woocommerce_order_id);
                                $transactworld_order_id = esc_html( $order->transactworld_order_id ); // Get custom meta
                                $tracking_id = esc_html($order->woocommerce_order_id);
                                $order_status = esc_html($order->order_status);
                                $transaction_time = esc_html( $order->transaction_time);
                            ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="order_ids[]" value="<?php echo esc_attr( $order_id ); ?>">
                                    </th>
                                    <td><?php echo esc_html( $order_id ); ?></td>
                                    <td><?php echo esc_html( $transactworld_order_id ); ?></td>
                                    <td><?php echo esc_html( $transaction_time ); ?></td>
                                    <td><?php echo esc_html( $tracking_id ? $tracking_id : '-' ); ?></td>
                                    <td><?php echo esc_html( $order_status ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php
                            $page_links = paginate_links(
                                array(
                                    'base'      => add_query_arg( 'pagenum', '%#%' ),
                                    'format'    => '',
                                    'prev_text' => __( '«', 'transactworld-payment-integration' ),
                                    'next_text' => __( '»', 'transactworld-payment-integration' ),
                                    'total'     => $num_of_pages,
                                    'current'   => $pagenum,
                                )
                            );

                            if ( $page_links ) {
                               $total = absint($total); // Sanitize as a non-negative integer
                            // translators: %s is the number of items, formatted according to the locale.
                            echo '<span class="displaying-num">' . esc_html( sprintf( _n( '%s item', '%s items', $total, 'transactworld-payment-integration' ), number_format_i18n( $total ) ) ) . '</span>'; 
                                echo wp_kses_post( $page_links );
                            }
                            ?>
                        </div>
                         <input type="submit" name="reconcile" class="button button-primary" value="<?php esc_attr_e( 'Reconcile', 'transactworld-payment-integration' ); ?>">
                    </div>
                </form>

                <?php
                // Handle reconciliation form submission.
                $nonce = isset( $_POST['twld_reconciliation_nonce'] ) 
                    ? sanitize_text_field( wp_unslash( $_POST['twld_reconciliation_nonce'] ) )
                    : '';

                if ( ! wp_verify_nonce( $nonce, 'twld_reconciliation_action' ) ) {
                    print 'Sorry, your nonce did not verify.';
                    exit;
                }
                if ( isset( $_POST['order_ids'] ) && is_array( $_POST['order_ids'] ) ) {
                    $this->process_reconciliation( array_map( 'absint', $_POST['order_ids'] ) );
                }
                } else { ?>
                <p><?php esc_html_e( 'No orders found with "authstarted" status.', 'transactworld-payment-integration' ); ?></p>
                <?php
            }
            ?>
        </div>
        <?php
    }

    /**
     * Process reconciliation for selected orders.
     *
     * @param array $order_ids Array of WooCommerce order IDs.
     */
    private function process_reconciliation( $order_ids ) {
        if ( empty( $order_ids ) ) {
            return;
        }
        global $wpdb;
        $twld       = apply_filters( 'twld_database', $wpdb );
        $table_name = $twld->prefix.'twld_trans_forms';
        $gateway = new TWLD_TransactWorld_Payment_Gateway();

        $success_count = 0;
        $error_count   = 0;
        $error_messages = array();

        foreach ( $order_ids as $order_id ) {
            $order_id = absint( $order_id ); // Ensure it's an integer.

            // Get the order object.  This is HPOS-compatible.
            $order = wc_get_order( $order_id );

                // Get order details from order meta.
            $cache_key_order = 'twpi_order_data_' . $order_ids;
            $order_data = wp_cache_get( $cache_key_order );
            if ( false === $order_data ) {
                $order_data = $wpdb->get_row(
                    $wpdb->prepare(
                       // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        "SELECT transactworld_order_id, tracking_id FROM {$table_name} WHERE woocommerce_order_id = %d",
                        $order_id
                    )
                );
                wp_cache_set( $cache_key_order, $order_data, '', 5 * MINUTE_IN_SECONDS );
            }

            if ( ! $order ) {
                // translators: %d is the order ID.
                $error_messages[] = sprintf( __( 'Order %d not found.', 'transactworld-payment-integration' ), esc_html( $order_id ) );
                $error_count++;
                continue;
            }

            $description  = $order_data->transactworld_order_id; //Old code
            $trackingid = $order_data->tracking_id; 

            // Prepare checksum string
            $checksum_str = $gateway->merchant_id . "|" . $gateway->working_key . "|" . $description;
            $checksum = md5($checksum_str);

            // Prepare Query URL
            $query_url = "{$gateway->queryurl}/" . $description;

            // Update order status in WooCommerce order.
            $new_status = 'authsuccessful';
            $wpdb->update(
                $table_name,
                array( 'order_status' => $new_status, 'tracking_id' => isset($result->trackingId) ? $result->trackingId : '' ),
                array( 'woocommerce_order_id' => $order_id )
            );

            if ( $order ) {
                $wc_status = '';  // Initialize $wc_status
                if ( 'capturesuccess' === $new_status || 'authsuccessful' === $new_status ) {
                    $wc_status = 'processing';
                    $order->payment_complete( isset( $result->trackingId ) ? $result->trackingId : '' );
                    // translators: %s is the transaction status.
                    $order->add_order_note( sprintf( __( 'TransactWorld reconciliation successful. Status: %s', 'transactworld-payment-integration' ), esc_html( $new_status ) ) );
                } elseif ( 'authstarted' === $new_status || 'begun' === $new_status ) {
                    $wc_status = 'pending';
                    // translators: %s is the transaction status.
                    $order->update_status( $wc_status, sprintf( __( 'TransactWorld reconciliation: Status remains %s.', 'transactworld-payment-integration' ), esc_html( $new_status ) ) );
                } else {
                    $wc_status = 'failed';
                    // translators: %s is the transaction status.
                    $order->update_status( $wc_status, sprintf( __( 'TransactWorld reconciliation: Status updated to %s.', 'transactworld-payment-integration' ), esc_html( $new_status ) ) );
                }

                // Update custom meta data. This is HPOS-compatible.
                $order->update_meta_data( '_transactworld_status', $new_status );
                if ( isset( $result->trackingId ) ) {
                    $order->update_meta_data( '_transactworld_tracking_id', $result->trackingId );
                }
                $order->save();

                $success_count++;
            } else {
                TWLD_TransactWorld_Logger::log( 'Error: Could not retrieve WooCommerce order during reconciliation for order ID: ' . esc_html( $order_id ) );
                $error_count++;
            }
        }

        // Display results to the admin user.
        if ( $success_count > 0 ) {
            // translators: %d is the number of orders successfully reconciled.
            echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%s order successfully reconciled.', '%s orders successfully reconciled.', $success_count, 'transactworld-payment-integration' ), esc_html( $success_count ) ) ) . '</p></div>';
        }
        if ( $error_count > 0 ) {
            // translators: %d is the number of orders that failed to reconcile.
            echo '<div class="notice notice-error"><p>' . esc_html( sprintf( _n( '%s order failed to reconcile.', '%s orders failed to reconcile.', $error_count, 'transactworld-payment-integration' ), esc_html( $error_count ) ) ) . '</p>';
            foreach ( $error_messages as $message ) {
                echo '<p>' . esc_html( $message ) . '</p>';
            }
            echo '</div>';
        }
    }
}