<?php

class Aoe_SalesRule_Model_Condition_Product_Subselect extends Mage_SalesRule_Model_Rule_Condition_Product_Subselect
{
    /**
     * validate
     *
     * @param Varien_Object $object Quote
     * @return boolean
     */
    public function validate(Varien_Object $object)
    {
        if (!$this->getConditions()) {
            return false;
        }

        // Fix a bug with the underlying combine condition check
        $value = $this->getValue();
        $this->setValue(true);

        $total = 0;
        foreach ($object->getQuote()->getAllVisibleItems() as $item) {
            if (Mage_SalesRule_Model_Rule_Condition_Product_Combine::validate($item)) {
                $total += $item->getData($this->getAttribute());
            }
        }

        // Fix a bug with the underlying combine condition check
        $this->setValue($value);

        return $this->validateAttribute($total);
    }
}
