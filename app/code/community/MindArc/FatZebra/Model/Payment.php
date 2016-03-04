<?php

class MindArc_FatZebra_Model_Payment extends Mage_Payment_Model_Method_Cc {

    const VERSION = "2.1.0";
    // Fraud Check Data Scrubbing...
    const RE_ANS = "/[^A-Z\d\-_',\.;:\s]*/i";
    const RE_AN = "/[^A-Z\d]/i";
    const RE_NUMBER = "/[^\d]/";

    protected $_code = 'fatzebra';
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canFetchTransactionInfo = true;
    protected $_canCreateBillingAgreement = true;
    protected $_canReviewPayment = true;
    protected $_formBlockType = 'fatzebra/form';
    protected $_api = null;
    protected $_auth_capture = false;

    public function __construct() {
        $this->_api = Mage::helper('fatzebra/api');
        $this->_auth_capture = Mage::getStoreConfig('payment/fatzebra/payment_action', Mage::app()->getStore()) == self::ACTION_AUTHORIZE_CAPTURE ? true : false;
        parent::__construct();
    }

    /**
     * Assign data to info model instance
     *
     * @param   mixed $data
     * @return MindArc_FatZebra_Model_Payment
     */
    public function assignData($data) {
        parent::assignData($data);
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();
        $info->setCcNumber($data->getCcNumber());
        $post = Mage::app()->getFrontController()->getRequest()->getPost();
      
        if (isset($post['payment']['cc_save'])) {
            Mage::getSingleton('core/session')->setFatZebraCcSave($post['payment']['cc_save']);
        }
        return $this;
    }

    /**
     * Check authorise availability
     *
     * @return bool
     */
    public function canAuthorize() {
        return $this->_canAuthorize;
    }

    public function authorize(Varien_Object $payment, $amount) {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper('paygate')->__('Invalid amount for authorization.'));
        }
        $result = $this->_api->auth($amount, $payment, $this->getInfoInstance());
        if (isset($result->successful) && $result->successful) {
            if ($result->response->successful) {
                $order = $payment->getOrder();
                if (Mage::getSingleton('core/session')->getFatZebraCcSave() == 1) {
                    Mage::getSingleton('core/session')->setFatZebraResult($result);
                }
                if (property_exists($result->response, 'fraud_result') && ($result->response->fraud_result && $result->response->fraud_result == 'Challenge')) {
                    $payment->setLastTransId($result->response->id);
                    $payment->setTransactionId($result->response->id);
                    $payment->setIsFraudDetected(true);
                    $state = Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW;
                    $status = Mage_Sales_Model_Order::STATUS_FRAUD;
                    $order->setState($state, $status);
                    $payment->setSkipOrderProcessing(true);
                } else {
                    $payment->setLastTransId($result->response->id)
                            ->setTransactionId($result->response->id)
                            ->setIsTransactionClosed('');
                }
            }
        }

        return $this;
    }

    /**
     * Performs a capture (full purchase transaction)
     * @param $payment the payment object to process
     * @param $amount the amount to be charged, as a decimal
     *
     * @return MindArc_FatZebra_Model_Payment
     */
    public function capture(Varien_Object $payment, $amount) {
        $this->setAmount($amount)->setPayment($payment);
        //Payment action Authorize and Capture
        if ($this->_auth_capture) {
            $result = $this->_api->auth_capture($amount, $payment, $this->getInfoInstance());

            if ($result == false) {
                $message = Mage::helper('fatzebra')->__('There has been an error processing your payment.');
                Mage::throwException($message);
                return $this;
            }

            $this->fzlog($result);
            if (isset($result->successful) && $result->successful) {
                $this->getInfoInstance()->setCcOwner($result->response->card_holder)
                        ->setCcLast4(substr($result->response->card_number, 12))
                        ->setCcExpMonth(substr($result->response->card_expiry, -5, 2))
                        ->setCcExpYear(substr($result->response->card_expiry, 0, 4));

                if ($result->response->successful) {
                    $order = $payment->getOrder();
                    if (Mage::getSingleton('core/session')->getFatZebraCcSave() == 1) {
                        Mage::getSingleton('core/session')->setFatZebraResult($result);
                    }
                    // TODO: This should set the order/payment result to 'FRAUD', whereas currently it sets the order to Processing
                    // However, the code eblow, setting to status_fraud etc doesn't seem to do anything...

                    if ($result->response->fraud_result && $result->response->fraud_result == 'Challenge') {
                        $payment->setLastTransId($result->response->id);
                        $payment->setTransactionId($result->response->id);
                        $payment->registerCaptureNotification($amount, false);
                        $payment->setIsTransactionPending(true)
                                ->setIsFraudDetected(true);
                        $payment->setSkipTransactionCreation(true);
                    } else {
                        //$payment->setStatus(Mage_Sales_Model_Order::STATUS_APPROVED);
                        $payment->setLastTransId($result->response->id);
                        $payment->setTransactionId($result->response->id);
                        $invoice = $order->getInvoiceCollection()->getFirstItem();
                        if ($invoice && !$invoice->getEmailSent()) {
                            $invoice->pay(); // Mark the invoice as paid
                            $invoice->addComment("Payment made by Credit Card. Reference " . $result->response->id . ", Masked number: " . $result->response->card_number, false, true);
                            $invoice->save();
                            $invoice->sendEmail();
                        }
                    }
                } else {
                    Mage::throwException(Mage::helper('fatzebra')->__("Unable to process payment: %s", $result->response->message));
                }
            } else {
                $message = Mage::helper('fatzebra')->__('There has been an error processing your payment. %s', implode(", ", $result->errors));
                Mage::throwException($message);
            }
        } elseif($payment->getLastTransId()!=''){// Payment action Authorize only 
            $result = $this->_api->capture($payment->getLastTransId());
            if (isset($result->successful) && $result->successful) {
                if (isset($result->successful) && $result->successful) {
                    $order = $payment->getOrder();
                    $payment->setLastTransId($result->response->id);
                    $payment->setTransactionId($result->response->id);
                    $invoice = $order->getInvoiceCollection()->getFirstItem();
                    if ($invoice && !$invoice->getEmailSent()) {
                            $invoice->pay(); // Mark the invoice as paid
                            $invoice->addComment("Payment made by Credit Card. Reference " . $result->response->id . ", Masked number: " . $result->response->card_number, false, true);
                            $invoice->save();
                            $invoice->sendEmail();
                    }
                }else{
                    Mage::throwException(Mage::helper('fatzebra')->__("Unable to process payment: %s", $result->response->message));
                }
            }
            else{
                $message = Mage::helper('fatzebra')->__('There has been an error processing your payment. %s', implode(", ", $result->errors));
                Mage::throwException($message);
            }
            $this->fzlog($result);
        }
        else
        {
            Mage::throwException("Unable to process capture payment online.");
        }
        return $this;
    }

    /**
     * Refunds a payment
     *
     * @param $payment the payment object
     * @param $amount the amount to be refunded, as a decimal
     *
     * @return MindArc_FatZebra_Model_Payment
     */
    public function refund(Varien_Object $payment, $amount) {
        $result = $this->_api->refund($payment, $amount);
        if (isset($result->successful) && $result->successful) {
            if ($result->response->successful) {
                $payment->setStatus(self::STATUS_SUCCESS);
                return $this;
            } else {
                Mage::throwException(Mage::helper('fatzebra')->__("Error processing refund: %s", $result->response->message));
            }
        }
        Mage::throwException(Mage::helper('fatzebra')->__("Error processing refund: %s", implode(", ", $result->errors)));
    }

    /**
     * Builds the refund payload and submits
     *
     * @param $payment the object to reference
     * @param $amount the refund amount, as a decimal
     *
     * @return StdObject response
     */

    /**
     * Validate payment method information object
     *
     * @param   Mage_Payment_Model_Info $info
     * @return  Mage_Payment_Model_Abstract
     */
    public function validate() {
        if (isset($_POST['use_saved_card'])) {
            return $this;
        }
        if (isset($_POST['payment']['cc_token']) && !empty($_POST['payment']['cc_token'])) {
            // Bypass if we are tokenized...
            return $this;
        }

        return parent::validate();
    }
    public function acceptPayment(Mage_Payment_Model_Info $payment)
    {
        parent::acceptPayment($payment);
        return true;
    }

    public function denyPayment(Mage_Payment_Model_Info $payment) {
        parent::denyPayment($payment);
        return true;
    }
    

    function fzlog($message, $level = Zend_Log::INFO) {
        Mage::log($message, $level, "FatZebra_gateway.log");
    }

}
