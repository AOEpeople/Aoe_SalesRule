<?php

class Aoe_SalesRule_Model_CoreRules_CartFixed extends Aoe_SalesRule_Model_CoreRules_Abstract
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
        if ($rule->getSimpleAction() !== Mage_SalesRule_Model_Rule::CART_FIXED_ACTION) {
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

        // Total available discount amounts
        $baseDiscountAmount = $helper->round($rule->getDiscountAmount(), $quote->getBaseCurrencyCode());
        $discountAmount = $helper->round($store->convertPrice($baseDiscountAmount), $quote->getQuoteCurrencyCode());

        // Skip zero discounts
        if ($discountAmount <= 0.0) {
            return false;
        }

        $applied = false;

        // Pre-calculate the totals for all valid items
        $ruleTotalItemsPrice = 0;
        $ruleTotalBaseItemsPrice = 0;
        $itemPrices = [];
        foreach ($validItems as $item) {
            // Get max quantity
            $qty = $helper->getItemRuleQty($item, $rule);

            // Skip zero quantity
            if ($qty <= 0.0) {
                continue;
            }
            // Get row prices
            $itemRowPrice = ($helper->getItemPrice($item) * $qty);
            $itemBaseRowPrice = ($helper->getItemBasePrice($item) * $qty);

            // Add to running totals
            $ruleTotalItemsPrice += $itemRowPrice;
            $ruleTotalBaseItemsPrice += $itemBaseRowPrice;

            // Save row prices for later
            $itemPrices[$item->getId()] = [$itemRowPrice, $itemBaseRowPrice];
        }

        foreach ($validItems as $item) {
            // Skip the skipped items
            if (!isset($itemPrices[$item->getId()])) {
                continue;
            }

            // Extract the pre-calculate row price information
            list($itemRowPrice, $itemBaseRowPrice) = $itemPrices[$item->getId()];

            // Flag indicating the rule was applied
            $applied = true;

            // Calculate the discount amounts
            $itemDiscountAmount = ($itemRowPrice * ($itemRowPrice / $ruleTotalItemsPrice));
            $itemBaseDiscountAmount = ($itemBaseRowPrice * ($itemBaseRowPrice / $ruleTotalItemsPrice));

            // Round the discount amount and make sure we didn't round UP and over the remaining discount amount
            $itemDiscountAmount = min($discountAmount, $helper->round($itemDiscountAmount, $quote->getQuoteCurrencyCode()));
            $itemBaseDiscountAmount = min($baseDiscountAmount, $helper->round($itemBaseDiscountAmount, $quote->getBaseCurrencyCode()));

            // Update the item discounts
            $item->setDiscountAmount($item->getDiscountAmount() + $itemDiscountAmount);
            $item->setBaseDiscountAmount($item->getBaseDiscountAmount() + $baseDiscountAmount);

            // This is a bit wonky, but needed for taxes
            $item->setOriginalDiscountAmount($item->getOriginalDiscountAmount() + $itemDiscountAmount);
            $item->setBaseOriginalDiscountAmount($item->getBaseOriginalDiscountAmount() + $itemBaseDiscountAmount);

            // Subtract from the total discount amounts
            $discountAmount -= $itemDiscountAmount;
            $baseDiscountAmount -= $itemBaseDiscountAmount;
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

            // Subtract from the total discount amounts
            $discountAmount -= $shippingDiscountAmount;
            $baseDiscountAmount -= $shippingBaseDiscountAmount;

            $applied = true;
        }

        // TODO: do something with possible remaining discount amount

        return $applied;
    }
}
