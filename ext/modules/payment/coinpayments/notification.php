<?php
/*
	ipn.php
	Based on ipn_standard.php by osCommerce.

  Copyright (c) 2013-2015 CoinPayments.net

  Released under the GNU General Public License
  
  ----------- original ipn_standard.php copyright notice -----------

  osCommerce, Open Source E-Commerce Solutions
  http://www.oscommerce.com

  Copyright (c) 2008 osCommerce

  Released under the GNU General Public License
*/

chdir('../../../../');
require('includes/application_top.php');

$report = true;
$error_msg = 'Unknown error';

if (
    tep_not_null(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID) &&
    tep_not_null(MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET) &&
    tep_not_null(MODULE_PAYMENT_COINPAYMENTS_WEBHOOK) &&
    MODULE_PAYMENT_COINPAYMENTS_WEBHOOK == 'Yes'
) {


    require_once 'includes/modules/payment/coinpayments/coinpayments_api.php';
    $api = new coinpayments_api();
    $signature = $_SERVER['HTTP_X_COINPAYMENTS_SIGNATURE'];
    $content = file_get_contents('php://input');
    $request_data = json_decode($content, true);

    if ($api->checkDataSignature($signature, $content) && isset($request_data['invoice']['invoiceId'])) {
//
        $invoice_str = $request_data['invoice']['invoiceId'];
        $invoice_str = explode('|', $invoice_str);
        $host_hash = array_shift($invoice_str);
        $invoice_id = array_shift($invoice_str);

        if ($host_hash == md5(tep_href_link('index.php', '', 'SSL', false, false))) {
            $display_value = $request_data['invoice']['amount']['displayValue'];
            $trans_id = $request_data['invoice']['id'];

            $order_query = tep_db_query("select orders_status, currency, currency_value from " . TABLE_ORDERS . " where orders_id = '" . $invoice_id . "'");
            if (tep_db_num_rows($order_query) > 0) {
                $report = false;
                $order = tep_db_fetch_array($order_query);
                if ($order) {
                    $status = $request_data['invoice']['status'];
                    if ($status == 'Completed') {

                        $total_query = tep_db_query("select value from " . TABLE_ORDERS_TOTAL . " where orders_id = '" . (int)$invoice_id . "' and class = 'ot_total' limit 1");
                        $total = tep_db_fetch_array($total_query);

                        $comment_status = $status;
                        $comment_status .= ' (transaction ID: ' . $trans_id . ')';
                        $comment_status .= '; (' . sprintf("%.08f", $request_data['invoice']['amount']['displayValue']) . ' ' . $request_data['invoice']['amount']['currency'] . ')';

                        $new_status = MODULE_PAYMENT_COINPAYMENTS_ORDER_STATUS_ID > 0 ? (int)MODULE_PAYMENT_COINPAYMENTS_ORDER_STATUS_ID : (int)DEFAULT_ORDERS_STATUS_ID;

                        $sql_data_array = array('orders_id' => $invoice_id,
                            'orders_status_id' => $new_status,
                            'date_added' => 'now()',
                            'customer_notified' => '0',
                            'comments' => 'CoinPayments.net Notification Verified [' . $comment_status . ']');

                        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);

                        tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . $new_status . "', last_modified = now() where orders_id = '" . (int)$invoice_id . "'");
                    } else {
                        if ($order['orders_status'] == MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID) {
                            $sql_data_array = array('orders_id' => (int)$invoice_id,
                                'orders_status_id' => MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID,
                                'date_added' => 'now()',
                                'customer_notified' => '0',
                                'comments' => $status);
                            tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
                        }
                    }
                }
            }
        }

    }
}


require('includes/application_bottom.php');
