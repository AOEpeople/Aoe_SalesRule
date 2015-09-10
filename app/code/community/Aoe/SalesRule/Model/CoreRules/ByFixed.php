<?php

class Aoe_SalesRule_Model_CoreRules_ByFixed extends Aoe_SalesRule_Model_CoreRules_Abstract
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
        if ($rule->getSimpleAction() !== Mage_SalesRule_Model_Rule::BY_FIXED_ACTION) {
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

        // Discount amount
        $baseDiscountAmount = $helper->round($rule->getDiscountAmount(), $quote->getBaseCurrencyCode());
        $discountAmount = $helper->round($store->convertPrice($baseDiscountAmount), $quote->getQuoteCurrencyCode());

        // Skip zero discounts
        if ($discountAmount <= 0.0) {
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

            // Calculate discount amounts
            $discountAmount = $discountAmount * $qty;
            $baseDiscountAmount = $baseDiscountAmount * $qty;
            $originalDiscountAmount = $discountAmount * $qty;
            $baseOriginalDiscountAmount = $baseDiscountAmount * $qty;

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

        if ($rule->getApplyToShipping()) {
            $shippingAmount = $address->getShippingAmountForDiscount();
            $baseShippingAmount = $address->getBaseShippingAmountForDiscount();
            if ($shippingAmount === null || $baseShippingAmount === null) {
                $shippingAmount = $address->getShippingAmount();
                $baseShippingAmount = $address->getBaseShippingAmount();
            }

            $shippingAmount -= $address->getShippingDiscountAmount();
            $baseShippingAmount -= $address->getBaseShippingDiscountAmount();

            $shippingDiscountAmount = min(max($discountAmount, 0.0), $shippingAmount);
            $shippingBaseDiscountAmount = min(max($baseDiscountAmount, 0.0), $baseShippingAmount);

            $address->setShippingDiscountAmount($address->getShippingDiscountAmount() + $shippingDiscountAmount);
            $address->setBaseShippingDiscountAmount($address->getBaseShippingDiscountAmount() + $shippingBaseDiscountAmount);

            $applied = true;
        }

        return $applied;
    }
}
