<?php

class Aoe_SalesRule_Model_Quote_Discount extends Mage_SalesRule_Model_Quote_Discount
{
    /**
     * Collect address discount amount
     *
     * @param  Mage_Sales_Model_Quote_Address $address
     *
     * @return $this
     */
    public function collect(Mage_Sales_Model_Quote_Address $address)
    {
        // Store address with collector for use with some helper methods
        $this->_setAddress($address);

        // Extract some needed data from the address
        $quote = $address->getQuote();

        // Reset the quote discount data
        $this->resetQuote($quote);

        // Reset the address discount data
        $this->resetAddress($address);

        // Return all non-subscription items in this part of the quote (exit early if we have none)
        /** @var Mage_Sales_Model_Quote_Item_Abstract[] $allItems */
        $allItems = $address->getAllNonNominalItems();
        if (!count($allItems)) {
            return $this;
        }

        // Reset the items discount data
        foreach ($allItems as $item) {
            $this->resetItem($item);
        }

        // Get the sales rule calculator
        /** @var Aoe_SalesRule_Helper_Calculator $calculator */
        $calculator = Mage::helper('Aoe_SalesRule/Calculator');

        // Iterate over the rules
        foreach ($calculator->getRules($quote) as $rule) {
            if ($calculator->applyRule($rule, $address) && $rule->getStopRulesProcessing()) {
                break;
            }
        }

        // Add the item discounts
        foreach ($allItems as $item) {
            $address->addTotalAmount('discount', -$item->getDiscountAmount());
            $address->addBaseTotalAmount('discount', -$item->getBaseDiscountAmount());
        }

        // Add in any shipping discount
        $this->_addAmount(-$address->getShippingDiscountAmount());
        $this->_addBaseAmount(-$address->getBaseShippingDiscountAmount());

        // Manually update some subtotals (not even sure WHY Magento stores this value since the getters dynamically calculate the value anyway)
        $address->setSubtotalWithDiscount($address->getSubtotal() + $address->getDiscountAmount());
        $address->setBaseSubtotalWithDiscount($address->getBaseSubtotal() + $address->getBaseDiscountAmount());

        // Update the address discount description
        $description = $address->getDiscountDescriptionArray();
        if (!$description && $address->getQuote()->getItemVirtualQty() > 0) {
            $description = $address->getQuote()->getBillingAddress()->getDiscountDescriptionArray();
        }
        $description = ($description && is_array($description) ? implode(', ', array_unique($description)) : '');
        $address->setDiscountDescription($description);

        // Check for free shipping
        if (!$address->getFreeShipping()) {
            $allFree = true;
            foreach ($allItems as $item) {
                $itemFree = ($item->getFreeShipping() === true || $item->getFreeShipping() >= $item->getQty());
                $allFree = $allFree && $itemFree;
            }
            $address->setFreeShipping($allFree);
        }

        return $this;
    }

    protected function resetQuote(Mage_Sales_Model_Quote $quote)
    {
        if (!$quote->getData('__applied_rules_reset__')) {
            $quote->setAppliedRuleIds('');
            $quote->setData('__applied_rules_reset__', true);
        }
    }

    protected function resetAddress(Mage_Sales_Model_Quote_Address $address)
    {
        $address->setDiscountAmount(0.0);
        $address->setBaseDiscountAmount(0.0);
        $address->setSubtotalWithDiscount($address->getSubtotal());
        $address->setBaseSubtotalWithDiscount($address->getBaseSubtotal());
        $address->setDiscountDescription('');
        $address->setDiscountDescriptionArray([]);
        if (!$address->getData('__applied_rules_reset__')) {
            $address->setAppliedRuleIds('');
            $address->setData('__applied_rules_reset__', true);
        }
        $address->setShippingDiscountAmount(0);
        $address->setBaseShippingDiscountAmount(0);
        $address->setFreeShipping(false);
    }

    protected function resetItem(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        $item->setDiscountAmount(0.0);
        $item->setBaseDiscountAmount(0.0);
        $item->setRowTotalWithDiscount($item->getRowTotal());
        $item->setBaseRowTotalWithDiscount($item->getBaseRowTotal());
        $item->setDiscountPercent(0);
        $item->setAppliedRuleIds('');
        $item->setFreeShipping(false);
    }
}
