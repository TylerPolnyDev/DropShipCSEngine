<?php 
namespace Veratics\OrderManagement\Api;
 
 
interface SetStateInterface {

    /**
     * Sets the state and status of an order
     *
     * @api
     * @param mixed $entity
     * @return \Magento\Sales\Api\Data\OrderInterface
     */
    public function setState($entity);
}
