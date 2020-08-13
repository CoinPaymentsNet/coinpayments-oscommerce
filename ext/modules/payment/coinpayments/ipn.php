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
  
	if (tep_not_null(MODULE_PAYMENT_COINPAYMENTS_MERCHANT) && tep_not_null(MODULE_PAYMENT_COINPAYMENTS_IPN_SECRET)) {
		$auth_ok = false;
		$ipn_mode = isset($HTTP_POST_VARS['ipn_mode']) ? $HTTP_POST_VARS['ipn_mode'] : '';
		if ($ipn_mode == 'hmac') {
			if (isset($_SERVER['HTTP_HMAC']) && !empty($_SERVER['HTTP_HMAC'])) {
				$request = file_get_contents('php://input');
				if ($request !== FALSE && !empty($request)) {
					$hmac = hash_hmac("sha512", $request, trim(MODULE_PAYMENT_COINPAYMENTS_IPN_SECRET));
					if ($hmac == $_SERVER['HTTP_HMAC']) {
						$auth_ok = true;
					} else {
						$error_msg = 'HMAC signature does not match';
					}
				} else {
					$error_msg = 'Error reading POST data';
				}
			} else {
				$error_msg = 'No HMAC signature sent.';
			}			
		} else if ($ipn_mode == 'httpauth') {
		  if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		  	if ($_SERVER['PHP_AUTH_USER'] == trim(MODULE_PAYMENT_COINPAYMENTS_MERCHANT) && $_SERVER['PHP_AUTH_PW'] == trim(MODULE_PAYMENT_COINPAYMENTS_IPN_SECRET)) {
		  		$auth_ok = true;
		  	} else {
		  		$error_msg = 'Invalid Merchant ID and/or IPN Secret. Make sure your IPN Secret matches in both the osCommerce plugin and in your CoinPayments\' settings.';
		  	}
		  } else {
		  	$error_msg = 'No Auth User/Pass given. Make sure your server has PHP Auth variables enabled or (preferably) switch to HMAC mode.';
		  }
		} else {
			$error_msg = 'Unknown IPN Verification Method';
		}
		
		if ($auth_ok) {
			$invoice = (isset($HTTP_POST_VARS['invoice']) && is_numeric($HTTP_POST_VARS['invoice'])) ? (int)$HTTP_POST_VARS['invoice'] : 0;
	    if ($invoice > 0) {
	      $order_query = tep_db_query("select orders_status, currency, currency_value from " . TABLE_ORDERS . " where orders_id = '" . $invoice . "' and customers_id = '" . (int)$HTTP_POST_VARS['custom'] . "'");
	      if (tep_db_num_rows($order_query) > 0) {
	        $report = false;
	        $order = tep_db_fetch_array($order_query);
	        
	        $status = (int)$HTTP_POST_VARS['status'];
	        $status_text = $HTTP_POST_VARS['status_text'];
					if ($status < 100) {
		        if ($order['orders_status'] == MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID) {
		          $sql_data_array = array('orders_id' => (int)$HTTP_POST_VARS['invoice'],
		                                  'orders_status_id' => MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID,
		                                  'date_added' => 'now()',
		                                  'customer_notified' => '0',
		                                  'comments' => $status_text);
		
		          tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);		
		        }
		      } else {
		        $total_query = tep_db_query("select value from " . TABLE_ORDERS_TOTAL . " where orders_id = '" . (int)$HTTP_POST_VARS['invoice'] . "' and class = 'ot_total' limit 1");
		        $total = tep_db_fetch_array($total_query);
		        
		        $comment_status = $status_text;
		        $comment_status .= ' (transaction ID: '.$HTTP_POST_VARS['txn_id'].')';
		        $comment_status .= '; ('.sprintf("%.08f", $HTTP_POST_VARS['amount1']).' '.$HTTP_POST_VARS['currency1'].' => '.sprintf("%.08f", $HTTP_POST_VARS['amount2']).' '.$HTTP_POST_VARS['currency2'].')';
		        
		        $new_status = MODULE_PAYMENT_COINPAYMENTS_ORDER_STATUS_ID > 0 ? (int)MODULE_PAYMENT_COINPAYMENTS_ORDER_STATUS_ID : (int)DEFAULT_ORDERS_STATUS_ID;
		
		        if ($HTTP_POST_VARS['amount1'] != number_format($total['value'] * $order['currency_value'], $currencies->get_decimal_places($order['currency']))) {
		          $comment_status .= '; transaction value (' . tep_output_string_protected($HTTP_POST_VARS['amount1']) . ') does not match order value (' . number_format($total['value'] * $order['currency_value'], $currencies->get_decimal_places($order['currency'])) . ')';
		          $new_status = MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID;
		        }
		        if ($HTTP_POST_VARS['currency1'] != $order['currency']) {
		          $comment_status .= '; original currency (' . tep_output_string_protected($HTTP_POST_VARS['currency1']) . ') does not match order value (' . tep_output_string_protected($order['currency']) . ')';
		          $new_status = MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID;
		        }
		        if ($HTTP_POST_VARS['merchant'] != MODULE_PAYMENT_COINPAYMENTS_MERCHANT) {
		          $comment_status .= '; merchant (' . tep_output_string_protected($HTTP_POST_VARS['merchant']) . ') does not match system value (' . tep_output_string_protected(MODULE_PAYMENT_COINPAYMENTS_MERCHANT) . ')';
		          $new_status = MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID;
		        }
		
		        $sql_data_array = array('orders_id' => $HTTP_POST_VARS['invoice'],
		                                'orders_status_id' => $new_status,
		                                'date_added' => 'now()',
		                                'customer_notified' => '0',
		                                'comments' => 'CoinPayments.net IPN Verified [' . $comment_status . ']');
		
		        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
	          
          	tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . $new_status . "', last_modified = now() where orders_id = '" . (int)$HTTP_POST_VARS['invoice'] . "'");
	        }
	      } else {
	      	$error_msg = 'Error finding order in database!';
	      }
	    } else {
	    	$error_msg = 'Invalid invoice number set!';
	    }
	  }
  } else {
  	$error_msg = 'Error: Merchant ID and/or IPN Secret are not set in your plugin configuration!';
  }
  
  if ($report) {
		if (tep_not_null(MODULE_PAYMENT_COINPAYMENTS_DEBUG_EMAIL)) {
			$email_body = 'Error: '.$error_msg."\n\n";
			$email_body .= 'AUTH User: '.$_SERVER['PHP_AUTH_USER']."\n";
			$email_body .= 'AUTH Pass: '.$_SERVER['PHP_AUTH_PW']."\n\n";
			
      $email_body .= '$HTTP_POST_VARS:' . "\n\n";

      reset($HTTP_POST_VARS);
      while (list($key, $value) = each($HTTP_POST_VARS)) {
        $email_body .= $key . '=' . $value . "\n";
      }

      $email_body .= "\n" . '$HTTP_GET_VARS:' . "\n\n";

      reset($HTTP_GET_VARS);
      while (list($key, $value) = each($HTTP_GET_VARS)) {
        $email_body .= $key . '=' . $value . "\n";
      }

      tep_mail('', MODULE_PAYMENT_COINPAYMENTS_DEBUG_EMAIL, 'CoinPayments.net Invalid IPN', $email_body, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
    }
    
		if (isset($HTTP_POST_VARS['invoice']) && is_numeric($HTTP_POST_VARS['invoice']) && ($HTTP_POST_VARS['invoice'] > 0)) {
     $check_query = tep_db_query("select orders_id from " . TABLE_ORDERS . " where orders_id = '" . (int)$HTTP_POST_VARS['invoice'] . "' and customers_id = '" . (int)$HTTP_POST_VARS['custom'] . "'");
      if (tep_db_num_rows($check_query) > 0) {
        $comment_status = ((int)$HTTP_POST_VARS['status'] >= 100) ? "Completed":"Pending";

        $sql_data_array = array('orders_id' => $HTTP_POST_VARS['invoice'],
                                'orders_status_id' => MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID,
                                'date_added' => 'now()',
                                'customer_notified' => '0',
                                'comments' => 'CoinPayments.net IPN INVALID - Check Status at CoinPayments.net! [' . $comment_status . ']');

        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
      }
    }    
  }

  require('includes/application_bottom.php');
