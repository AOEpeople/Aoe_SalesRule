<?php

class Aoe_SalesRule_Model_Observer
{
    public function applyCoreSalesRule(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Quote_Address $address */
        $address = $observer->getData('address');
        if (!$address instanceof Mage_Sales_Model_Quote_Address) {
            return;
        }

        /** @var Mage_SalesRule_Model_Rule $rule */
        $rule = $observer->getData('rule');
        if (!$rule instanceof Mage_SalesRule_Model_Rule) {
            return;
        }

        /** @var Mage_Sales_Model_Quote_Item_Abstract[] $allItems */
        $allItems = $observer->getData('all_items');
        if (!is_array($allItems)) {
            return;
        }

        /** @var Mage_Sales_Model_Quote_Item_Abstract[] $validItems */
        $validItems = $observer->getData('valid_items');
        if (!is_array($allItems)) {
            return;
        }

        /** @var Varien_Object $result */
        $result = $observer->getData('result');
        if (!$result instanceof Varien_Object) {
            return;
        }

        $applied = false;
        switch ($rule->getSimpleAction()) {
            case Mage_SalesRule_Model_Rule::BY_FIXED_ACTION:
                /** @var Aoe_SalesRule_Model_CoreRules_ByFixed $ruleHandler */
                $ruleHandler = Mage::getModel('Aoe_SalesRule/CoreRules_ByFixed');
                if ($ruleHandler instanceof Aoe_SalesRule_Model_CoreRules_ByFixed) {
                    $applied = $ruleHandler->handle($address, $rule, $allItems, $validItems);
                }
                break;
            case Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION:
                /** @var Aoe_SalesRule_Model_CoreRules_ByPercent $ruleHandler */
                $ruleHandler = Mage::getModel('Aoe_SalesRule/CoreRules_ByPercent');
                if ($ruleHandler instanceof Aoe_SalesRule_Model_CoreRules_ByPercent) {
                    $applied = $ruleHandler->handle($address, $rule, $allItems, $validItems);
                }
                break;
            case Mage_SalesRule_Model_Rule::CART_FIXED_ACTION:
                /** @var Aoe_SalesRule_Model_CoreRules_CartFixed $ruleHandler */
                $ruleHandler = Mage::getModel('Aoe_SalesRule/CoreRules_CartFixed');
                if ($ruleHandler instanceof Aoe_SalesRule_Model_CoreRules_CartFixed) {
                    $applied = $ruleHandler->handle($address, $rule, $allItems, $validItems);
                }
                break;
            case Mage_SalesRule_Model_Rule::BUY_X_GET_Y_ACTION:
                /** @var Aoe_SalesRule_Model_CoreRules_BuyxGety $ruleHandler */
                $ruleHandler = Mage::getModel('Aoe_SalesRule/CoreRules_BuyxGety');
                if ($ruleHandler instanceof Aoe_SalesRule_Model_CoreRules_BuyxGety) {
                    $applied = $ruleHandler->handle($address, $rule, $allItems, $validItems);
                }
                break;
        }

        if ($applied) {
            $result->setData('applied', true);
        }
    }
}
