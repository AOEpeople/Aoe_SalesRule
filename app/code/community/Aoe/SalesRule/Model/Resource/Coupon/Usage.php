<?php

class Aoe_SalesRule_Model_Resource_Coupon_Usage extends Mage_SalesRule_Model_Resource_Coupon_Usage
{
    /**
     * Increment times_used counter
     *
     * NB: This replaces the parent method to fix a possible race condition on usage tracking.
     *
     * @param int $customerId
     * @param int $couponId
     */
    public function updateCustomerCouponTimesUsed($customerId, $couponId)
    {
        $read = $this->_getReadAdapter();
        $select = $read->select();
        $select->from($this->getMainTable(), ['times_used'])
            ->where('coupon_id = :coupon_id')
            ->where('customer_id = :customer_id');

        $timesUsed = $read->fetchOne($select, [':coupon_id' => $couponId, ':customer_id' => $customerId]);

        if ($timesUsed > 0) {
            $this->_getWriteAdapter()->update(
                $this->getMainTable(),
                [
                    'times_used' => new Zend_Db_Expr('times_used+1'),
                ],
                [
                    'coupon_id = ?'   => $couponId,
                    'customer_id = ?' => $customerId,
                ]
            );
        } else {
            $this->_getWriteAdapter()->insert(
                $this->getMainTable(),
                [
                    'coupon_id'   => $couponId,
                    'customer_id' => $customerId,
                    'times_used'  => 1,
                ]
            );
        }
    }
}
