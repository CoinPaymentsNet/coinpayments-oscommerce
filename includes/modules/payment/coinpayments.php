<?php
/*
  coinpayments.php
  Copyright (c) 2020 CoinPayments.net

  Released under the GNU General Public License
  
  ----------- original paypal_standard.php copyright notice -----------
	
  osCommerce, Open Source E-Commerce Solutions
  http://www.oscommerce.com

  Copyright (c) 2008 osCommerce

  Released under the GNU General Public License
*/


class coinpayments
{
    var $code, $title, $public_title, $description, $enabled, $api;

    public function __construct()
    {
        global $order;

        $this->signature = 'coinpayments|coinpayments|2.0';

        $this->code = 'coinpayments';
        $this->title = MODULE_PAYMENT_COINPAYMENTS_TEXT_TITLE;
        $this->public_title = MODULE_PAYMENT_COINPAYMENTS_TEXT_PUBLIC_TITLE;
        $coinpayments_link = sprintf(
            '<a href="%s" target="_blank" style="text-decoration: underline; font-weight: bold;" title="CoinPayments.net">CoinPayments.net</a>',
            'https://alpha.coinpayments.net/'
        );
        $coin_description = 'Pay with Bitcoin, Litecoin, or other altcoins via ';
        $this->description = sprintf('%s<br/>%s', $coin_description, $coinpayments_link);
        $this->sort_order = defined('MODULE_PAYMENT_COINPAYMENTS_SORT_ORDER') ? MODULE_PAYMENT_COINPAYMENTS_SORT_ORDER : 0;

        if (defined('MODULE_PAYMENT_COINPAYMENTS_STATUS')) {
            $this->enabled = ((MODULE_PAYMENT_COINPAYMENTS_STATUS == 'True') ? true : false);
        } else {
            $this->enabled = false;
        }

        if (defined('MODULE_PAYMENT_COINPAYMENTS_SORT_ORDER') && (int)MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID;
        }


        $api = substr(__FILE__, 0, -4) . '/coinpayments_api.php';
        if (!is_file($api)) {
            throw new Exception('Main class not found! Check files.');
        }

        require_once $api;

        $this->api = new coinpayments_api();

        $this->form_action_url = sprintf('%s/%s/', coinpayments_api::CHECKOUT_URL, coinpayments_api::API_CHECKOUT_ACTION);

        try {
            if (
                $this->enabled &&
                defined('MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID') &&
                defined('MODULE_PAYMENT_COINPAYMENTS_WEBHOOK') &&
                defined('MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET') &&
                !empty(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID) &&
                MODULE_PAYMENT_COINPAYMENTS_WEBHOOK == 'Yes' &&
                !empty(MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET)
            ) {

                $webhooks_list = $this->api->getWebhooksList(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID, MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET);

                if (!empty($webhooks_list)) {
                    $webhooks_urls_list = array();
                    if (!empty($webhooks_list['items'])) {
                        $webhooks_urls_list = array_map(function ($webHook) {
                            return $webHook['notificationsUrl'];
                        }, $webhooks_list['items']);
                    }

                    if (!in_array($this->api->getNotificationUrl(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID, coinpayments_api::CANCELLED_EVENT), $webhooks_urls_list) || !in_array($this->api->getNotificationUrl(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID,coinpayments_api::PAID_EVENT), $webhooks_urls_list)) {
                        $this->api->createWebHook(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID, MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET, coinpayments_api::CANCELLED_EVENT);
                        $this->api->createWebHook(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID, MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET, coinpayments_api::PAID_EVENT);
                    }
                }
            }
        } catch (Exception $e) {
        }
    }

    public function update_status()
    {
        global $order;

        if (($this->enabled == true) && ((int)MODULE_PAYMENT_COINPAYMENTS_ZONE > 0)) {
            $check_flag = false;
            $check_query = tep_db_query("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_COINPAYMENTS_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
            while ($check = tep_db_fetch_array($check_query)) {
                if ($check['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check['zone_id'] == $order->delivery['zone_id']) {
                    $check_flag = true;
                    break;
                }
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }

    }

    public function javascript_validation()
    {

        return false;
    }

    public function selection()
    {
        return array('id' => $this->code,
            'module' => $this->description);
    }

    public function pre_confirmation_check()
    {
        return false;
    }

    function checkout_initialization_method() {
        global $order, $cart;
        $currency_code = $order->info['currency'];
        $coin_currency = $this->api->getCoinCurrency($currency_code);
        $items = array();
        foreach ($cart->get_products() as $product) {
            array_push($items, array("name"=>$product['name'], "quantity"=>array("value"=>$product['quantity'], "type"=>"1"), "amount"=>strval(number_format($product['final_price'] * $product['quantity'], $coin_currency['decimalPlaces'], '', ''))));
        }
        $items = json_encode($items);
        $total_price =  number_format($cart->total, $coin_currency['decimalPlaces'], '', '');
        $string = '<script src="https://checkout.coinpayments.net/static/js/checkout.js"></script>' . '<div id = "cps-button-container-1" style="text-align: right"></div>' .
            '<script language="javascript">' .
            'CoinPayments.Button({' .
            'style: { color: "blue", width: 180 },'.
            'createInvoice: async function (data, actions) {' .
            'const invoiceId = await actions.invoice.create({' .
            'clientId: "'. MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID .'",' .
            'currencyId: "'. $coin_currency['id'] .'",' .
            'items: ' . $items .', ' .
            'amount: {' .
            'value: "' . $total_price .'"}, ' .
            'requireBuyerNameAndEmail: true, ' .
            'buyerDataCollectionMessage: "Your email and name is collected for customer service purposes such as order fulfillment."}); 
                        return invoiceId;}}).render("cps-button-container-1");</script>';
        return $string;
    }

    public function confirmation()
    {
        global $cartID, $cart_CoinPayments_Standard_ID, $customer_id, $languages_id, $order, $order_total_modules, $currency;

        if (tep_session_is_registered('cartID')) {
            $insert_order = false;

            if (tep_session_is_registered('cart_CoinPayments_Standard_ID')) {
                $order_id = substr($cart_CoinPayments_Standard_ID, strpos($cart_CoinPayments_Standard_ID, '-') + 1);

                $curr_check = tep_db_query("select currency from " . TABLE_ORDERS . " where orders_id = '" . (int)$order_id . "'");
                $curr = tep_db_fetch_array($curr_check);
                if (($curr['currency'] != $order->info['currency']) || ($cartID != substr($cart_CoinPayments_Standard_ID, 0, strlen($cartID)))) {
                    $check_query = tep_db_query('select orders_id from ' . TABLE_ORDERS_STATUS_HISTORY . ' where orders_id = "' . (int)$order_id . '" limit 1');
                    if (tep_db_num_rows($check_query) < 1) {
                        tep_db_query('delete from ' . TABLE_ORDERS . ' where orders_id = "' . (int)$order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_TOTAL . ' where orders_id = "' . (int)$order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_STATUS_HISTORY . ' where orders_id = "' . (int)$order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_PRODUCTS . ' where orders_id = "' . (int)$order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_PRODUCTS_ATTRIBUTES . ' where orders_id = "' . (int)$order_id . '"');
                        tep_db_query('delete from ' . TABLE_ORDERS_PRODUCTS_DOWNLOAD . ' where orders_id = "' . (int)$order_id . '"');
                    }

                    $insert_order = true;
                }
            } else {
                $insert_order = true;
            }

            if ($insert_order == true) {
                $order_totals = array();
                if (is_array($order_total_modules->modules)) {
                    reset($order_total_modules->modules);
                    while (list(, $value) = each($order_total_modules->modules)) {
                        $class = substr($value, 0, strrpos($value, '.'));
                        if ($GLOBALS[$class]->enabled) {
                            for ($i = 0, $n = sizeof($GLOBALS[$class]->output); $i < $n; $i++) {
                                if (tep_not_null($GLOBALS[$class]->output[$i]['title']) && tep_not_null($GLOBALS[$class]->output[$i]['text'])) {
                                    $order_totals[] = array('code' => $GLOBALS[$class]->code,
                                        'title' => $GLOBALS[$class]->output[$i]['title'],
                                        'text' => $GLOBALS[$class]->output[$i]['text'],
                                        'value' => $GLOBALS[$class]->output[$i]['value'],
                                        'sort_order' => $GLOBALS[$class]->sort_order);
                                }
                            }
                        }
                    }
                }

                $sql_data_array = array('customers_id' => $customer_id,
                    'customers_name' => $order->customer['firstname'] . ' ' . $order->customer['lastname'],
                    'customers_company' => $order->customer['company'],
                    'customers_street_address' => $order->customer['street_address'],
                    'customers_suburb' => $order->customer['suburb'],
                    'customers_city' => $order->customer['city'],
                    'customers_postcode' => $order->customer['postcode'],
                    'customers_state' => $order->customer['state'],
                    'customers_country' => $order->customer['country']['title'],
                    'customers_telephone' => $order->customer['telephone'],
                    'customers_email_address' => $order->customer['email_address'],
                    'customers_address_format_id' => $order->customer['format_id'],
                    'delivery_name' => $order->delivery['firstname'] . ' ' . $order->delivery['lastname'],
                    'delivery_company' => $order->delivery['company'],
                    'delivery_street_address' => $order->delivery['street_address'],
                    'delivery_suburb' => $order->delivery['suburb'],
                    'delivery_city' => $order->delivery['city'],
                    'delivery_postcode' => $order->delivery['postcode'],
                    'delivery_state' => $order->delivery['state'],
                    'delivery_country' => $order->delivery['country']['title'],
                    'delivery_address_format_id' => $order->delivery['format_id'],
                    'billing_name' => $order->billing['firstname'] . ' ' . $order->billing['lastname'],
                    'billing_company' => $order->billing['company'],
                    'billing_street_address' => $order->billing['street_address'],
                    'billing_suburb' => $order->billing['suburb'],
                    'billing_city' => $order->billing['city'],
                    'billing_postcode' => $order->billing['postcode'],
                    'billing_state' => $order->billing['state'],
                    'billing_country' => $order->billing['country']['title'],
                    'billing_address_format_id' => $order->billing['format_id'],
                    'payment_method' => $order->info['payment_method'],
                    'cc_type' => $order->info['cc_type'],
                    'cc_owner' => $order->info['cc_owner'],
                    'cc_number' => $order->info['cc_number'],
                    'cc_expires' => $order->info['cc_expires'],
                    'date_purchased' => 'now()',
                    'orders_status' => $order->info['order_status'],
                    'currency' => $order->info['currency'],
                    'currency_value' => $order->info['currency_value']);

                tep_db_perform(TABLE_ORDERS, $sql_data_array);

                $insert_id = tep_db_insert_id();

                for ($i = 0, $n = sizeof($order_totals); $i < $n; $i++) {
                    $sql_data_array = array('orders_id' => $insert_id,
                        'title' => $order_totals[$i]['title'],
                        'text' => $order_totals[$i]['text'],
                        'value' => $order_totals[$i]['value'],
                        'class' => $order_totals[$i]['code'],
                        'sort_order' => $order_totals[$i]['sort_order']);

                    tep_db_perform(TABLE_ORDERS_TOTAL, $sql_data_array);
                }

                for ($i = 0, $n = sizeof($order->products); $i < $n; $i++) {
                    $sql_data_array = array('orders_id' => $insert_id,
                        'products_id' => tep_get_prid($order->products[$i]['id']),
                        'products_model' => $order->products[$i]['model'],
                        'products_name' => $order->products[$i]['name'],
                        'products_price' => $order->products[$i]['price'],
                        'final_price' => $order->products[$i]['final_price'],
                        'products_tax' => $order->products[$i]['tax'],
                        'products_quantity' => $order->products[$i]['qty']);

                    tep_db_perform(TABLE_ORDERS_PRODUCTS, $sql_data_array);

                    $order_products_id = tep_db_insert_id();

                    $attributes_exist = '0';
                    if (isset($order->products[$i]['attributes'])) {
                        $attributes_exist = '1';
                        for ($j = 0, $n2 = sizeof($order->products[$i]['attributes']); $j < $n2; $j++) {
                            if (DOWNLOAD_ENABLED == 'true') {
                                $attributes_query = "select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix, pad.products_attributes_maxdays, pad.products_attributes_maxcount , pad.products_attributes_filename
                                       from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa
                                       left join " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
                                       on pa.products_attributes_id=pad.products_attributes_id
                                       where pa.products_id = '" . $order->products[$i]['id'] . "'
                                       and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "'
                                       and pa.options_id = popt.products_options_id
                                       and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "'
                                       and pa.options_values_id = poval.products_options_values_id
                                       and popt.language_id = '" . $languages_id . "'
                                       and poval.language_id = '" . $languages_id . "'";
                                $attributes = tep_db_query($attributes_query);
                            } else {
                                $attributes = tep_db_query("select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa where pa.products_id = '" . $order->products[$i]['id'] . "' and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "' and pa.options_id = popt.products_options_id and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "' and pa.options_values_id = poval.products_options_values_id and popt.language_id = '" . $languages_id . "' and poval.language_id = '" . $languages_id . "'");
                            }
                            $attributes_values = tep_db_fetch_array($attributes);

                            $sql_data_array = array('orders_id' => $insert_id,
                                'orders_products_id' => $order_products_id,
                                'products_options' => $attributes_values['products_options_name'],
                                'products_options_values' => $attributes_values['products_options_values_name'],
                                'options_values_price' => $attributes_values['options_values_price'],
                                'price_prefix' => $attributes_values['price_prefix']);

                            tep_db_perform(TABLE_ORDERS_PRODUCTS_ATTRIBUTES, $sql_data_array);

                            if ((DOWNLOAD_ENABLED == 'true') && isset($attributes_values['products_attributes_filename']) && tep_not_null($attributes_values['products_attributes_filename'])) {
                                $sql_data_array = array('orders_id' => $insert_id,
                                    'orders_products_id' => $order_products_id,
                                    'orders_products_filename' => $attributes_values['products_attributes_filename'],
                                    'download_maxdays' => $attributes_values['products_attributes_maxdays'],
                                    'download_count' => $attributes_values['products_attributes_maxcount']);

                                tep_db_perform(TABLE_ORDERS_PRODUCTS_DOWNLOAD, $sql_data_array);
                            }
                        }
                    }
                }

                $cart_CoinPayments_Standard_ID = $cartID . '-' . $insert_id;
                tep_session_register('cart_CoinPayments_Standard_ID');
            }
        }


        return false;
    }

    public function process_button()
    {
        global $order, $cartID, $cart_CoinPayments_Standard_ID;


        $process_button_string = '';

        $client_id = MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID;
        $client_secret = MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET;
        $order_id = substr($cart_CoinPayments_Standard_ID, strlen($cartID) + 1);
        $invoice_id = sprintf('%s|%s', md5(tep_href_link('index.php', '', 'SSL', false, false)), $order_id);

        try {

            $currency_code = $order->info['currency'];
            $coin_currency = $this->api->getCoinCurrency($currency_code);

            $amount = number_format($order->info['total'], $coin_currency['decimalPlaces'], '', '');;
            $display_value = $order->info['total'];

            $invoice_params = array(
                'invoice_id' => $invoice_id,
                'currency_id' => $coin_currency['id'],
                'amount' => $amount,
                'display_value' => $display_value,
                'billing_data' => $order->billing,
                'notes_link' => sprintf(
                    "%s|Store name: %s|Order #%s",
                    tep_href_link('admin/orders.php' . "?" . 'oID=' . $order_id . '&action=edit', '', 'SSL', false, false),
                    STORE_NAME,
                    $order_id),
            );

            if (MODULE_PAYMENT_COINPAYMENTS_WEBHOOK == 'Yes') {
                $resp = $this->api->createMerchantInvoice($client_id, $client_secret, $invoice_params);
                $invoice = array_shift($resp['invoices']);
            } else {
                $invoice = $this->api->createSimpleInvoice($client_id, $invoice_params);
            }

            $parameters = array(
                'invoice-id' => $invoice['id'],
                'success-url' => tep_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL'),
                'cancel-url' => tep_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL'),
            );

            while (list($key, $value) = each($parameters)) {
                $process_button_string .= tep_draw_hidden_field($key, $value);
            }
        } catch (Exception $e) {

        }

        $js = <<<EOD
<script type="text/javascript">
    $(function() {
        $('form[name="checkout_confirmation"]').submit(function() {
            $(this).attr('method', 'get');
        });
    });
</script>
EOD;

        return $process_button_string . $js;
    }

    public function before_process()
    {
        tep_session_unregister('sendto');
        tep_session_unregister('billto');
        tep_session_unregister('shipping');
        tep_session_unregister('payment');
        tep_session_unregister('comments');

        tep_session_unregister('cart_CoinPayments_Standard_ID');


        tep_redirect(tep_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL'));
        return false;
    }

    public function after_process()
    {
        return false;
    }

    public function check()
    {
        return defined('MODULE_PAYMENT_COINPAYMENTS_STATUS');
    }

    public function install($parameter = null)
    {

        $params = $this->getParams();

        if (isset($parameter)) {
            if (isset($params[$parameter])) {
                $params = array($parameter => $params[$parameter]);
            } else {
                $params = array();
            }
        }

        foreach ($params as $key => $data) {
            $sql_data_array = array('configuration_title' => $data['title'],
                'configuration_key' => $key,
                'configuration_value' => (isset($data['value']) ? $data['value'] : ''),
                'configuration_description' => $data['desc'],
                'configuration_group_id' => '6',
                'sort_order' => '0',
                'date_added' => 'now()');

            if (isset($data['set_func'])) {
                $sql_data_array['set_function'] = $data['set_func'];
            }

            if (isset($data['use_func'])) {
                $sql_data_array['use_function'] = $data['use_func'];
            }

            tep_db_perform(TABLE_CONFIGURATION, $sql_data_array);
        }
    }

    public function remove()
    {
        tep_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    public function keys()
    {
        return array(
            'MODULE_PAYMENT_COINPAYMENTS_STATUS',
            'MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID',
            'MODULE_PAYMENT_COINPAYMENTS_WEBHOOK',
            'MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET',
            'MODULE_PAYMENT_COINPAYMENTS_ZONE',
            'MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID',
            'MODULE_PAYMENT_COINPAYMENTS_ORDER_STATUS_ID',
            'MODULE_PAYMENT_COINPAYMENTS_SORT_ORDER',
            'MODULE_PAYMENT_COINPAYMENTS_DEBUG_EMAIL',
        );
    }

    public function getParams()
    {

        $prep_status_id = $this->install_status('Preparing [CoinPayments.net]');
        $complete_status_id = $this->install_status('Complete [CoinPayments.net]');

        $params = array(
            'MODULE_PAYMENT_COINPAYMENTS_STATUS' => array(
                'title' => 'Enable CoinPayments.net Payments',
                'value' => 'True',
                'desc' => 'Do you want to accept CoinPayments.net payments?',
                'set_func' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID' => array(
                'title' => 'Client ID',
                'value' => '',
                'desc' => 'Your Coinpayments.net Client ID (You can find it on the My Account page)',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_WEBHOOK' => array(
                'title' => 'Enable CoinPayments.net Webhooks',
                'value' => 'No  ',
                'desc' => 'Do you want to accept CoinPayments.net notifications?',
                'set_func' => 'tep_cfg_pull_down_coin_webhook_classes(',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET' => array(
                'title' => 'Client Secret',
                'value' => '',
                'desc' => 'Your Client Secret (Set on the Edit Settings page on CoinPayments.net)',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_ZONE' => array(
                'title' => 'Payment Zone',
                'value' => '0',
                'desc' => 'If a zone is selected, only enable this payment method for that zone.',
                'use_func' => 'tep_get_zone_class_title',
                'set_func' => 'tep_cfg_pull_down_zone_classes(',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID' => array(
                'title' => 'Set Preparing Order Status',
                'value' => $prep_status_id,
                'desc' => 'Set the status of prepared orders made with this payment module to this value',
                'set_func' => 'tep_cfg_pull_down_order_statuses(',
                'use_func' => 'tep_get_order_status_name',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_ORDER_STATUS_ID' => array(
                'title' => 'Set Completed Order Status',
                'value' => $complete_status_id,
                'desc' => 'Set the status of orders made with this payment module to this value',
                'set_func' => 'tep_cfg_pull_down_order_statuses(',
                'use_func' => 'tep_get_order_status_name',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_SORT_ORDER' => array(
                'title' => 'Sort order of display.',
                'value' => '0',
                'desc' => 'Sort order of display. Lowest is displayed first.',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_DEBUG_EMAIL' => array(
                'title' => 'Debug E-Mail Address',
                'value' => '',
                'desc' => 'All parameters of an Invalid IPN notification will be sent to this email address if one is entered.',
            ),
        );

        return $params;
    }

    protected function install_status($status_title)
    {

        $check_query = tep_db_query("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_name = '" . $status_title . "' limit 1");
        if (tep_db_num_rows($check_query) < 1) {
            $status_query = tep_db_query("select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS);
            $status = tep_db_fetch_array($status_query);

            $status_id = $status['status_id'] + 1;

            $languages = tep_get_languages();

            foreach ($languages as $lang) {
                tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name) values ('" . $status_id . "', '" . $lang['id'] . "', '" . $status_title . "')");
            }

            $flags_query = tep_db_query("describe " . TABLE_ORDERS_STATUS . " public_flag");
            if (tep_db_num_rows($flags_query) == 1) {
                tep_db_query("update " . TABLE_ORDERS_STATUS . " set public_flag = 0 and downloads_flag = 0 where orders_status_id = '" . $status_id . "'");
            }
        } else {
            $check = tep_db_fetch_array($check_query);

            $status_id = $check['orders_status_id'];
        }


        return $status_id;
    }

    protected function format_raw($number, $currency_code = '', $currency_value = '')
    {
        global $currencies, $currency;

        if (empty($currency_code) || !$this->is_set($currency_code)) {
            $currency_code = $currency;
        }

        if (empty($currency_value) || !is_numeric($currency_value)) {
            $currency_value = $currencies->currencies[$currency_code]['value'];
        }

        return number_format(tep_round($number * $currency_value, $currencies->currencies[$currency_code]['decimal_places']), $currencies->currencies[$currency_code]['decimal_places'], '.', '');
    }
}

function tep_cfg_pull_down_coin_webhook_classes($webhooks, $key = '')
{
    $name = (($key) ? 'configuration[' . $key . ']' : 'configuration_value');
    $list_array = array(
        array(
            'id' => 'Yes',
            'text' => 'Enabled',
        ),
        array(
            'id' => 'No',
            'text' => 'Disabled',
        ),
    );

    return tep_draw_pull_down_menu($name, $list_array, $webhooks);
}

?>