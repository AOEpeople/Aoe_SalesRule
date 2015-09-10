<?php

class Aoe_SalesRule_Model_Quote_Freeshipping extends Mage_SalesRule_Model_Quote_Freeshipping
{
    public function __construct()
    {
        $this->setCode('discount');
    }

    /**
     * Collect information about free shipping for all address items
     *
     * NB: All of this functionality moved into the primary discount total collector
     *
     * @param  Mage_Sales_Model_Quote_Address $address
     *
     * @return $this
     */
    public function collect(Mage_Sales_Model_Quote_Address $address)
    {
        return $this;
    }
}
