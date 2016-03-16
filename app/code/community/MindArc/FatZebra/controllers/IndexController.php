<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class MindArc_FatZebra_IndexController extends Mage_Core_Controller_Front_Action {

    public function indexAction() {
        $response = array();
        $session = $this->getRequest()->getParam('io_bb');
        if($session!=null && $session!=""){
            $response['code'] = 'success';
            Mage::getSingleton('core/session')->setFatzebraFraud($session);
        }else{
            $response['code'] = 'error';
        }


        $this->getResponse()->setHeader('Content-type', 'application/json');
        if ($response['code'] != 'success') {
            $this->getResponse()->setHeader('HTTP/1.0', '404', true);
        } else {
            $this->getResponse()->setHeader('HTTP/1.0', '200', true);
        }
        $jsonData = json_encode($response);
        $this->getResponse()->setBody($jsonData);

    }
    public function sessionAction(){
        echo Mage::getSingleton('core/session')->getFatzebraFraud();
    }
}