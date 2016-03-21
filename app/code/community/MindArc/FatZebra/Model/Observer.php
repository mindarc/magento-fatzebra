<?php
class MindArc_FatZebra_Model_Observer 
{
     public function paymentMethodIsActive(Varien_Event_Observer $observer) {
        $event           = $observer->getEvent();
        $method          = $event->getMethodInstance();
        $result          = $event->getResult();
        $fatzebraCustomer = Mage::getModel('fatzebra/customer');
        if($result->isAvailable) 
        {
            if(!$fatzebraCustomer->getCustomerToken()&&$method->getCode()=="fatzebra_saved_cc"){
                    $result->isAvailable = false;
            }
        }

    }
    
    public function handleSuccessAction(Varien_Event_Observer $observer)
    {
        if(Mage::getSingleton('core/session')->getFatZebraCcSave()==1){
            $fatzebraCustomerModel = Mage::getModel('fatzebra/customer');
            $fatzebraCustomerModel->saveData(Mage::getSingleton('core/session')->getFatZebraResult());            
        }
        Mage::getSingleton('core/session')->unsFatZebraCcSave();
        Mage::getSingleton('core/session')->unsFatZebraResult();
    }

    public function addActionColumn(Varien_Event_Observer $observer) {

        $block = $observer->getEvent()->getBlock();
        $this->_block = $block;
        $blockOverride = Mage::getStoreConfig('payment/fatzebra/fraud_order_grid', Mage::app()->getStore());

        if (!$blockOverride) $blockOverride = 'Mage_Adminhtml_Block_Sales_Order_Grid';

        if (get_class($block) == $blockOverride) {
            // $block->setUseAjax(false);
            $block->addColumnAfter('fraud_result', array(
                'header' => Mage::helper('sales')->__('Fraud Detected'),
                'width' => '15px',
                'type' => 'options',
                'index' => 'fraud_result',
                'filter_condition_callback' => array($this, '_filterFraudResult'),
                'align' => 'center',
                'filter' => 'adminhtml/widget_grid_column_filter_select',
                'options'   => Mage::getSingleton('fatzebra/filterfraud')->getFilter(),
                'renderer' => 'MindArc_FatZebra_Block_Adminhtml_Widget_Grid_Column_Renderer_Fraudresult',
                ), 'status');

            $block->sortColumnsByOrder();
        }



        return $observer;
    }

    public function _filterFraudResult($collection, $column) {


        // 1.6.1    
        if ($collection instanceof Mage_Sales_Model_Resource_Order_Grid_Collection) {

            // we have to change this so the join doesn't get reset
            $collection->addFieldToFilter('`fatzebrafraud_data`.fraud_result', $column->getFilter()->getCondition());
            // 1.4.1
        } else if ($collection instanceof Mage_Core_Model_Mysql4_Collection_Abstract) {


            // we have to change this so the join doesn't get reset
            $collection->addFieldToFilter('`fatzebrafraud_data`.fraud_result', $column->getFilter()->getCondition());
        } else {


            $collection->addFieldToFilter('`fatzebrafraud_data`.fraud_result', $column->getFilter()->getCondition());
        }
    }

    public function prepareOrderGridCollection($observer) {

        $collection = $observer->getOrderGridCollection();



        if ($collection instanceof Mage_Sales_Model_Resource_Order_Grid_Collection) {
           
            $collection->getSelect()
                    ->joinLeft(array('fatzebrafraud_data' => $collection->getTable('fatzebra/fraud')), 'fatzebrafraud_data.order_id=main_table.entity_id', 'fraud_result');
        } else if ($collection instanceof Mage_Core_Model_Mysql4_Collection_Abstract) { // 1.4.1
           
            $collection->getSelect()
                    ->joinLeft(array('fatzebrafraud_data' => $collection->getTable('fatzebra/fraud')), 'fatzebrafraud_data.order_id=main_table.entity_id', 'fraud_result');
        } else if ($collection instanceof Mage_Eav_Model_Entity_Collection_Abstract) {
          
            $collection->joinTable('fatzebra/fraud', 'order_id=entity_id', array("fraud_result" => "fraud_result"), null, "left");
        }

        return $collection;
    }
}