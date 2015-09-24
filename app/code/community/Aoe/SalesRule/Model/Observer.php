<?php

class Aoe_SalesRule_Model_Observer extends Mage_SalesRule_Model_Observer
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

    /**
     * Observer used to record a rule usage and a coupon usage
     *
     * NB: This replaces the parent method so that event zero value discounts are recorded properly and to remove race conditions
     *
     * @param Varien_Event_Observer $observer
     *
     * @return void
     */
    public function sales_order_afterPlace(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = $observer->getData('order');
        if (!$order instanceof Mage_Sales_Model_Order) {
            return;
        }

        // Get the used rule IDs
        $ruleIds = array_unique(array_filter(array_map('intval', explode(',', $order->getAppliedRuleIds()))));

        $ruleCustomer = null;
        $customerId = $order->getCustomerId();

        // use each rule (and apply to customer, if applicable)
        foreach ($ruleIds as $ruleId) {
            /** @var Mage_SalesRule_Model_Rule $rule */
            $rule = Mage::getModel('salesrule/rule');
            $rule->load($ruleId);
            if (!$rule->getId()) {
                continue;
            }

            // Update the rule usage counter - the DB expression is used to prevent race conditions
            $rule->setDataUsingMethod('times_used', new Zend_Db_Expr('times_used+1'));
            $rule->save();

            if ($customerId) {
                // If we add a unique index of rule and customer we can use \Varien_Db_Adapter_Interface::insertOnDuplicate to prevent race conditions
                /** @var Mage_SalesRule_Model_Rule_Customer $ruleCustomer */
                $ruleCustomer = Mage::getModel('salesrule/rule_customer');
                $ruleCustomer->loadByCustomerRule($customerId, $ruleId);

                if ($ruleCustomer->getId()) {
                    // Update the usage counter - the DB expression is used to prevent race conditions
                    $ruleCustomer->setDataUsingMethod('times_used', new Zend_Db_Expr('times_used+1'));
                } else {
                    $ruleCustomer
                        ->setCustomerId($customerId)
                        ->setRuleId($ruleId)
                        ->setTimesUsed(1);
                }

                $ruleCustomer->save();
            }
        }

        // Trim the coupon code to match all the other coupon code processing
        $couponCode = trim($order->getCouponCode());
        if (!empty($couponCode)) {
            /** @var Mage_SalesRule_Model_Coupon $coupon */
            $coupon = Mage::getModel('salesrule/coupon');
            $coupon->load($couponCode, 'code');
            if ($coupon->getId()) {
                $coupon->setDataUsingMethod('times_used', new Zend_Db_Expr('times_used+1'));
                $coupon->save();
                if ($customerId) {
                    /** @var Aoe_SalesRule_Model_Resource_Coupon_Usage $couponUsage */
                    $couponUsage = Mage::getResourceModel('salesrule/coupon_usage');
                    $couponUsage->updateCustomerCouponTimesUsed($customerId, $coupon->getId());
                }
            }
        }
    }
}
