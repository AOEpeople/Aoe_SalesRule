<?php

class Aoe_SalesRule_Model_CoreRules_BuyxGety extends Aoe_SalesRule_Model_CoreRules_Abstract
{
    /**
     * @param Mage_Sales_Model_Quote_Address         $address
     * @param Mage_SalesRule_Model_Rule              $rule
     * @param Mage_Sales_Model_Quote_Item_Abstract[] $allItems
     * @param Mage_Sales_Model_Quote_Item_Abstract[] $validItems
     *
     * @return bool
     */
    public function handle(Mage_Sales_Model_Quote_Address $address, Mage_SalesRule_Model_Rule $rule, array $allItems, array $validItems)
    {
        if ($rule->getSimpleAction() !== Mage_SalesRule_Model_Rule::BUY_X_GET_Y_ACTION) {
            return false;
        }

        $helper = $this->getHelper();

        // Get the X and Y values
        $x = max(floatval($rule->getDiscountStep()), 0.0);
        $y = max(floatval($rule->getDiscountAmount()), 0.0);
        if ($x <= 0.0 || $y <= 0.0) {
            return false;
        }

        // Get the discount step size
        $step = $x + $y;

        $applied = false;

        foreach ($validItems as $item) {
            // Get max quantity
            $qty = $helper->getItemRuleQty($item, $rule);

            // Apply discount step size limitation
            $qty = max($qty - (ceil($qty / $step) * $x), 0.0);

            if ($qty <= 0.0) {
                continue;
            }

            $applied = true;

            // Get unit prices
            $itemPrice = $helper->getItemPrice($item);
            $itemBasePrice = $helper->getItemBasePrice($item);
            $itemOriginalPrice = $helper->getItemOriginalPrice($item);
            $itemBaseOriginalPrice = $helper->getItemBaseOriginalPrice($item);

            // Calculate discount amounts
            $discountAmount = ($itemPrice * $qty);
            $originalDiscountAmount = ($itemOriginalPrice * $qty);
            $baseDiscountAmount = ($itemBasePrice * $qty);
            $baseOriginalDiscountAmount = ($itemBaseOriginalPrice * $qty);

            // Round the discount amounts
            $discountAmount = $helper->round($discountAmount, $address->getQuote()->getQuoteCurrencyCode());
            $baseDiscountAmount = $helper->round($baseDiscountAmount, $address->getQuote()->getBaseCurrencyCode());
            $originalDiscountAmount = $helper->round($originalDiscountAmount, $address->getQuote()->getQuoteCurrencyCode());
            $baseOriginalDiscountAmount = $helper->round($baseOriginalDiscountAmount, $address->getQuote()->getBaseCurrencyCode());

            // Update the item discounts
            $item->setDiscountAmount($item->getDiscountAmount() + $discountAmount);
            $item->setBaseDiscountAmount($item->getBaseDiscountAmount() + $baseDiscountAmount);
            $item->setOriginalDiscountAmount($item->getOriginalDiscountAmount() + $originalDiscountAmount);
            $item->setBaseOriginalDiscountAmount($item->getBaseOriginalDiscountAmount() + $baseOriginalDiscountAmount);
        }

        return $applied;
    }
}
