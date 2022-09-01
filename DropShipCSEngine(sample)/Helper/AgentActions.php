<?php
/**
 * Copyright (c) 2022, Tyler Polny
 * All rights reserved.
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree.
 */

namespace REDACTED\OrderManagement\Helper;

class AgentActions
{
    protected $_utils;
    protected $_zendeskUtils;
    protected $_adminUtils;
    protected $_orderRepository;
    protected $_searchCriteriaBuilder;
    protected $_entityResolver;
    protected $_saveHandler;
    private \Zend\Log\Logger $_logger;
    private $Test;
    private $QueueFieldID;
    private $OrderIncrementIDFieldID;
    private $vendorCodeFieldID;
    private $supplierOrderNumberFieldID;



    public function __construct(\Magento\Sales\Model\OrderRepository $orderRepository,
                                \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
                                \REDACTED\OrderManagement\Helper\Utils $utils,
                                \REDACTED\OrderManagement\Helper\ZendeskUtils $zendeskUtils,
                                \REDACTED\AdminSupportTools\Helper\Data $adminUtils,
                                \Amasty\Orderattr\Model\Entity\EntityResolver $entityResolver,
                                \Amasty\Orderattr\Model\Entity\Handler\Save $saveHandler)
    {
        $this->_zendeskUtils = $zendeskUtils;
        $this->_utils = $utils;
        $this->_orderRepository = $orderRepository;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_adminUtils = $adminUtils;
        $this->_entityResolver = $entityResolver;
        $this->_saveHandler = $saveHandler;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/dropShipCS.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->_logger = $logger;

        $this->Test = $this->_utils->Test();
        if ($this->Test){
            $this->QueueFieldID = "1500011234061";
            $this->OrderIncrementIDFieldID = "1500012542762";
            $this->vendorCodeFieldID = "1500005678702";
            $this->supplierOrderNumberFieldID = "1500015543821";
        }else{
            $this->QueueFieldID = "1500011596361";
            $this->OrderIncrementIDFieldID = "360041196794";
            $this->vendorCodeFieldID = "360055641194";
            $this->supplierOrderNumberFieldID = "1500015544161";
        }

    }

/// GENERAL FUNCTIONS########################################################################
/// functions used in various sections to take actions needed for different events for orders

    public function unitTester($incrementId,$ticketID){
        if ($this->_utils->Test()){
            try{
                $this->_logger->info("this is a test of the logger");
            }catch (\Exception $e) {
                $this->_logger->info(' Caught exception: ' . $e->getMessage());
                return "rejected";
            }
        }
    }



    /**
     * called when our system parses a trigger for notice of cancellation specifically in a response to a cancellation request.
     * @param string $orderObj object for order confirmed canceled
     * @param string $ticketID
     * @action Sets state/status to holded/canceled_refund_requested
     * @action Notifies accounting of cancellation by email via side conversation
     * @action replies to the ticketID provided notifying the end customer of cancellation using positive verbiage
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */
    public function orderConfirmedCanceled($orderObj,$ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        $customerName = $order->getCustomerFirstname();
        $state = $order->getState();
        if ($state != "holded"){
            $order->hold();
        }
        $status = $order->getStatus();
        if (($state == 'closed')||($status == "canceled_supplier_confirmed")||($status == "canceled_supplier_confirmed_nc")){
            $this->_zendeskUtils->rejectTicket($ticketID,"PO# ".$incrementId." is either already refunded or in queue to be refunded by automation.");
            return "rejected";
        }
        $order->setStatus('canceled_refund_requested');
        $format = $this->_adminUtils->getTranslationFromKey("CONFIRMED_CANCELED_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $formatReply = $this->_adminUtils->getTranslationFromKey("CONFIRMED_CANCELED_TO_CUSTOMER");
        $Reply = sprintf($formatReply,$customerName);
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Reply,
                    "public" => "true"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "cancellation_request"
                    )
                ),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);

        $this->_logger->info("Function Applied: orderConfirmedCanceled");
    }

    /**
     * called when our system parses a trigger for notice of cancellation specifically in a response to a cancellation request.
     * @param string $orderObj object for order confirmed canceled
     * @param string $ticketID
     * @action Sets state/status to holded/canceled_refund_requested
     * @action Notifies accounting of cancellation by email via side conversation
     * @action replies to the ticketID provided notifying the end customer of cancellation using positive verbiage
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */

    public function orderConfirmedCanceledSendBySideConvo($orderObj,$ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        if ($this->_utils->Test()){
            $customerName = "TEST CUSTOMER";
            $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
        }else{
            $customerName = $order->getCustomerFirstname();
            $customerEmail = $order->getCustomerEmail();
        }

        $state = $order->getState();
        if ($state != "holded"){
            $order->hold();
        }
        $status = $order->getStatus();
        if (($state == 'closed')||($status == "canceled_supplier_confirmed")||($status == "canceled_supplier_confirmed_nc")){
            $this->_zendeskUtils->rejectTicket($ticketID,"PO# ".$incrementId." is either already refunded or in queue to be refunded by automation.");
            return "rejected";
        }
        $order->setStatus('canceled_refund_requested');
        $format = $this->_adminUtils->getTranslationFromKey("CONFIRMED_CANCELED_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $format = $this->_adminUtils->getTranslationFromKey("CONFIRMED_CANCELED_TO_CUSTOMER");
        $body = sprintf($format,$customerName);
        $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED Order# ".sprintf("%'.09d", $incrementId)." Confirmed Cancelled",$body,$customerEmail);
        $formatNote = $this->_adminUtils->getTranslationFromKey("CONFIRMED_CANCELED_INTERNAL_NOTE_TICKET");
        $Note = sprintf($formatNote,sprintf("%'.09d", $incrementId),sprintf("%'.09d", $incrementId),$customerName,sprintf("%'.09d", $incrementId),sprintf("%'.09d", $incrementId));
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Note,
                    "public" => "false"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "cancellation_request"
                    )
                ),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);
        $this->_logger->info("Function Applied: orderConfirmedCanceledSendBySideConvo");
    }


    /**
     * called when our system parses a trigger for confirmation of shipment.
     * @param string $orderObj object for order confirmed shipped
     * @param string $ticketID
     * @action if order complete (sets state/status to complete/canceled_refund_requested)
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */
    public function orderConfirmedShipped($orderObj,$ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }

        $incrementId = $order->getIncrementId();
        $customerName = $order->getCustomerFirstname();

        //$state = $order->getState();
        //if ($state == "complete"){
        //    $order->setStatus('manual_complete');
        //}

        $format = $this->_adminUtils->getTranslationFromKey("CONFIRMED_SHIPPED_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $formatReply = $this->_adminUtils->getTranslationFromKey("CONFIRMED_SHIPPED_TO_CUSTOMER");
        $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId));
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Reply,
                    "public" => "true"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "order_status_request"
                    )
                ),
                "subject" => "REDACTED Order #".sprintf("%'.09d", $incrementId)." - Confirmed Shipped",
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);

        $this->_logger->info("Function Applied: orderConfirmedShipped");

    }

    /**
     * called when our system parses a trigger for stating that a requested cancellation has been denied.
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action replies to the ticketID provided notifying the end customer of the status of their request
     * @action updates ticket subject
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */
    public function orderCancellationDenied($orderObj,$ticketID){

        try{
            $order = $orderObj;
            $items = $order->getAllItems();
            foreach ($items as $item) {
                $sku = $item->getSku();
                $vendorCode = substr($sku,0,3);
            }

            $incrementId = $order->getIncrementId();
            $customerName = $order->getCustomerFirstname();
            $formatReply = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_TO_CUSTOMER");
            $Reply = sprintf($formatReply,$customerName);
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Reply,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => $vendorCode
                        ),
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "cancellation_request"
                        )
                    ),
                    "subject" => "REDACTED Order #".$incrementId." - Cancellation Denied, Order has already Shipped",
                    "status" => "solved"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
            $format = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_INTERNAL_NOTE_ORDER");
            $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
            $order->addStatusHistoryComment($body);
            $order->save();
            $this->_logger->info("Function Applied: orderCancellationDenied");

        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }
    /**
     * called when our system parses a trigger for stating that a requested cancellation has been denied.
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action replies to the ticketID provided notifying the end customer of the status of their request
     * @action updates ticket subject
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */
    public function orderCancellationDeniedSideConvo($orderObj,$ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }

        $incrementId = $order->getIncrementId();
        if ($this->_utils->Test()){
            $customerName = "TEST CUSTOMER";
            $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
        }else{
            $customerName = $order->getCustomerFirstname();
            $customerEmail = $order->getCustomerEmail();
        }
        $format = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_TO_CUSTOMER");
        $body = sprintf($format,$customerName);
        $this->_zendeskUtils->create_side_convo($ticketID,"Too Late To Cancel - REDACTED Order ".sprintf("%'.09d", $incrementId),$body,$customerEmail);
        $formatNote = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_INTERNAL_NOTE_TICKET");
        $Note = sprintf($formatNote,$customerName,sprintf("%'.09d", $incrementId),sprintf("%'.09d", $incrementId));
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Note,
                    "public" => "false"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "cancellation_request"
                    )
                ),
                "subject" => "REDACTED Order# ".$incrementId."-Cancellation Denied-Order Shipped",
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);
        $format = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();

        $this->_logger->info("Function Applied: orderCancellationDeniedSideConvo");

    }

    /**
     * called when our system parses a trigger for notice of cancellation any where other than a response to a cancellation request.
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action Sets state/status to holded/canceled_refund_requested
     * @action Notifies accounting of cancellation by email via side conversation
     * @action replies to the ticketID provided notifying the end customer of cancellation using apologetic verbiage
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */
    public function orderCanceledWithOutRequest($orderObj,$ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
        $state = $order->getState();
        if ($state != "holded"){
            $order->hold();
        }
        $status = $order->getStatus();
        if (($state == 'closed')||($status == "canceled_supplier_confirmed")||($status == "canceled_supplier_confirmed_nc")){
            $this->_zendeskUtils->rejectTicket($ticketID,"PO# ".$incrementId." is either already refunded or in queue to be refunded by automation.");
            return "rejected";
        }
        $order->setStatus('canceled_refund_requested');

        $format = $this->_adminUtils->getTranslationFromKey("CANCELLATION_NOTICE_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $formatReply = $this->_adminUtils->getTranslationFromKey("CANCELLATION_NOTICE_TO_CUSTOMER");
        $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId));
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Reply,
                    "public" => "true"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "order_status_request"
                    )
                ),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);
        $this->_logger->info("Function Applied: orderCanceledWithOutRequest");

    }
    /**
     * called when our system parses a trigger for notice of cancellation any where other than a response to a cancellation request.
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action Sets state/status to holded/canceled_refund_requested
     * @action Notifies accounting of cancellation by email via side conversation
     * @action replies to the ticketID provided notifying the end customer of cancellation using apologetic verbiage
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */

    public function orderCanceledWithOutRequestContactBySideConvo($orderObj,$ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        if ($this->_utils->Test()){
            $customerName = "TEST CUSTOMER";
            $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
        }else{
            $customerName = $order->getCustomerFirstname();
            $customerEmail = $order->getCustomerEmail();
        }
        $state = $order->getState();
        if ($state != "holded"){
            $order->hold();
        }
        $status = $order->getStatus();
        if (($state == 'closed')||($status == "canceled_supplier_confirmed")||($status == "canceled_supplier_confirmed_nc")){
            $this->_zendeskUtils->rejectTicket($ticketID,"PO# ".$incrementId." is either already refunded or in queue to be refunded by automation.");
            return "rejected";
        }
        $order->setStatus('canceled_refund_requested');

        $format = $this->_adminUtils->getTranslationFromKey("CANCELLATION_NOTICE_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $format = $this->_adminUtils->getTranslationFromKey("CANCELLATION_NOTICE_TO_CUSTOMER_SIDE_CONVO");
        $body = sprintf($format,$customerName,sprintf("%'.09d", $incrementId));
        $this->_zendeskUtils->create_side_convo($ticketID,"Order Unavailable - REDACTED Order ".sprintf("%'.09d", $incrementId),$body,$customerEmail);
        $update = array(
            "ticket" => array(
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "cancellation_request"
                    )
                ),
                "subject" => "REDACTED Order #".sprintf("%'.09d", $incrementId)." - Order Cancelled Due to Item Availability",
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);
        $this->_logger->info("Function Applied: orderCanceledWithOutRequestContactBySideConvo");

    }

    /**
     * called when our system parses a trigger for a supplier stating that they can not locate an order with the information provided
     * @param string $orderObj object for order
     * @param string $ticketID
     * @param string $sideConvoID id to side convo that supplier has asked for more details in.
     * @action replies to the supplier on sideConvoID provided informing the supplier of other formats they could try when attempting to locate the order, as well as notifying them that we have escalated this to a live agent for further processing.
     * @action replies to the ticketID provided following up with the end customer letting them know that we are still working on this case
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system as well as directing the agent to provide further details to the supplier
     * @action rejects the ticket removing it from automation
     */
    public function supplierFailedToLocateOrder($orderObj,$ticketID,$sideConvoID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
        $this->_zendeskUtils->replyToSideConversation($ticketID,$sideConvoID,$this->_adminUtils->getTranslationFromKey("SUPPLIER_FAILED_TO_LOCATE_TO_SUPPLIER"));
        $formatReply = $this->_adminUtils->getTranslationFromKey("TICKET_DELAY_TO_CUSTOMER");
        $Reply = sprintf($formatReply,$customerName,$ticketID);
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Reply,
                    "public" => "true"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    )
                ),
                "subject" => "REDACTED Order #".sprintf("%'.09d", $incrementId)." - Supplier Requires Further Details",
                "status" => "open"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);
        $this->_zendeskUtils->rejectTicket($ticketID,"The supplier was unable to locate this order with the information that our system has available.");


        $this->_logger->info("Function Applied: supplierFailedToLocateOrder");

    }


    /**
     * called when our system parses a trigger in a supplier response to a return request stating that a return is not needed and a credit was issued.
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action if order is not complete ( rejects the ticket removing it from automation and returns rejected to stop further actions)
     * @action replies to the ticketID provided notifying the end customer that they will be getting a refund and no further action is needed by them
     * @action Notifies accounting of the credit issued by email via side conversation
     * @action Sets state/status to complete/return_complete_refund_required
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action updates ticket subject
     * @action solves the ticketID provided
     */
    public function refundIssuedWithoutReturn($orderObj, $ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $orderState = $order->getState();
        if ($orderState != "complete"){
            $this->_zendeskUtils->rejectTicket($ticketID,"Return for order that is not in complete state.");
            return "rejected";
        }
        $incrementId = $order->getIncrementId();
        $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
        $formatReply = $this->_adminUtils->getTranslationFromKey("REFUND_NO_RETURN_TO_CUSTOMER");
        $Reply = sprintf($formatReply,$customerName);
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Reply,
                    "public" => "true"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "return_request"
                    )
                ),
                "subject" => "Refund - No Return Needed - REDACTED# ".sprintf("%'.09d", $incrementId),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);


        $format = $this->_adminUtils->getTranslationFromKey("REFUND_NO_RETURN_INTERNAL_NOTE_ORDER");

        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();

        $this->_logger->info("Function Applied: refundIssuedWithoutReturn");

    }



    /**
     * identical to refundIssuedWithoutReturn, but uses the side convo to notify the end customer.
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action if order is not complete ( rejects the ticket removing it from automation and returns rejected to stop further actions)
     * @action replies to the ticketID provided notifying the end customer that they will be getting a refund and no further action is needed by them
     * @action Notifies accounting of the credit issued by email via side conversation
     * @action Sets state/status to complete/return_complete_refund_required
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action updates ticket subject
     * @action solves the ticketID provided
     */
    public function refundIssuedWithoutReturnUseSideConvo($orderObj, $ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $orderState = $order->getState();
        if ($orderState != "complete"){
            $this->_zendeskUtils->rejectTicket($ticketID,"Return for order that is not in complete state.");
            return "rejected";
        }
        $incrementId = $order->getIncrementId();
        if ($this->_utils->Test()){
            $customerName = "TEST CUSTOMER";
            $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
        }else{
            $customerName = $order->getCustomerFirstname();
            $customerEmail = $order->getCustomerEmail();
        }
        $format = $this->_adminUtils->getTranslationFromKey("REFUND_NO_RETURN_TO_CUSTOMER");
        $body = sprintf($format,$customerName);
        $this->_zendeskUtils->create_side_convo($ticketID,"Refund - No Return Needed - REDACTED# ".sprintf("%'.09d", $incrementId),$body,$customerEmail);

        $format = $this->_adminUtils->getTranslationFromKey("REFUND_NO_RETURN_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $update = array(
            "ticket" => array(
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "return_request"
                    )
                ),
                "subject" => "Refund - No Return Needed - REDACTED# ".sprintf("%'.09d", $incrementId),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);

        $this->_logger->info("Function Applied: refundIssuedWithoutReturn");

    }


    /**
     * called when our system parses a trigger in a supplier response to a return request stating that a return is not needed and reship has been set up.
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action if order is not complete ( rejects the ticket removing it from automation and returns rejected to stop further actions)
     * @action replies to the ticketID provided notifying the end customer that they will be receiving a replacement and no further action is needed by them
     * @action Sets state/status to complete/complete
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action updates ticket subject
     * @action solves the ticketID provided
     */
    public function reshipIssuedWithoutReturn($orderObj, $ticketID){
        $order = $orderObj;
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $orderState = $order->getState();
        if ($orderState != "complete"){
            $this->_zendeskUtils->rejectTicket($ticketID,"Return for order that is not in complete state.");
            return "rejected";
        }
        $incrementId = $order->getIncrementId();
        $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
        $formatReply = $this->_adminUtils->getTranslationFromKey("RESHIP_NO_RETURN_TO_CUSTOMER");
        $Reply = sprintf($formatReply,$customerName);
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Reply,
                    "public" => "true"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "return_request"
                    )
                ),
                "subject" => "Reship - No Return Needed - REDACTED# ".sprintf("%'.09d", $incrementId),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);
        $format = $this->_adminUtils->getTranslationFromKey("RESHIP_NO_RETURN_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();

        $this->_logger->info("Function Applied: reshipIssuedWithoutReturn");

    }


    /**
     * called when our system parses a date from a string and confirms that it is valid for ETA use
     * @param string $orderObj object for order
     * @param string $ETA new ETA for order
     * @param string $ticketID
     * @action replies to the ticketID provided notifying the end customer of their updated ETA for their order.
     * @action Sets state/status to processing/in_fulfillment
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action Adds an internal note to the order containing override comment to adjust the promise date for oFlow
     * @action solves the ticketID provided
     */
    public function updateOrderETA($orderObj,$ETA,$ticketID){

        $order = $orderObj;
        $dateETA = date("Y-m-d H:i:s",strtotime($ETA));
        $entity= $this->_entityResolver->GetEntityByOrder($order);
        $entity->setData('updated_shipdate',$dateETA);
        $entity->setData('updated_alert_ship_processed',0);
        $this->_saveHandler->execute($entity);
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
        $format = $this->_adminUtils->getTranslationFromKey("NEW_ETA_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ETA,$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $formatReply = $this->_adminUtils->getTranslationFromKey("NEW_ETA_TO_CUSTOMER");
        $Reply = sprintf($formatReply,$customerName,$ticketID,sprintf("%'.09d", $incrementId),$ETA);
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Reply,
                    "public" => "true"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "order_status_request"
                    )
                ),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);


        $this->_logger->info("Function Applied: updateOrderETA");

    }


    /**
     * called when our system parses a date from a string and confirms that it is valid for ETA use but the requester of the ticket is the supplier (new email sent to support from supplier)
     * @param string $orderObj object for order
     * @param string $ETA new ETA for order
     * @param string $ticketID
     * @action Creates a new side conversation to send an email notifying the end customer of their updated ETA for their order.
     * @action Sets state/status to processing/in_fulfillment
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action Adds an internal note to the order containing override comment to adjust the promise date for oFlow
     * @action solves the ticketID provided
     */
    public function updateOrderETASideConvo($orderObj,$ETA,$ticketID){
        $order = $orderObj;
        $dateETA = date("Y-m-d H:i:s",strtotime($ETA));
        $entity= $this->_entityResolver->GetEntityByOrder($order);
        $entity->setData('updated_shipdate',$dateETA);
        $entity->setData('updated_alert_ship_processed',0);
        $this->_saveHandler->execute($entity);
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        if ($this->_utils->Test()){
            $customerName = "TEST CUSTOMER";
            $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
        }else{
            $customerName = $order->getCustomerFirstname();
            $customerEmail = $order->getCustomerEmail();
        }

        $format = $this->_adminUtils->getTranslationFromKey("NEW_ETA_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ETA,$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $format = $this->_adminUtils->getTranslationFromKey("NEW_ETA_TO_CUSTOMER");
        $body = sprintf($format,$customerName,$ticketID,sprintf("%'.09d", $incrementId),$ETA);
        $this->_zendeskUtils->create_side_convo($ticketID,"Update on REDACTED Order# ".sprintf("%'.09d", $incrementId),$body,$customerEmail);
        $formatNote = $this->_adminUtils->getTranslationFromKey("NEW_ETA_INTERNAL_NOTE_TICKET_ALT");
        $Note = sprintf($formatNote,sprintf("%'.09d", $incrementId),$ETA,sprintf("%'.09d", $incrementId));
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Note,
                    "public" => "false"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "order_status_request"
                    )
                ),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);
        $this->_logger->info("Function Applied: updateOrderETASideConvo");
    }



    /**
     * called if our system parses a trigger in a side convo from supplier email stating that the order is on back order with no known ETA
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action Sets state/status to processing/in_fulfillment
     * @action replies to the ticketID provided notifying the end customer of the status of their order.
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */
    public function updateOrderBackOrderNoETA($orderObj,$ticketID){
        $order = $orderObj;
        $dateETA = date("Y-m-d H:i:s",strtotime("+14 days"));
        $entity= $this->_entityResolver->GetEntityByOrder($order);
        $entity->setData('updated_shipdate',$dateETA);
        $entity->setData('updated_alert_ship_processed',0);
        $this->_saveHandler->execute($entity);
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));

        $format = $this->_adminUtils->getTranslationFromKey("BACKORDERED_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $formatReply = $this->_adminUtils->getTranslationFromKey("BACKORDERED_TO_CUSTOMER");
        $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId));
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Reply,
                    "public" => "true"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "order_status_request"
                    )
                ),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);

        $formatBody = "Attention Backorders Team,\n\nA supplier has responded to an order status request notifying REDACTED that an order is on backorder with out an ETA.\n\nREDACTED Order: %s\nZendesk Ticket: #%s";
        $Body = sprintf($formatBody,$incrementId,$ticketID);
        if ($this->Test){
            $email = "tyler.polny@REDACTED.com";
            $name = "Tyler Polny";
        }else{
            $email = "backorders@REDACTED.com";
            $name = "Backorders REDACTED";
        }
        $ticket = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Body,
                    "public" => "true"
                ),
                "requester" => array(
                    "name" => $name,
                    "email" => $email
                ),
                "status" => "closed",
                "subject" => "Backorder No ETA - supplier reply to OSR"
            )
        );
        $this->_zendeskUtils->createTicketWithArray($ticket);


        $this->_logger->info("Function Applied: updateOrderBackOrderNoETA");

    }



    /**
     * called if our system parses a trigger in the tickets main thread stating that the order is on back order with no known ETA
     * @param string $orderObj object for order
     * @param string $ticketID
     * @action Creates a new side conversation to send an email notifying the end customer of the status of their order.
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action solves the ticketID provided
     */
    public function updateOrderBackOrderNoETASideConvo($orderObj,$ticketID){
        $order = $orderObj;
        $dateETA = date("Y-m-d H:i:s",strtotime("+14 days"));
        $entity= $this->_entityResolver->GetEntityByOrder($order);
        $entity->setData('updated_shipdate',$dateETA);
        $entity->setData('updated_alert_ship_processed',0);
        $this->_saveHandler->execute($entity);
        $items = $order->getAllItems();
        foreach ($items as $item) {
            $sku = $item->getSku();
            $vendorCode = substr($sku,0,3);
        }
        $incrementId = $order->getIncrementId();
        if ($this->_utils->Test()){
            $customerName = "TEST CUSTOMER";
            $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
        }else{
            $customerName = $order->getCustomerFirstname();
            $customerEmail = $order->getCustomerEmail();
        }

        $format = $this->_adminUtils->getTranslationFromKey("BACKORDERED_INTERNAL_NOTE_ORDER");
        $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
        $order->addStatusHistoryComment($body);
        $order->save();
        $format = $this->_adminUtils->getTranslationFromKey("BACKORDERED_TO_CUSTOMER");
        $body = sprintf($format,$customerName,sprintf("%'.09d", $incrementId));
        $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED Order # ".sprintf("%'.09d", $incrementId)." Order Update",$body,$customerEmail);
        $formatNote = $this->_adminUtils->getTranslationFromKey("BACKORDERED_INTERNAL_NOTE_TICKET_ALT");
        $Note = sprintf($formatNote,sprintf("%'.09d", $incrementId),sprintf("%'.09d", $incrementId));
        $update = array(
            "ticket" => array(
                "comment" => array(
                    "body" => $Note,
                    "public" => "false"
                ),
                "custom_fields" => array(
                    array(
                        "id" => $this->OrderIncrementIDFieldID,
                        "value" => sprintf("%'.09d", $incrementId)
                    ),
                    array(
                        "id" => $this->vendorCodeFieldID,
                        "value" => $vendorCode
                    ),
                    array(
                        "id" => $this->QueueFieldID,
                        "value" => "order_status_request"
                    )
                ),
                "status" => "solved"
            )
        );
        $this->_zendeskUtils->updateTicket($ticketID,$update);

        $this->_logger->info("Function Applied: updateOrderBackOrderNoETASideConvo");
    }




/// OPEN TICKET QUEUE FUNCTIONS####################################################################################################################
/// functions used to address tickets in open status. Primarily this includes replies from suppliers regarding previously made requests by our system.

// Order Status Reply Functions
// functions used to route and parse supplier responses to a lead-time requests.


    /**
     * called if no ETA found in routeSupplierReplyOrderStatus and email is from "REDACTED@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of order
     * if $orderCanceledTrigger312 in reply
     * @action $this->orderCanceledWithOutRequest($order,$ticketID);
     * if $orderShippedTrigger312 in reply
     * @action $this->orderConfirmedShipped($order,$ticketID);
     * if $noETAtrigger312 in reply
     * @action $this->updateOrderBackOrderNoETA($order,$ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyOrderStatus312($ticketID){

        try {
            $this->_logger->info('Function: ParseSupplierReplyOrderStatus312');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState == "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in complete state");
                    return 'rejected';
                }
                if ($orderState == "closed") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in closed state");
                    return 'rejected';
                }
            }
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Order Status Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Order Status Request]");
                return 'rejected';
            }
            $replyBody = $sideConversation['body'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Order Status Request]');
                return 'rejected';
            }

            $orderCanceledTrigger312string = $this->_adminUtils->getTranslationFromKey('ORDER_CANCELED_TRIGGER_ARRAY_312');
            $orderCanceledTrigger312 = explode(";",$orderCanceledTrigger312string);
            $orderShippedTrigger312string = $this->_adminUtils->getTranslationFromKey('ORDER_SHIPPED_TRIGGER_ARRAY_312');
            $orderShippedTrigger312 = explode(";",$orderShippedTrigger312string);
            $noETAtrigger312string = $this->_adminUtils->getTranslationFromKey("ORDER_NO_ETA_TRIGGER_ARRAY_312");
            $noETAtrigger312 = explode(";",$noETAtrigger312string);

            foreach ($orderCanceledTrigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderCanceledWithOutRequest($order,$ticketID);
                    return null;
                }
            }
            foreach ($orderShippedTrigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderConfirmedShipped($order,$ticketID);
                    return null;

                }
            }
            foreach ($noETAtrigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->updateOrderBackOrderNoETA($order,$ticketID);
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

    /**
     * called if no ETA found in routeSupplierReplyOrderStatus and email is from "REDACTED@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of order
     * if $orderCanceledTrigger226 in reply
     * @action $this->orderCanceledWithOutRequest($order,$ticketID);
     * if $orderShippedTrigger226 in reply
     * @action $this->orderConfirmedShipped($order,$ticketID);
     * if $poNotFoundTrigger226 in reply
     * @action $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyOrderStatus226($ticketID){

        try {
            $this->_logger->info('Function: ParseSupplierReplyOrderStatus226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState == "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in complete state");
                    return 'rejected';
                }
                if ($orderState == "closed") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in closed state");
                    return 'rejected';
                }
            }
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Order Status Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Order Status Request]");
                return 'rejected';
            }
            $replyBody = $sideConversation['body'];
            $REDACTEDConfOfRecTrigger = $this->_adminUtils->getTranslationFromKey("REDACTED_CONF_OF_REC_TRIGGER");

            $this->_logger->info("trigger string we are looking for:");
            $this->_logger->info($REDACTEDConfOfRecTrigger);
            $this->_logger->info("body Text:");
            $this->_logger->info($replyBody);
            if (str_starts_with($replyBody,$REDACTEDConfOfRecTrigger)){
                $internalNote = $this->_adminUtils->getTranslationFromKey("REDACTED_CONF_OF_REC_INTERNAL_NOTE");
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $internalNote,
                            "public" => "false"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "order_status_request"
                            ),
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => '226'
                            )
                        ),
                        "status" => "pending"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                return "rejected";
            }

            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Order Status Request]');
                return 'rejected';
            }

            $orderCanceledTrigger226string = $this->_adminUtils->getTranslationFromKey("ORDER_CANCELED_TRIGGER_ARRAY_226");
            $orderCanceledTrigger226 = explode(";",$orderCanceledTrigger226string);

            $orderShippedTrigger226string = $this->_adminUtils->getTranslationFromKey("ORDER_SHIPPED_TRIGGER_ARRAY_226");
            $orderShippedTrigger226 = explode(";",$orderShippedTrigger226string);

            $poNotFoundTrigger226string = $this->_adminUtils->getTranslationFromKey("ORDER_NOT_FOUND_TRIGGER_ARRAY_226");
            $poNotFoundTrigger226 = explode(";",$poNotFoundTrigger226string);

            $noETAtrigger226string = $this->_adminUtils->getTranslationFromKey("NO_ETA_TRIGGER_ARRAY_226");
            $this->_logger->info("test string:");
            $this->_logger->info($noETAtrigger226string);

            $noETAtrigger226 = explode(";",$noETAtrigger226string);


            foreach ($orderCanceledTrigger226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderCanceledWithOutRequest($order,$ticketID);
                    return null;
                }
            }
            foreach ($orderShippedTrigger226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderConfirmedShipped($order,$ticketID);
                    return null;
                }
            }
            foreach ($poNotFoundTrigger226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
                    return null;
                }
            }
            foreach ($noETAtrigger226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->updateOrderBackOrderNoETA($order,$ticketID);
                    return null;
                }
            }

            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

    /**
     * called if no ETA found in routeSupplierReplyOrderStatus and email is from "REDACTED"
     * @param string $ticketID
     * @action parse supplier response to determine status of order
     * if $orderCanceledTrigger310 in reply
     * @action $this->orderCanceledWithOutRequest($order,$ticketID);
     * if $orderShippedTrigger310 in reply
     * @action $this->orderConfirmedShipped($order,$ticketID);
     * if $noETAtrigger310 in reply
     * @action $this->updateOrderBackOrderNoETA($order,$ticketID);
     * if $poNotFoundTrigger310 in reply
     * @action $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyOrderStatus310($ticketID){

        try {
            $this->_logger->info('Function: ParseSupplierReplyOrderStatus310');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState == "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in complete state");
                    return 'rejected';
                }
                if ($orderState == "closed") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in closed state");
                    return 'rejected';
                }
            }
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Order Status Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Order Status Request]");
                return 'rejected';
            }
            $replyBody = $sideConversation['body'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Order Status Request]');
                return 'rejected';
            }
            $orderCanceledTrigger310 = array('I have cancelled this order.','I have cancelled the order','have been cancelled');
            $orderShippedTrigger310 = array('Shipping Method:','this order shipped', 'FedEx ', 'the order shipped', 'UPS');
            $noETAtrigger310 = array('1-2 weeks','2-3 weeks','3-4 weeks','4-5 weeks');
            $poNotFoundTrigger310 = array('any orders', 'unable to locate', 'don\'t see purchase order','please submit your PO ','Please resend a copy of the PO');
            foreach ($orderCanceledTrigger310 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderCanceledWithOutRequest($order,$ticketID);
                    return null;
                }
            }
            foreach ($orderShippedTrigger310 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderConfirmedShipped($order,$ticketID);
                    return null;
                }
            }
            foreach ($noETAtrigger310 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->updateOrderBackOrderNoETA($order,$ticketID);
                    return null;
                }
            }
            foreach ($poNotFoundTrigger310 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

    /**
     * called if no ETA found in routeSupplierReplyOrderStatus and email contains the string "@thehighlandmint.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of order
     * if $orderShippedTrigger306 in reply
     * @action $this->orderConfirmedShipped($order,$ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyOrderStatus306($ticketID){

        try {
            $this->_logger->info('Function: ParseSupplierReplyOrderStatus306');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState == "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for ticket in complete state");
                    return 'rejected';
                }
                if ($orderState == "closed") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for ticket in closed state");
                    return 'rejected';
                }
            }
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Order Status Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Order Status Request]");
                return 'rejected';
            }
            $replyBody = $sideConversation['body'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Return Request]');
                return 'rejected';
            }
            $orderShippedTrigger306 = array('shipped');
            foreach ($orderShippedTrigger306 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderConfirmedShipped($order,$ticketID);
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"could not parse supplier reply");
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

    /**
     * called if no ETA found in routeSupplierReplyOrderStatus and email is from "REDACTED@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of order
     * if $orderCanceledTrigger281 in reply
     * @action $this->orderCanceledWithOutRequest($order,$ticketID);
     * if $noETAtrigger281 in reply
     * @action $this->updateOrderBackOrderNoETA($order,$ticketID);
     * if $poNotFoundTrigger281 in reply
     * @action $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyOrderStatus281($ticketID){

        try {
            $this->_logger->info('Function: ParseSupplierReplyOrderStatus281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState == "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in complete state");
                    return 'rejected';
                }
                if ($orderState == "closed") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in closed state");
                    return 'rejected';
                }
            }
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Order Status Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Order Status Request]");
                return 'rejected';
            }
            $replyBody = $sideConversation['body'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Order Status Request]]');
                return 'rejected';
            }

            $orderCanceledTrigger281string = $this->_adminUtils->getTranslationFromKey("ORDER_CANCELED_TRIGGER_ARRAY_281");
            $orderCanceledTrigger281 = explode(";",$orderCanceledTrigger281string);

            $noETAtrigger281string = $this->_adminUtils->getTranslationFromKey("ORDER_NO_ETA_TRIGGER_ARRAY_281");
            $noETAtrigger281 = explode(";",$noETAtrigger281string);

            $poNotFoundTrigger281string = $this->_adminUtils->getTranslationFromKey("ORDER_NOT_FOUND_TRIGGER_ARRAY_281");
            $poNotFoundTrigger281 = explode(";",$poNotFoundTrigger281string);

            foreach ($orderCanceledTrigger281 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderCanceledWithOutRequest($order,$ticketID);
                    return null;
                }
            }
            foreach ($noETAtrigger281 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->updateOrderBackOrderNoETA($order,$ticketID);
                    return null;
                }
            }
            foreach ($poNotFoundTrigger281 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

    /**
     * called if open ticket has string "[Order Status Request]" in subject
     * @param string $ticketID
     * if ETA is successfully parsed from supplier reply
     * @action this->updateOrderETA($ticketID,$ETA);
     * if reply is from "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyOrderStatus312($ticketID)
     * if reply is from "customersupport@REDACTED.com"
     * @action this->ParseSupplierReplyOrderStatus226($ticketID)
     * if reply is from "REDACTED"
     * @action this->ParseSupplierReplyOrderStatus310($ticketID)
     * if reply is from an email containing substring "@thehighlandmint.com"
     * @action this->ParseSupplierReplyOrderStatus306($ticketID)
     * if reply is from an email containing substring "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyOrderStatus281($ticketID)
     * @return null on success, 'rejected' on failure
     */
    public function routeSupplierReplyOrderStatus($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: routeSupplierReplyOrderStatus');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState == "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in complete state");
                    return 'rejected';
                }
                if ($orderState == "closed") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Order Status for order in closed state");
                    return 'rejected';
                }
            }
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Order Status Request]");

            $this->_logger->info(print_r($sideConversation,true));
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Order Status Request]");
                return 'rejected';
            }
            $replyEmail = $sideConversation['email'];
            $replyBody = $sideConversation['body'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Order Status Request]');
                return 'rejected';
            }
            $items = $order->getAllItems();
            foreach ($items as $item) {
                $sku = $item->getSku();
                $vendorCode = substr($sku,0,3);
            }
            if ($vendorCode == "226"){
                $searchString = explode("Please Do Not Delete or Change the REDACTED Tracking Text",$replyBody)[0];
            }else{
                $searchString = $replyBody;
            }
            $eta = $this->_utils->ParseDate($searchString);
            $this->_logger->info("ETA value: ");
            $this->_logger->info($eta);
            if ($eta != null){
                $this->updateOrderETA($order,$eta,$ticketID);
                return null;
            }else{

                if ($this->_utils->Test() == True) {
                    if (str_contains($replyEmail,"tyler.polny96@gmail.com")){
                        $this->ParseSupplierReplyOrderStatus312($ticketID);
                        return "test";
                    }
                }

                if(str_contains($replyEmail, $this->_adminUtils->getTranslationFromKey("EMAIL_SERVICE_312"))){
                    $this->ParseSupplierReplyOrderStatus312($ticketID);

                }elseif(str_contains($replyEmail,$this->_adminUtils->getTranslationFromKey("EMAIL_SERVICE_226"))){
                    $this->ParseSupplierReplyOrderStatus226($ticketID);

                }elseif(str_contains($replyEmail,$this->_adminUtils->getTranslationFromKey("EMAIL_SERVICE_310"))){
                    $this->ParseSupplierReplyOrderStatus310($ticketID);

                }elseif(str_contains($replyEmail,$this->_adminUtils->getTranslationFromKey("EMAIL_SERVICE_306"))){
                    $this->ParseSupplierReplyOrderStatus306($ticketID);

                }elseif(str_contains($replyEmail,$this->_adminUtils->getTranslationFromKey("EMAIL_SERVICE_281"))){
                    $this->ParseSupplierReplyOrderStatus281($ticketID);
                }else{
                    $this->_zendeskUtils->rejectTicket($ticketID,"our system 2 has not yet been developed to work with the supplier for REDACTED Order ".sprintf("%'.09d", $incrementId).". Supplier email: ".$replyEmail);
                    return 'rejected';
                }
            }
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }



// Return Reply Functions
// functions used to route and parse supplier responses to a return requests.
    /**
     * called from routeSupplierReplyReturn if email is from "REDACTED@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of request
     * if $addressConfirmationRequestedTrigger312 in reply
     * @action reply to the supplier and notify them that the address is valid.
     * @action sets ticket to pending
     * if $refundIssuedWithoutReturnTrigger312 in reply
     * @action $this->refundIssuedWithoutReturn($order,$ticketID);
     * if $pickUpTimeTrigger312 in reply
     * @action search for return confirmation number
     * @action searches for pick up date
     * @action notifies the end customer by replying to ticket
     * @action updates order status
     * @action adds an internal note detailing the actions taken by our system
     * @action solves the ticket
     * if $pickUpIn7to10Trigger312 in reply
     * @action search for return confirmation number
     * @action notifies the end customer by replying to ticket
     * @action updates order status
     * @action adds an internal note detailing the actions taken by our system
     * @action solves the ticket
     * if $pickUpTimeTrigger312Alt in reply
     * @action search for return confirmation number
     * @action searches for pick up date
     * @action notifies the end customer by replying to ticket
     * @action updates order status
     * @action adds an internal note detailing the actions taken by our system
     * @action solves the ticket
     * if $exchangeTimeTrigger in reply
     * @action search for return confirmation number
     * @action searches for pick up date
     * @action notifies the end customer by replying to ticket
     * @action updates order status
     * @action adds an internal note detailing the actions taken by our system
     * @action solves the ticket
     * if $evenExchangeTrigger312 in reply
     * @action search for return confirmation number
     * @action searches for pick up date
     * @action notifies the end customer by replying to ticket
     * @action updates order status
     * @action adds an internal note detailing the actions taken by our system
     * @action solves the ticket
     * if $returnDateNoFormatTrigger312 in reply
     * @action search for return confirmation number
     * @action searches for pick up date using $this->Utils->findSubStr($replyBody,' on ','.');
     * @action notifies the end customer by replying to ticket
     * @action updates order status
     * @action adds an internal note detailing the actions taken by our system
     * @action solves the ticket
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyReturn312($ticketID)
    {

        try {

            $this->_logger->info('Function: ParseSupplierReplyReturn312');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState != "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Return for order that is not in complete state.");
                    return "rejected";
                }
            }
            $customerName = $order->getCustomerFirstname();
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Return Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Return Request]");
                return "rejected";
            }
            $replyBody = $sideConversation['body'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Return Request]');
                return "rejected";
            }

            $addressConfirmationRequestedTrigger312 = array ('confirm the pickup address');
            $refundIssuedWithoutReturnTrigger312string = $this->_adminUtils->getTranslationFromKey("REFUND_WITHOUT_RETURN_TRIGGER_ARRAY_312");
            $refundIssuedWithoutReturnTrigger312 = explode(";",$refundIssuedWithoutReturnTrigger312string);

            $pickUpTimeTrigger312 = array('picked up on');
            $pickUpIn7to10Trigger312 = array(' 7-10 ');
            $pickUpTimeTrigger312Alt = array('will be picked up by REDACTED driver by');
            $exchangeTimeTrigger = array('will be picked up by REDACTED driver by');
            $evenExchangeTrigger312 = array('even exchange');
            $returnDateNoFormatTrigger312 = array('Please know that return label is not required for this return order and do not put return address on the return ');
            foreach ($addressConfirmationRequestedTrigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->_zendeskUtils->replyToSideConversation($ticketID,$replyID,$this->_adminUtils->getTranslationFromKey("CONFIRM_PICK_UP_ADDRESS_312"));
                    $this->_zendeskUtils->setTicketToPending($ticketID);
                    return null;
                }
            }
            foreach ($refundIssuedWithoutReturnTrigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->refundIssuedWithoutReturn($order,$ticketID);
                    return null;
                }
            }
            foreach ($pickUpTimeTrigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    if (preg_match('/[1][0-9]{8}/',$replyBody)){
                        preg_match('/[1][0-9]{8}/',$replyBody,$matches);
                        if(!empty($matches)){
                            $returnConfirmationNumber= $matches[0];
                        }else{
                            $returnConfirmationNumber = null;
                        }
                    }else{
                        $returnConfirmationNumber = null;
                    }
                    if ($returnConfirmationNumber == null){
                        $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate a return confirmation number in the following text:\n".$replyBody);
                        return "rejected";
                    }
                    if (preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/',$replyBody)) {
                        preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/', $replyBody, $matches);
                        $pickUpDate = $matches[0];
                    }else{
                        $this->_zendeskUtils->rejectTicket($ticketID,"our system could not parse the pick up date from OD's reply email.");
                    }
                    $formatReply = $this->_adminUtils->getTranslationFromKey("PICKUP_DETAILS_312");
                    $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId),$pickUpDate,$returnConfirmationNumber);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => '312'
                                )
                            ),
                            "subject" => "Return Pick-Up Date - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                            "status" => "pending"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    $formatNote = $this->_adminUtils->getTranslationFromKey("RETURN_PICKUP_DATE_INTERNAL_NOTE_ORDER");
                    $Note = sprintf($formatNote,sprintf("%'.09d", $incrementId),$pickUpDate,$ticketID,$ticketID);
                    $order->addStatusHistoryComment($Note);
                    $order->save();

                    return null;
                }
            }
            foreach ($pickUpIn7to10Trigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    if (preg_match('/[1][0-9]{8}/',$replyBody)){
                        preg_match('/[1][0-9]{8}/',$replyBody,$matches);
                        if(!empty($matches)){
                            $returnConfirmationNumber= $matches[0];
                        }else{
                            $returnConfirmationNumber = null;
                        }
                    }else{
                        $returnConfirmationNumber = null;
                    }
                    if ($returnConfirmationNumber == null){
                        $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate a return confirmation number in the following text:\n".$replyBody);
                        return "rejected";
                    }

                    $formatReply = $this->_adminUtils->getTranslationFromKey("PICKUP_DETAILS_312");
                    $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId),"With in the next 7-10 business days.",$returnConfirmationNumber);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => '312'
                                )
                            ),
                            "subject" => "Return Pick-Up Date - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                            "status" => "pending"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    $formatNote = $this->_adminUtils->getTranslationFromKey("RETURN_PICKUP_DATE_INTERNAL_NOTE_ORDER");
                    $Note = sprintf($formatNote,sprintf("%'.09d", $incrementId),"With in the next 7-10 business days.",$ticketID,$ticketID);
                    $order->addStatusHistoryComment($Note);
                    $order->save();
                    return null;

                }
            }
            foreach ($pickUpTimeTrigger312Alt as $trigger){
                if (str_contains($replyBody,$trigger)){
                    if (preg_match('/[1][0-9]{8}/',$replyBody)){
                        preg_match('/[1][0-9]{8}/',$replyBody,$matches);
                        if(!empty($matches)){
                            $returnConfirmationNumber= $matches[0];
                        }else{
                            $returnConfirmationNumber = null;
                        }
                    }else{
                        $returnConfirmationNumber = null;
                    }
                    if ($returnConfirmationNumber == null){
                        $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate a return confirmation number in the following text:\n".$replyBody);
                        return "rejected";
                    }

                    if (preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/',$replyBody)) {
                        preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/', $replyBody, $matches);
                        $pickUpDate = $matches[1];$matches[1];
                        //$pickUpDate = $this->_utils->findSubStr($replyBody, 'picked up on ', '.');
                    }else{
                        $this->_zendeskUtils->rejectTicket($ticketID,"our system could not parse the pick up date from OD's reply email.");
                    }

                    $formatReply = $this->_adminUtils->getTranslationFromKey("PICKUP_DETAILS_312");
                    $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId),$pickUpDate,$returnConfirmationNumber);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => '312'
                                )
                            ),
                            "subject" => "Return Pick-Up Date - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                            "status" => "pending"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);


                    $formatNote = $this->_adminUtils->getTranslationFromKey("RETURN_PICKUP_DATE_INTERNAL_NOTE_ORDER");
                    $note = sprintf($formatNote,sprintf("%'.09d", $incrementId),$pickUpDate,$ticketID,$ticketID);
                    $order->addStatusHistoryComment($note);
                    $order->save();
                    return null;

                }
            }
            foreach ($exchangeTimeTrigger as $trigger){
                if (str_contains($replyBody,$trigger)){
                    if (preg_match('/[1][0-9]{8}/',$replyBody)){
                        preg_match('/[1][0-9]{8}/',$replyBody,$matches);
                        if(!empty($matches)){
                            $returnConfirmationNumber= $matches[0];
                        }else{
                            $returnConfirmationNumber = null;
                        }
                    }else{
                        $returnConfirmationNumber = null;
                    }
                    if ($returnConfirmationNumber == null){
                        $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate a return confirmation number in the following text:\n".$replyBody);
                        return "rejected";
                    }

                    if (preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/',$replyBody)) {
                        preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/', $replyBody, $matches);
                        $pickUpDate = $matches[1];$matches[1];
                        //$pickUpDate = $this->_utils->findSubStr($replyBody, 'picked up on ', '.');
                    }else{
                        $this->_zendeskUtils->rejectTicket($ticketID,"our system could not parse the pick up date from OD's reply email.");
                    }

                    $formatReply = $this->_adminUtils->getTranslationFromKey("PICKUP_DETAILS_312");
                    $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId),$pickUpDate,$returnConfirmationNumber);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => '312'
                                )
                            ),
                            "subject" => "Return Pick-Up Date - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                            "status" => "pending"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    $formatNote = $this->_adminUtils->getTranslationFromKey("RETURN_PICKUP_DATE_INTERNAL_NOTE_ORDER");
                    $Note = sprintf($formatNote,sprintf("%'.09d", $incrementId),$pickUpDate,$ticketID,$ticketID);
                    $order->addStatusHistoryComment($Note);
                    $order->save();
                    return null;
                }
            }
            foreach ($evenExchangeTrigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    if (preg_match('/[1][0-9]{8}/',$replyBody)){
                        preg_match('/[1][0-9]{8}/',$replyBody,$matches);
                        if(!empty($matches)){
                            $returnConfirmationNumber= $matches[0];
                        }else{
                            $returnConfirmationNumber = null;
                        }
                    }else{
                        $returnConfirmationNumber = null;
                    }
                    if ($returnConfirmationNumber == null){
                        $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate a return confirmation number in the following text:\n".$replyBody);
                        return "rejected";
                    }

                    if (preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/',$replyBody)) {
                        preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/', $replyBody, $matches);
                        $pickUpDate = $matches[1];$matches[1];
                        //$pickUpDate = $this->_utils->findSubStr($replyBody, 'picked up on ', '.');
                    }else{
                        $this->_zendeskUtils->rejectTicket($ticketID,"our system could not parse the pick up date from OD's reply email.");
                    }

                    $formatReply = $this->_adminUtils->getTranslationFromKey("PICKUP_DETAILS_312");
                    $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId),$pickUpDate,$returnConfirmationNumber);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => '312'
                                )
                            ),
                            "subject" => "Return Pick-Up Date - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                            "status" => "pending"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);



                    $formatNote = $this->_adminUtils->getTranslationFromKey("RETURN_PICKUP_DATE_INTERNAL_NOTE_ORDER");
                    $Note = sprintf($formatNote,sprintf("%'.09d", $incrementId),$pickUpDate,$ticketID,$ticketID);
                    $order->addStatusHistoryComment($Note);
                    $order->save();
                    return null;
                }
            }
            foreach ($returnDateNoFormatTrigger312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    if (preg_match('/[1][0-9]{8}/',$replyBody)){
                        preg_match('/[1][0-9]{8}/',$replyBody,$matches);
                        if(!empty($matches)){
                            $returnConfirmationNumber= $matches[0];
                        }else{
                            $returnConfirmationNumber = null;
                        }
                    }else{
                        $returnConfirmationNumber = null;
                    }
                    if (preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/',$replyBody)) {
                        preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)(, )(January|February|March|April|May|June|July|August|September|October|November|December)( [0-9]*, [0-9]{4})/', $replyBody, $matches);
                        $pickUpDate = $matches[1];$matches[1];
                        //$pickUpDate = $this->_utils->findSubStr($replyBody, 'picked up on ', '.');
                    }else{
                        $this->_zendeskUtils->rejectTicket($ticketID,"our system could not parse the pick up date from OD's reply email.");
                    }

                    $formatReply = $this->_adminUtils->getTranslationFromKey("PICKUP_DETAILS_312");
                    $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId),$pickUpDate,$returnConfirmationNumber);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => '312'
                                )
                            ),
                            "subject" => "Return Pick-Up Date - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                            "status" => "pending"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);



                    $formatNote = $this->_adminUtils->getTranslationFromKey("RETURN_PICKUP_DATE_INTERNAL_NOTE_ORDER");
                    $Note = sprintf($formatNote,sprintf("%'.09d", $incrementId),$pickUpDate,$ticketID,$ticketID);
                    $order->addStatusHistoryComment($Note);
                    $order->save();
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

    /**
     * called from routeSupplierReplyReturn if email is from "customersupport@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of request
     * if $refundIssuedWithoutReturnTrigger226 in reply
     * @action $this->refundIssuedWithoutReturn($order,$ticketID);
     * if $reshipIssuedWithoutReturnTrigger226 in reply
     * @action $this->reshipIssuedWithoutReturn($order,$ticketID);
     * if $moreInfoNeededTrigger226 in reply
     * @action reply to the main string to notify the customer that we are still working on their ticket and will have an update soon
     * @action reply to the supplier notifying them that we have escalated this ticket to be handled by a live agent and marked the ticket as urgent
     * @action reject ticket removing it from our system. this will cause the function to return 'rejected'
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyReturn226($ticketID)
    {

        try {
            $this->_logger->info('Function: ParseSupplierReplyReturn226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState != "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Return for order that is not in complete state.");
                    return "rejected";
                }
            }
            $customerName = $order->getCustomerFirstname();
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Return Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Return Request]");
                return "rejected";
            }
            $replyBody = $sideConversation['body'];

            $REDACTEDConfOfRecTrigger = $this->_adminUtils->getTranslationFromKey("REDACTED_CONF_OF_REC_TRIGGER");

            if (str_starts_with($replyBody,$REDACTEDConfOfRecTrigger)){
                $internalNote = $this->_adminUtils->getTranslationFromKey("REDACTED_CONF_OF_REC_INTERNAL_NOTE");
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $internalNote,
                            "public" => "false"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => '226'
                            )
                        ),
                        "status" => "pending"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                return "rejected";
            }

            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Return Request]');
                return "rejected";
            }

            $refundIssuedWithoutReturnTrigger226string = $this->_adminUtils->getTranslationFromKey("REFUND_WITHOUT_RETURN_TRIGGER_ARRAY_226");
            $refundIssuedWithoutReturnTrigger226 = explode(";",$refundIssuedWithoutReturnTrigger226string);

            $reshipIssuedWithoutReturnTrigger226string = $this->_adminUtils->getTranslationFromKey("RESHIP_WITHOUT_RETURN_TRIGGER_ARRAY_226");
            $reshipIssuedWithoutReturnTrigger226 = explode(";",$reshipIssuedWithoutReturnTrigger226string);

            $moreInfoNeededTrigger226string = $this->_adminUtils->getTranslationFromKey("RETURN_MORE_INFO_NEEDED_TRIGGER_ARRAY_226");
            $moreInfoNeededTrigger226 = explode(";",$moreInfoNeededTrigger226string);


            foreach ($refundIssuedWithoutReturnTrigger226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->refundIssuedWithoutReturn($order,$ticketID);
                    return null;
                }
            }
            foreach ($reshipIssuedWithoutReturnTrigger226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->reshipIssuedWithoutReturn($order,$ticketID);
                    return null;
                }
            }
            foreach ($moreInfoNeededTrigger226 as $trigger){
                if (str_contains($replyBody,$trigger)){

                    $this->_zendeskUtils->replyToSideConversation($ticketID,$replyID,$this->_adminUtils->getTranslationFromKey("RETURN_MORE_INFO_NEEDED_TO_SUPPLIER_226"));

                    $format = $this->_adminUtils->getTranslationFromKey("TICKET_DELAY_TO_CUSTOMER");
                    $body = sprintf($format,$customerName,$ticketID);
                    $this->_zendeskUtils->replyToTicketMainConvo($ticketID,$body);
                    $this->_zendeskUtils->rejectTicket($ticketID,"REDACTED was not able to resolve this return request with the information that our system has available to provide.");
                    return "rejected";
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

    /**
     * called from routeSupplierReplyReturn if email is from "REDACTED@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of request
     * if $refundIssuedWithoutReturnTrigger281 in reply
     * @action $this->refundIssuedWithoutReturn($order,$ticketID);
     * if $confirmPackageConditionTrigger281 in reply
     * @action reply to main thread asking the customer to provide photos of the return item
     * @action reply to side conversation to notify the supplier that we have requested this information and we will follow up with them shortly
     * @action reject ticket removing it from our system. this will cause the function to return 'rejected'
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyReturn281($ticketID)
    {

        try {
            $this->_logger->info('Function: ParseSupplierReplyReturn281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderState = $order->getState();
                if ($orderState != "complete") {
                    $this->_zendeskUtils->rejectTicket($ticketID, "Return for order that is not in complete state.");
                    return "rejected";
                }
            }
            $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Return Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Return Request]");
                return "rejected";
            }
            $replyBody = $sideConversation['body'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Return Request]');
                return "rejected";
            }

            $refundIssuedWithoutReturnTrigger281string = $this->_adminUtils->getTranslationFromKey("REFUND_WITHOUT_RETURN_TRIGGER_ARRAY_281");
            $refundIssuedWithoutReturnTrigger281 = explode(";",$refundIssuedWithoutReturnTrigger281string);


            $confirmPackageConditionTrigger281string = $this->_adminUtils->getTranslationFromKey("CONFIRM_PACKAGE_CONDITION_TRIGGER_ARRAY_281");
            $confirmPackageConditionTrigger281 = explode(";",$confirmPackageConditionTrigger281string);

            foreach ($refundIssuedWithoutReturnTrigger281 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->refundIssuedWithoutReturn($order,$ticketID);
                    return null;
                }
            }
            foreach ($confirmPackageConditionTrigger281 as $trigger){
                if (str_contains($replyBody,$trigger)){

                    $formatReply = $this->_adminUtils->getTranslationFromKey("CONFIRM_PACKAGE_CONDITION_281_TO_CUSTOMER");
                    $reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId));
                    $this->_zendeskUtils->rejectTicket($ticketID,"The supplier has requested photos of the return. our system reached out to the end customer on this ticket, but is not yet developed to pares the customers response and forward this photo to the supplier. This development is on the to do list.");
                    $this->_zendeskUtils->replyToSideConversation($ticketID,$replyID,$this->_adminUtils->getTranslationFromKey("CONFIRM_PACKAGE_CONDITION_281_TO_SUPPLIER"));
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => '281'
                                )
                            ),
                            "subject" => "Photos Requested - Return - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                            "status" => "open"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);


                    return "rejected";
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

    /**
     * called if open ticket has string "[Return Request]" in subject
     * @param string $ticketID
     * if requester is "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyReturn312($ticketID)
     * if requester is "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyReturn226($ticketID)
     * if requester is "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyReturn281($ticketID)
     * @return null on success, 'rejected' on failure
     */
    public function routeSupplierReplyReturn($ticketID)
    {

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: ' . $ticketID);
            $this->_logger->info('Function: routeSupplierReplyReturn');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku, 0, 3);
                }
            }
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Return Request]");
            if ($sideConversation == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Return Request]");
                return 'rejected';
            }
            $replyEmail = $sideConversation['email'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Return Request]');
                return "rejected";
            }

            if ($this->Test){
                if ($replyEmail == "tyler.polny96@gmail.com"){
                    $this->ParseSupplierReplyReturn281($ticketID);
                }
            }elseif ($replyEmail == $this->_adminUtils->getTranslationFromKey("EMAIL_SERVICE_312")){
                $this->ParseSupplierReplyReturn312($ticketID);

            }elseif ($replyEmail == $this->_adminUtils->getTranslationFromKey("EMAIL_SERVICE_226")){
                $this->ParseSupplierReplyReturn226($ticketID);

            }elseif ($replyEmail == $this->_adminUtils->getTranslationFromKey("EMAIL_SERVICE_281")){
                $this->ParseSupplierReplyReturn281($ticketID);
            }else{
                $this->_zendeskUtils->rejectTicket($ticketID,"our system has not yet been developed to parse responses from the supplier on this order. Supplier contact: ".$replyEmail);
                return 'rejected';
            }
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }


// Cancel Reply Functions
// functions used to route and parse supplier responses to a cancellation requests.
    /**
     * called from routeSupplierReplyCancel if email is from "REDACTED@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of request
     * if $cancelTriggerWords312 in reply
     * @action $this->orderConfirmedCanceled($order,$ticketID);
     * if $deniedTriggerWords312 in reply
     * @action $this->orderCancellationDenied($order,$ticketID);
     * if $delayedTriggerWords312 in reply
     * @action reject ticket removing it from our system. this will cause the function to return 'rejected'
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyCancel312 ($ticketID)
    {

        try {
            $this->_logger->info('Function: ParseSupplierReplyCancel312');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Cancellation Request]");
                if ($sideConversation == null) {
                    $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Cancellation Request]");
                    return 'rejected';
                }
                $replyBody = $sideConversation['body'];
                $replyID = $sideConversation['ID'];
                if ($replyID == null) {
                    $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Cancellation Request]');
                    return "rejected";
                }
            }

            $cancelTriggerWords312string = $this->_adminUtils->getTranslationFromKey("ORDER_CANCELED_TRIGGER_ARRAY_312");
            $cancelTriggerWords312 = explode(";",$cancelTriggerWords312string);


            $deniedTriggerWords312string = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_TRIGGER_ARRAY_312");
            $deniedTriggerWords312 = explode(";",$deniedTriggerWords312string);


            $delayedTriggerWords312string = $this->_adminUtils->getTranslationFromKey("CANCELLATION_DELAYED_TRIGGER_ARRAY_312");
            $delayedTriggerWords312 = explode(";",$delayedTriggerWords312string);


            foreach ($cancelTriggerWords312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderConfirmedCanceled($order,$ticketID);
                    return null;
                }
            }
            foreach ($deniedTriggerWords312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderCancellationDenied($order,$ticketID);
                    return null;
                }
            }
            foreach ($delayedTriggerWords312 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->_zendeskUtils->rejectTicket($ticketID,'REDACTED stated that they would need to contact their vendor. our system is not yet developed to handle multiple messages back and forth.');
                    return 'rejected';
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        } catch (\Exception $e) {
            $this->_logger->info('Ticket# ' . $ticketID . ' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID, "our system's code hit an 'Exception' when attempting to process this ticket. Exception: " . $e->getMessage());
            return "rejected";
        }
    }

    /**
     * called from routeSupplierReplyCancel if email is from "customersupport@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of request
     * if $cancelTriggerWords226 in reply
     * @action $this->orderConfirmedCanceled($order,$ticketID);
     * if $deniedTriggerWords226 in reply
     * @action $this->orderCancellationDenied($order,$ticketID);
     * if $delayedTriggerWords226 in reply
     * @action reject ticket removing it from our system. this will cause the function to return 'rejected'
     * if $poNotFoundTriggerWords226 in reply
     * @action $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyCancel226 ($ticketID)
    {

        try {
            $this->_logger->info('Function: ParseSupplierReplyCancel226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Cancellation Request]");
                if ($sideConversation == null) {
                    $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Cancellation Request]");
                    return 'rejected';
                }

                $replyBody = $sideConversation['body'];
                $REDACTEDConfOfRecTrigger = $this->_adminUtils->getTranslationFromKey("REDACTED_CONF_OF_REC_TRIGGER");

                if (str_starts_with($replyBody,$REDACTEDConfOfRecTrigger)){
                    $internalNote = $this->_adminUtils->getTranslationFromKey("REDACTED_CONF_OF_REC_INTERNAL_NOTE");
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $internalNote,
                                "public" => "false"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => '226'
                                )
                            ),
                            "status" => "pending"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    return "rejected";

                }
                $replyID = $sideConversation['ID'];
                if ($replyID == null) {
                    $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Cancellation Request]');
                    return "rejected";
                }
            }

            $cancelTriggerWords226string = $this->_adminUtils->getTranslationFromKey("ORDER_CANCELED_TRIGGER_ARRAY_226");
            $cancelTriggerWords226 = explode(";",$cancelTriggerWords226string);


            $deniedTriggerWords226string = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_TRIGGER_ARRAY_226");
            $deniedTriggerWords226= explode(";",$deniedTriggerWords226string);


            $delayedTriggerWords226string = $this->_adminUtils->getTranslationFromKey("CANCELLATION_DELAYED_TRIGGER_ARRAY_226");
            $delayedTriggerWords226= explode(";",$delayedTriggerWords226string);

            $poNotFoundTriggerWords226string = $this->_adminUtils->getTranslationFromKey("ORDER_NOT_FOUND_TRIGGER_ARRAY_226");
            $poNotFoundTriggerWords226= explode(";",$poNotFoundTriggerWords226string);


            foreach ($cancelTriggerWords226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderConfirmedCanceled($order,$ticketID);
                    return null;
                }
            }
            foreach ($deniedTriggerWords226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderCancellationDenied($order,$ticketID);
                    return null;
                }
            }
            foreach ($delayedTriggerWords226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->_zendeskUtils->rejectTicket($ticketID,'REDACTED stated that they would need to contact their vendor. our system is not yet developed to handle multiple messages back and forth.');
                    return "rejected";
                }
            }
            foreach ($poNotFoundTriggerWords226 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");

        } catch (\Exception $e) {
            $this->_logger->info('Ticket# ' . $ticketID . ' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID, "our system's code hit an 'Exception' when attempting to process this ticket. Exception: " . $e->getMessage());
            return "rejected";
        }
    }

    /**
     * called from routeSupplierReplyCancel if email is from "REDACTED"
     * @param string $ticketID
     * @action parse supplier response to determine status of request
     * if $cancelTriggerWords310 in reply
     * @action $this->orderConfirmedCanceled($order,$ticketID);
     * if $deniedTriggerWords310 in reply
     * @action $this->orderCancellationDenied($order,$ticketID);
     * if $poNotFoundTriggerWords310 in reply
     * @action $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyCancel310 ($ticketID)
    {

        try {
            $this->_logger->info('Function: ParseSupplierReplyCancel310');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Cancellation Request]");
                if ($sideConversation == null) {
                    $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Cancellation Request]");
                    return 'rejected';
                }
                $replyBody = $sideConversation['body'];
                $replyID = $sideConversation['ID'];
                if ($replyID == null) {
                    $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Cancellation Request]');
                    return "rejected";
                }
            }
            $cancelTriggerWords310 = array('we have canceled');
            $deniedTriggerWords310 = array('Shipped UPS','Your order shipped',"order shipped via");
            $poNotFoundTriggerWords310 = array('o record of this PO');
            foreach ($cancelTriggerWords310 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderConfirmedCanceled($order,$ticketID);
                    return null;
                }
            }
            foreach ($deniedTriggerWords310 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderCancellationDenied($order,$ticketID);
                    return null;
                }
            }
            foreach ($poNotFoundTriggerWords310 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        } catch (\Exception $e) {
            $this->_logger->info('Ticket# ' . $ticketID . ' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID, "our system's code hit an 'Exception' when attempting to process this ticket. Exception: " . $e->getMessage());
            return "rejected";
        }
    }
    /**
     * called from routeSupplierReplyCancel if email is from "REDACTED@REDACTED.com"
     * @param string $ticketID
     * @action parse supplier response to determine status of request
     * if $cancelTriggerWords281 in reply
     * @action $this->orderConfirmedCanceled($order,$ticketID);
     * if $deniedTriggerWords281 in reply
     * @action $this->orderCancellationDenied($order,$ticketID);
     * if $delayedTriggerWords281 in reply
     * @action reject ticket removing it from our system. this will cause the function to return 'rejected'
     * if $poNotFoundTriggerWords281 in reply
     * @action $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseSupplierReplyCancel281 ($ticketID)
    {

        try {
            $this->_logger->info('Function: ParseSupplierReplyCancel281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $Subject . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID, "[Cancellation Request]");
                if ($sideConversation == null) {
                    $this->_zendeskUtils->rejectTicket($ticketID, "our system could not locate the side conversation with the supplier. No side convo with string: [Cancellation Request]");
                    return 'rejected';
                }
                $replyBody = $sideConversation['body'];
                $replyID = $sideConversation['ID'];
                if ($replyID == null) {
                    $this->_zendeskUtils->rejectTicket($ticketID, 'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Cancellation Request]');
                    return "rejected";
                }
            }

            $cancelTriggerWords281string = $this->_adminUtils->getTranslationFromKey("ORDER_CANCELED_TRIGGER_ARRAY_281");
            $cancelTriggerWords281= explode(";",$cancelTriggerWords281string);

            //I have never seen denial come from this email but I keep a space for it.
            $deniedTriggerWords281 = array();
            $delayedTriggerWords281 = array('I will confirm once completed','reach out to the manufacturer','will advise','Please allow some time for an answer');
            $poNotFoundTriggerWords281 = array('Do you have the order number','any order');
            foreach ($cancelTriggerWords281 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderConfirmedCanceled($order,$ticketID);
                    return null;
                }
            }
            foreach ($deniedTriggerWords281 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->orderCancellationDenied($order,$ticketID);
                    return null;
                }
            }
            foreach ($delayedTriggerWords281 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->_zendeskUtils->rejectTicket($ticketID,'REDACTED stated that they would need to contact their vendor. our system is not yet developed to handle multiple messages back and forth.');
                    return "rejected";
                }
            }
            foreach ($poNotFoundTriggerWords281 as $trigger){
                if (str_contains($replyBody,$trigger)){
                    $this->supplierFailedToLocateOrder($order,$ticketID,$replyID);
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"Supplier reply did not have a valid ETA or a known trigger phrase.");
        } catch (\Exception $e) {
            $this->_logger->info('Ticket# ' . $ticketID . ' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID, "our system's code hit an 'Exception' when attempting to process this ticket. Exception: " . $e->getMessage());
            return "rejected";
        }
    }

    /**
     * called if open ticket has string "[Return Request]" in subject
     * @param string $ticketID
     * if order is closed
     * @action notify end customer via main thread of ticket that a refund has already been processed
     * @action solves ticket
     * if order is complete
     * @action notify end customer that order has shipped
     * @action solves ticket
     * if requester is "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyCancel312($ticketID)
     * if requester is "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyCancel226($ticketID)
     * if requester is "REDACTED"
     * @action this->ParseSupplierReplyCancel310($ticketID)
     * if requester is "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyCancel281($ticketID)
     * if requester is "REDACTED@REDACTED.com"
     * @action this->ParseSupplierReplyCancel281($ticketID)
     * @return null on success, 'rejected' on failure
     */
    public function routeSupplierReplyCancel ($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: routeSupplierReplyCancel');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $incrementIdFull = sprintf("%'.09d", $incrementId);
            $this->_searchCriteriaBuilder->addFilter('increment_id', $incrementIdFull);
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $orderState = $order->getState();
                $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));

                if ($orderState == 'closed') {
                    $formatReply = $this->_adminUtils->getTranslationFromKey("ALREADY_CANCELLED_TO_CUSTOMER");
                    $Reply = sprintf($formatReply,$customerName,$incrementIdFull);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                )
                            ),
                            "subject" => "REDACTED Order# 000" . $incrementId . " Cancelled",
                            "status" => "solved"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    return "rejected";
                } elseif ($orderState == "complete") {
                    $formatReply = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_TO_CUSTOMER");
                    $Reply = sprintf($formatReply,$customerName);

                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                )
                            ),
                            "subject" => "REDACTED Order# 000" . $incrementId . " Cancellation Denied",
                            "status" => "solved"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    return "rejected";
                }
            }
            $sideConversation = $this->_zendeskUtils->getSideConvoBySubjectSubstr($ticketID,"[Cancellation Request]");
            if ($sideConversation == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not locate the side conversation with the supplier. No side convo with string: [Cancellation Request]");
                return 'rejected';
            }
            $replyEmail = $sideConversation['email'];
            $replyID = $sideConversation['ID'];
            if ($replyID == null){
                $this->_zendeskUtils->rejectTicket($ticketID,'our system could not locate the side conversation with the supplier on this ticket. No convo with key in subject: [Cancellation Request]');
                return "rejected";
            }
            if ($this->_utils->Test()){
                $this->ParseSupplierReplyCancel226($ticketID);
                return null;
            }
            if ($replyEmail == 'REDACTED'){
                $this->ParseSupplierReplyCancel312($ticketID);
                return null;
            }elseif ($replyEmail == 'REDACTED'){
                $this->ParseSupplierReplyCancel226($ticketID);
                return null;
            }elseif ($replyEmail == 'REDACTED'){
                $this->ParseSupplierReplyCancel310($ticketID);
                return null;
            }elseif ($replyEmail == 'REDACTED'){
                $this->ParseSupplierReplyCancel281($ticketID);
                return null;
            }elseif ($replyEmail == 'REDACTED'){
                $this->ParseSupplierReplyCancel281($ticketID);
                return null;
            }else{
                $this->_zendeskUtils->rejectTicket($ticketID,"At this time our system has not been developed to work with the supplier for this order.");
                return 'rejected';
            }
        } catch (\Exception $e) {
            $this->_logger->info('Ticket# ' . $ticketID . ' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID, "our system's code hit an 'Exception' when attempting to process this ticket. Exception: " . $e->getMessage());
            return "rejected";
        }
    }

/// PENDING TICKET QUEUE FUNCTIONS###############################################################################################################
/// functions used to address tickets in pending status. Primarily this includes new request that are set to pending to be hidden from agent view.
/// this includes tickets that our system can process quickly and consistently with out error.

// Return Functions
// functions used to contact supplier for new return




    /**
     * called if in $this->routeCustomerServiceFormData() the $request is "Return Request" or "Exchange Request"
     * @param string $ticketID
     * @param string $reshipOrRefund a string that is added to the email to the supplier stating if the customer wants a reship or a refund
     * @action reaches out to the supplier
     * @action updates order status to complete/return_in_progress(orders that are not complete are rejected before this point)
     * @action adds internal note to order and ticket detailing actions taken by our system
     * @action update ticket subject to include trigger for routeSupplierReplyReturn "[Return Request]"
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function contactSupplierReturn($ticketID,$reshipOrRefund){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: contactSupplierReturn');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $orderNumberIndicator = $this->_adminUtils->getTranslationFromKey("ORDER_NUMBER_INDICATOR");
            $PoSearchStr = $this->_utils->findSubStr($Body,$orderNumberIndicator,"\n");
            $incrementId = $this->_utils->ParsePO($PoSearchStr);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$PoSearchStr."]");
                return 'rejected';
            }

            $incrementIdFull = sprintf("%'.09d", $incrementId);
            $this->_searchCriteriaBuilder->addFilter('increment_id', sprintf("%'.09d", $incrementId));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderTotal = $order->getGrandTotal();
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $supplierOrderNumber = $this->_utils->getSupplierOrderNumberFromOrder($order);
                if ($orderTotal > 1000.0000){
                    $entity = $this->_entityResolver->GetEntityByOrder($order);
                    $whiteGloveID = $this->_utils->getWhiteGloveID();
                    $entity->setData('order_owner', $whiteGloveID);
                    $this->_saveHandler->execute($entity);
                    $formatOrderNote = $this->_adminUtils->getTranslationFromKey("WHITEGLOVE_ORDER_COMMENT");
                    $OrderNote = sprintf($formatOrderNote, $incrementIdFull);
                    $formatTicketNote = $this->_adminUtils->getTranslationFromKey("WHITEGLOVE_TICKET_NOTE");
                    $ticketNote = sprintf($formatTicketNote, $incrementIdFull);
                    $order->addStatusHistoryComment($OrderNote);
                    $order->save();
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $ticketNote,
                                "public" => "false"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                ),
                                array(
                                    "id" => $this->QueueFieldID,
                                    "value" => "white_glove"
                                ),
                                array(
                                    "id" => $this->supplierOrderNumberFieldID,
                                    "value" => $supplierOrderNumber
                                )
                            ),
                            "status" => "open"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    $this->_logger->info("Order owner for " . $incrementIdFull . " set to Whiteglove");
                    $this->_zendeskUtils->add_tag_ticket($ticketID,"white_glove,rejected,csb2_rejected");
                    return "rejected";
                }
                $state = $order->getState();
                if ($state != "complete"){
                    $this->_zendeskUtils->rejectTicket($ticketID,"A return has been requested but PO# ".sprintf("%'.09d", $incrementId)." is in state [".$state."], and not in state [complete]");
                    return 'rejected';
                }
                $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                }
            }
            $supplierKey = substr($sku, 0, 4);
            $supplierArray = $this->_zendeskUtils->getSupplierArray();
            $returnReason = $this->_utils->findSubStr($Body,'Why are you returning this?',"\n");
            $returnQty = $this->_utils->findSubStr($Body,'Quantity',"\n");
            if (str_contains($Body,"Additional Information")){
                $returnNote = $this->_utils->findSubStr($Body,'Additional Information',"\n");
            }else{
                $returnNote = null;
            }
            if ($returnReason == 'Other'){
                $returnReason = $this->_utils->findSubStr($Body,"Other Return Reason","\n");
            }
            if (array_key_exists($supplierKey, $supplierArray)) {
                if ($this->_utils->Test() == true){
                    $supplierEmail = "tyler.polny96@gmail.com";
                }else{
                    $supplierEmail = $supplierArray[$supplierKey]["email"];
                }
                $supplierAcct = $supplierArray[$supplierKey]["account"];
            } else{
                $this->_zendeskUtils->rejectTicket($ticketID,"At this time our system does not have contact information for the supplier providing sku '.$sku.'.");
                $format = $this->_adminUtils->getTranslationFromKey("NEW_RETURN_REQUEST_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
                return 'rejected';
            }
            $EmailBodyFormat = $this->_adminUtils->getTranslationFromKey("NEW_RETURN_REQUEST_BODY");
            $EmailBody = sprintf($EmailBodyFormat,$supplierAcct,$incrementId,$supplierOrderNumber,$returnReason,$returnQty,$reshipOrRefund,$returnNote);

            if ($supplierKey == '281-'){
                $this->_zendeskUtils->create_side_convo($ticketID,"Account 5645121-[Return Request] - REDACTED PO# 0".$incrementId,$EmailBody,$supplierEmail);
            }else{
                $this->_zendeskUtils->create_side_convo($ticketID,"[Return Request] - REDACTED PO# 0".$incrementId,$EmailBody,$supplierEmail);
            }
            $format = $this->_adminUtils->getTranslationFromKey("NEW_RETURN_REQUEST_INTERNAL_NOTE_ORDER");
            $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
            $order->addStatusHistoryComment($body);
            $order->save();
            $formatReply = $this->_adminUtils->getTranslationFromKey("NEW_RETURN_REQUEST_TO_CUSTOMER");
            $Reply = sprintf($formatReply,$customerName,$incrementIdFull);

            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Reply,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => $vendorCode
                        ),
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "return_request"
                        ),
                        array(
                            "id" => $this->supplierOrderNumberFieldID,
                            "value" => $supplierOrderNumber
                        )
                    ),
                    "subject" => '[Return Request] - REDACTED Order# 000'.$incrementId,
                    "status" => "pending"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

// Order Status Functions
// functions used to contact supplier for new Order Status request

    public function contactSupplierOrderStatusBulk($ticketID){

        try{
            $ticketJson = $this->_zendeskUtils->getSingleTicket($ticketID);
            $ticketArray = json_decode($ticketJson,true);
            $ticket = $ticketArray["ticket"];
            $requesterID = $ticket["requester_id"];
            $requesterArry = $this->_zendeskUtils->getUserByID($requesterID);
            $requesterEmail = $requesterArry["email"];
            $requesterName = $requesterArry["name"];
            $ticketBody = $ticket['description'];
            $originalTicketID = ($ticket["id"]);
            $Subject = ($ticket["subject"]);
            $this->_logger->info("\n\n");
            $this->_logger->info("******************************************************************************");
            $this->_logger->info("***".$Subject."***");
            $this->_logger->info('Function: contactSupplierOrderStatusBulk');
            $orderListIndicator = $this->_adminUtils->getTranslationFromKey("ORDER_LIST_INDICATOR");
            $orderListString = $this->_utils->findSubStr($ticketBody,$orderListIndicator,"\n");
            $FormEmail = $this->_utils->findSubStr($ticketBody,"Email Address","\n");
            $this->_logger->info("Order List Parsed:");
            $this->_logger->info($orderListString);
            $orderArray = explode(",",$orderListString);
            $this->_logger->info("Order array :");
            $this->_logger->info(print_r($orderArray,true));
            $this->_logger->info("iterating through parsed order list");
            $TicketsToBeCreated = array();
            foreach ($orderArray as $orderNumber){
                $incrementId = $this->_utils->ParsePO("0".$orderNumber." ");
                $incrementIdFull = sprintf("%'.09d", $incrementId);
                if ($incrementId == null){
                    $this->_logger->info("Invalid PO Parsed. Going to next PO.");
                    continue;
                }

                $this->_logger->info("\n===============\nWorking order: [".$incrementIdFull."]");
                $this->_searchCriteriaBuilder->addFilter('increment_id', $incrementIdFull);
                $searchCriteria = $this->_searchCriteriaBuilder->create();
                $orderList = $this->_orderRepository->getList($searchCriteria);
                $orders = $orderList->getItems();
                foreach ($orders as $order) {
                }
                $supplierOrderNumber = $this->_utils->getSupplierOrderNumberFromOrder($order);
                $items = $order->getAllItems();
                foreach ($items as $item) {
                }
                $sku = $item->getSku();
                $this->_logger->info("Order is for SKU ".$sku);
                $vendorCode = substr($sku,0,3);


                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                    $QueueFieldID = "1500011234061";
                    $OrderIncrementIDFieldID = "1500012542762";
                    $vendorCodeFieldID = "1500005678702";

                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                    $QueueFieldID = "1500011596361";
                    $OrderIncrementIDFieldID = "360041196794";
                    $vendorCodeFieldID = "360055641194";

                }

                if (!$this->_utils->test()){
                    if ($customerEmail != $FormEmail){
                        $this->_logger->info("Order ".$incrementId." is not associated with email address provided on form");
                        continue;
                    }
                }
                $formatBody = $this->_adminUtils->getTranslationFromKey("CREATE_ORDER_STATUS_REQUEST_TO_CUSTOMER");
                $body = sprintf($formatBody,$customerName,$incrementIdFull,$incrementIdFull,$originalTicketID);
                $ticket = array(
                    "comment" => array(
                        "body" => $body,
                        "public" => 'false'
                    ),
                    "requester" => array(
                        "name" => $requesterName,
                        "email" => $requesterEmail
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $QueueFieldID,
                            "value" => "order_status_request"
                        ),
                        array(
                            "id" => $OrderIncrementIDFieldID,
                            "value" => $incrementIdFull
                        ),
                        array(
                            "id" => $vendorCodeFieldID,
                            "value" => $vendorCode
                        ),
                        array(
                            "id" => $this->supplierOrderNumberFieldID,
                            "value" => $supplierOrderNumber
                        )
                    ),
                    "priority" => "normal",
                    "subject" => "REDACTED Form Submission",
                    "status" => "pending"
                );
                $TicketsToBeCreated[] = $ticket;
            }
            $tickets = array(
                "tickets" => $TicketsToBeCreated
            );

            $job = $this->_zendeskUtils->batchCreateTickets($tickets);
            $jobURL = $job['job_status']['url'];
            $this->_logger->info("Job URL:");
            $this->_logger->info($jobURL);
            $ticketIDs = $this->_zendeskUtils->getTicketIDsFromBatchCall($jobURL);
            $ticketIDsLinks = null;
            foreach ($ticketIDs as $newTicketID){
                if ($ticketIDsLinks == null){
                    $ticketIDsLinks = "#".$newTicketID;
                }else{
                    $ticketIDsLinks = $ticketIDsLinks."\n#".$newTicketID;
                }
            }
            $formatTicketInternalNote = $this->_adminUtils->getTranslationFromKey("BULK_ORDER_STATUS_TICKET_NOTE");
            $TicketInternalNote = sprintf($formatTicketInternalNote,$ticketIDsLinks,$ticketID);
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $TicketInternalNote,
                        "public" => "false"
                    ),
                    "status" => "solved"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);

        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception Processing Pending Ticket Queue: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }

    }

    /**
     * called if in $this->routeCustomerServiceFormData() the $request is "Order Status Update (Tracking, Arrival Date)"
     * @param string $ticketID
     * @action reaches out to the supplier
     * @action updates order status to processing/awaiting_supplier_feedback
     * @action adds internal note to order and ticket detailing actions taken by our system
     * @action update ticket subject to include trigger for routeSupplierReplyReturn "[Order Status Request]"
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function contactSupplierOrderStatus ($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: contactSupplierOrderStatus');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $orderNumberIndicator = $this->_adminUtils->getTranslationFromKey("ORDER_NUMBER_INDICATOR");
            $PoSearchStr = $this->_utils->findSubStr($Body,$orderNumberIndicator,"\n");
            $incrementId = $this->_utils->ParsePO($PoSearchStr);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$PoSearchStr."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $supplierOrderNumber = $this->_utils->getSupplierOrderNumberFromOrder($order);
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                    $supplierKey = substr($sku,0,4);
                }
                $state = $order->getState();
                if ($state == "holded"){
                    $this->_zendeskUtils->rejectTicket($ticketID,"PO# ".sprintf("%'.09d", $incrementId)." is currently in a [holded] state.");
                    $format = $this->_adminUtils->getTranslationFromKey("NEW_ORDER_STATUS_REQUEST_INTERNAL_NOTE_ORDER");
                    $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                    $order->addStatusHistoryComment($body);
                    $order->save();
                    return "rejected";
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
            }
            $supplierArray = $this->_zendeskUtils->getSupplierArray();
            if (array_key_exists($supplierKey, $supplierArray)) {
                if ($this->_utils->Test() == true){
                    $supplierName = "Test Supplier Name";
                    $supplierEmail = "tyler.polny96@gmail.com";
                }else{
                    $supplierName = $supplierArray[$supplierKey]["name"];
                    $supplierEmail = $supplierArray[$supplierKey]["email"];
                }
                $supplierAcct = $supplierArray[$supplierKey]["account"];
            } else{
                $this->_zendeskUtils->rejectTicket($ticketID,"At this time our system does not have contact information for the supplier providing sku ".$sku.".");
                $format = $this->_adminUtils->getTranslationFromKey("NEW_ORDER_STATUS_REQUEST_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();

                return 'rejected';
            }
            $note = $this->_utils->findSubStr($Body,'Additional Information ',"\n");
            $format = $this->_adminUtils->getTranslationFromKey("NEW_ORDER_STATUS_EMAIL_TO_SUPPLIER");
            $body = sprintf($format,$supplierName,$supplierAcct,$supplierOrderNumber,$incrementId,$note);

            if ($supplierKey == "281-"){
                $this->_zendeskUtils->create_side_convo($ticketID,"Account 5645121-Order Status Request for PO# 0".$incrementId." [Order Status Request]",$body,$supplierEmail);
            }else{
                $this->_zendeskUtils->create_side_convo($ticketID,"Order Status Request for PO# 0".$incrementId." [Order Status Request]",$body,$supplierEmail);
            }
            $formatReply = $this->_adminUtils->getTranslationFromKey("NEW_ORDER_STATUS_EMAIL_TO_CUSTOMER");
            $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId));

            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Reply,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => $vendorCode
                        ),
                        array(
                            "id" => $this->supplierOrderNumberFieldID,
                            "value" => $supplierOrderNumber
                        ),
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "order_status_request"
                        )
                    ),
                    "subject" => "Customer Requested Update For PO# 0".$incrementId." [Order Status Request]",
                    "status" => "pending"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
            $format = $this->_adminUtils->getTranslationFromKey("NEW_ORDER_STATUS_REQUEST_INTERNAL_NOTE_ORDER");
            $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
            $order->addStatusHistoryComment($body);
            $order->save();
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }



// Cancel Functions
// functions used to contact supplier for new cancellation request

    public function contactSupplierCancelBulk($ticketID){

        try{
            $ticketJson = $this->_zendeskUtils->getSingleTicket($ticketID);
            $ticketArray = json_decode($ticketJson,true);
            $ticket = $ticketArray["ticket"];
            $requesterID = $ticket["requester_id"];
            $requesterArry = $this->_zendeskUtils->getUserByID($requesterID);
            $requesterEmail = $requesterArry["email"];
            $requesterName = $requesterArry["name"];

            $ticketBody = $ticket['description'];
            $this->_logger->info("Ticket Body Below");
            $this->_logger->info($ticketBody);
            $originalTicketID = ($ticket["id"]);
            $Subject = ($ticket["subject"]);
            $this->_logger->info("\n\n");
            $this->_logger->info("******************************************************************************");
            $this->_logger->info("***".$Subject."***");
            $this->_logger->info("Ticket ID: ".$ticketID);
            $this->_logger->info('Function: contactSupplierCancelBulk');
            $orderListIndicator = $this->_adminUtils->getTranslationFromKey("ORDER_LIST_INDICATOR");
            $this->_logger->info("Order List Indicator [".$orderListIndicator."]");

            $orderListString = $this->_utils->findSubStr($ticketBody,$orderListIndicator,"\n");
            $FormEmail = $this->_utils->findSubStr($ticketBody,"Email Address","\n");
            $this->_logger->info("Order List Parsed:");
            $this->_logger->info($orderListString);
            $orderArray = explode(",",$orderListString);
            $this->_logger->info("Order array :");
            $this->_logger->info(print_r($orderArray,true));
            $this->_logger->info("iterating through parsed order list");
            $TicketsToBeCreated = array();
            foreach ($orderArray as $orderNumber){
                $incrementId = $this->_utils->ParsePO("0".$orderNumber." ");
                $incrementIdFull = sprintf("%'.09d", $incrementId);
                if ($incrementId == null){
                    $this->_logger->info("Invalid PO Parsed. Going to next PO.");
                    continue;
                }
                $this->_logger->info("\n===============\nWorking order: [".$incrementIdFull."]");
                $this->_searchCriteriaBuilder->addFilter('increment_id', $incrementIdFull);
                $searchCriteria = $this->_searchCriteriaBuilder->create();
                $orderList = $this->_orderRepository->getList($searchCriteria);
                $orders = $orderList->getItems();
                foreach ($orders as $order) {
                }
                $supplierOrderNumber = $this->_utils->getSupplierOrderNumberFromOrder($order);
                if ($supplierOrderNumber == null) {
                    $supplierOrderNumber = null;
                }

                $this->_logger->info("Supplier Order Number Parsed:");
                $this->_logger->info($supplierOrderNumber);

                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                if (!$this->_utils->test()){
                    if ($customerEmail != $FormEmail){
                        $this->_logger->info("Order ".$incrementId." is not associated with email address provided on form");
                        continue;
                    }
                }


                $items = $order->getAllItems();
                foreach ($items as $item) {
                }
                $sku = $item->getSku();
                $this->_logger->info("Order is for SKU ".$sku);
                $vendorCode = substr($sku,0,3);

                if ($this->_utils->Test()){
                    $QueueFieldID = "1500011234061";
                    $OrderIncrementIDFieldID = "1500012542762";
                    $vendorCodeFieldID = "1500005678702";

                }else{
                    $QueueFieldID = "1500011596361";
                    $OrderIncrementIDFieldID = "360041196794";
                    $vendorCodeFieldID = "360055641194";

                }

                $formatBody = $this->_adminUtils->getTranslationFromKey("CREATE_CANCEL_REQUEST_TO_CUSTOMER");
                $Body = sprintf($formatBody,$customerName,$incrementIdFull,$incrementIdFull,$originalTicketID);
                $ticket = array(
                    "comment" => array(
                        "body" => $Body,
                        "public" => 'false'
                    ),
                    "requester" => array(
                        "name" => $requesterName,
                        "email" => $requesterEmail
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $QueueFieldID,
                            "value" => "cancellation_request"
                        ),
                        array(
                            "id" => $OrderIncrementIDFieldID,
                            "value" => $incrementIdFull
                        ),
                        array(
                            "id" => $vendorCodeFieldID,
                            "value" => $vendorCode
                        ),
                        array(
                            "id" => $this->supplierOrderNumberFieldID,
                            "value" => $supplierOrderNumber
                        )
                    ),
                    "priority" => "normal",
                    "subject" => "REDACTED Form Submission",
                    "status" => "pending"
                );
                $TicketsToBeCreated[] = $ticket;
            }

            $tickets = array(
                "tickets" => $TicketsToBeCreated
            );

            $job = $this->_zendeskUtils->batchCreateTickets($tickets);
            $jobURL = $job['job_status']['url'];
            $this->_logger->info("Job URL:");
            $this->_logger->info($jobURL);
            $ticketIDs = $this->_zendeskUtils->getTicketIDsFromBatchCall($jobURL);
            $ticketIDsLinks = null;
            foreach ($ticketIDs as $newTicketID){
                if ($ticketIDsLinks == null){
                    $ticketIDsLinks = "#".$newTicketID;
                }else{
                    $ticketIDsLinks = $ticketIDsLinks."\n#".$newTicketID;
                }
            }
            $formatTicketInternalNote = $this->_adminUtils->getTranslationFromKey("BULK_CANCEL_TICKET_NOTE");
            $TicketInternalNote = sprintf($formatTicketInternalNote,$ticketIDsLinks,$ticketID);
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $TicketInternalNote,
                        "public" => "false"
                    ),
                    "status" => "solved"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
            $this->_logger->info("******************************************************************************");
            $this->_logger->info("\n\n");

        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception Processing Pending Ticket Queue: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }

    }




    /**
     * called if in $this->routeCustomerServiceFormData() the $request is "Cancel Request"
     * @param string $ticketID
     * @action reaches out to the supplier
     * @action updates order status to hold/cancel_requested_by_customers
     * @action adds internal note to order and ticket detailing actions taken by our system
     * @action update ticket subject to include trigger for routeSupplierReplyReturn "[Cancellation Request]"
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function contactSupplierCancel ($ticketID){
        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: contactSupplierCancel');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $orderNumberIndicator = $this->_adminUtils->getTranslationFromKey("ORDER_NUMBER_INDICATOR");
            $PoSearchStr = $this->_utils->findSubStr($Body,$orderNumberIndicator,"\n");
            $incrementId = $this->_utils->ParsePO($PoSearchStr);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$PoSearchStr."]");
                return 'rejected';
            }
            $incrementIdFull = sprintf("%'.09d", $incrementId);
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $orderTotal = $order->getGrandTotal();
                $supplierOrderNumber = $this->_utils->getSupplierOrderNumberFromOrder($order);
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }

                if ($orderTotal > 1000.0000){
                    $entity = $this->_entityResolver->GetEntityByOrder($order);
                    $whiteGloveID = $this->_utils->getWhiteGloveID();
                    $entity->setData('order_owner', $whiteGloveID);
                    $this->_saveHandler->execute($entity);
                    $formatOrderNote = $this->_adminUtils->getTranslationFromKey("WHITEGLOVE_ORDER_COMMENT");
                    $OrderNote = sprintf($formatOrderNote, $incrementIdFull);
                    $formatTicketNote = $this->_adminUtils->getTranslationFromKey("WHITEGLOVE_TICKET_NOTE");
                    $ticketNote = sprintf($formatTicketNote, $incrementIdFull);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $ticketNote,
                                "public" => "false"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                ),
                                array(
                                    "id" => $this->QueueFieldID,
                                    "value" => "white_glove"
                                ),
                                array(
                                    "id" => $this->supplierOrderNumberFieldID,
                                    "value" => $supplierOrderNumber
                                )
                            ),
                            "status" => "open"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);

                    $order->addStatusHistoryComment($OrderNote);
                    $order->save();
                    $this->_logger->info("Order owner for " . $incrementIdFull . " set to Whiteglove");
                    $this->_zendeskUtils->add_tag_ticket($ticketID,"white_glove,rejected,csb2_rejected");
                    return "rejected";
                }

                $orderState = $order->getState();
                $orderStatus = $order->getStatus();
                $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
                if (($orderState == 'closed')||($orderStatus == "canceled_supplier_confirmed")||($orderStatus == "canceled_supplier_confirmed_nc")){
                    $formatReply = $this->_adminUtils->getTranslationFromKey("NEW_CANCEL_REQUEST_ALREADY_REFUNDED");
                    $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId));

                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                ),
                                array(
                                    "id" => $this->QueueFieldID,
                                    "value" => "cancellation_request"
                                ),
                                array(
                                    "id" => $this->supplierOrderNumberFieldID,
                                    "value" => $supplierOrderNumber
                                )
                            ),
                            "subject" => "REDACTED Order# ".sprintf("%'.09d", $incrementId)." Cancelled",
                            "status" => "solved"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    $this->_logger->info('Ticket '.$ticketID.' solved. Cancellation request for order in closed status.');
                    return "already cancelled";

                }elseif($orderState == "complete"){
                    $formatReply = $this->_adminUtils->getTranslationFromKey("DENIED_CANCELLATION_TO_CUSTOMER");
                    $Reply = sprintf($formatReply,$customerName);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                ),
                                array(
                                    "id" => $this->QueueFieldID,
                                    "value" => "cancellation_request"
                                ),
                                array(
                                    "id" => $this->supplierOrderNumberFieldID,
                                    "value" => $supplierOrderNumber
                                )
                            ),
                            "subject" => "REDACTED Order# ".sprintf("%'.09d", $incrementId)." Cancellation Denied",
                            "status" => "solved"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    return "already shipped";
                }
                $customerName = substr($order->getCustomerName(), 0, strpos($order->getCustomerName(), ' '));
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                }
            }
            $supplierKey = substr($sku, 0, 4);
            $supplierArray = $this->_zendeskUtils->getSupplierArray();
            if (array_key_exists($supplierKey, $supplierArray)) {
                if ($this->_utils->Test() == true){
                    $supplierName = "Test Supplier Name";
                    $supplierEmail = "tyler.polny96@gmail.com";
                }else{
                    $supplierName = $supplierArray[$supplierKey]["name"];
                    $supplierEmail = $supplierArray[$supplierKey]["email"];
                }
                $supplierAcct = $supplierArray[$supplierKey]["account"];
            } else{
                $this->_zendeskUtils->rejectTicket($ticketID,"At this time our system does not have contact information for the supplier providing sku ".$sku.".");
                $state = $order->getState();
                if ($state != "holded"){
                    $order->hold();
                }
                $order->setStatus('cancel_requested_by_customers');
                $format = $this->_adminUtils->getTranslationFromKey("NEW_CANCEL_REQUEST_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
                return 'rejected';
            }
            $format = $this->_adminUtils->getTranslationFromKey("NEW_CANCEL_REQUEST_TO_SUPPLIER");
            $body = sprintf($format,$supplierName,$supplierAcct,$supplierOrderNumber,$incrementId);
            if ($supplierKey == '281-'){
                $this->_zendeskUtils->create_side_convo($ticketID,"Account 5645121- REDACTED PO# 0".$incrementId."- [Cancellation Request]",$body,$supplierEmail);
            }else{
                $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED PO# 0".$incrementId."- [Cancellation Request]",$body,$supplierEmail);
            }
            $formatReply = $this->_adminUtils->getTranslationFromKey("NEW_CANCEL_REQUEST_TO_CUSTOMER");
            $Reply = sprintf($formatReply,$customerName,sprintf("%'.09d", $incrementId));

            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Reply,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => $vendorCode
                        ),
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "cancellation_request"
                        ),
                        array(
                            "id" => $this->supplierOrderNumberFieldID,
                            "value" => $supplierOrderNumber
                        )
                    ),
                    "subject" => "[Cancellation Request]REDACTED Order# ".sprintf("%'.09d", $incrementId),
                    "status" => "pending"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);


            $state = $order->getState();
            if ($state != "holded"){
                $order->hold();
            }
            $order->setStatus('cancel_requested_by_customers');
            $format = $this->_adminUtils->getTranslationFromKey("NEW_CANCEL_REQUEST_INTERNAL_NOTE_ORDER");
            $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
            $order->addStatusHistoryComment($body);
            $order->save();


        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * called if "Product Availability Request" in "Request Type" in routeCustomerServiceFormData
     * @param string $ticketID
     * @action Contact supplier for availability
     * @action reply to end customer
     * @action set ticket to pending
     * @action update ticket subject
     * @action adds internal note to ticket
     * @return null on success, 'rejected' on failure
     */
    public function contactSupplierSkuAvailability($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: contactSupplierSkuAvailability');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $sku = $this->_utils->findSubStr($Body,"Product SKU ","\n");
            $supplierKey = substr($sku,0,4);
            $vendorCode = substr($sku,0,3);
            if ($supplierKey == "226-"){
                $this->_zendeskUtils->rejectTicket($ticketID,"REDACTED has been responding to product availability requests by processing an order and charging REDACTED. Because of this until further notice tickets with this request for 226 will not be processed by automation.");
                return "rejected";
            }
            $supplierSku = str_replace($supplierKey,"",$sku);
            $productName = $this->_utils->findSubStr($Body,"Product Name ","\n");
            $qty = $this->_utils->findSubStr($Body,"Quantity ","\n");
            $zipCode = $this->_utils->findSubStr($Body,"Zipcode ","\n");
            $customerName = $this->_utils->findSubStr($Body,"Customer Name ","\n");
            $customerNote = $this->_utils->findSubStr($Body,"Additional Information ","\n");
            if (array_key_exists($supplierKey,$this->_zendeskUtils->getSupplierArray()) == false){
                $this->_zendeskUtils->changeTicketSubject($ticketID,"Product Availability for Sku ".$sku);
                $this->_zendeskUtils->rejectTicket($ticketID,"Availability request for sku sourced through non automated supplier");
                return "rejected";
            }
            $supplierArray = $this->_zendeskUtils->getSupplierArray();

            if (array_key_exists($supplierKey, $supplierArray)) {
                if ($this->_utils->Test() == true){
                    $supplierName = "Test Supplier Name";
                    $supplierEmail = "tyler.polny96@gmail.com";
                }else{
                    $supplierName = $supplierArray[$supplierKey]["name"];
                    $supplierEmail = $supplierArray[$supplierKey]["email"];
                }
                $supplierAcct = $supplierArray[$supplierKey]["account"];
            }
            $format = $this->_adminUtils->getTranslationFromKey("SKU_AVAILABILITY_TO_SUPPLIER");
            $body = sprintf($format,$supplierName,$supplierAcct,$productName,$supplierSku,$qty,$zipCode,$customerNote);
            $this->_zendeskUtils->create_side_convo($ticketID,"Expected lead time for SKU: ".$supplierSku."-[SKU Availability]",$body,$supplierEmail);
            $formatReply = $this->_adminUtils->getTranslationFromKey("SKU_AVAILABILITY_TO_CUSTOMER");
            $Reply = sprintf($formatReply,$customerName,$productName,$qty,$sku);
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Reply,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => $vendorCode
                        ),
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "product_availability_requests"
                        )
                    ),
                    "subject" => "SKU# ".$sku." Pending Availability Request",
                    "status" => "pending"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * @param $ticketID string the ticket that our system will sent order status to if possible
     * @param $incrementId string order in question
     * @return string ERROR if GetOrderStatus_Cognigy returns ERROR in place of order status
     * @return string SUCCESS if function sends order status to ticket ID
     */
    public function replyWithExternalOrderStatusToCustomer($ticketID,$incrementId){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: ' . $ticketID);
            $this->_logger->info('Function: replyWithExternalOrderStatusToCustomer');
            $this->_searchCriteriaBuilder->addFilter('increment_id', sprintf("%'.09d", $incrementId));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order){
            }
            if ($this->_utils->Test()){
                //$incrementId = '000260369';
                //$zipCode = '08618';
                //$customerName = "test";
                return "NO ORDER STATUS";

            }else{
                $customerName = $order->getCustomerFirstname();
                $zipCode = $order->getShippingAddress()->getPostcode();

            }

            $this->_logger->info($customerName);
            $this->_logger->info($zipCode);
            $orderStatus = $this->_utils->GetExternalOrderStatus($incrementId,$zipCode);
            if ($orderStatus == "NO ORDER STATUS"){
                return "NO ORDER STATUS";
            }
            $formatBody = $this->_adminUtils->getTranslationFromKey("SEND_EXTERNAL_ORDER_STATUS");
            $body = sprintf($formatBody,$customerName,$incrementId,$orderStatus);

            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $body,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        )
                    ),
                    "status" => "solved"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
            return "SUCCESS";
        }catch (\Exception $e) {
            $this->_logger->info(' exception: ' . $e->getMessage());
            return "rejected";
        }
    }
    /**
     * @param $ticketID string the ticket that our system will sent order status to if possible
     * @param $sku string the sku which we are looking up availability
     * @return string ERROR if GetOrderStatus_Cognigy returns ERROR in place of order status
     * @return string SUCCESS if function sends order status to ticket ID
     */
    public function replyWithExternalAvailabilityToCustomer($sku,$zipCode,$ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: ' . $ticketID);
            $this->_logger->info('Function: replyWithExternalAvailabilityToCustomer');

            $availability = $this->_utils->GetExternalSkuAvailability($sku,$zipCode);
            //if ($this->Test){
                //$availability = "NO AVAILABILITY";
            //}
            if ($availability == "NO AVAILABILITY"){
                return "NO AVAILABILITY";
            }
            $formatBody = $this->_adminUtils->getTranslationFromKey("SEND_EXTERNAL_SKU_AVAILABILITY");
            $body = sprintf($formatBody,$sku,$zipCode,$availability);

            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $body,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "product_availability_requests"
                        )
                    ),
                    "status" => "solved"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
            return "SUCCESS";
        }catch (\Exception $e) {
            $this->_logger->info(' exception: ' . $e->getMessage());
            return "rejected";
        }
    }
// Route Ticket Functions
// functions used to route tickets to other functions based on parsed data
    /**
     * called if "REDACTED Form Submission" in ticket subject and in pending status
     * @param string $ticketID
     * @action parse value on form form "Request Type"
     * if "Return Request"  in "Request Type"
     * @action $this->contactSupplierReturn($ticketID,"Return For Refund");
     * if "Exchange Request" in "Request Type"
     * @action $this->contactSupplierReturn($ticketID,'Return For an Exchange');
     * if "Cancel Request" in "Request Type"
     * @action $this->contactSupplierCancel($ticketID);
     * if "Order Status Update (Tracking, Arrival Date)" in "Request Type"
     * @action $this->contactSupplierOrderStatus
     * if "Product Availability Request" in "Request Type"
     * @action $this->contactSupplierSkuAvailability($ticketID);
     * @return null on success, 'rejected' on failure
     */

    public function routeCustomerServiceFormData($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: ' . $ticketID);
            $this->_logger->info('Function: customerserviceformdata');
            $ticket_json = $this->_zendeskUtils->getSingleTicket($ticketID);
            $ticket_decode = json_decode($ticket_json,true);
            $ticket = $ticket_decode["ticket"];
            $Body = $ticket['description'];
            $request = $this->_utils->findSubStr($Body,"Request Type","\n");
            $OrderStatusRequestTrigger = $this->_adminUtils->getTranslationFromKey("ORDER_STATUS_REQUEST_TRIGGER");
            $ReturnRequestTrigger = $this->_adminUtils->getTranslationFromKey("RETURN_REQUEST_TRIGGER");
            $ExchangeRequestTrigger = $this->_adminUtils->getTranslationFromKey("EXCHANGE_REQUEST_TRIGGER");
            $ProductAvailabilityRequestTrigger = $this->_adminUtils->getTranslationFromKey("PRODUCT_AVAILABILITY_REQUEST_TRIGGER");
            $CancelRequestTrigger = $this->_adminUtils->getTranslationFromKey("CANCEL_REQUEST_TRIGGER");
            $TaxExemptRequestTrigger = $this->_adminUtils->getTranslationFromKey("TAX_EXEMPT_REQUEST_TRIGGER");
            $orderNumberIndicator = $this->_adminUtils->getTranslationFromKey("ORDER_NUMBER_INDICATOR");
            if (str_contains($Body,"No (2 or more orders)")){
                if ($request == $OrderStatusRequestTrigger) {
                    $this->contactSupplierOrderStatusBulk($ticketID);
                }elseif ($request == $CancelRequestTrigger){
                    $this->contactSupplierCancelBulk($ticketID);
                }else{
                    $this->_zendeskUtils->rejectTicket($ticketID,"our system is only programed to process bulk cancellation and order status requests.");
                    return 'rejected';
                }
            }elseif(str_contains($Body,$orderNumberIndicator)){
                $PoSearchStr = $this->_utils->findSubStr($Body,$orderNumberIndicator,"\n");
                $incrementId = $this->_utils->ParsePO($PoSearchStr);
                if ($incrementId == null){
                    $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$PoSearchStr."]");
                    return 'rejected';
                }


                $this->_searchCriteriaBuilder->addFilter('increment_id', sprintf("%'.09d", $incrementId));
                $searchCriteria = $this->_searchCriteriaBuilder->create();
                $orderList = $this->_orderRepository->getList($searchCriteria);
                $orders = $orderList->getItems();
                foreach ($orders as $order){
                    if ($this->_utils->Test()){
                        $customerName = "TEST CUSTOMER";
                    }else{
                        $customerName = $order->getCustomerFirstname();
                    }
                    $incrementIdFull = $order->getIncrementId();
                    $customerEmailOrder = strtolower($order->getCustomerEmail());
                    $customerEmailForm = strtolower($this->_utils->findSubStr($Body,"Email Address","\n"));
                    $orderDate = $order->getCreatedAt();
                    $orderTotal = $order->getGrandTotal();
                    $orderStatus = $order->getStatus();
                    $fiveDaysAgo = date("Y-m-d",strtotime("-5 days"));
                    if(!$this->_utils->Test()){
                        if ($customerEmailForm != $customerEmailOrder){
                            $this->_zendeskUtils->rejectTicket($ticketID,"The order listed on this customer service form is not associated with the email address on this form.\nEmail on form: ".$customerEmailForm."\nEmail associated with ".$incrementId.": ".$customerEmailOrder);
                            $this->_logger->info("A customer service form was submitted for an order that is attached to a different email address then the requester. Zendesk ticket ".$ticketID);
                            return "rejected";
                        }
                    }
                    if ($request == $ExchangeRequestTrigger){
                        $this->contactSupplierReturn($ticketID,"Return and have item reshipped");
                    }elseif ($request == $ReturnRequestTrigger){
                        $this->contactSupplierReturn($ticketID,'Return For Refund');
                    }elseif ($request == $CancelRequestTrigger){
                        if(($orderTotal > 500.0000)&&($orderStatus == "backordered") ){
                            $formatInternalNote = $this->_adminUtils->getTranslationFromKey("BACKORDER_RETENTION_ORDER_NOTE");
                            $internalNote = sprintf($formatInternalNote,$ticketID,$ticketID);
                            $order->addStatusHistoryComment($internalNote);
                            $order->save();

                            $formatBody = $this->_adminUtils->getTranslationFromKey("BACKORDER_RETENTION");
                            $replyBody = sprintf($formatBody,$customerName,$incrementIdFull);
                            $update = array(
                                "ticket" => array(
                                    "comment" => array(
                                        "body" => $replyBody,
                                        "public" => "true"
                                    ),
                                    "custom_fields" => array(
                                        array(
                                            "id" => $this->OrderIncrementIDFieldID,
                                            "value" => sprintf("%'.09d", $incrementId)
                                        ),
                                        array(
                                            "id" => $this->QueueFieldID,
                                            "value" => "white_glove"
                                        )
                                    ),
                                    "status" => "pending"
                                )
                            );
                            $this->_zendeskUtils->updateTicket($ticketID,$update);
                            $tags = ["whiteglove","rejected","csb2_rejected"];
                            $this->_zendeskUtils->add_tag_ticket($ticketID,$tags);


                        }else{
                            $this->contactSupplierCancel($ticketID);
                        }
                    }elseif ($request == $OrderStatusRequestTrigger) {
                        $orderStatus_External = $this->replyWithExternalOrderStatusToCustomer($ticketID,$incrementId);
                        if ($orderStatus_External == "NO ORDER STATUS"){
                            $this->_logger->info("Call to external.REDACTED did not return an order status.");
                            if($orderDate<$fiveDaysAgo){
                                $this->_logger->info("Order is at least 5 days old.");
                                if ($orderStatus == "awaiting_ack"){
                                    $this->_logger->info("Order is still in Awaiting Ack. Queueing ticket to be processed manually.");
                                    $this->_zendeskUtils->rejectTicket($ticketID,"Order Status Request for an order in awaiting_ack status.");
                                    return "rejected";
                                }else{
                                    $this->_logger->info("Reaching out to the supplier to get an Order Status.");
                                    $this->contactSupplierOrderStatus($ticketID);
                                }
                            }else{
                                $this->_logger->info("Order is under 5 days old.");
                                $this->_logger->info("Passing over ticket until order is over 5 days old.");
                            }
                        }
                    }elseif ($request == $TaxExemptRequestTrigger){
                        $update = array(
                            "ticket" => array(
                                "custom_fields" => array(
                                    array(
                                        "id" => $this->OrderIncrementIDFieldID,
                                        "value" => sprintf("%'.09d", $incrementId)
                                    ),
                                    array(
                                        "id" => $this->QueueFieldID,
                                        "value" => "tax_exempt"
                                    )
                                ),
                                "subject" => "Tax Exempt Request",
                                "status" => "open"
                            )
                        );
                        $this->_zendeskUtils->updateTicket($ticketID,$update);
                        $this->_zendeskUtils->rejectTicket($ticketID,"The request type selected on this form has not yet been developed for our system 2.0. Request type: [".$request."]");
                        return "rejected";
                    }else{
                        $this->_zendeskUtils->rejectTicket($ticketID,"The request type selected on this form has not yet been developed for our system 2.0. Request type: [".$request."]");
                        $update = array(
                            "ticket" => array(
                                "custom_fields" => array(
                                    array(
                                        "id" => $this->OrderIncrementIDFieldID,
                                        "value" => sprintf("%'.09d", $incrementId)
                                    ),
                                    array(
                                        "id" => $this->QueueFieldID,
                                        "value" => "other"
                                    )
                                ),
                                "subject" => "Request Type: Other",
                                "status" => "open"
                            )
                        );
                        $this->_zendeskUtils->updateTicket($ticketID,$update);
                        return "rejected";
                    }
                }
            }elseif ($request == $ProductAvailabilityRequestTrigger){
                $zipCode = $this->_utils->findSubStr($Body,"Zipcode ","\n");
                $sku = $this->_utils->findSubStr($Body,"Product SKU ","\n");
                $availability = $this->replyWithExternalAvailabilityToCustomer($sku,$zipCode,$ticketID);
                if ($availability == "NO AVAILABILITY"){
                    $this->contactSupplierSkuAvailability($ticketID);
                }
            }else{
                $this->_zendeskUtils->rejectTicket($ticketID,"The request type selected on this form has not yet been developed for our system 2.0. Request type: [".$request."]");
                $update = array(
                    "ticket" => array(
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "other"
                            )
                        ),

                        "subject" => "Request Type: Other",
                        "status" => "open"

                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                return "rejected";
            }

        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }

// Red Status Functions
// functions used to contact applicable party for various red order alerts.
    /**
     * called if " status changed to red_ship_deliver (URGENT)" in ticket subject
     * @param string $ticketID
     * @action reaches out to the supplier
     * @action adds internal note to order and ticket detailing actions taken by our system
     * @action update ticket subject to include trigger for routeSupplierReplyReturn " over due RedShipP2"
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function contactSupplierRedShip($ticketID){

        try{
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: contactSupplierRedShip');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = $this->_utils->ParsePO($Subject);
            $this->_logger->info('PO# found: '.$incrementId);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', sprintf("%'.09d", $incrementId));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order){
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $status = $order->getStatus();
                $this->_logger->info('status of PO is: '.$status);
                if ($status != 'red_ship_deliver') {
                    $this->_zendeskUtils->rejectTicket($ticketID,"PO# ".sprintf("%'.09d", $incrementId)." is not currently in status [red_ship_deliver]. It was in status: [".$status."]");
                    $this->_zendeskUtils->setTicketToSolved($ticketID);
                    return 'rejected';
                }

                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $this->_logger->info('customer name: '.$customerName);

                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                }
                $this->_logger->info('sku: '.$sku);
                $supplierKey = substr($sku, 0, 4);
                $supplierArray = $this->_zendeskUtils->getSupplierArray();
                if (array_key_exists($supplierKey, $supplierArray)) {
                    if ($this->_utils->Test() == true){
                        $supplierName = "Test Supplier Name";
                        $supplierEmail = "tyler.polny96@gmail.com";
                    }else{
                        $supplierName = $supplierArray[$supplierKey]["name"];
                        $supplierEmail = $supplierArray[$supplierKey]["email"];
                    }
                    $supplierAcct = $supplierArray[$supplierKey]["account"];
                } else{
                    $this->_zendeskUtils->rejectTicket($ticketID,"At this time our system has not been developed to work with the supplier providing sku ".$sku.".");
                    return 'rejected';
                }
                $this->_logger->info('supplier account number: '.$supplierAcct);
                $format = $this->_adminUtils->getTranslationFromKey("RED_SHIP_TO_SUPPLIER");
                $body = sprintf($format,$supplierName,$supplierAcct,$incrementId);
                $this->_zendeskUtils->create_side_convo($ticketID,'PO# 0'.$incrementId.' overdue [Order Status Request]',$body,$supplierEmail);

                $format = $this->_adminUtils->getTranslationFromKey("RED_SHIP_TO_CUSTOMER");
                $body = sprintf($format,$customerName,sprintf("%'.09d", $incrementId));
                $this->_zendeskUtils->create_side_convo($ticketID,'REDACTED Order# '.$incrementId.' is running late',$body,$customerEmail);

                $format = $this->_adminUtils->getTranslationFromKey("RED_SHIP_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();

                $format = $this->_adminUtils->getTranslationFromKey("RED_SHIP_INTERNAL_NOTE_TICKET");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$customerName,sprintf("%'.09d", $incrementId),sprintf("%'.09d", $incrementId),$ticketID);


                $this->_zendeskUtils->addInternalNoteTicket($ticketID,$body);
                $this->_zendeskUtils->changeTicketSubject($ticketID,'PO# 0'.$incrementId.' overdue [Order Status Request]');
                $this->_logger->info('Ticket# '.$ticketID.' successfully processed as contactSupplierRedShip');
                $this->_zendeskUtils->setTicketToPending($ticketID);
            }
        }catch (\Exception $e) {
            $this->_logger->info('Ticket# '.$ticketID.' Caught exception: ' . $e->getMessage());
            $this->_zendeskUtils->rejectTicket($ticketID,"our system's code hit an 'Exception' when attempting to process this ticket. Exception: ".$e->getMessage());
            return "rejected";
        }
    }


    /**
     * called if " status changed to red_ack (URGENT)" parsed from ticket subject of ticket tagged "csb2_pending_queue"
     * @param string $ticketID
     * if order in red_ack
     * @action contact supplier
     * @action update ticket subject
     * @action pending ticket
     * else
     * @action update subject
     * @action solve ticket
     * @return null on success, 'rejected' on failure
     */
    public function contactSupplierRedAck($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: contactSupplierRedAck');
            $this->_zendeskUtils->add_tag_ticket($ticketID, 'csb2_contactSupplierRedAck');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $this->_zendeskUtils->updateTicketOrderIncrementID($ticketID,sprintf("%'.09d", $incrementId));
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $status = $order->getStatus();
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $supplierKey = substr($sku, 0, 4);
                $supplierArray = $this->_zendeskUtils->getSupplierArray();
                if (array_key_exists($supplierKey, $supplierArray)) {
                    if ($this->_utils->Test() == true){
                        $supplierEmail = "tyler.polny96@gmail.com";
                        $supplierName = '[Test Supplier Name]';
                        $supplierAcct = '[Test Account Number]';
                    }else{
                        $supplierEmail = $supplierArray[$supplierKey]["email"];
                        $supplierName = $supplierArray[$supplierKey]["name"];
                        $supplierAcct = $supplierArray[$supplierKey]["account"];
                    }
                    if ($status == 'red_ack') {
                        $format = $this->_adminUtils->getTranslationFromKey("RED_ACK_TO_SUPPLIER");
                        $body = sprintf($format,$supplierName,$supplierAcct,$incrementId);
                        $this->_zendeskUtils->create_side_convo($ticketID, "Acknowledgment never received for PO# 0" . $incrementId, $body, $supplierEmail);
                        $this->_zendeskUtils->changeTicketSubject($ticketID, "PO# 0" . $incrementId . " awaiting supplier update");
                        $this->_zendeskUtils->setTicketToPending($ticketID);
                    }else{
                        $this->_zendeskUtils->changeTicketSubject($ticketID,"Order ".sprintf("%'.09d", $incrementId)." no longer red ship");
                        $this->_zendeskUtils->setTicketToSolved($ticketID);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if " status changed to closed_order_shipped (URGENT)" parsed from ticket subject that is tagged "csb2_pending_queue"
     * @param string $ticketID
     * if order in closed_order_shipped
     * @action Contacts the end customer informing them they must pay for or return product
     * @action Contacts supplier to inform of this error and ask that they help to resolve it
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action update ticket subject
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function contactSupplierClosedOrderShipped($ticketID){
        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: contactSupplierClosedOrderShipped');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $status = $order->getStatus();
                if ($status != 'closed_order_shipped') {
                    $Reply = $this->_adminUtils->getTranslationFromKey("NOT_CLOSED_ORDER_SHIPPED_NOTE");
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "false"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                )
                            ),
                            "status" => "solved"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    return null;
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $supplierKey = substr($sku, 0, 4);
            $supplierArray = $this->_zendeskUtils->getSupplierArray();
            if (array_key_exists($supplierKey, $supplierArray)) {
                if ($this->_utils->Test() == true){
                    $supplierName = "Test Supplier Name";
                    $supplierEmail = "tyler.polny96@gmail.com";
                }else{
                    $supplierName = $supplierArray[$supplierKey]["name"];
                    $supplierEmail = $supplierArray[$supplierKey]["email"];
                }
                $supplierAcct = $supplierArray[$supplierKey]["account"];
                $format = $this->_adminUtils->getTranslationFromKey("CLOSED_ORDER_SHIPPED_TO_CUSTOMER");
                $body = sprintf($format,$customerName,sprintf("%'.09d", $incrementId));
                $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED Order# ".sprintf("%'.09d", $incrementId)." Shipped in Error",$body,$customerEmail);
                $format = $this->_adminUtils->getTranslationFromKey("CLOSED_ORDER_SHIPPED_TO_SUPPLIER");
                $body = sprintf($format,$supplierName,$supplierAcct,$incrementId);
                if ($supplierKey == "281-"){
                    $this->_zendeskUtils->create_side_convo($ticketID,"Account 5645121-PO# 0".$incrementId." CANCELLED ORDER SHIPPED ALERT  [Closed Order Shipped]",$body,$supplierEmail);
                }else{
                    $this->_zendeskUtils->create_side_convo($ticketID,"PO# 0".$incrementId." CANCELLED ORDER SHIPPED ALERT  [Closed Order Shipped]",$body,$supplierEmail);
                }

                $FormatReply = $this->_adminUtils->getTranslationFromKey("CLOSED_ORDER_SHIPPED_TICKET_NOTE");
                $Reply = sprintf($FormatReply,$incrementId,sprintf("%'.09d", $incrementId));
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $Reply,
                            "public" => "false"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            )
                        ),
                        "subject" => "REDACTED Order ".sprintf("%'.09d", $incrementId)." - [Closed Order Shipped]",
                        "status" => "pending"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                $format = $this->_adminUtils->getTranslationFromKey("CLOSED_ORDER_SHIPPED_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if " status changed to complete_order_shipped_again (URGENT)" in ticket subject of ticket tagged "csb2_pending_queue"
     * @param string $ticketID
     * if order in complete_order_shipped_again
     * @action Contacts the end customer informing them they must pay for or return product
     * @action Contacts supplier to inform of this error and ask that they help to resolve it
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action update ticket subject
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function contactSupplierCompleteOrderShippedAgain($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: contactSupplierCompleteOrderShippedAgain');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $status = $order->getStatus();
                if ($status != 'complete_order_shipped_again') {
                    $Reply = $this->_adminUtils->getTranslationFromKey("NOT_COMPLETE_ORDER_SHIPPED_AGAIN");
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "false"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                )
                            ),
                            "status" => "solved"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                    return null;
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $supplierKey = substr($sku, 0, 4);
            $supplierArray = $this->_zendeskUtils->getSupplierArray();
            if (array_key_exists($supplierKey, $supplierArray)) {
                if ($this->_utils->Test() == true){
                    $supplierName = "Test Supplier Name";
                    $supplierEmail = "tyler.polny96@gmail.com";
                }else{
                    $supplierName = $supplierArray[$supplierKey]["name"];
                    $supplierEmail = $supplierArray[$supplierKey]["email"];
                }
                $supplierAcct = $supplierArray[$supplierKey]["account"];
                $format = $this->_adminUtils->getTranslationFromKey("COMPLETE_ORDER_SHIPPED_TO_CUSTOMER");
                $body = sprintf($format,$customerName,sprintf("%'.09d", $incrementId));
                $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED Order# ".sprintf("%'.09d", $incrementId)."  Shipped Twice in Error",$body,$customerEmail);
                $format = $this->_adminUtils->getTranslationFromKey("COMPLETE_ORDER_SHIPPED_TO_SUPPLIER");
                $body = sprintf($format,$supplierName,$supplierAcct,$incrementId);
                if ($supplierKey == "281-"){
                    $this->_zendeskUtils->create_side_convo($ticketID,"Account 5645121-PO# 0".$incrementId." Shipped Twice in Error [Complete Order Shipped Again]",$body,$supplierEmail);
                }else{
                    $this->_zendeskUtils->create_side_convo($ticketID,"PO# 0".$incrementId." Shipped Twice in Error [Complete Order Shipped Again]",$body,$supplierEmail);
                }

                $FormatReply = $this->_adminUtils->getTranslationFromKey("COMPLETE_ORDER_SHIPPED_AGAIN_TICKET_NOTE");
                $Reply = sprintf($FormatReply,$incrementId,sprintf("%'.09d", $incrementId));

                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $Reply,
                            "public" => "false"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            ),
                        ),
                        "subject" => "REDACTED Order ".sprintf("%'.09d", $incrementId)." - [Complete Order Shipped Again]",
                        "status" => "pending"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);

                $format = $this->_adminUtils->getTranslationFromKey("COMPLETE_ORDER_SHIPPED_AGAIN_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }


/// NEW TICKET QUEUE FUNCTIONS############################################################################
/// functions used to sort and address one off template email from suppliers and acts upon the parsed data.



    /**
     * called if "Return To:" parsed from body in "routeNewSupplierEmail310"
     * @param string $ticketID
     * if order is complete
     * @action contact end customer with RMA details
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action Solves ticket
     * @return null on success, 'rejected' on failure
     */
    public function ParseReturnAuthorization310($ticketID){

        try {

            $this->_logger->info('Function: ParseReturnAuthorization310');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $IDseach = $this->_utils->findSubStr($Body,"Purchase Order # ","\n");
            $incrementId = $this->_utils->ParsePO($IDseach);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDseach."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $state = $order->getState();
                if ($state != 'complete'){
                    $this->_zendeskUtils->rejectTicket($ticketID,"A return approval was received for an order not in complete state. Order state is \"".$state."\"");
                    return "rejected";
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
            }
            $ReturnAddress = $this->_utils->findSubStr($Body,"Return To:","This ");
            $format = $this->_adminUtils->getTranslationFromKey("RETURN_APPROVED_GENERAL_TO_CUSTOMER");
            $body = sprintf($format,$customerName,$ReturnAddress);
            $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED Return Approved for Order# ".sprintf("%'.09d", $incrementId),$body,$customerEmail);

            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => "our system has located RMA details provided by REDACTED and forwarded these details to the end customer on the side conversation of this ticket. -our system 2",
                        "public" => "false"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        ),
                        array(
                            "id " => $this->QueueFieldID,
                            "value" => "return_request"
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => $vendorCode
                        )
                    ),
                    "status" => "solved"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
            $format = $this->_adminUtils->getTranslationFromKey("RETURN_APPROVAL_INTERNAL_NOTE_ORDER");
            $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
            $order->addStatusHistoryComment($body);
            $order->save();

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if "We would like to notify you of a change to your order. The below has been rejected as a line item from your EDI purchase order." in body in "routeNewSupplierEmail310"
     * @param string $ticketID
     * if order is not closed
     * @action $this->orderConfirmedCanceled($order,$ticketID)
     * @return null on success, 'rejected' on failure
     */
    public function ParseCancellationNotice310($ticketID){

        try {
            $this->_logger->info('Function: ParseCancellationNotice310');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $IDSearch = $this->_utils->findAllAfterSubStr(strtolower($Body),"po #");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $state = $order->getState();
                if ($state != 'closed'){
                    $this->orderCanceledWithOutRequestContactBySideConvo($order,$ticketID);
                }else{
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => "our system has detected a confirmation of cancellation from REDACTED. However this order is already in a closed state. So our system has solved this ticket.",
                                "public" => "false"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->QueueFieldID,
                                    "value" => "new_supplier_email"
                                ),
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                )
                            ),
                            "status" => "solved"
                        )
                    );
                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                }
            }

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }


    /**
     * called if "sold in quantities of" in "$Body" in routeNewSupplierEmail310
     * @param string $ticketID
     * @action Sets state/status to holded/awaiting_customer_feedback
     * @action notifies end customer of action required
     * @action notifies REDACTED that we will follow up with them
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */

    public function contactCustomerUpdateOrderQty310($ticketID){

        try {
            $this->_logger->info('Function: contactCustomerUpdateOrderQty310');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = strtolower($data['results'][0]['description']);
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                }
            }
            $searchString = $this->_utils->findSubStr($Body,"sold in quantities",".");
            $this->_logger->info("search string being used");
            $this->_logger->info("[".$searchString."]");
            if (preg_match('/ [0-9]{1,3}/',$searchString)){
                preg_match('/ [0-9]{1,3}/',$searchString,$matches);

            }else{
                $this->_zendeskUtils->rejectTicket($ticketID,"our system can see that REDACTED is requesting that we update the quantity for Order. But could not determine the required quantity.");
                return "rejected";
            }
            $requiredQty = $matches[0];
            $format = $this->_adminUtils->getTranslationFromKey("QTY_RESTRICTED_GENERAL_TO_CUSTOMER");
            $body = sprintf($format,$customerName,$sku,$requiredQty,$requiredQty);
            $this->_zendeskUtils->create_side_convo($ticketID,"Action Required. Quantity Must Be Updated For Order: ".sprintf("%'.09d", $incrementId),$body,$customerEmail);
            $formatReply = $this->_adminUtils->getTranslationFromKey("QTY_RESTRICTED_TO_SUPPLIER");
            $Reply = sprintf($formatReply,"REDACTED Team",$incrementId,$requiredQty);
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Reply,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "new_supplier_email"
                        ),
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => $vendorCode
                        )
                    ),
                    "subject" => "Action Required. Quantity Must Be Updated For Order: ".sprintf("%'.09d", $incrementId),
                    "status" => "pending"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
            $this->_zendeskUtils->rejectTicket($ticketID,"our system has not yet been developed to process the customers response to this. This function is soon to come.");
            $format = $this->_adminUtils->getTranslationFromKey("QTY_RESTRICTED_INTERNAL_NOTE_ORDER");
            $body = sprintf($format,sprintf("%'.09d", $incrementId),$requiredQty,$ticketID,$ticketID);
            $order->addStatusHistoryComment($body);
            $order->save();

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * Called when our system detects template email from REDACTED stating that an item has VOC (Volatile Organic Compound) restrictions
     * @param string $ticketID
     * @return string null on success, 'rejected' on failure
     */
    public function cancelOrderVocRestriction310($ticketID)
    {

        try {
            $this->_logger->info('Function: cancelOrderVocRestriction310');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Body = $data['results'][0]['description'];
            $IDSearch = $this->_utils->findAllAfterSubStr($Body, "PO #");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null) {
                $this->_zendeskUtils->rejectTicket($ticketID, "our system could not find a valid PO# in the following text:\n[" . $IDSearch . "]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku, 0, 3);
                }
                $state = $order->getState();
            }
            if($state != "closed"){
                $this->orderCanceledWithOutRequestContactBySideConvo($order,$ticketID);
            }else{
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => "our system has detected a confirmation of cancellation from REDACTED. However this order is already in a closed state. So our system has solved this ticket.",
                            "public" => "false"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            )
                        ),
                        "status" => "solved"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: ' . $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }


    /**
     * called if "@REDACTED" in ticket requester and in new status
     * @param string $ticketID
     * @action parse ticket contents to determine the suppliers request/notice
     * if "RGA # " or "Return Authorization Reprint" in "$Subject"
     * @action $this->ParseReturnAuthorization310($ticketID);
     * if "Return To:" in "$Body"
     * @action $this->ParseReturnAuthorization310($ticketID);
     * if "We would like to notify you of a change to your order. The below has been rejected as a line item from your EDI purchase order." in "$Body"
     * @action $this->ParseCancellationNotice310($ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function routeNewSupplierEmail310($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: routeNewSupplierEmail310');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]['subject'];
            $Body = $data['results'][0]['description'];
            if (str_contains($Subject,"RGA # ")){
                $this->ParseReturnAuthorization310($ticketID);
            }elseif(str_contains($Subject,"Return Authorization Reprint")){
                $this->ParseReturnAuthorization310($ticketID);
            }elseif(str_contains($Body,"Return To:")){
                $this->ParseReturnAuthorization310($ticketID);
            }elseif(str_contains(strtolower($Body),"we would like to notify you of a change to your order. the below has been rejected as a line item from your edi purchase order")){
                $this->ParseCancellationNotice310($ticketID);
            }elseif(str_contains($Body,"sold in quantities of")){
                $this->contactCustomerUpdateOrderQty310($ticketID);
            }elseif(str_contains($Body,"in your geographic location, the following item is not available to be shipped:")){
                $this->cancelOrderVocRestriction310($ticketID);
            }else{
                $update = array(
                    "ticket" => array(
                        "custom_fields" => array(
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => "310"
                            ),
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            )
                        )
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                $this->_zendeskUtils->rejectTicket($ticketID,"our system can tell that this is a new email from REDACTED, but could not determine what they were requesting.");
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if " Delivery Date has changed" is parsed from ticket subject in routeNewSupplierEmail226
     * @param string $ticketID
     * @action $this->_utils->ParseDate on body after "New Delivery Date"
     * if date is located
     * @action $this->updateOrderETASideConvo($order,$ETA,$ticketID);
     * if date is not located
     * @action $this->updateOrderBackorderNoETASideConvo($order,$ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function newOrderETA226($ticketID){

        try {
            $this->_logger->info('Function: newOrderETA226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket ' . $ticketID);
            $data = json_decode($ticket, true);
            $Body = $data['results'][0]['description'];
            $poSearch = $this->_utils->findAllAfterSubStr($Body,'PO #');
            $incrementId = $this->_utils->ParsePO($poSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$poSearch."]");
                $this->_logger->info('incrementID could not be parsed');
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $ETAsearchStr = substr($Body,strpos($Body,"New Delivery Date"));
                $ETA = $this->_utils->ParseDate($ETAsearchStr);
                if ($ETA != null){
                    $this->updateOrderETASideConvo($order,$ETA,$ticketID);
                }else{
                    $this->updateOrderBackorderNoETASideConvo($order,$ticketID);
                }
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if "has been removed from your order" parsed from body in "routeNewSupplierEmail226"
     * @param string $ticketID
     * if order is not closed
     * @action $this->orderConfirmedCanceled($order,$ticketID)
     * @return null on success, 'rejected' on failure
     */
    public function ParseCancellationNotice226($ticketID){

        try {
            $this->_logger->info('Function: ParseCancellationNotice226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $IDsearch = substr(strtolower($Subject),strpos(strtolower($Subject),"p")+1);
            $incrementId = $this->_utils->ParsePO($IDsearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDsearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $state = $order->getState();
                $status = $order->getStatus();
                if (($state == 'closed')||($status == "canceled_supplier_confirmed")||($status == "canceled_supplier_confirmed_nc")){
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => "our system has detected a confirmation of cancellation from REDACTED. However this order is already in a closed state. So our system has solved this ticket.",
                                "public" => "false"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->QueueFieldID,
                                    "value" => "new_supplier_email"
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                )
                            ),
                            "status" => "solved"
                        )
                    );
                } else {
                    //TP - REDACTED have been sending this template in error causing closed order shipped events. Tickets must be processed manually.
                    //$this->orderConfirmedCanceledSendBySideConvo($order, $ticketID);

                    //todo submit below string to DB
                    $update = array(
                        "ticket" => array(
                            "commnet" => array(
                                "body" => "Attention REDACTED Team,\n\nThe email below is a cancellation notice for order ".$incrementId.". However this is not a valid proof of cancellation. REDACTED has sent these emails in error in the past. Please pull up this order in our suppliers system to confirm its actual status and process this ticket accordingly.\n\nThank you for all that you do,\n-our system 2",
                                "public" => "false"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->QueueFieldID,
                                    "value" => "new_supplier_email"
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                )
                            ),
                            "status" => "solved"
                        )
                    );
                }
                $this->_zendeskUtils->updateTicket($ticketID,$update);

            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }


    /**
     * called if ""Please send documentation on your company's letterhead stating your intended use of the product." parsed from ticket body in "routeNewSupplierEmail226"
     * @param string $ticketID
     * @action contact end customer to request documentation to authorize their order
     * @action set ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function ParseRestrictedProductNotice226($ticketID){

        try {
            $this->_logger->info('Function: ParseRestrictedProductNotice226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $incrementId = $this->_utils->ParsePO($Body);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Body."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $format = $this->_adminUtils->getTranslationFromKey("SKU_RESTRICTED_226_TO_CUSTOMER");
                $body = sprintf($format,$customerName,$sku);
                $this->_zendeskUtils->create_side_convo($ticketID,"URGENT - Action Required - REDACTED Order#: ".sprintf("%'.09d", $incrementId)." On Hold.",$body,$customerEmail);
                $formatReply = $this->_adminUtils->getTranslationFromKey("SKU_RESTRICTED_226_TO_SUPPLIER");
                $Reply = sprintf($formatReply,$incrementId);
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $Reply,
                            "public" => "true"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            ),
                        ),
                        "subject" => "Restricted Product - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                        "status" => "pending"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);


                $format = $this->_adminUtils->getTranslationFromKey("SKU_RESTRICTED_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();

            }

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if "quantities of" in ticket body in "routeNewSupplierEmail226"
     * @param string $ticketID
     * @action contacts the end customer notifying them of order status and requirements for processing
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @return null on success, 'rejected' on failure
     */
    public function contactCustomerUpdateOrderQty226($ticketID){

        try {
            $this->_logger->info('Function: contactCustomerUpdateOrderQty226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $Subject = $data['results'][0]['subject'];
            $IDSearch = $this->_utils->findAllAfterSubStr(strtolower($Subject),"p");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }

                $requiredQty = $this->_utils->findSubStr($Body,"quantities of ",".");
                $this->_logger->info("required qty parsed: ". $requiredQty);
                $format = $this->_adminUtils->getTranslationFromKey("QTY_RESTRICTED_GENERAL_TO_CUSTOMER");
                $body = sprintf($format,$customerName,$sku,$requiredQty,$requiredQty);
                $this->_zendeskUtils->create_side_convo($ticketID,"Action Required - REDACTED Order ".sprintf("%'.09d", $incrementId)." on hold",$body,$customerEmail);

                $format = $this->_adminUtils->getTranslationFromKey("QTY_RESTRICTED_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$requiredQty,$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
                $update = array(
                    "ticket" => array(
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            )
                        ),
                        "subject" => "Must Update Quantity - REDACTED Order# ".sprintf("%'.09d", $incrementId),
                        "status" => "pending"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);



                $this->_zendeskUtils->rejectTicket($ticketID,"our system is not yet developed to process the response to this ticket.");
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if "backordered with a delayed ship date" in ticket subject in function "routeNewSupplierEmail226"
     * @param string $ticketID
     * @action $this->updateOrderBackOrderNoETASideConvo($order,$ticketID)
     * @return null on success, 'rejected' on failure
     */
    public function ParseBackOrderNotice226($ticketID){

        try {
            $this->_logger->info('Function: ParseBackOrderNotice226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = $data['results'][0]['description'];
            $IDSearch = $this->_utils->findSubStr($Body,"PO Number","\n");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $this->updateOrderBackOrderNoETASideConvo($order,$ticketID);

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if "not approved for personal use" parsed from "routeNewSupplierEmail226"
     * @param string $ticketID
     * @action contacts the end customer notifying them of order status and requirements for processing
     * @action Sets state/status to holded/awaiting_customer_feedback
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action updates ticket subject
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function ParseNonComplianceNotice226($ticketID){

        try {
            $this->_logger->info('Function: ParseNonComplianceNotice226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $IDSearch = $this->_utils->findAllAfterSubStr(strtolower($Body),"p");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Body."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {

                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $supplierKey = substr($sku, 0, 4);
                if ($this->_utils->Test() != true){
                    if ($supplierKey != '226-'){
                        $this->_zendeskUtils->rejectTicket($ticketID,"our system found what it thought was an order number. But when checking the order it was not for REDACTED. Order number located: ".$incrementId);
                        return "rejected";
                    }
                }
            }
            $cancelTriggerWords226string = $this->_adminUtils->getTranslationFromKey("ORDER_CANCELED_TRIGGER_ARRAY_226");
            $this->_logger->info("test start below");
            $this->_logger->info("triggers:");
            $this->_logger->info($cancelTriggerWords226string);
            $this->_logger->info("body:");
            $this->_logger->info($Body);
            $cancelTriggerWords226 = explode(";",$cancelTriggerWords226string);
            foreach ($cancelTriggerWords226 as $trigger){
                if (str_contains($Body,$trigger)){
                    $this->orderCanceledWithOutRequestContactBySideConvo($order,$ticketID);
                    return null;
                }
            }
            $this->_zendeskUtils->rejectTicket($ticketID,"REDACTED has notified us that SKU ".$sku." is restricted from residential sales, but email did not include a known phrase that confirms that the order has been fully cancelled.");
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if " Item Delayed - Alternative Offering " in "$Subject" in $this->routeNewSupplierEmail226
     * @param string $ticketID
     * @action Contact end customer to notify them that the item they have ordered is unavailable.
     * @action sends the end customer a link to item suggested by REDACTED
     * @action sets ticket to pending
     * @action updates the ticket subject
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @return null on success, 'rejected' on failure
     */
    public function ParseAltItemSuggestion226($ticketID){
//TP all calls for function below have been muted. This function needs to confirm that the item being sent to the customer is available on REDACTED.

        try {
            $this->_logger->info('Function: ParseAltItemSuggestion226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = $data['results'][0]['description'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $suggestedAltItem = $this->_utils->findSubStr($Body,"Alternative Item#: ","\n");
                $linkToSuggestedAlt = "https://www.REDACTED.com/index.php/catalogsearch/result/?q=%22".$suggestedAltItem."%22";
                $formatReply = $this->_adminUtils->getTranslationFromKey("ALT_ITEM_SUGGESTION_226_TO_SUPPLIER");
                $Reply = sprintf($formatReply,$suggestedAltItem);
                if ($this->Test){
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $Reply,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $this->OrderIncrementIDFieldID,
                                    "value" => sprintf("%'.09d", $incrementId)
                                ),
                                array(
                                    "id" => $this->vendorCodeFieldID,
                                    "value" => $vendorCode
                                )
                            ),
                            "subject" => "Request Made to Cancel PO# 0".$incrementId,
                            "status" => "pending"
                        )
                    );

                    $this->_zendeskUtils->updateTicket($ticketID,$update);
                }else{
                    $this->_zendeskUtils->replyToTicketMainConvo($ticketID,$Reply);
                    $this->_zendeskUtils->changeTicketSubject($ticketID,"Request Made to Cancel PO# 0".$incrementId);
                    $this->_zendeskUtils->setTicketToPending($ticketID);
                }

                $format = $this->_adminUtils->getTranslationFromKey("ALT_ITEM_SUGGESTION_226_TO_CUSTOMER");
                $body = sprintf($format,$customerName,sprintf("%'.09d", $incrementId),$sku,$linkToSuggestedAlt);
                $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED Order# ".sprintf("%'.09d", $incrementId)." is unavailable.",$body,$customerEmail);


                $format = $this->_adminUtils->getTranslationFromKey("COMPLETE_ORDER_SHIPPED_AGAIN_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if "We must have your assurance that this item is not used for drinking purposes" in "$Body" in $this->routeNewSupplierEmail226
     * @param string $ticketID
     * @action Replies to supplier directing them to process the order
     * @action notifies end customer by side convo to notify that item can not be used for drinking water
     * @action solves ticket
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @return null on success, 'rejected' on failure
     */
    public function ParseNonPotableWaterNotice226($ticketID){

        try {
            $this->_logger->info('Function: ParseNonPotableWaterNotice226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = $data['results'][0]['description'];
            $IDSearch = $this->_utils->findAllAfterSubStr(strtolower($Body),"p");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $state = $order->getState();
                if ($state == "holded"){
                    $this->_zendeskUtils->rejectTicket($ticketID,"PO# ".sprintf("%'.09d", $incrementId)." is currently in a [holded] state.");
                    $format = $this->_adminUtils->getTranslationFromKey("NON_POTABLE_WATER_NOTICE_226_INTERNAL_NOTE_ORDER");
                    $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                    $order->addStatusHistoryComment($body);
                    $order->save();
                    return "rejected";
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $itemName = $this->_utils->findSubStr($Body,"Item Description: ","\n");
                $formatReply = $this->_adminUtils->getTranslationFromKey("NON_POTABLE_WATER_NOTICE_226_TO_SUPPLIER");
                $Reply = sprintf($formatReply,$itemName);
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $Reply,
                            "public" => "true"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            )
                        ),
                        "subject" => 'Awaiting Customer Confirmation - REDACTED Order '.sprintf("%'.09d", $incrementId),
                        "status" => "pending"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                $format = $this->_adminUtils->getTranslationFromKey("NON_POTABLE_WATER_NOTICE_226_TO_CUSTOMER");
                $body = sprintf($format,$customerName,sprintf("%'.09d", $incrementId),$itemName,$sku);
                $this->_zendeskUtils->create_side_convo($ticketID,"Action Required - REDACTED Order#: ".sprintf("%'.09d", $incrementId),$body,$customerEmail);
                $format = $this->_adminUtils->getTranslationFromKey("NON_POTABLE_WATER_NOTICE_226_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }


    /**
     * if "contact information for" in "$Body" in routeNewSupplierEmail226
     * @param string $ticketID
     * @action Replies to supplier with end customer contact details
     * @action solves ticket
     * @return null on success, 'rejected' on failure
     */
    public function provideEndCustomerContactInfo226($ticketID){

        try {
            $this->_logger->info('Function: provideEndCustomerContactInfo226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $incrementId = $this->_utils->ParsePO($Body);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Body."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $customerPhone = $order->getShippingAddress()->getTelephone();
                $formatReply = $this->_adminUtils->getTranslationFromKey("PROVIDE_CUSTOMER_CONTACT_TO_SUPPLIER");
                $Reply = sprintf($formatReply,$incrementId,$customerName,$customerPhone,$customerEmail);
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $Reply,
                            "public" => "true"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            )
                        ),
                        "status" => "solved"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }


    /**
     * called if "The item below is still on backorder." in "$Body" in  routeNewSupplierEmail226
     * @param string $ticketID
     * @action replies to the supplier
     * @action notifes the end customer of order status.
     * @action Sets state/status to processing/backordered
     * @action updates ticket subject
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */
    public function ParseStillBackorderNotice226($ticketID){
//TP this function is deprecated - all calls to this function have been commented out.
        //REDACTED no longer uses the "still on back order" template
        //this function sends an email to the end customer suggesting to cancel their order.
        try {
            $this->_logger->info('Function: ParseStillBackorderNotice226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = $data['results'][0]['description'];
            $IDSearch = $this->_utils->findAllAfterSubStr(strtolower($Subject),"p");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                }
                $expectedShipDate = $this->_utils->findSubStr($Body,"Expected Ship Date:","\n");
                $orderPlacedDate =  $this->_utils->findSubStr($Body,"order was placed on ",",");
                $itemName = $this->_utils->findSubStr($Body,"Description:","\n");
                $format = $this->_adminUtils->getTranslationFromKey("NEW_ETA_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$expectedShipDate,$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
                $format = $this->_adminUtils->getTranslationFromKey("STILL_BACKORDER_NOTICE_226_TO_CUSTOMER");
                $body = sprintf($format,$customerName,sprintf("%'.09d", $incrementId),$sku,$orderPlacedDate,$expectedShipDate,$itemName,$sku,$orderPlacedDate,$expectedShipDate);
                $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED Order #".sprintf("%'.09d", $incrementId)." Still On Backorder",$body,$customerEmail);

                $formatReply = $this->_adminUtils->getTranslationFromKey("STILL_BACKORDER_NOTICE_226_TO_SUPPLIER");
                $Reply = sprintf($formatReply,"0".$incrementId);
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $Reply,
                            "public" => "true"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            )
                        ),
                        "subject" => "Awaiting Customer Feedback - REDACTED Backorder ".sprintf("%'.09d", $incrementId),
                        "status" => "pending"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if "REDACTED" in ticket requester and in new status
     * @param string $ticketID
     * @action parse ticket contents to determine the suppliers request/notice
     * if "Please send documentation on your company's letterhead stating your intended use of the product." in "$Body"
     * @action $this->ParseRestrictedProductNotice226($ticketID);
     * if "The item below is still on backorder." in "$Body"
     * @action $this->(ParseStillBackorderNotice226$ticketID);
     * if "We must have your assurance that this item is not used for drinking purposes" in "$Body"
     * @action $this->ParseNonPotableWaterNotice226($ticketID);
     * if " Item Delayed - Alternative Offering " in "$Subject"
     * @action $this->ParseAltItemSuggestion226($ticketID);
     * if "has been cancelled from your order due to non-compliance" or "not approved for personal use" in "$Body"
     * @action $this->ParseNonComplianceNotice226($ticketID);
     * if "contact information for" in "$Body"
     * @action $this->provideEndCustomerContactInfo226($ticketID);
     * if "Cancellation Notice" in "$Subject"
     * @action $this->ParseCancellationNotice226($ticketID);
     * if "has been removed from your order" in "$Body"
     * @action $this->ParseCancellationNotice226($ticketID);
     * if "backordered with a delayed ship date" in "$Subject"
     * @action $this->ParseBackOrderNotice226($ticketID);
     * if "quantities of " in "$Body"
     * @action $this->contactCustomerUpdateOrderQty226($ticketID);
     * if " Delivery Date has changed" in "$Subject"
     * @action $this->newOrderETA226($ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function routeNewSupplierEmail226($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: routeNewSupplierEmail226');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]['subject'];
            $Body = $data['results'][0]['description'];


            if (str_contains($Body,"intended use of the product")){
                $this->ParseRestrictedProductNotice226($ticketID);
            //}elseif (str_contains($Body,"The item below is still on backorder.")){
            //    $this->ParseStillBackorderNotice226($ticketID);
                //function deprecated
            }elseif (str_contains($Body,"We must have your assurance that this item is not used for drinking purposes")){
                $this->ParseNonPotableWaterNotice226($ticketID);
            //}elseif(str_contains($Subject," Item Delayed - Alternative Offering ")){
            //    $this->ParseAltItemSuggestion226($ticketID);
                //function needs to confirm that item being sent to end customer is available on REDACTED.
            }elseif(str_contains($Body,"non-compliance with state or federal regulations")){
                $this->ParseNonComplianceNotice226($ticketID);
            }elseif(str_contains($Body,"not approved for personal use")){
                $this->ParseNonComplianceNotice226($ticketID);
            }elseif(str_contains($Body,"contact information for")){
                $this->provideEndCustomerContactInfo226($ticketID);
            }elseif(str_contains($Body,"has been removed from your order")){
                $this->ParseCancellationNotice226($ticketID);
            }elseif(str_contains($Subject,"backordered with a delayed ship date")){
                $this->ParseBackOrderNotice226($ticketID);
            }elseif(str_contains($Subject,"Cancellation Notice")){
                $this->ParseCancellationNotice226($ticketID);
            }elseif(str_contains($Body,"quantities of ")){
                $this->contactCustomerUpdateOrderQty226($ticketID);
            }elseif(str_contains($Subject," Delivery Date has changed")){
                $this->newOrderETA226($ticketID);
            }else{
                $update = array(
                    "ticket" => array(
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => "226"
                            )
                        )
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                $this->_zendeskUtils->rejectTicket($ticketID,"our system can tell that this is a new email from REDACTED, but could not determine what they were requesting.");
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }


    /**
     * called if "has been delayed" in "$Body" in routeNewSupplierEmail281
     * @param string $ticketID
     * @action $this->updateOrderETASideConvo($order,$ETA,$ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseDelayedNotice281($ticketID){

        try {
            $this->_logger->info('Function: ParseDelayedNotice281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = $data['results'][0]['description'];
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $ETA = $this->_utils->ParseDate($Body);
            $this->_logger->info('value parsed for ETA: ['.$ETA.']');
            if ($ETA == null) {
                $this->updateOrderBackOrderNoETASideConvo($order, $ticketID);
            }else{
                $this->updateOrderETASideConvo($order,$ETA,$ticketID);
            }

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * called if "an ETA of" or "expected to ship" in "$Body" in routeNewSupplierEmail281
     * @param string $ticketID
    if ETA == null
     * @action $this->updateOrderBackOrderNoETASideConvo($order,$ticketID);
    else
     * @action $this->updateOrderETASideConvo($order,$ETA,$ticketID);
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @return null on success, 'rejected' on failure
     */
    public function newOrderETA281($ticketID){

        try {
            $this->_logger->info('Function: newOrderETA281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = $data['results'][0]['description'];
            $IDsearch = $Subject.$Body;
            $incrementId = $this->_utils->ParsePO($IDsearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDsearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $ETAsearch = $this->_utils->findSubStr($Body,"Estimated Ship Date: ","\n");
            $ETA = $this->_utils->ParseDate($ETAsearch);
            if ($ETA == null){
                $this->updateOrderBackOrderNoETASideConvo($order,$ticketID);
            }else{
                $this->updateOrderETASideConvo($order,$ETA,$ticketID);
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }


    /**
     * if "has been deleted from the order" or "the order you placed has been cancelled" in "$Body" in routeNewSupplierEmail281
     * @param string $ticketID
     * @action $this->orderConfirmedCanceledSendBySideConvo($order,$ticketID)
     * @return null on success, 'rejected' on failure
     */
    public function ParseCancellationNotice281($ticketID){

        try {
            $this->_logger->info('Function: ParseCancellationNotice281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $SubjectLower = strtolower($Subject);
            $IDSearch = $this->_utils->findAllAfterSubStr(strtolower($SubjectLower),"p");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $this->orderCanceledWithOutRequestContactBySideConvo($order,$ticketID);
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }
    /**
     * called if "Cancellation Notice: One or more items for Sales Order" in "$Subject" in routeNewSupplierEmail281
     * @param string $ticketID
     * @action$this->orderConfirmedCanceledSendBySideConvo($order,$ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseCancellationNoticeAlt281($ticketID){

        try {
            $this->_logger->info('Function: ParseCancellationNotice281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $IDSearch = $this->_utils->findSubStr($Body,"Purchase Order:","\n");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $this->orderCanceledWithOutRequestContactBySideConvo($order,$ticketID);
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }



    /**
     * called if "has a minimum" or "min order" in "$Body" in routeNewSupplierEmailTBond
     * @param string $ticketID
     * @action Sets state/status to holded/awaiting_customer_feedback
     * @action notifies end customer of action required
     * @action notifies REDACTED that we will follow up with them
     * @action Adds an internal note to the order and the ticket detailing the actions taken by our system
     * @action sets ticket to pending
     * @return null on success, 'rejected' on failure
     */

    public function contactCustomerUpdateOrderQty281($ticketID){

        try {
            $this->_logger->info('Function: contactCustomerUpdateOrderQty281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = strtolower($data['results'][0]['description']);
            $incrementId = $this->_utils->ParsePO($Subject);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Subject."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $this->_logger->info("ticket body");
            $this->_logger->info($Body);


            $qtySearch = $this->_utils->findSubStr($Body, "min",".");
            $this->_logger->info("Search String");
            $this->_logger->info($qtySearch);

            if (preg_match('/ [0-9]{1,3}/',$qtySearch)){
                preg_match('/ [0-9]{1,3}/',$qtySearch,$matches);


            }else{
                $this->_zendeskUtils->rejectTicket($ticketID,"our system can see that REDACTED is requesting that we update the quantity for Order. But could not determine the required quantity.");
                return "rejected";
            }
            $requiredQty = $matches[0];
            $format = $this->_adminUtils->getTranslationFromKey("QTY_RESTRICTED_GENERAL_TO_CUSTOMER");
            $body = sprintf($format,$customerName,$sku,$requiredQty,$requiredQty);
            $this->_zendeskUtils->create_side_convo($ticketID,"Action Required. Quantity Must Be Updated For Order: ".sprintf("%'.09d", $incrementId),$body,$customerEmail);
            $formatReply = $this->_adminUtils->getTranslationFromKey("QTY_RESTRICTED_281_TO_SUPPLIER");
            $Reply = sprintf($formatReply,$incrementId,$requiredQty);
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Reply,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "new_supplier_email"
                        ),
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => "281"
                        )
                    ),
                    "subject" => "Action Required. Quantity Must Be Updated For Order: ".sprintf("%'.09d", $incrementId),
                    "status" => "pending"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);


            $this->_zendeskUtils->rejectTicket($ticketID,"our system has not yet been developed to process the customers response to this. This function is soon to come.");

            $format = $this->_adminUtils->getTranslationFromKey("QTY_RESTRICTED_INTERNAL_NOTE_ORDER");
            $body = sprintf($format,sprintf("%'.09d", $incrementId),$requiredQty,$ticketID,$ticketID);
            $order->addStatusHistoryComment($body);
            $order->save();

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }


    /**
     * called if "@REDACTED" in ticket requester and in new status
     * @param string $ticketID
     * @action parse ticket contents to determine the suppliers request/notice
     * if "has been delayed" in "$Body"
     * @action $this->ParseDelayedNotice281($ticketID);
     * if "has been deleted from the order" or "the order you placed has been cancelled" in "$Body"
     * @action $this->ParseCancellationNotice281($ticketID);
     * if "Cancellation Notice: One or more items for Sales Order" in "$Subject"
     * @action $this->ParseCancellationNoticeAlt281($ticketID);
     * if "an ETA of" or "expected to ship" in "$Body"
     * @action $this->newOrderETA281($ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function routeNewSupplierEmail281($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: routeNewSupplierEmail281');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]['subject'];
            $Body = $data['results'][0]['description'];
            if (str_contains($Subject,"LTL")){
                $this->notateLtlRequests281($ticketID);
            }elseif (str_contains($Body,"has been delayed")){
                $this->ParseDelayedNotice281($ticketID);
            }elseif(str_contains($Body,"has been deleted from the order")){
                $this->ParseCancellationNotice281($ticketID);
            }elseif(str_contains($Body,"the order you placed has been cancelled")){
                $this->ParseCancellationNotice281($ticketID);
            }elseif(str_contains($Subject,"Cancellation Notice: One or more items for Sales Order")){
                $this->ParseCancellationNoticeAlt281($ticketID);
            }elseif(str_contains($Body,"an ETA of")){
                $this->newOrderETA281($ticketID);
            }elseif(str_contains($Body,"expected to ship")){
                $this->newOrderETA281($ticketID);
            }else{
                $update = array(
                    "ticket" => array(
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => "281"
                            )
                        )
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);

                $this->_zendeskUtils->rejectTicket($ticketID,"our system can tell that this is a new email from REDACTED, but could not determine what they were requesting.");

            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * called if requester is "REDACTED@REDACTED.com" and in new status
     * @param string $ticketID
     * @action parse ticket contents to determine the suppliers request/notice
     * if "has a minimum" or "min order" in "$Body"
     * @action $this->contactCustomerUpdateOrderQty281($ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function routeNewSupplierEmailTBond($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: routeNewSupplierEmailTBond');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $Subject = $data['results'][0]['subject'];
            $minQtyTrigger= array('has a minimum','min order');
            foreach ($minQtyTrigger as $trigger){
                if (str_contains($Body,$trigger)){
                    $this->contactCustomerUpdateOrderQty281($ticketID);
                }
            }
            if (str_contains($Subject,"LTL")){
                $this->notateLtlRequests281($ticketID);
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * called if "has been delayed" in "$Body" in routeNewSupplierEmail312
     * @param string $ticketID
     * if ETA == null
     * @action $this->updateOrderBackOrderNoETASideConvo($order,$ticketID);
     * else
     * @action $this->updateOrderETASideConvo($order,$ETA,$ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function ParseDelayedNotice312($ticketID){

        try {
            $this->_logger->info('Function: ParseDelayedNotice312');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]["subject"];
            $Body = $data['results'][0]['description'];
            $incrementId = $this->_utils->ParsePO($Body);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Body."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
            }
            $ETA = $this->_utils->ParseDate($Body);
            if ($ETA == null){
                $this->updateOrderBackOrderNoETASideConvo($order,$ticketID);
            }else{
                $this->updateOrderETASideConvo($order,$ETA,$ticketID);
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * called if "contact" in "$Subject" in routeNewSupplierEmail312
     * @param string $ticketID
     * @action replies to ticket providing requested details
     * @action solves ticket
     * @return null on success, 'rejected' on failure
     */
    public function provideEndCustomerContactInfo312($ticketID){

        try {
            $this->_logger->info('Function: provideEndCustomerContactInfo312');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $incrementId = $this->_utils->ParsePO($Body);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$Body."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $customerPhone = $order->getShippingAddress()->getTelephone();
            }
            $formatReply = $this->_adminUtils->getTranslationFromKey("PROVIDE_CUSTOMER_CONTACT_TO_SUPPLIER");
            $Reply = sprintf($formatReply,$incrementId,$customerName,$customerPhone,$customerEmail);
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Reply,
                        "public" => "true"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "new_supplier_email"
                        ),
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => $vendorCode
                        )
                    ),
                    "status" => "solved"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * called if "PO Exception Report" in "$Subject" in routeNewSupplierEmail312
     * @param string $ticketID
     * if order processing
     * @action$this->orderCanceledWithOutRequestContactBySideConvo($order,$ticketID);
     * @return null on success, 'rejected' on failure
     */
    public function ParsePOExceptionNotice312($ticketID){

        try {
            $this->_logger->info('Function: ParsePOExceptionNotice312');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $IDSearch = $this->_utils->findSubStr($Body,"PO Number ","\n");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                $state = $order->getState();
                $format = $this->_adminUtils->getTranslationFromKey("CANCELLATION_NOTICE_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);

                if ($state == "closed"){


                    $order->addStatusHistoryComment($body);
                    $order->save();
                    $this->_zendeskUtils->setTicketToSolved($ticketID);
                    return null;
                }
                if ($state == "holded"){
                    $order->addStatusHistoryComment($body);
                    $order->save();
                    $this->_zendeskUtils->setTicketToSolved($ticketID);
                    return null;
                }
                $this->orderCanceledWithOutRequestContactBySideConvo($order,$ticketID);
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }


    /**
     * called if "Return Confirmation" in subject of email from REDACTED
     * @param string $ticketID
     *
     */
    public function ParseReturnConfirmation312($ticketID){

        try {
            $this->_logger->info('Function: ParseReturnConfirmation312');
            $this->_zendeskUtils->add_tag_ticket($ticketID, 'csb2_ParseReturnConfirmation312');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Body = $data['results'][0]['description'];
            $this->_logger->info($Body);
            $IDSearch = $this->_utils->findSubStr($Body,"PO#:","\n");
            $incrementId = $this->_utils->ParsePO($IDSearch);
            if ($incrementId == null){
                $this->_zendeskUtils->rejectTicket($ticketID,"our system could not find a valid PO# in the following text:\n[".$IDSearch."]");
                return 'rejected';
            }
            $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
            $searchCriteria = $this->_searchCriteriaBuilder->create();
            $orderList = $this->_orderRepository->getList($searchCriteria);
            $this->_logger->info('total orders found for 000'.$incrementId.': '.$orderList->getTotalCount());
            $orders = $orderList->getItems();
            foreach ($orders as $order) {
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                    $vendorCode = substr($sku,0,3);
                }
                if ($this->_utils->Test()){
                    $customerName = "TEST CUSTOMER";
                    $customerEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                }else{
                    $customerName = $order->getCustomerFirstname();
                    $customerEmail = $order->getCustomerEmail();
                }
                $incrementIdFull = sprintf("%'.09d", $incrementId);
            }
            $ReturnAction = $this->_utils->findSubStr($Body,"Return action : "," Return reason");
            $this->_logger->info("Return Action = [".$ReturnAction."]");
            if ($ReturnAction == "Return for Credit"){
                $ReturnPickUpDate = $this->_utils->findSubStr($Body,"Pickup - Estimated date ","\n");
                $this->_logger->info("Return Pick Up Date: ");
                $this->_logger->info($ReturnPickUpDate);
                $ReturnOrderNumber = $this->_utils->findSubStr($Body,"Return Order Number : ","-001")."-001";
                $this->_logger->info("Return Order Number : ");
                $this->_logger->info($ReturnOrderNumber);
                $format = $this->_adminUtils->getTranslationFromKey("PICKUP_DETAILS_312");
                $bodyToCustomer = sprintf($format,$customerName,$incrementIdFull,$ReturnPickUpDate,$ReturnOrderNumber);
                $this->_zendeskUtils->create_side_convo($ticketID,"REDACTED Return Pickup Details",$bodyToCustomer,$customerEmail);
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => "REDACTED has provided Return Pickup Confirmation. Below is the original email from REDACTED. Above in the side conversation our system has sent this information to the end customer.\n\n-our system 2",
                            "public" => "false"
                        ),
                        "custom_fields" => array(
                            array(
                                "id" => $this->OrderIncrementIDFieldID,
                                "value" => sprintf("%'.09d", $incrementId)
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => $vendorCode
                            ),
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "return_request"
                            )
                        ),
                        "status" => "solved"
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                $format = $this->_adminUtils->getTranslationFromKey("RETURN_APPROVAL_INTERNAL_NOTE_ORDER");
                $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                $order->addStatusHistoryComment($body);
                $order->save();
            }
            elseif ($ReturnAction == "Credit Only"){
                $this->refundIssuedWithoutReturnUseSideConvo($order, $ticketID);
            }
            else{
                $this->_zendeskUtils->rejectTicket($ticketID,"The Return Action listed on this return confirmation is not recognized. \n\nReturn Action: [".$ReturnAction."]");
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * called if "@REDACTED" in ticket requester and in new status
     * @param string $ticketID
     * @action parse ticket contents to determine the suppliers request/notice
     * if "has been delayed" in "$Body"
     * @action $this->ParseDelayedNotice312($ticketID);
     * if "contact" in "$Subject"
     * @action $this->provideEndCustomerContactInfo312($ticketID);
     * if "PO Exception Report" in "$Subject"
     * @action $this->ParsePOExceptionNotice312($ticketID);
     * if "be picked up today before end of the day" in "$Body"
     * @return null on success, 'rejected' on failure
     */
    public function routeNewSupplierEmail312($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: '.$ticketID);
            $this->_logger->info('Function: routeNewSupplierEmail312');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]['subject'];
            $SubjectLower = strtolower($Subject);
            $Body = $data['results'][0]['description'];
            if (str_contains($Body,"has been delayed")){
                $this->ParseDelayedNotice312($ticketID);
            }elseif(str_contains($Body,"contact")){
                $this->provideEndCustomerContactInfo312($ticketID);
            }elseif(str_contains($Subject,"PO Exception Report")) {
                $this->ParsePOExceptionNotice312($ticketID);
            }elseif(str_contains($Subject,"Return Confirmation")){
                $this->ParseReturnConfirmation312($ticketID);
            }elseif(str_contains($Body,"be picked up today before end of the day")){
                $this->_logger->info("function not developed for pick up confirmation 312");
                $this->_zendeskUtils->rejectTicket($ticketID,"our system can tell that this is a new email from REDACTED, but could not determine what they were requesting.");
            }else{
                $update = array(
                    "ticket" => array(
                        "custom_fields" => array(
                            array(
                                "id" => $this->QueueFieldID,
                                "value" => "new_supplier_email"
                            ),
                            array(
                                "id" => $this->vendorCodeFieldID,
                                "value" => "312"
                            )
                        )
                    )
                );
                $this->_zendeskUtils->updateTicket($ticketID,$update);
                $this->_zendeskUtils->rejectTicket($ticketID,"our system can tell that this is a new email from REDACTED, but could not determine what they were requesting.");
            }
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

//General
    public function notateLtlRequests281($ticketID)
    {

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: ' . $ticketID);
            $this->_logger->info('Function: notateLtlRequests');
            $ticket = $this->_zendeskUtils->searchZendesk('type:ticket '.$ticketID);
            $data = json_decode($ticket,true);
            $Subject = $data['results'][0]['subject'];
            $incrementId = null;
            if (str_contains($Subject,"0")){
                $incrementId = $this->_utils->ParsePO($Subject);
            }
            if ($incrementId != null){
                $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementId)));
                $searchCriteria = $this->_searchCriteriaBuilder->create();
                $orderList = $this->_orderRepository->getList($searchCriteria);
                $orders = $orderList->getItems();
                foreach ($orders as $order) {
                    $format = $this->_adminUtils->getTranslationFromKey("LTL_APPROVAL_ORDER_NOTE");
                    $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                    $order->addStatusHistoryComment($body);
                    $order->save();
                }
            }
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $this->_adminUtils->getTranslationFromKey("LTL_PRICING_APPROVAL_INTERNAL_NOTE_TICKET"),
                        "public" => "false"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "new_supplier_email"
                        ),
                        array(
                            "id" => $this->vendorCodeFieldID,
                            "value" => "281"
                        ),
                        array(
                            "id" => $this->OrderIncrementIDFieldID,
                            "value" => sprintf("%'.09d", $incrementId)
                        )
                    ),
                    "status" => "open"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);
            $this->_zendeskUtils->removeTagFromTicket($ticketID,"csb2_new_queue");
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }
    public function PaymentTransactionFailedReminder($ticketID){

        try {
            $this->_logger->info('******************************************************************************');
            $this->_logger->info('Ticket ID: ' . $ticketID);
            $this->_logger->info('Function: paymentTransactionFailedReminder');
            $ticketJson = $this->_zendeskUtils->getSingleTicket($ticketID);
            $ticketArray = json_decode($ticketJson,true);
            $ticket = $ticketArray["ticket"];
            $ticketBody = $ticket['description'];
            $this->_logger->info("Ticket Body from TEST to follow:");
            $this->_logger->info($ticketBody);




            $emailSearchString = $this->_utils->findSubStr($ticketBody,"Customer:","Items");
            $customerEmail = $this->_utils->findSubStr($emailSearchString,"<",">");
            $customerName = ucfirst($this->_utils->findSubStr($ticketBody,"Billing Address:\n"," "));
            $item = $this->_utils->findSubStr($ticketBody,"Items\n","\n");
            $reason = $this->_utils->findSubStr($ticketBody," Reason\n","\n");
            if (str_contains($item,"USD ")){
                $item = str_replace("USD ","$",$item);
            }
            
            $this->_logger->info("- Code parsed the following values from ticket body - ");
            $this->_logger->info("Customer Email [".$customerEmail."]");
            $this->_logger->info("Customer Name [".$customerName."]");
            $this->_logger->info("Item [".$item."]");
            $this->_logger->info("Reason [".$reason."]");
            if ($this->Test){
                $customerEmail = "tyler.polny96@gmail.com";
                $customerName = "TEST CUSTOMER NAME";
                $this->_logger->info("Because this is a run on TEST the two following values have been overridden:");
                $this->_logger->info("Customer Email [".$customerEmail."]");
                $this->_logger->info("Customer Name [".$customerName."]");

            }

            $formatEmail = $this->_adminUtils->getTranslationFromKey("PAYMENT_TRANSACTION_FAILED");
            //todo look into using hyperlinks on zendesk side conversation. links are returning as raw text not hyperlinks using both href and or markdown when using side convo.
            //$formatEmail = "Hello %s,\n\nThank you for choosing REDACTED!\n\nWe noticed the following issue when you attempted to make a purchase on REDACTED: **%s**\n\n**Product:** %s\n\nIf this issue has been resolved and your order has been processed, we thank you for your business. If not, there are other ways to process your order, including:\n- Use a different credit card or debit card.\n- Review our <a href=\"https://www.REDACTED.com/accepted-forms-of-payment?utm_source=our system&utm_medium=Email&utm_campaign=REDACTED-Payment-Failed-Reminder&utm_id=REDACTED-email-failed-pmt\">accepted forms of payment</a>.\n-**Business & Government**: Sign-up for REDACTED Credit and Net30 Terms at <a href=\"https://app.resolvepay.com/REDACTED?utm_source=REDACTED&utm_medium=FAQ&utm_campaign=ResolvePAy\">ResolvePay</a>.\n\nIf you have any further questions, please visit <a href=\"https://www.REDACTED.com/get-help\">REDACTED.com/Help</a>, Chat with us or simply respond to this email. We would be more than happy to help!\n\nThank you for your business,\n-REDACTED customer care team";
            $EmailBody = sprintf($formatEmail,$customerName,$reason,$item);
            $Note = $this->_adminUtils->getTranslationFromKey("PAYMENT_TRANSACTION_FAILED_NOTE");

            $this->_zendeskUtils->create_side_convo($ticketID,"Payment Transaction Failed Reminder",$EmailBody,$customerEmail);
            $update = array(
                "ticket" => array(
                    "comment" => array(
                        "body" => $Note,
                        "public" => "false"
                    ),
                    "custom_fields" => array(
                        array(
                            "id" => $this->QueueFieldID,
                            "value" => "order_status_request"
                        )
                    ),
                    "status" => "solved"
                )
            );
            $this->_zendeskUtils->updateTicket($ticketID,$update);



        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }
}
