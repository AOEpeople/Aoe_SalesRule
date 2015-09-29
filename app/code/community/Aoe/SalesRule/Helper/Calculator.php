<?php

class Aoe_SalesRule_Helper_Calculator extends Aoe_SalesRule_Helper_Data
{
    /** @var Mage_SalesRule_Model_Rule[][] */
    protected $rules = [];

    protected $legacyModeActive = null;

    public function getLegacyModeActive()
    {
        if ($this->legacyModeActive === null) {
            $this->legacyModeActive = false;
            $eventName = 'salesrule_validator_process';
            $property = new ReflectionProperty(Mage::app(), '_events');
            $property->setAccessible(true);
            $events = $property->getValue(Mage::app());
            foreach ($events as $area => $areaEvents) {
                if (!array_key_exists($eventName, $areaEvents)) {
                    $eventConfig = Mage::app()->getConfig()->getEventConfig($area, $eventName);
                    if (!$eventConfig) {
                        continue;
                    }
                } elseif ($areaEvents[$eventName] === false) {
                    continue;
                }

                $this->legacyModeActive = true;
                break;
            }
        }

        return $this->legacyModeActive;
    }

    public function getLegacyModeSkipIfApplied($store = null)
    {
        $skip = Mage::getStoreConfigFlag('aoe_salesrule/legacy_mode/skip_if_applied', $store);
        return $skip;
    }

    /**
     * Return the correct rule set
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param bool                   $forceReload
     *
     * @return Mage_SalesRule_Model_Rule[]
     */
    public function getRules(Mage_Sales_Model_Quote $quote, $forceReload = false)
    {
        $websiteId = intval($quote->getStore()->getWebsiteId());
        $customerGroupId = intval($quote->getCustomerGroupId());
        $couponCode = trim($quote->getCouponCode());

        $key = $websiteId . '_' . $customerGroupId . '_' . $couponCode;

        if ($forceReload) {
            unset($this->rules[$key]);
        }

        if (!isset($this->rules[$key])) {
            /** @var Mage_SalesRule_Model_Resource_Rule_Collection $rules */
            $rules = Mage::getResourceModel('salesrule/rule_collection');
            $rules->setValidationFilter($websiteId, $customerGroupId, $couponCode);
            $this->rules[$key] = $rules->getItems();
        }

        return $this->rules[$key];
    }

    /**
     * Attempt to apply a sales rule to a quote address
     *
     * @param Mage_SalesRule_Model_Rule      $rule
     * @param Mage_Sales_Model_Quote_Address $address
     *
     * @return bool
     */
    public function applyRule(Mage_SalesRule_Model_Rule $rule, Mage_Sales_Model_Quote_Address $address)
    {
        // Check is the rule is valid before applying
        if (!$this->canApplyRule($rule, $address)) {
            return false;
        }

        // Get all non-subscription items
        /** @var Mage_Sales_Model_Quote_Item_Abstract[] $allItems */
        $allItems = $address->getAllNonNominalItems();

        // Find which items this rule will apply to
        /** @var Mage_Sales_Model_Quote_Item_Abstract[] $validItems */
        $validItems = $this->filterForValidItems($rule, $allItems);

        // Fire rule event
        $applied = $this->fireRuleEvent($address, $rule, $allItems, $validItems);

        // Fire legacy events
        $fireLegacyEvents = $this->getLegacyModeActive() && !($applied && $this->getLegacyModeSkipIfApplied($address->getQuote()->getStore()));
        if ($fireLegacyEvents) {
            foreach ($validItems as $item) {
                $applied = $this->fireLegacyEvent($rule, $item, $address) || $applied;
            }
        }

        foreach ($allItems as $item) {
            // Round and limit discount amounts
            $this->fixDiscounts($item, $address->getQuote()->getQuoteCurrencyCode(), $address->getQuote()->getBaseCurrencyCode());

            // Add the item discounts
            $address->addTotalAmount('discount', -$item->getDiscountAmount());
            $address->addBaseTotalAmount('discount', -$item->getBaseDiscountAmount());

            // Check free shipping
            if ($rule->getSimpleFreeShipping() === Mage_SalesRule_Model_Rule::FREE_SHIPPING_ITEM) {
                $item->setFreeShipping($rule->getDiscountQty() ? $rule->getDiscountQty() : true);
            }
        }

        // Check free shipping
        if ($rule->getSimpleFreeShipping() === Mage_SalesRule_Model_Rule::FREE_SHIPPING_ADDRESS) {
            $address->setFreeShipping(true);
        }

        if ($applied) {
            // Record that the coupon code was applied
            if ($rule->getCouponType() != Mage_SalesRule_Model_Rule::COUPON_TYPE_NO_COUPON) {
                $address->setCouponCode($address->getQuote()->getCouponCode());
            }

            // Record that the rule was applied
            $this->addAppliedRule($address, $rule, $validItems);

            // Add a description for the applied rule
            $this->addRuleDescription($address, $rule);
        }

        return $applied;
    }

    /**
     * Fire an event to process this rule
     *
     * @param Mage_Sales_Model_Quote_Address         $address
     * @param Mage_SalesRule_Model_Rule              $rule
     * @param Mage_Sales_Model_Quote_Item_Abstract[] $allItems
     * @param Mage_Sales_Model_Quote_Item_Abstract[] $validItems
     *
     * @return bool
     */
    protected function fireRuleEvent(Mage_Sales_Model_Quote_Address $address, Mage_SalesRule_Model_Rule $rule, array $allItems, array $validItems)
    {
        $result = new Varien_Object(['applied' => false]);

        Mage::dispatchEvent(
            'sales_quote_address_discount_apply_rule_' . str_replace(' ', '', strtolower(trim($rule->getSimpleAction()))),
            [
                'address'     => $address,
                'rule'        => $rule,
                'all_items'   => $allItems,
                'valid_items' => $validItems,
                'result'      => $result,
            ]
        );

        return (bool)$result->getData('applied');
    }

    /**
     * Fire a legacy event to process this rule for this item
     *
     * @param Mage_SalesRule_Model_Rule            $rule
     * @param Mage_Sales_Model_Quote_Item_Abstract $item
     * @param Mage_Sales_Model_Quote_Address       $address
     *
     * @return bool
     */
    protected function fireLegacyEvent(Mage_SalesRule_Model_Rule $rule, Mage_Sales_Model_Quote_Item_Abstract $item, Mage_Sales_Model_Quote_Address $address)
    {
        // Prepare values for the event
        $quote = $address->getQuote();
        $qty = ($rule->getDiscountQty() ? min($item->getTotalQty(), $rule->getDiscountQty()) : $item->getTotalQty());

        // Prepare result object
        $result = new Varien_Object(['discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'applied' => false]);

        // Fire legacy event
        Mage::dispatchEvent(
            'salesrule_validator_process',
            [
                'quote'   => $quote,
                'address' => $address,
                'rule'    => $rule,
                'item'    => $item,
                'qty'     => $qty,
                'result'  => $result,
            ]
        );

        // Save discount amounts
        $item->setDiscountAmount($item->getDiscountAmount() + $result->getData('discount_amount'));
        $item->setBaseDiscountAmount($item->getBaseDiscountAmount() + $result->getData('base_discount_amount'));

        // brain-dead check to see if a rule was applied
        return ($result->getData('applied') || $result->getData('discount_amount') != 0.0);
    }

    /**
     * Round discount amounts and bound them to the total row price
     *
     * @param Mage_Sales_Model_Quote_Item_Abstract $item
     * @param string                               $currencyCode
     * @param string                               $baseCurrencyCode
     *
     * @return Mage_Sales_Model_Quote_Item_Abstract
     */
    protected function fixDiscounts(Mage_Sales_Model_Quote_Item_Abstract $item, $currencyCode, $baseCurrencyCode)
    {
        // Get discount amounts
        $itemDiscountAmount = $item->getDiscountAmount();
        $itemOriginalDiscountAmount = $item->getOriginalDiscountAmount();
        $itemBaseDiscountAmount = $item->getBaseDiscountAmount();
        $itemBaseOriginalDiscountAmount = $item->getBaseOriginalDiscountAmount();

        // Round discount amounts
        $itemDiscountAmount = $this->round($itemDiscountAmount, $currencyCode);
        $itemOriginalDiscountAmount = $this->round($itemOriginalDiscountAmount, $currencyCode);
        $itemBaseDiscountAmount = $this->round($itemBaseDiscountAmount, $baseCurrencyCode);
        $itemBaseOriginalDiscountAmount = $this->round($itemBaseOriginalDiscountAmount, $baseCurrencyCode);

        // Get item unit prices
        $itemPrice = $this->getItemPrice($item);
        $baseItemPrice = $this->getItemBasePrice($item);
        $itemOriginalPrice = $this->getItemOriginalPrice($item);
        $baseItemOriginalPrice = $this->getItemBaseOriginalPrice($item);

        // Discount cannot exceed row total
        $itemDiscountAmount = min($itemDiscountAmount, $itemPrice * $item->getQty());
        $itemOriginalDiscountAmount = min($itemOriginalDiscountAmount, $itemOriginalPrice * $item->getQty());
        $itemBaseDiscountAmount = min($itemBaseDiscountAmount, $baseItemPrice * $item->getQty());
        $itemBaseOriginalDiscountAmount = min($itemBaseOriginalDiscountAmount, $baseItemOriginalPrice * $item->getQty());

        // Save fixed discount amounts
        $item->setDiscountAmount($itemDiscountAmount);
        $item->setOriginalDiscountAmount($itemOriginalDiscountAmount);
        $item->setBaseDiscountAmount($itemBaseDiscountAmount);
        $item->setBaseOriginalDiscountAmount($itemBaseOriginalDiscountAmount);

        return $item;
    }

    /**
     * Add the rule ID to the applied rule list for the quote, address, and items
     *
     * @param Mage_Sales_Model_Quote_Address         $address
     * @param Mage_SalesRule_Model_Rule              $rule
     * @param Mage_Sales_Model_Quote_Item_Abstract[] $items
     *
     * @return $this
     */
    protected function addAppliedRule(Mage_Sales_Model_Quote_Address $address, Mage_SalesRule_Model_Rule $rule, array $items)
    {
        // Record that the rule id was applied
        $address->getQuote()->setAppliedRuleIds($this->mergeIds($address->getQuote()->getAppliedRuleIds(), $rule->getId()));
        $address->setAppliedRuleIds($this->mergeIds($address->getAppliedRuleIds(), $rule->getId()));
        foreach ($items as $item) {
            $item->setAppliedRuleIds($this->mergeIds($item->getAppliedRuleIds(), $rule->getId()));
        }

        return $this;
    }

    /**
     * Add the rule label to the address for later usage
     *
     * @param Mage_Sales_Model_Quote_Address $address
     * @param Mage_SalesRule_Model_Rule      $rule
     *
     * @return $this
     */
    protected function addRuleDescription(Mage_Sales_Model_Quote_Address $address, Mage_SalesRule_Model_Rule $rule)
    {
        $descriptions = $address->getDiscountDescriptionArray();

        $label = trim($rule->getStoreLabel($address->getQuote()->getStore()));
        if (empty($label) && strlen($address->getCouponCode())) {
            $label = $address->getCouponCode();
        }

        if (!empty($label)) {
            $descriptions[$rule->getId()] = $label;
        }

        $address->setDiscountDescriptionArray($descriptions);

        return $this;
    }
}
