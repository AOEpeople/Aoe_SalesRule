<?php

class Aoe_SalesRule_Model_Condition_Product_Found extends Mage_SalesRule_Model_Rule_Condition_Product_Found
{
    /**
     * validate
     *
     * @param Varien_Object $object Quote
     *
     * @return boolean
     */
    public function validate(Varien_Object $object)
    {
        $all = $this->getAggregator() === 'all';
        $true = (bool)$this->getValue();
        $found = false;

        foreach ($object->getQuote()->getAllVisibleItems() as $item) {
            $found = $all;
            foreach ($this->getConditions() as $cond) {
                $validated = $cond->validate($item);
                if (($all && !$validated) || (!$all && $validated)) {
                    $found = $validated;
                    break;
                }
            }
            if (($found && $true) || (!$true && $found)) {
                break;
            }
        }

        if ($found && $true) {
            // found an item and we're looking for existing one
            return true;
        } elseif (!$found && !$true) {
            // not found and we're making sure it doesn't exist
            return true;
        }

        return false;
    }
}
