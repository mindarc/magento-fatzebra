<?php
class MindArc_FatZebra_Block_Form extends Mage_Payment_Block_Form_Cc
{
      protected function _construct()
      {
        parent::_construct();
        $this->setTemplate('mindarc/fatzebra/form.phtml');
      }
      
      public function canSave() {
        $cansave = Mage::getStoreConfig('payment/fatzebra/can_save', Mage::app()->getStore());
        $isLoggedIn = Mage::getSingleton('customer/session')->isLoggedIn();        
        $isRegister= Mage::getSingleton('checkout/type_onepage')->getCheckoutMethod()=="register"?true:false;
        
        if ($cansave && ($isLoggedIn||$isRegister)) {
                return true;
        }
        return false;
      }
      public function hasCustomerToken(){
         $fatzebraCustomer = Mage::getModel('fatzebra/customer');
          return $fatzebraCustomer->getCustomerToken();
      }
      public function getMaskedCardNumber() {
          $fatzebraCustomer = Mage::getModel('fatzebra/customer');
          return $fatzebraCustomer->getMaskedCardNumber();
     }
}