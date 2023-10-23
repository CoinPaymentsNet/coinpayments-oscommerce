<?php

namespace common\modules\orderPayment;

use common\classes\modules\ModulePayment;
use common\classes\modules\ModuleStatus;
use common\classes\modules\ModuleSortOrder;
use CoinPayments\ApiHelper;
use Exception;

class coinpayments extends ModulePayment
{
    var $code, $title, $description, $enabled;

    private $api;

    public $form_action_url;

    protected $doCheckoutInitializationOnInactive = true;

    protected $defaultTranslationArray = [
        'MODULE_PAYMENT_COINPAYMENTS_TEXT_TITLE' => 'CoinPayments.net Bitcoin/Litecoin/Other Payments',
        'MODULE_PAYMENT_COINPAYMENTS_TEXT_PUBLIC_TITLE' => 'CoinPayments - Bitcoin, Litecoin, and other cryptocurrencies',
        'MODULE_PAYMENT_COINPAYMENTS_TEXT_DESCRIPTION' => 'Pay with Bitcoin, Litecoin, or other altcoins via ',
        'MODULE_PAYMENT_COINPAYMENTS_ERROR' => 'There has been an error processing your payment',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->code = 'coinpayments';
        $this->title = MODULE_PAYMENT_COINPAYMENTS_TEXT_TITLE;

        $coinpayments_link = sprintf(
            '<a href="%s" target="_blank" style="text-decoration: underline; font-weight: bold;" title="CoinPayments.net">CoinPayments.net</a>',
            'https://alpha.coinpayments.net/'
        );

        $this->description = sprintf('%s<br/>%s', MODULE_PAYMENT_COINPAYMENTS_TEXT_DESCRIPTION, $coinpayments_link);
        if (!defined('MODULE_PAYMENT_COINPAYMENTS_STATUS')) {
            $this->enabled = false;
            return false;
        }

        $this->enabled = MODULE_PAYMENT_COINPAYMENTS_STATUS == 'True';
        $this->sort_order = MODULE_PAYMENT_COINPAYMENTS_SORT_ORDER;

        if ((int)MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID > 0) {
            $this->order_status = MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID;
        }


        require_once __DIR__ . '/lib/CoinPayments/ApiHelper.php';
        $this->api = new ApiHelper();

        $this->update_status();

        $this->form_action_url = sprintf('%s/%s/', ApiHelper::CHECKOUT_URL, ApiHelper::API_CHECKOUT_ACTION);
    }

    public function update_status()
    {
        if (($this->enabled == true) && ((int)MODULE_PAYMENT_COINPAYMENTS_ZONE > 0)) {
            $check_flag = false;
            $sqlTpl = "select zone_id from %s where geo_zone_id = '%s' and zone_country_id = '%s' order by zone_id";
            $check_query = tep_db_query(sprintf($sqlTpl, TABLE_ZONES_TO_GEO_ZONES, MODULE_PAYMENT_COINPAYMENTS_ZONE, $this->delivery['country']['id']));
            while ($check = tep_db_fetch_array($check_query)) {
                if ($check['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check['zone_id'] == $this->delivery['zone_id']) {
                    $check_flag = true;
                    break;
                }
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    public function selection() {
        return array('id' => $this->code, 'module' => $this->description);
    }

    public function checkout_initialization_method() {
        $cart = $this->manager->getCart();
        $order = $this->manager->getOrderInstance();
        $currency_code = $order->info['currency'];
        $coin_currency = $this->api->getCoinCurrency($currency_code);
        $currencyId = $coin_currency['id'];

        $items = array();
        foreach ($cart->get_products() as $product) {
            $items[] = array(
                "name" => $product['name'],
                "quantity" => array(
                    "value" => $product['quantity'],
                    "type" => "1"
                ),
                "amount" => number_format($product['final_price'] * $product['quantity'], $coin_currency['decimalPlaces'], '', '')
            );
        }

        $items = json_encode($items);
        $total_price =  number_format($cart->total, $coin_currency['decimalPlaces'], '', '');
        $clientId = MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID;
        $javaScript = <<< JS
            CoinPayments.Button({
                style: { color: "blue", width: 250 },
                createInvoice: async function (data, actions) {
                    const invoiceId = await actions.invoice.create({
                        clientId: '%s',
                        currencyId: '%s',
                        items: %s,
                        amount: {value: '%s'},
                        requireBuyerNameAndEmail: true,
                        buyerDataCollectionMessage: "Your email and name is collected for customer service purposes such as order fulfillment."
                    });

                    return invoiceId;
                }
            }).render("cps-button-container-1");
JS;

        return '<script src="https://checkout.starhermit.com/static/js/checkout.js"></script>' .
            '<div id = "cps-button-container-1" style="text-align: center"></div>' .
            '<script language="javascript">' . sprintf($javaScript, $clientId, $currencyId, $items, $total_price) . '</script>';
    }

    public function confirmation()
    {
        global $cart_CoinPayments_Standard_ID;

        $cartID = (string) $this->manager->get('cartID');
        $order = $this->manager->getOrderInstance();
        if ($cartID) {
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
                $orderId = $order->save_order();
                $order->save_totals();
                $order->save_products();

                $cart_CoinPayments_Standard_ID = $cartID . '-' . $orderId;
                tep_session_register('cart_CoinPayments_Standard_ID');
            }
        }


        return false;
    }

    public function process_button()
    {
        global $cart_CoinPayments_Standard_ID;

        $cartID = (string) $this->manager->get('cartID');

        $order = $this->manager->getOrderInstance();

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
                'email_address' => $order->customer["email_address"],
                'notes_link' => sprintf(
                    "%s|Store name: %s|Order #%s",
                    tep_href_link('admin/orders.php', sprintf('oID=%s&action=edit', $order_id), 'SSL', false, false),
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

            foreach ($parameters as $key => $value) {
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

    public function check($platform_id)
    {
        if(defined('MODULE_PAYMENT_COINPAYMENTS_STATUS')){
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

                        if (!in_array($this->api->getNotificationUrl(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID, ApiHelper::CANCELLED_EVENT), $webhooks_urls_list) || !in_array($this->api->getNotificationUrl(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID,coinpayments_api::PAID_EVENT), $webhooks_urls_list)) {
                            $this->api->createWebHook(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID, MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET, ApiHelper::CANCELLED_EVENT);
                            $this->api->createWebHook(MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID, MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET, ApiHelper::PAID_EVENT);
                        }
                    }
                }
            } catch (Exception $e) {
            }

            return true;
        }

        return false;
    }

    public function configure_keys()
    {

        $prep_status_id = $this->install_status('Preparing [CoinPayments.net]', 1);
        $complete_status_id = $this->install_status('Complete [CoinPayments.net]', 9);

        $params = array(
            'MODULE_PAYMENT_COINPAYMENTS_STATUS' => array(
                'title' => 'Enable CoinPayments.net Payments',
                'value' => 'True',
                'description' => 'Do you want to accept CoinPayments.net payments?',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_CLIENT_ID' => array(
                'title' => 'Client ID',
                'value' => '',
                'description' => 'Your Coinpayments.net Client ID (You can find it on the My Account page)',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_WEBHOOK' => array(
                'title' => 'Enable CoinPayments.net Webhooks',
                'value' => 'No  ',
                'description' => 'Do you want to accept CoinPayments.net notifications?',
                'set_function' => 'tep_cfg_pull_down_coin_webhook_classes(',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_CLIENT_SECRET' => array(
                'title' => 'Client Secret',
                'value' => '',
                'description' => 'Your Client Secret (Set on the Edit Settings page on CoinPayments.net)',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_ZONE' => array(
                'title' => 'Payment Zone',
                'value' => '0',
                'description' => 'If a zone is selected, only enable this payment method for that zone.',
                'use_function' => '\\common\\helpers\\Zones::get_zone_class_title',
                'set_function' => 'tep_cfg_pull_down_zone_classes(',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_PREPARE_ORDER_STATUS_ID' => array(
                'title' => 'Set Preparing Order Status',
                'value' => $prep_status_id,
                'description' => 'Set the status of prepared orders made with this payment module to this value',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_ORDER_STATUS_ID' => array(
                'title' => 'Set Completed Order Status',
                'value' => $complete_status_id,
                'description' => 'Set the status of orders made with this payment module to this value',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => '\\common\\helpers\\Order::get_order_status_name',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_SORT_ORDER' => array(
                'title' => 'Sort order of display.',
                'value' => '0',
                'description' => 'Sort order of display. Lowest is displayed first.',
            ),
            'MODULE_PAYMENT_COINPAYMENTS_DEBUG_EMAIL' => array(
                'title' => 'Debug E-Mail Address',
                'value' => '',
                'description' => 'All parameters of an Invalid IPN notification will be sent to this email address if one is entered.',
            ),
        );

        return $params;
    }

    protected function install_status($status_title, $groupId = 1)
    {

        $check_query = tep_db_query("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_name = '" . $status_title . "' limit 1");
        if (tep_db_num_rows($check_query) < 1) {
            $status_query = tep_db_query("select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS);
            $status = tep_db_fetch_array($status_query);

            $status_id = $status['status_id'] + 1;

            $languages = \common\helpers\Language::get_languages();

            foreach ($languages as $lang) {
                tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_groups_id, orders_status_id, language_id, orders_status_name) values ('" . $groupId . "', '" . $status_id . "', '" . $lang['id'] . "', '" . $status_title . "')");
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

    public function describe_status_key() {
        return new ModuleStatus('MODULE_PAYMENT_COINPAYMENTS_STATUS', 'True', 'False');
    }

    public function describe_sort_key() {
        return new ModuleSortOrder('MODULE_PAYMENT_COINPAYMENTS_SORT_ORDER');
    }

    public function isOnline() {
        return true;
    }

    public static function tep_cfg_pull_down_coin_webhook_classes($webhooks, $key = '')
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
}
