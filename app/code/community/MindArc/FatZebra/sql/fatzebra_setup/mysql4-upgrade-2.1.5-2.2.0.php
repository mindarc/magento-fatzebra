<?php
$installer = $this;
$installer->startSetup();

$installer->run("
DROP TABLE IF EXISTS {$this->getTable('fatzebrafraud_data')};
CREATE TABLE {$this->getTable('fatzebrafraud_data')} (
  `entity_id` int(10) NOT NULL auto_increment,
  `order_id` int(10) NOT NULL,
  `fraud_result` text NULL,
  `fraud_messages_title` text NULL,
  `fraud_messages_detail` text NULL,
  `fraud_created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY  (`entity_id`),
  KEY `order_id_idx` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");


$installer->addAttribute('customer', 'fatzebra_token', array(
    'label'         => 'Fat Zebra Token',
    'visible'       => 1,
    'required'      => 0, 
    'position'      => 1,
    'sort_order'    => 82
));
$installer->addAttribute('customer', 'fatzebra_masked_card_number', array(
    'label'         => 'Fat Zebra Masked Card Number',
    'visible'       => 1,
    'required'      => 0,
    'position'      => 1,
    'sort_order'    => 83
));
$installer->addAttribute('customer', 'fatzebra_expiry_date', array(
    'label'         => 'Fat Zebra Expriy Date',
    'visible'       => 1,
    'required'      => 0,
    'position'      => 1,
    'sort_order'    => 84 
));

$customerattrubute = Mage::getModel('customer/attribute')->loadByCode('customer', 'fatzebra_masked_card_number');
$forms=array('adminhtml_customer');
$customerattrubute->setData('used_in_forms', $forms);
$customerattrubute->save();

$installer->endSetup();

