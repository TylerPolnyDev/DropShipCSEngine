<?php
namespace REDACTED\OrderManagement\Model;


class SetState {

    /**
     * Sets the state and status of an order
     *
     * @api
     * @param mixed $entity
     * @return \Magento\Sales\Api\Data\OrderInterface
     */
    public function setState($entity){

        $entity_id = $entity['entity_id'];
        $status = $entity['status'];
        $state = $entity['state'];

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order = $objectManager->create('\Magento\Sales\Model\OrderRepository')->get($entity_id);

        $order->setStatus($status);
        $order->setState($state);

        $order->save();

        return $order;       
        
    }
}
