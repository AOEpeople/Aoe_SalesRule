<?php

abstract class Aoe_SalesRule_Model_CoreRules_Abstract
{
    /**
     * @param Mage_Sales_Model_Quote_Address         $address
     * @param Mage_SalesRule_Model_Rule              $rule
     * @param Mage_Sales_Model_Quote_Item_Abstract[] $allItems
     * @param Mage_Sales_Model_Quote_Item_Abstract[] $validItems
     *
     * @return bool
     */
    abstract public function handle(Mage_Sales_Model_Quote_Address $address, Mage_SalesRule_Model_Rule $rule, array $allItems, array $validItems);

    /**
     * @return Aoe_SalesRule_Helper_Data
     */
    protected function getHelper()
    {
        return Mage::helper('Aoe_SalesRule/Data');
    }
}
