<?php

class Aoe_SalesRule_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Round a currency amount
     *
     * @param float  $amount
     * @param string $currencyCode
     *
     * @return float
     */
    public function round($amount, $currencyCode)
    {
        // TODO: Use proper round based on currency code
        return round($amount, 2);
    }

    public function getItemRuleQty(Mage_Sales_Model_Quote_Item_Abstract $item, Mage_SalesRule_Model_Rule $rule)
    {
        return $rule->getDiscountQty() ? min($item->getTotalQty(), $rule->getDiscountQty()) : $item->getTotalQty();
    }

    /**
     * Return item price
     *
     * @param Mage_Sales_Model_Quote_Item_Abstract $item
     *
     * @return float
     */
    public function getItemPrice(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        $price = $item->getDiscountCalculationPrice();
        $calcPrice = $item->getCalculationPrice();

        return ($price !== null) ? $price : $calcPrice;
    }

    /**
     * Return item original price
     *
     * @param Mage_Sales_Model_Quote_Item_Abstract $item
     *
     * @return float
     */
    public function getItemOriginalPrice(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        return Mage::helper('tax')->getPrice($item, $item->getOriginalPrice(), true);
    }

    /**
     * Return item base price
     *
     * @param Mage_Sales_Model_Quote_Item_Abstract $item
     *
     * @return float
     */
    public function getItemBasePrice(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        $price = $item->getDiscountCalculationPrice();

        return ($price !== null) ? $item->getBaseDiscountCalculationPrice() : $item->getBaseCalculationPrice();
    }

    /**
     * Return item base original price
     *
     * @param Mage_Sales_Model_Quote_Item_Abstract $item
     *
     * @return float
     */
    public function getItemBaseOriginalPrice(Mage_Sales_Model_Quote_Item_Abstract $item)
    {
        return Mage::helper('tax')->getPrice($item, $item->getBaseOriginalPrice(), true);
    }

    /**
     * Check if a rule applies to a quote address
     *
     * @param Mage_SalesRule_Model_Rule      $rule
     * @param Mage_Sales_Model_Quote_Address $address
     *
     * @return bool
     */
    public function canApplyRule(Mage_SalesRule_Model_Rule $rule, Mage_Sales_Model_Quote_Address $address)
    {
        // If rule require a coupon, verify the coupon code and usage
        if ($rule->getCouponType() != Mage_SalesRule_Model_Rule::COUPON_TYPE_NO_COUPON) {
            // Grab the coupon code from the quote
            $couponCode = trim($address->getQuote()->getCouponCode());

            // Rule requires a coupon and none was applied
            if (empty($couponCode)) {
                return false;
            }

            // Load the referenced coupon
            /** @var Mage_SalesRule_Model_Coupon $coupon */
            $coupon = Mage::getModel('salesrule/coupon')->load($couponCode, 'code');

            // Rule requires a coupon and the provided code is not valid
            if (!$coupon->getId() || $coupon->getRuleId() != $rule->getId()) {
                return false;
            }

            // Check coupon global usage limit
            if ($coupon->getUsageLimit() && $coupon->getTimesUsed() >= $coupon->getUsageLimit()) {
                return false;
            }

            // Check per customer usage limit - only for logged in customers
            if ($coupon->getUsagePerCustomer() && $address->getQuote()->getCustomerId()) {
                $useCount = $this->getCustomerCouponUseCount($address->getQuote()->getCustomerId(), $coupon);
                if ($useCount >= $coupon->getUsagePerCustomer()) {
                    return false;
                }
            }
        }

        if ($rule->getUsesPerCustomer() && $address->getQuote()->getCustomerId()) {
            $useCount = $this->getCustomerRuleUseCount($address->getQuote()->getCustomerId(), $rule);
            if ($useCount >= $rule->getUsesPerCustomer()) {
                return false;
            }
        }

        // Since the rule passed all the pre-checks, if the rule conditions validate then the rule applies
        return $rule->validate($address);
    }

    /**
     * @param Mage_SalesRule_Model_Rule              $rule
     * @param Mage_Sales_Model_Quote_Item_Abstract[] $allItems
     *
     * @return Mage_Sales_Model_Quote_Item_Abstract[]
     */
    public function filterForValidItems(Mage_SalesRule_Model_Rule $rule, array $allItems)
    {
        // Array of items this rule will apply to
        /** @var Mage_Sales_Model_Quote_Item_Abstract[] $validItems */
        $validItems = [];

        // Loop all items checking if rule should apply
        foreach ($allItems as $item) {
            // Skip items flagged for no discount
            if ($item->getNoDiscount()) {
                continue;
            }

            // Skip non-calculated child items
            if ($item->getParentItemId() && !$item->isChildrenCalculated()) {
                continue;
            }

            // Skip non-calculated parent items
            if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                continue;
            }

            // NB: validate() doesn't look like it exists because getActions() has the wrong @return type
            if (!$rule->getActions()->validate($item)) {
                continue;
            }

            // Add item to the valid item list
            $validItems[] = $item;
        }

        return $validItems;
    }

    /**
     * Look up how many times a coupon was used by a customer
     *
     * @param int                         $customerId
     * @param Mage_SalesRule_Model_Coupon $coupon
     *
     * @return int
     */
    public function getCustomerCouponUseCount($customerId, Mage_SalesRule_Model_Coupon $coupon)
    {
        $couponUsage = new Varien_Object();
        Mage::getResourceModel('salesrule/coupon_usage')->loadByCustomerCoupon(
            $couponUsage,
            $customerId,
            $coupon->getId()
        );

        return intval($couponUsage->getTimesUsed());
    }

    /**
     * Look up how many times a rule was used by a customer
     *
     * @param int                       $customerId
     * @param Mage_SalesRule_Model_Rule $rule
     *
     * @return int
     */
    public function getCustomerRuleUseCount($customerId, Mage_SalesRule_Model_Rule $rule)
    {
        /** @var Mage_SalesRule_Model_Rule_Customer $ruleCustomer */
        $ruleCustomer = Mage::getModel('salesrule/rule_customer');
        $ruleCustomer->loadByCustomerRule($customerId, $rule->getId());

        return intval($ruleCustomer->getTimesUsed());
    }

    /**
     * Merge two sets of rule ids
     *
     * @param int[]|string $a1
     * @param int[]|string $a2
     * @param bool         $asString
     *
     * @return int[]
     */
    protected function mergeIds($a1, $a2, $asString = true)
    {
        if (!is_array($a1)) {
            $a1 = empty($a1) ? [] : explode(',', $a1);
        }

        if (!is_array($a2)) {
            $a2 = empty($a2) ? [] : explode(',', $a2);
        }

        $a = array_unique(array_map('intval', array_merge($a1, $a2)));

        return ($asString ? implode(',', $a) : $a);
    }
}
