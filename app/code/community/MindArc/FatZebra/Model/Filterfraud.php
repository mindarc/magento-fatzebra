<?php

class MindArc_FatZebra_Model_Filterfraud extends Mage_Core_Model_Abstract
{
    public function _construct()
    {
        parent::_construct();
    }
    public function getFilter()
    {
        $resource = Mage::getSingleton('core/resource');
        $readConnection = $resource->getConnection('core_read');

        $table = "fatzebrafraud_data";
        // use your own table name
        $tableName = Mage::getSingleton("core/resource")->getTableName($table);

        $query = "SELECT  `fraud_result` FROM  `".$tableName."` GROUP BY  `fraud_result` ";
        $results = $readConnection->fetchAll($query);
        $options = array();
        foreach($results as $status)
            $options[$status['fraud_result']]=$status['fraud_result'];
        return $options;
    }
}