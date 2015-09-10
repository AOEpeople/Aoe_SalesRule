<?php

class Aoe_SalesRule_Model_CoreRules_ByPercent extends Aoe_SalesRule_Model_CoreRules_Abstract
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
        // Skip invalid rule actions
        if ($rule->getSimpleAction() !== Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION) {
            return false;
        }

        // Skip if there are no valid items and it's not applied to shipping
        if (!count($validItems) && !$rule->getApplyToShipping()) {
            return false;
        }

        // Define a few helpful variables
        $helper = $this->getHelper();
        $quote = $address->getQuote();
        $store = $quote->getStore();

        // Get discount percent
        $discountPercent = max(min($rule->getDiscountAmount(), 100.0), 0.0);
        $discountMultiplier = ($discountPercent / 100);

        // Skip zero discounts
        if ($discountPercent <= 0.0) {
            return false;
        }

        // Get the discount step size
        $step = max(floatval($rule->getDiscountStep()), 0.0);

        $applied = false;

        foreach ($validItems as $item) {
            // Get max quantity
            $qty = $helper->getItemRuleQty($item, $rule);

            // Apply discount step size limitation
            if ($step > 0.0) {
                $qty = (floor($qty / $step) * $step);
            }

            // Skip zero quantities
            if ($qty <= 0.0) {
                continue;
            }

            $applied = true;

            // Get row prices
            $itemRowPrice = ($helper->getItemPrice($item) * $qty);
            $itemBaseRowPrice = ($helper->getItemBasePrice($item) * $qty);
            $itemOriginalRowPrice = ($helper->getItemOriginalPrice($item) * $qty);
            $itemBaseOriginalRowPrice = ($helper->getItemBaseOriginalPrice($item) * $qty);

            // Calculate discount amounts
            $discountAmount = ($itemRowPrice - $item->getDiscountAmount()) * $discountMultiplier;
            $baseDiscountAmount = ($itemBaseRowPrice - $item->getBaseDiscountAmount()) * $discountMultiplier;
            $originalDiscountAmount = ($itemOriginalRowPrice - $item->getOriginalDiscountAmount()) * $discountMultiplier;
            $baseOriginalDiscountAmount = ($itemBaseOriginalRowPrice - $item->getBaseOriginalDiscountAmount()) * $discountMultiplier;

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

            // Update the item percent discount value
            $discountPercent = min(100, $item->getDiscountPercent() + $discountPercent);
            $item->setDiscountPercent($discountPercent);
        }

        if ($rule->getApplyToShipping()) {
            $shippingAmount = $address->getShippingAmountForDiscount();
            $baseShippingAmount = $address->getBaseShippingAmountForDiscount();
            if ($shippingAmount === null || $baseShippingAmount === null) {
                $shippingAmount = $address->getShippingAmount();
                $baseShippingAmount = $address->getBaseShippingAmount();
            }

            // Subtract existing discounts
            $shippingAmount -= $address->getShippingDiscountAmount();
            $baseShippingAmount -= $address->getBaseShippingDiscountAmount();

            // Calculate and round discounts
            $shippingDiscountAmount = $helper->round($shippingAmount * $discountMultiplier, $address->getQuote()->getQuoteCurrencyCode());
            $shippingBaseDiscountAmount = $helper->round($baseShippingAmount * $discountMultiplier, $address->getQuote()->getBaseCurrencyCode());

            // Make sure the discount isn't more that the remaining amount
            $shippingDiscountAmount = min(max($shippingDiscountAmount, 0.0), $shippingAmount);
            $shippingBaseDiscountAmount = min(max($shippingBaseDiscountAmount, 0.0), $baseShippingAmount);

            // Store discounts
            $address->setShippingDiscountAmount($address->getShippingDiscountAmount() + $shippingDiscountAmount);
            $address->setBaseShippingDiscountAmount($address->getBaseShippingDiscountAmount() + $shippingBaseDiscountAmount);

            $applied = true;
        }

        return $applied;
    }
}
