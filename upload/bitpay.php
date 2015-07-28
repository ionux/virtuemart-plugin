<?php
/**
 * @plugin VmPayment - BitPay
 * @Website : https://bitpay.com
 * @package VirtueMart
 * @subpackage Plugins - payment
 * @author Integrations Team
 * @copyright 2015 BitPay
 */

/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2011-2015 BitPay
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

if (!defined('_JEXEC')) {
    bplog('JEXEC not defined');
    die('Restricted access');
}

if(!defined('JPATH_BASE')) {
    bplog('JPATH_BASE not defined');
    die('Restricted access');
}

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

if (!class_exists('VmConfig')) {
    require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');
}

if (!class_exists('ShopFunctions')) {
    require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'shopfunctions.php');
}

if (!class_exists('shopFunctionsF')) {
    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
}

if (!class_exists('VirtueMartModelOrders')) {
    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
}

if (!class_exists('VirtueMartCart')) {
    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
}

function bplog($contents) {
    if (isset($contents)) {
        if (is_resource($contents)) {
            return error_log(serialize($contents));
        } else {
            return error_log(var_export($contents, true));
        }
    } else {
        return false;
    }
}

class plgVmPaymentBitPay extends vmPSPlugin
{
    public static $_this = false;

    const BPDEBUG = 1;

    /**
     * @param $subject
     * @param $config
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable   = true;
        $this->_tablepkey  = 'id';
		$this->_tableId    = 'id';
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush        = $this->getVarsToPush();

        if (BPDEBUG) {
            bplog('In __construct()...');
            bplog($varsToPush);
        }

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     *
     * @return
     */
    public function getVmPluginCreateTableSQL()
    {
        if (BPDEBUG) {
            bplog('In getVmPluginCreateTableSQL()...');
        }

        return $this->createTableSQL('Payment BitPay Table');
    }

    /**
     * Fields to create the payment table
     *
     * @return array
     */
    public function getTableSQLFields()
    {
        if (BPDEBUG) {
            bplog('In getTableSQLFields()...');
        }

        $SQLfields = array(
            'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED DEFAULT NULL',
            'order_number'                => 'char(64) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name'                => 'varchar(5000) DEFAULT NULL',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3) DEFAULT NULL',
        );

        return $SQLfields;
    }

    /**
     * Display stored payment data for an order
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_payment_id
     *
     * @return
     */
    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (BPDEBUG) {
            bplog('In plgVmOnShowOrderBEPayment()...');
            bplog('virtuemart_order_id: ' . $virtuemart_order_id);
            bplog('virtuemart_payment_id: ' . $virtuemart_payment_id);
        }

        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            bplog('In plgVmOnShowOrderBEPayment: Could not retrieve data for this order!');
            JError::raiseWarning(500, 'Could not retrieve data for this order!');
            return NULL;
        }

        $html  = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('BITPAY_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('BITPAY_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";

        if (BPDEBUG) {
            bplog('HTML: ' . $html);
        }

        return $html;
    }

    /**
     * @param VirtueMartCart $cart
     * @param                $method
     * @param array          $cart_prices
     *
     * @return
     */
    public function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        $cost_percent_total     = str_ireplace('$', '', $method->cost_percent_total);
        $cost_per_transaction   = str_ireplace('$', '', $method->cost_per_transaction);
        $cart_prices_salesPrice = str_ireplace('$', '', $cart_prices['salesPrice']);

        if (BPDEBUG) {
            bplog('cost_percent_total: ' . $cost_percent_total);
            bplog('cost_per_transaction: ' . $cost_per_transaction);
            bplog('cart_prices_salesPrice: ' . $cart_prices_salesPrice);
            bplog('getCosts() returning: ' . ($cost_per_transaction + ($cart_prices_salesPrice * $cost_percent_total * 0.01)));
        }

        return ($cost_per_transaction + ($cart_prices_salesPrice * $cost_percent_total * 0.01));
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param $cart
     * @param $method
     * @param $cart_prices
     *
     * @return boolean
     */
    public protected function checkConditions($cart, $method, $cart_prices)
    {
        $this->convert($method);

        $address     = (($cart->ST == 0) ? $cart->BT : $cart->ST);
        $amount      = $cart_prices['salesPrice'];
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount OR ($method->min_amount <= $amount AND ($method->max_amount == 0)));

        if (BPDEBUG) {
            bplog('In checkConditions()...');
            bplog('address: ' . $address);
            bplog('amount: ' . $amount);
            bplog('amount_cond: ' . $amount_cond);
        }

        if (!$amount_cond) {
            bplog('In checkConditions(): Returning false because !amount_cond.');
            return false;
        }

        $countries = array();

        if (!empty($method->countries)) {
            if (BPDEBUG) {
                bplog('In checkConditions(): !empty(method->countries)');
            }

            if (!is_array($method->countries)) {
                if (BPDEBUG) {
                    bplog('In checkConditions(): !is_array(method->countries), so countries[0] = method->countries');
                }

                $countries[0] = $method->countries;
            } else {
                if (BPDEBUG) {
                    bplog('In checkConditions(): empty(method->countries), so countries = method->countries');
                }

                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            if (BPDEBUG) {
                bplog('In checkConditions(): !is_array(address), so address['virtuemart_country_id'] = 0');
            }

            $address                          = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            if (BPDEBUG) {
                bplog('In checkConditions(): !isset(address['virtuemart_country_id']), so address['virtuemart_country_id'] = 0');
            }

            $address['virtuemart_country_id'] = 0;
        }

        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries)) {
            if (BPDEBUG) {
                bplog('In checkConditions(): returning true');
            }

            return true;
        }

        if (BPDEBUG) {
            bplog('In checkConditions(): returning false');
        }

        return false;
    }

    /**
     * @param $method
     */
    public function convert($method)
    {
        $method->min_amount = (float)$method->min_amount;
        $method->max_amount = (float)$method->max_amount;

        if (BPDEBUG) {
            bplog('In convert(): ');
            bplog('method->min_amount = ' . $method->min_amount);
            bplog('method->max_amount = ' . $method->max_amount);
        }
    }

    /*
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @param $jplugin_id
     *
     * @return
     */
    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        if (BPDEBUG) {
            bplog('In plgVmOnStoreInstallPaymentPluginTable(): ');
            bplog('jplugin_id = ' . $jplugin_id);
        }

        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /*
     * plgVmonSelectedCalculatePricePayment
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     *
     * @param VirtueMartCart $cart
     * @param array          $cart_prices
     * @param                $cart_prices_name
     *
     * @return
     */

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        if (BPDEBUG) {
            bplog('In plgVmonSelectedCalculatePricePayment()...');
        }

        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     *
     * @return
     */
    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (BPDEBUG) {
            bplog('In plgVmgetPaymentCurrency()...');
        }

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            if (BPDEBUG) {
                bplog('In plgVmgetPaymentCurrency(): ');
                bplog('!(method = this->getVmPluginMethod(virtuemart_paymentmethod_id))');
                bplog('Returning null...');
            }

            return NULL; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            if (BPDEBUG) {
                bplog('In plgVmgetPaymentCurrency(): ');
                bplog('!this->selectedThisElement(method->payment_element)');
                bplog('Returning false...');
            }

            return false;
        }

        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;

        if (BPDEBUG) {
            bplog('In plgVmgetPaymentCurrency(): ');
            bplog('paymentCurrencyId = ' . $paymentCurrencyId);
            bplog('Returning...');
        }

        return;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart $cart
     * @param array          $cart_prices
     * @param                $paymentCounter
     *
     * @return
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        if (BPDEBUG) {
            bplog('In plgVmOnCheckAutomaticSelectedPayment()...');
        }

        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_order_id
     * @param $virtuamart_paymentmethod_id
     * @param $payment_name
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        if (BPDEBUG) {
            bplog('In plgVmOnShowOrderFEPayment()...');
        }

        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     *
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     */
    public function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        if (BPDEBUG) {
            bplog('In plgVmonShowOrderPrintPayment()...');
        }

        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * @param $name
     * @param $id
     * @param $data
     *
     * @return
     */
    public function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        if (BPDEBUG) {
            bplog('In plgVmDeclarePluginParamsPayment()...');
        }

        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    /**
     * @param $name
     * @param $id
     * @param $table
     *
     * @return
     */
    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        if (BPDEBUG) {
            bplog('In plgVmSetOnTablePluginParamsPayment()...');
        }

        return $this->setOnTablePluginParams($name, $id, $table);
    }


    /**
     * This event is fired by Offline Payment. It can be used to validate the payment data as entered by the user.
     *
     * @return
     */
    public function plgVmOnPaymentNotification()
    {
        if (BPDEBUG) {
            bplog('In plgVmSetOnTablePluginParamsPayment()...');
        }

        $bitpay_data = file_get_contents("php://input");
        $bitpay_data = json_decode($bitpay_data, true);

        if (!isset($bitpay_data['id']) || !isset($bitpay_data['posData'])) {
            bplog('no invoice in data');
            return NULL;
        }

        $order_number = $bitpay_data['posData']['id_order'];

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number)))
        {
            bplog('order not found '.$order_number);
            return NULL;
        }

        $modelOrder = VmModel::getModel ('orders');
        $order      = $modelOrder->getOrder($virtuemart_order_id);

        if (!$order) {
            bplog('order could not be loaded '.$virtuemart_order_id);
            return NULL;
        }

        $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);

        if ($bitpay_data['posData']['hash'] != crypt($order_number, $method->merchant_apikey)) {
            bplog('api key invalid for order '.$order_number);
            return NULL;
        }

        // Call BitPay
        $curl   = curl_init('https://bitpay.com/api/invoice/'.$bitpay_data['id']);
        $length = 0;

        $header = array(
            'Content-Type: application/json',
            "Content-Length: $length",
            "Authorization: Basic " . base64_encode($method->merchant_apikey),
            'X-BitPay-Plugin-Info: virtuemart033114',
        );

        curl_setopt($curl, CURLOPT_PORT, 443);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1); // verify certificate
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); // check existence of CN and verify that it matches hostname
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

        $responseString = curl_exec($curl);

        if($responseString == false)
        {
            return NULL;
        }
        else
        {
            $bitpay_data = json_decode($responseString, true);
        }
        curl_close($curl);

        $this->logInfo ('IPN ' . implode (' / ', $bitpay_data), 'message');

        if ($bitpay_data['status'] != 'confirmed' and $bitpay_data['status'] != 'complete')
        {
            return NULL; // not the status we're looking for
        }

        $order['order_status'] = 'C'; // move to admin method option?
        $modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
    }

    /**
     * @param $html
     *
     * @return bool|null|string
     */
    public function plgVmOnPaymentResponseReceived(&$html)
    {
        if (BPDEBUG) {
            bplog('In plgVmOnPaymentResponseReceived()...');
        }

        // the payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = JRequest::getInt ('pm', 0);
        $order_number                = JRequest::getString ('on', 0);
        $vendorId                    = 0;

        if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id)))
        {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement ($method->payment_element))
        {
            return NULL;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number)))
        {
            return NULL;
        }
        if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id)))
        {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }
        $payment_name = $this->renderPluginName ($method);
        $html         = $this->_getPaymentResponseHtml ($paymentTable, $payment_name);

        //We delete the old stuff
        // get the correct cart / session
        $cart = VirtueMartCart::getCart ();
        $cart->emptyCart ();
        return TRUE;
    }

    /**
     * This shows the plugin for choosing in the payment list of the checkout process.
     *
     * @param VirtueMartCart $cart
     * @param integer        $selected
     * @param                $htmlIn
     *
     * @return
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        if (BPDEBUG) {
            bplog('In plgVmDisplayListFEPayment()...');
        }

        $session = JFactory::getSession ();
        $errors  = $session->get ('errorMessages', 0, 'vm');

        if($errors != "")
        {
            $errors = unserialize($errors);
            $session->set ('errorMessages', "", 'vm');
        }
        else
        {
            $errors = array();
        }

        return $this->displayListFE ($cart, $selected, $htmlIn);
    }

    /**
     * getGMTTimeStamp:
     *
     * this function creates a timestamp formatted as per requirement in the
     * documentation
     *
     * @return string The formatted timestamp
     */
    public function getGMTTimeStamp()
    {
        if (BPDEBUG) {
            bplog('In getGMTTimeStamp()...');
        }

        /* Format: YYYYDDMMHHNNSSKKK000sOOO
            YYYY is a 4-digit year
            DD is a 2-digit zero-padded day of month
            MM is a 2-digit zero-padded month of year (January = 01)
            HH is a 2-digit zero-padded hour of day in 24-hour clock format (midnight =0)
            NN is a 2-digit zero-padded minute of hour
            SS is a 2-digit zero-padded second of minute
            KKK is a 3-digit zero-padded millisecond of second
            000 is a Static 0 characters, as BitPay does not store nanoseconds
            sOOO is a Time zone offset, where s is + or -, and OOO = minutes, from GMT.
         */
        $tz_minutes = date('Z') / 60;

        if ($tz_minutes >= 0)
        {
            $tz_minutes = '+' . sprintf("%03d",$tz_minutes); //Zero padding in-case $tz_minutes is 0
        }

        $stamp = date('YdmHis000000') . $tz_minutes; //In some locales, in some situations (i.e. Magento 1.4.0.1) some digits are missing. Added 5 zeroes and truncating to the required length. Terrible terrible hack.

        return $stamp;
    }

    /**
     * @param       $data
     * @param array $outputArray
     *
     * @return
     */
    private function makeXMLTree($data, &$outputArray = array())
    {
        if (BPDEBUG) {
            bplog('In makeXMLTree()...');
        }

        $parser = xml_parser_create();
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        $result = xml_parse_into_struct($parser, $data, $values, $tags);
        xml_parser_free($parser);
        if ($result == 0)
        {
            return false;
        }

        $hash_stack = array();
        foreach ($values as $key => $val)
        {
            switch ($val['type'])
            {
            case 'open':
                array_push($hash_stack, $val['tag']);
                break;
            case 'close':
                array_pop($hash_stack);
                break;
            case 'complete':
                array_push($hash_stack, $val['tag']);
                // ATTN, I really hope this is sanitized
                eval("\$outputArray['" . implode($hash_stack, "']['") . "'] = \"{$val['value']}\";");
                array_pop($hash_stack);
                break;
            }
        }

        return true;
    }

    /**
     * @param $cart
     * @param $order
     *
     * @return
     */
    public function plgVmConfirmedOrder($cart, $order)
    {
        if (BPDEBUG) {
            bplog('In plgVmConfirmedOrder()...');
        }

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $lang     = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $vendorId = 0;
        $html     = "";

        $lang->load($filename, JPATH_ADMINISTRATOR);

        $this->getPaymentCurrency($method, true);

        // END printing out HTML Form code (Payment Extra Info)
        $q  = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db = JFactory::getDBO();

        $db->setQuery($q);

        $currency_code_3        = $db->loadResult();
        $paymentCurrency        = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
        $cd                     = CurrencyDisplay::getInstance($cart->pricesCurrency);
        $usrBT                  = $order['details']['BT'];
        $usrST                  = (isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT'];

        $options['transactionSpeed'] = 'high';
        $options['currency']         = $currency_code_3;
        $options['notificationURL']  = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component');
        $options['redirectURL']      = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . JRequest::getInt('Itemid'));

        $options['posData']          = json_encode(
                                                   array(
                                                         'id_order' => $order['details']['BT']->order_number,
                                                         'hash' => crypt($order['details']['BT']->order_number, $method->merchant_apikey),
                                                        )
                                                   );

        $options['orderID']          = $order['details']['BT']->order_number;
        $options['price']            = $order['details']['BT']->order_total;

        // Call BitPay
        $response = $this->makeCurlCall();

        $this->logInfo ('invoice ' . implode (' / ', $response), 'message');

        if (isset($response['url'])) {
            header('Location: ' . $response['url']);
            exit;
        } else {
            bplog('curl error - no invoice url');
        }
    }

    /**
     * @param $virtualmart_order_id
     * @param $html
     */
    public function _handlePaymentCancel($virtuemart_order_id, $html)
    {
        if (BPDEBUG) {
            bplog('In _handlePaymentCancel()...');
        }

        $modelOrder = VmModel::getModel('orders');
        $modelOrder->remove(array('virtuemart_order_id' => $virtuemart_order_id));

        // error while processing the payment
        $mainframe = JFactory::getApplication();
        $mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&task=editpayment'), $html);
    }

    /**
     * Wrapper for cURL extension calls
     *
     * @param $post
     * @param $url
     * @param $opts
     */
    public function makeCurlCall($url, $post = null, $opts = array())
    {
        if (BPDEBUG) {
            bplog('In makeCurlCall()...');
        }

        $length = 0;

        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
            $length = strlen($post);

            $postOptions = array('orderID', 'itemDesc', 'itemCode', 'notificationEmail', 'notificationURL', 'redirectURL',
                'posData', 'price', 'currency', 'physical', 'fullNotifications', 'transactionSpeed', 'buyerName',
                'buyerAddress1', 'buyerAddress2', 'buyerCity', 'buyerState', 'buyerZip', 'buyerEmail', 'buyerPhone');

            foreach($postOptions as $o) {
                if (array_key_exists($o, $options)) {
                    $post[$o] = $options[$o];
                }
            }

            $post = json_encode($post);
        }

        //$curl   = curl_init('https://bitpay.com/api/invoice/'.$bitpay_data['id']);
        //$curl   = curl_init('https://bitpay.com/api/invoice/');

        if ($curl === false || is_resource($curl) === false) {
            bplog('In plgVmConfirmedOrder(): Could not initialize cURL resource!');
            die('There was an error connecting to BitPay. Please contact the store administrator to process your order.');
        }

        $header = array(
            'Content-Type: application/json',
            "Content-Length: $length",
            "Authorization: Basic " . base64_encode($method->merchant_apikey),
            'X-BitPay-Plugin-Info: virtuemart033114',
        );

        curl_setopt($curl, CURLOPT_PORT, 443);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

        $responseString = curl_exec($curl);

        $response = ($responseString == false) ? curl_error($curl): json_decode($responseString, true);

        curl_close($curl);
    }
}
