<?php

class MindArc_FatZebra_Helper_Api extends Mage_Core_Helper_Abstract {

    protected $_url = null;
    protected $_username = null;
    protected $_token = null;
    protected $_sandbox = null;
    protected $_testmode = null;

    const VERSION = "2.1.0";
    const RE_ANS = "/[^A-Z\d\-_',\.;:\s]*/i";
    const RE_AN = "/[^A-Z\d]/i";
    const RE_NUMBER = "/[^\d]/";

    function __construct() {
        $this->_username = Mage::getStoreConfig('payment/fatzebra/username', Mage::app()->getStore());
        $this->_token = Mage::getStoreConfig('payment/fatzebra/token', Mage::app()->getStore());
        $this->_sandbox = (boolean) Mage::getStoreConfig('payment/fatzebra/sandbox', Mage::app()->getStore());
        $this->_testmode = (boolean) Mage::getStoreConfig('payment/fatzebra/testmode', Mage::app()->getStore());
        $this->_url = $this->_testmode ? "https://gateway.sandbox.fatzebra.com.au" : "https://gateway.fatzebra.com.au";
    }

    function getDataSubmit($amount, $payment, $info) {
        $order = $payment->getOrder();
        $customer_ip = null;
        if (!is_null($_SERVER['REMOTE_ADDR'])) {
            $ips_ = explode(',', $_SERVER['REMOTE_ADDR']);
            $customer_ip = isset($ips_[0]) && $ips_[0] != '' ? $ips_[0] : null;
        }

        $forwarded_for = null;
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips_ = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $forwarded_for = isset($ips_[0]) && $ips_[0] != '' ? $ips_[0] : null;
        }
        $fraud_detected = (boolean) Mage::getStoreConfig('payment/fatzebra/fraud_detected', Mage::app()->getStore());
        if (isset($_POST['use_saved_card']) && $_POST['use_saved_card'] == 1) {
            $fatzebraCustomer = Mage::getModel('fatzebra/customer');
            $payload = array(
                "amount" => (int) ($amount * 100),
                "currency" => Mage::app()->getStore()->getBaseCurrencyCode(),
                "reference" => $order->getIncrementId(),
                "card_token" => $fatzebraCustomer->getCustomerToken(),
                "customer_ip" => empty($forwarded_for) ? $customer_ip : $forwarded_for
            );
        } else {
            $payload = array(
                "amount" => (int) ($amount * 100),
                "currency" => Mage::app()->getStore()->getBaseCurrencyCode(),
                "reference" => $order->getIncrementId(),
                "card_holder" => str_replace('&', '&amp;', $info->getCcOwner()),
                "card_number" => $info->getCcNumber(),
                "card_expiry" => $info->getCcExpMonth() . "/" . $info->getCcExpYear(),
                "cvv" => $info->getCcCid(),
                "customer_ip" => empty($forwarded_for) ? $customer_ip : $forwarded_for
            );
        }
        // If a token is being used replace the card details (which will be masked) with the token
        if (isset($_POST['payment']['cc_token']) && !empty($_POST['payment']['cc_token'])) {
            $payload['card_token'] = $_POST['payment']['cc_token'];
            unset($payload['card_number']);
            unset($payload['card_holder']);
            unset($payload['card_expiry']);
            // Keep the CVV if present.
            if (empty($payload['cvv'])) {
                unset($payload['cvv']);
            }
        }
        if ($order->getCustomerIsGuest() == 0) {
            $existing_customer = 'true';
            $customer_id = $order->getCustomerId();
            $customer = Mage::getModel('customer/customer')->load($customer_id);
            $customer_created_at = date('c', strtotime($customer->getCreatedAt()));

            if ($customer->getDob() != '') {
                $customer_dob = date('c', strtotime($customer->getDob()));
            } else {
                $customer_dob = '';
            }
        } else {
            $existing_customer = 'false';
            $customer_id = '';
            $customer_created_at = '';
            $customer_dob = '';
        }
        if ($fraud_detected) {
            $ordered_items = $order->getAllItems();
            foreach ($ordered_items as $item) {
                $item_name = $item->getName();
                $item_id = $item->getProductId();
                $_newProduct = Mage::getModel('catalog/product')->load($item_id);
                $item_sku = $_newProduct->getSku();
                $order_items[] = array("cost" => (float) $item->getPrice(),
                    "description" => $this->cleanForFraud($item_name, self::RE_ANS, 26),
                    "line_total" => (float) $item->getRowTotalInclTax(),
                    "product_code" => $this->cleanForFraud($item_id, self::RE_ANS, 12, 'left'),
                    "qty" => (int) $item->getQtyOrdered(),
                    "sku" => $this->cleanForFraud($item_sku, self::RE_ANS, 12, 'left'));
            }

            $billingaddress = $order->getBillingAddress();
            $shippingaddress = $order->getShippingAddress();
            $payload["fraud"] = array(
                "customer" =>
                array(
                    "address_1" => $this->cleanForFraud($billingaddress->getStreetFull(), self::RE_ANS, 30),
                    "city" => $this->cleanForFraud($billingaddress->getCity(), self::RE_ANS, 20),
                    "country" => $this->cleanForFraud(Mage::getModel('directory/country')->load($billingaddress->getCountry())->getIso3Code(), self::RE_AN, 3),
                    "created_at" => $customer_created_at,
                    "date_of_birth" => $customer_dob,
                    "email" => $order->getCustomerEmail(),
                    "existing_customer" => $existing_customer,
                    "first_name" => $this->cleanForFraud($order->getCustomerFirstname(), self::RE_ANS, 30),
                    "home_phone" => $this->cleanForFraud($billingaddress->getTelephone(), self::RE_NUMBER, 19),
                    "id" => $this->cleanForFraud($customer_id, self::RE_ANS, 16),
                    "last_name" => $this->cleanForFraud($order->getCustomerLastname(), self::RE_ANS, 30),
                    "post_code" => $this->cleanForFraud($billingaddress->getPostcode(), self::RE_AN, 9)
                ),
                "device_id" => isset($_POST['payment']['io_bb']) ? $_POST['payment']['io_bb'] : '',
                "items" => $order_items,
                "recipients" => array(
                    array("address_1" => $this->cleanForFraud($billingaddress->getStreetFull(), self::RE_ANS, 30),
                        "city" => $this->cleanForFraud($billingaddress->getCity(), self::RE_ANS, 20),
                        "country" => $this->cleanForFraud(Mage::getModel('directory/country')->load($billingaddress->getCountryId())->getIso3Code(), self::RE_AN, 3),
                        "email" => $billingaddress->getEmail(),
                        "first_name" => $this->cleanForFraud($billingaddress->getFirstname(), self::RE_ANS, 30),
                        "last_name" => $this->cleanForFraud($billingaddress->getLastname(), self::RE_ANS, 30),
                        "phone_number" => $this->cleanForFraud($billingaddress->getTelephone(), self::RE_NUMBER, 19),
                        "post_code" => $this->cleanForFraud($billingaddress->getPostcode(), self::RE_AN, 9),
                        "state" => $this->stateMap($billingaddress->getRegion())
                    )
                ),
                "shipping_address" => array(
                    "address_1" => $this->cleanForFraud($shippingaddress->getStreetFull(), self::RE_ANS, 30),
                    "city" => $this->cleanForFraud($shippingaddress->getCity(), self::RE_ANS, 20),
                    "country" => $this->cleanForFraud(Mage::getModel('directory/country')->load($shippingaddress->getCountryId())->getIso3Code(), self::RE_AN, 3),
                    "email" => $shippingaddress->getEmail(),
                    "first_name" => $this->cleanForFraud($shippingaddress->getFirstname(), self::RE_ANS, 30),
                    "last_name" => $this->cleanForFraud($shippingaddress->getLastname(), self::RE_ANS, 30),
                    "home_phone" => $this->cleanForFraud($shippingaddress->getTelephone(), self::RE_NUMBER, 19),
                    "post_code" => $this->cleanForFraud($shippingaddress->getPostcode(), self::RE_AN, 9),
                    "shipping_method" => $this->getFraudShippingMethod($order)
                ),
                "custom" => array("3" => "Facebook"),
                "website" => Mage::getBaseUrl()
            );
        }
        if ($existing_customer == 'false') {
            unset($payload["fraud"]['customer']['created_at']);
            unset($payload["fraud"]['customer']['date_of_birth']);
        } else if ($customer_dob == '') {
            unset($payload["fraud"]['customer']['date_of_birth']);
        }
        return $payload;
    }

    function auth($amount, $payment, $info) {
        $payload = $this->getDataSubmit($amount, $payment, $info);
        $payload['capture'] = false;
        $reference = $payment->getOrder()->getIncrementId();
        try {
            $this->fzlog("{$reference}: Submitting payment for {$payload["reference"]}.");
            $this->fzlog($payload);
            $response = $this->_post("purchases", $payload);
        } catch (Exception $e) {
            $exMessage = $e->getMessage();
            $this->fzlog("{$reference}: Payment request failed ({$exMessage}) - querying payment from Fat Zebra", Zend_Log::WARN);
            try {
                $response = $this->_fetch("purchases", $reference);
            } catch (Exception $e) {
                $exMessage = $e->getMessage();
                $this->fzlog("{$reference}: Payment request failed after query ({$exMessage}).", Zend_Log::ERR);
            }
            return false;
        }
        if ($response->successful) {
            $txn_result = $response->response->message;
            $fz_id = $response->response->id;
            $reference = $response->response->reference;

            Mage::helper('fatzebra')->setFraudOrder($reference, $response);

            $this->fzlog("{$reference}: Payment outcome: Successful, Result - {$txn_result}, Fat Zebra ID - {$fz_id}.");
        }

        if (!empty($response->errors)) {
            foreach ($response->errors as $err) {
                $this->fzlog("{$reference}: Error - {$err}", Zend_Log::ERR);
            }
        }
        return $response;
    }

    function auth_capture($amount, $payment, $info) {
        $payload = $this->getDataSubmit($amount, $payment, $info);
        $reference = $payment->getOrder()->getIncrementId();
        try {
            $this->fzlog("{$reference}: Submitting payment for {$payload["reference"]}.");
            
            $response = $this->_post("purchases", $payload);
        } catch (Exception $e) {
            $exMessage = $e->getMessage();
            $this->fzlog("{$reference}: Payment request failed ({$exMessage}) - querying payment from Fat Zebra", Zend_Log::WARN);
            try {
                $response = $this->_fetch("purchases", $reference);
            } catch (Exception $e) {
                $exMessage = $e->getMessage();
                $this->fzlog("{$reference}: Payment request failed after query ({$exMessage}).", Zend_Log::ERR);
                return false;
            }
        }

        if ($response->successful) {
            $txn_result = $response->response->message;
            $fz_id = $response->response->id;
            $reference = $response->response->reference;
            Mage::helper('fatzebra')->setFraudOrder($reference, $response);

            $this->fzlog("{$reference}: Payment outcome: Successful, Result - {$txn_result}, Fat Zebra ID - {$fz_id}.");
        }

        if (!empty($response->errors)) {
            foreach ($response->errors as $err) {
                $this->fzlog("{$reference}: Error - {$err}", Zend_Log::ERR);
            }
        }
        return $response;
    }

    public function capture($order_id) {
        return $this->_post("purchases/" . $order_id . "/capture", null);
    }

    /*
     * 
     */

    public function refund($payment, $amount) {
        $reference = $payment->getOrder()->getIncrementId();
        $payload = array("transaction_id" => $payment->getLastTransId(),
            "amount" => (int) ($amount * 100),
            "reference" => $reference);
        $this->fzlog("Refund post message", Zend_Log::ERR);
        $this->fzlog($payload, Zend_Log::ERR);
        return $this->_post("refunds", $payload);
    }

    /*
     * 
     */

    private function _post($path, $payload) {
        return $this->_request($path, Zend_Http_Client::POST, $payload);
    }

    private function _request($path, $method = Zend_Http_Client::GET, $payload = null) {

        if ($this->_testmode) {
            $payload["test"] = true;
        }
        $uri = $this->_url . "/v1.0/" . $path;
        $this->fzlog($uri);

        $client = new Zend_Http_Client();
        $client->setUri($uri);
        $client->setAuth($this->_username, $this->_token);
        $client->setHeaders('Content-Type', 'application/json');
        $client->setMethod($method);
        if ($method == Zend_Http_Client::POST) {
            $client->setRawData(json_encode($payload));
        }

        $client->setAdapter('Zend_Http_Client_Adapter_Curl');

        $client->setConfig(array(
            'adapter'   => 'Zend\Http\Client\Adapter\Curl',
            'curloptions' => array(CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSLVERSION => 6),
            'maxredirects'      => 0,
            'timeout'           => 30,
            'useragent'         => 'User-Agent: Fat Zebra Magento Library ' . self::VERSION,
        ));

        try {
            $response = $client->request();
        } catch (Exception $e) {
            $exMessage = $e->getMessage();
            $this->fzlog("{$path}: Fetching purchase failed: {$exMessage}", Zend_Log::ERR);
            Mage::logException($e);
            Mage::throwException(Mage::helper('fatzebra')->__("Gateway Error: %s", $e->getMessage()));
        }

        $responseBody = $response->getRawBody();
        $result = json_decode($responseBody);
        if (is_null($response)) {
            $response = array("successful" => false,
                "result" => null);
            $err = json_last_error();
            if ($err == JSON_ERROR_SYNTAX) {
                $result["errors"] = array("JSON Syntax error. JSON attempted to parse: " . $responseBody);
            } elseif ($err == JSON_ERROR_UTF8) {
                $result["errors"] = array("JSON Data invalid - Malformed UTF-8 characters. Data: " . $responseBody);
            } else {
                $result["errors"] = array("JSON parse failed. Unknown error. Data:" . $responseBody);
            }
        }
        return $result;
    }

    public function getFraudShippingMethod(Mage_Sales_Model_Order $order) {
        // Load Configs
        // See which method is mapped to which code
        // Return code or 'other'

        $shipping = $order->getShippingMethod();

        $method_lowcost = explode(',', Mage::getStoreConfig('payment/fatzebra/fraud_ship_lowcost'), Mage::app()->getStore());
        $method_overnight = explode(',', Mage::getStoreConfig('payment/fatzebra/fraud_ship_overnight'), Mage::app()->getStore());
        $method_sameday = explode(',', Mage::getStoreConfig('payment/fatzebra/fraud_ship_sameday'), Mage::app()->getStore());
        $method_pickup = explode(',', Mage::getStoreConfig('payment/fatzebra/fraud_ship_pickup'), Mage::app()->getStore());
        $method_express = explode(',', Mage::getStoreConfig('payment/fatzebra/fraud_ship_express'), Mage::app()->getStore());
        $method_international = explode(',', Mage::getStoreConfig('payment/fatzebra/fraud_ship_international'), Mage::app()->getStore());

        if (in_array($shipping, $method_lowcost)) {
            return 'low_cost';
        }

        if (in_array($shipping, $method_overnight)) {
            return 'overnight';
        }

        if (in_array($shipping, $method_sameday)) {
            return 'same_day';
        }

        if (in_array($shipping, $method_pickup)) {
            return 'pickup';
        }

        if (in_array($shipping, $method_express)) {
            return 'express';
        }

        if (in_array($shipping, $method_international)) {
            return 'international';
        }

        return 'other';
    }

    function fzlog($message, $level = Zend_Log::INFO) {
        Mage::log($message, $level, "FatZebra_gateway.log");
    }

    function cleanForFraud($data, $pattern, $maxlen, $trimDirection = 'right') {
        $data = preg_replace($pattern, '', $this->toASCII($data));
        $data = preg_replace('/[\r\n]/', ' ', $data);
        if (strlen($data) > $maxlen) {
            if ($trimDirection == 'right') {
                return substr($data, 0, $maxlen);
            } else {
                return substr($data, -1, $maxlen);
            }
        } else {
            return $data;
        }
    }

    
    /** Translates accented characters, ligatures etc to the latin equivalent.
     * @param $str string the input to be translated
     * @return string output once translated
     */
    function toASCII($str) {
        return strtr(utf8_decode($str), utf8_decode(
                        'ŠŒŽšœžŸ¥µÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýÿ'), 'SOZsozYYuAAAAAAACEEEEIIIIDNOOOOOOUUUUYsaaaaaaaceeeeiiiionoooooouuuuyy');
    }

    private function _fetch($path, $id) {
        $path = $path . "/" . urlencode($id);
        return $this->_request($path, Zend_Http_Client::GET);
    }
     // Maps AU States to the codes... otherwise return the state scrubbed for fraud....
    public function stateMap($stateName) {
        $states = array('Australia Capital Territory' => 'ACT',
                        'New South Wales' => 'NSW',
                        'Northern Territory' => 'NT',
                        'Queensland' => 'QLD',
                        'South Australia' => 'SA',
                        'Tasmania' => 'TAS',
                        'Victoria' => 'VIC',
                        'Western Australia' => 'WA');

        if (array_key_exists($stateName, $states)) {
            return $states[$stateName];
        } else {
            return $this->cleanForFraud($stateName, self::RE_AN, 10);
        }
    }

}
