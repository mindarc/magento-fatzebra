<?php
class MindArc_FatZebra_Helper_Data extends Mage_Core_Helper_Abstract
{ 
    public function setFraudOrder($increment_id,$response)
    {
            $order_id = Mage::getModel('sales/order')->loadByIncrementId($increment_id)->getId();
            $model = Mage::getModel('fatzebra/fraud')->loadByOrderId($order_id);
            if (!$model->getId()) {
                if (property_exists($response->response, 'fraud_result')) {
                    $fraud_result = $response->response->fraud_result;
                    $fraud_fraud_messages = $response->response->fraud_messages;
                    $model->setFraudCreatedAt(now());
                    $model->setOrderId($order_id);
                    $model->setFraudResult($fraud_result);
                    $model->setFraudMessagesTitle(isset($fraud_fraud_messages[0]) ? $fraud_fraud_messages[0] : "");
                    $model->setFraudMessagesDetail(isset($fraud_fraud_messages[1]) ? $fraud_fraud_messages[1] : "");
                    $model->save();
                }
            }
    }
}