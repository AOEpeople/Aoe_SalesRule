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
            // Get max quantity (min or rule max qty or item qty)
            $qty = $helper->getItemRuleQty($item, $rule);

            // Skip zero quantity
            if ($qty <= 0.0) {
                continue;
            }

            // Get unit price
            $itemPrice = $helper->getItemPrice($item);
            $itemBasePrice = $helper->getItemBasePrice($item);

            // Get row price
            $itemRowPrice = ($itemPrice * $item->getTotalQty());
            $itemBaseRowPrice = ($itemBasePrice * $item->getTotalQty());

            // Get discountable price
            $itemDiscountablePrice = ($itemPrice * $qty);
            $itemBaseDiscountablePrice = ($itemBasePrice * $qty);

            // Save price data for later
            $itemPrices[$item->getId()] = [
                $itemPrice,
                $itemBasePrice,
                $itemRowPrice,
                $itemBaseRowPrice,
                $itemDiscountablePrice,
                $itemBaseDiscountablePrice,
            ];

            // Add row prices to running totals
            $ruleTotalItemsPrice += $itemDiscountablePrice;
            $ruleTotalBaseItemsPrice += $itemBaseDiscountablePrice;
        }

        $startDiscountAmount = $discountAmount;
        $startBaseDiscountAmount = $baseDiscountAmount;

        foreach ($validItems as $item) {
            // Skip the skipped items
            if (!isset($itemPrices[$item->getId()])) {
                continue;
            }

            // Flag indicating the rule was applied
            $applied = true;

            // Extract the pre-calculate price data
            list($itemPrice, $itemBasePrice, $itemRowPrice, $itemBaseRowPrice, $itemDiscountablePrice, $itemBaseDiscountablePrice) = $itemPrices[$item->getId()];

            // Calculate remaining row amount
            $itemRemainingRowPrice = max($itemRowPrice - $item->getDiscountAmount(), 0);
            $itemRemainingBaseRowPrice = max($itemBaseRowPrice - $item->getBaseDiscountAmount(), 0);

            // Calculate price factor
            $priceFactor = ($itemDiscountablePrice / $ruleTotalItemsPrice);
            $basePriceFactor = ($itemBaseDiscountablePrice / $ruleTotalBaseItemsPrice);

            // Calculate (and round) the item discount amount
            $itemDiscountAmount = $helper->round($startDiscountAmount * $priceFactor, $quote->getQuoteCurrencyCode());
            $itemBaseDiscountAmount = $helper->round($startBaseDiscountAmount * $basePriceFactor, $quote->getBaseCurrencyCode());

            // Ensure discount does not exceed the remaining discount, max item discount, or remaining row price
            $itemDiscountAmount = max(min($itemDiscountAmount, $discountAmount, $itemDiscountablePrice, $itemRemainingRowPrice), 0.0);
            $itemBaseDiscountAmount = max(min($itemBaseDiscountAmount, $baseDiscountAmount, $itemBaseDiscountablePrice, $itemRemainingBaseRowPrice), 0.0);

            // Update the item discount
            $item->setDiscountAmount($item->getDiscountAmount() + $itemDiscountAmount);
            $item->setBaseDiscountAmount($item->getBaseDiscountAmount() + $itemBaseDiscountAmount);

            // This is a bit wonky, but needed for taxes
            $item->setOriginalDiscountAmount($item->getOriginalDiscountAmount() + $itemDiscountAmount);
            $item->setBaseOriginalDiscountAmount($item->getBaseOriginalDiscountAmount() + $itemBaseDiscountAmount);

            // Subtract from the total remaining discount amount
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

            $shippingDiscountAmount = max(min($discountAmount, $shippingAmount), 0.0);
            $shippingBaseDiscountAmount = max(min($baseDiscountAmount, $baseShippingAmount), 0.0);

            $address->setShippingDiscountAmount($address->getShippingDiscountAmount() + $shippingDiscountAmount);
            $address->setBaseShippingDiscountAmount($address->getBaseShippingDiscountAmount() + $shippingBaseDiscountAmount);

            // Subtract from the total discount amount
            $discountAmount -= $shippingDiscountAmount;
            $baseDiscountAmount -= $shippingBaseDiscountAmount;

            $applied = true;
        }

        // Do something with possible remaining discount amount
        if ($applied && $discountAmount > 0.0) {
            foreach ($validItems as $item) {
                // Skip the skipped items
                if (!isset($itemPrices[$item->getId()])) {
                    continue;
                }

                // Extract the pre-calculate price data
                list($itemPrice, $itemBasePrice, $itemRowPrice, $itemBaseRowPrice, $itemDiscountablePrice, $itemBaseDiscountablePrice) = $itemPrices[$item->getId()];

                // Calculate remaining row amount
                $itemRemainingRowPrice = max($itemRowPrice - $item->getDiscountAmount(), 0);
                $itemRemainingBaseRowPrice = max($itemBaseRowPrice - $item->getBaseDiscountAmount(), 0);

                // Apply the discount
                if ($itemRemainingRowPrice >= $discountAmount && $itemRemainingBaseRowPrice >= $baseDiscountAmount) {
                    // Update the item discount
                    $item->setDiscountAmount($item->getDiscountAmount() + $discountAmount);
                    $item->setBaseDiscountAmount($item->getBaseDiscountAmount() + $baseDiscountAmount);

                    // This is a bit wonky, but needed for taxes
                    $item->setOriginalDiscountAmount($item->getOriginalDiscountAmount() + $discountAmount);
                    $item->setBaseOriginalDiscountAmount($item->getBaseOriginalDiscountAmount() + $baseDiscountAmount);

                    // Zero out remaining discount
                    $discountAmount = 0.0;
                    $baseDiscountAmount = 0.0;
                }

                // If we've used the discount, exit the loop early
                if ($discountAmount <= 0.0) {
                    break;
                }
            }
        }

        return $applied;
    }
}
