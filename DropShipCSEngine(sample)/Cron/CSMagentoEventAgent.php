<?php
/**
 * Copyright (c) 2022, Tyler Polny
 * All rights reserved.
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree.
 */
namespace REDACTED\OrderManagement\Cron;

class CSMagentoEventAgent
{
    protected $_logger;
    protected $_orderRepository;
    protected $_filterBuilder;
    protected $_filterGroupBuilder;
    protected $_searchCriteriaBuilder;
    protected $_customerRepository;
    protected $_zendeskUtils;
    protected $_utils;
    protected $_adminUtils;
    protected $_entityResolver;
    protected $_saveHandler;
    protected $_entityData;
    protected $_metadataFormFactory;


    public function __construct(\Psr\Log\LoggerInterface $logger,
                                \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
                                \Magento\Framework\Api\FilterBuilder $filterBuilder,
                                \Magento\Framework\Api\Search\FilterGroupBuilder $filterGroupBuilder,
                                \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
                                \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
                                \REDACTED\OrderManagement\Helper\ZendeskUtils $zendeskUtils,
                                \REDACTED\OrderManagement\Helper\Utils $utils,
                                \REDACTED\AdminSupportTools\Helper\Data $adminUtils,
                                \Amasty\Orderattr\Model\Entity\EntityResolver $entityResolver,
                                \Amasty\Orderattr\Model\Entity\Handler\Save $saveHandler,
                                \Amasty\Orderattr\Model\Entity\EntityData $entityData,
                                \Amasty\Orderattr\Model\Value\Metadata\FormFactory $metadataFormFactory)
    {
        $this->_logger = $logger;
        $this->_orderRepository = $orderRepository;
        $this->_filterBuilder = $filterBuilder;
        $this->_filterGroupBuilder = $filterGroupBuilder;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_customerRepository = $customerRepository;
        $this->_zendeskUtils = $zendeskUtils;
        $this->_utils = $utils;
        $this->_adminUtils = $adminUtils;
        $this->_entityResolver = $entityResolver;
        $this->_saveHandler = $saveHandler;
        $this->_entityData = $entityData;
        $this->_metadataFormFactory = $metadataFormFactory;


    }

    //this file is called once every 2 hours to check values of all orders in processing/holded state in magento and take needed actions
    public function getOrderAttributesData($order)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSMagentoEventAgent.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        try{
            $orderAttributesData = [];
            $entity = $this->_entityResolver->getEntityByOrder($order);
            if ($entity->isObjectNew()) {
                return [];
            }
            $form = $this->createEntityForm($entity,$order);
            $outputData = $form->outputData(\Magento\Eav\Model\AttributeDataFactory::OUTPUT_FORMAT_ARRAY);
            foreach ($outputData as $attributeCode => $data) {
                if (!empty($data)) {
                    $orderAttributesData[] = [
                        'label' => $form->getAttribute($attributeCode)->getDefaultFrontendLabel(),
                        'value' => $data
                    ];
                }
            }

            return $orderAttributesData;

        }catch (\Exception $e) {
            $t_logger->info('Caught Exception on getOrderAttributesData: '. $e->getMessage());
        }
    }
    protected function createEntityForm($entity,$order)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSMagentoEventAgent.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        try{
            /** @var Form $formProcessor */
            $formProcessor = $this->_metadataFormFactory->create();
            $formProcessor->setFormCode('adminhtml_order_view')
                ->setEntity($entity)
                ->setStore($order->getStore());

            return $formProcessor;

        }catch (\Exception $e) {
            $t_logger->info('Caught Exception on createEntityForm: '. $e->getMessage());
        }

    }




    //function name must be changed to RunMagentoEventAgent
    public function checkOrderMaxtime()
    {

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSMagentoEventAgent.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);

        $t_logger->info("MagentoEventAgent Start");

        // Events for orders in Processing or Holded state
        //setting up query
        try{
            $t_logger->info("starting to set up query");


///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// Test Control Panel
            if ($this->_utils->Test()){
                // if $fullQuery set to true, CSMagentoEventAgent will run on all orders in processing or holded state
                // if $fullQuery set to false, CSMagentoEventAgent will run on incrementIdTEST only
                $fullQuery = false;
                $incrementIdTEST = "130397";
                $set_AlertAckProcessed_false = false;// if set to true, all orders for tyler.polny@REDACTED.com that have alert_ack = true, will set alert_ack_processed to be false
                $set_AlertShipProcessed_false = false;// if set to true, all orders for tyler.polny@REDACTED.com that have alert_ship = true, will set alert_ship_processed to be false
                $turnOffCSMagentoEventAgent = false;// if set to true, CSMagento Event Agent will take no actions on TEST
                $set_allTestOrders_inFulfillment = false;// if set to true, all orders for tyler.polny@REDACTED.com will be set to in fulfillment
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
            }else{
                //DO NOT change below values
                $fullQuery = true;
                $set_AlertAckProcessed_false = false;
                $set_AlertShipProcessed_false = false;
                $turnOffCSMagentoEventAgent = false;
                $set_allTestOrders_inFulfillment = false;
            }


            if ($turnOffCSMagentoEventAgent == true){
                $t_logger->info("CSMagentoEventAgent has been disabled in test. Please set turnOffCSMagentoEventAgent to false to re-enable.");
                return 'test';
            }
            if ($fullQuery == false){
                $t_logger->info("fullQuery == false - CSMagentoEventAgent will only run on order ".$incrementIdTEST);
                $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $incrementIdTEST)));
                $searchCriteria = $this->_searchCriteriaBuilder->create();
                $orderList = $this->_orderRepository->getList($searchCriteria);
                $orders = $orderList->getItems();
            }else{
                $this->_customerRepository;
                $processingState = $this->_filterBuilder->setField('state')
                    ->setValue('processing')
                    ->setConditionType('eq')
                    ->create();
                $holdedState = $this->_filterBuilder->setField('state')
                    ->setValue('holded')
                    ->setConditionType('eq')
                    ->create();
                $orderFilter = $this->_filterGroupBuilder
                    ->addFilter($processingState)
                    ->addFilter($holdedState)
                    ->create();

                $this->_searchCriteriaBuilder->setFilterGroups([$orderFilter]);
                $searchCriteria = $this->_searchCriteriaBuilder->create();
                $orderResult = $this->_orderRepository->getList($searchCriteria);
                $orders = $orderResult->getItems();

            }
            $t_logger->info("query set up");

        }catch (\Exception $e) {
            $t_logger->info('Caught Exception setting up query: '. $e->getMessage());
        }



        $alertAckTickets = array();
        $alertAckOrders = array();
        $alertShipTickets = array();
        $alertShipOrders = array();
        $today = date("Y-m-d H:i:s");

        $t_logger->info("gathering attributes and going through checks.");
        foreach ($orders as $order) {
            //gathering attributes

            try{
                $customerEmail = $order->getCustomerEmail();
                $status = $order->getStatus();
                $state = $order->getState();
                $incrementIdFull = $order->getIncrementId();
                $incrementId = $this->_utils->ParsePO($incrementIdFull);
                $customerName = $order->getCustomerFirstname();
                $orderTotal = $order->getGrandTotal();
                $items = $order->getAllItems();
                foreach ($items as $item) {
                    $sku = $item->getSku();
                }
                $vendorCode = substr($sku,0,3);
                $supplierKey = substr($sku, 0, 4);
                $supplierArray = $this->_zendeskUtils->getSupplierArray();
                if (array_key_exists($supplierKey, $supplierArray)) {
                    if ($this->_utils->Test() == true) {
                        $supplierName = 'test';
                        $supplierEmail = $this->_adminUtils->getTranslationFromKey("EMAIL_TEST");
                    } else {
                        $supplierName = $supplierArray[$supplierKey]['name'];
                        $supplierEmail = $supplierArray[$supplierKey]['email'];
                    }
                    $supplierAcct = $supplierArray[$supplierKey]["account"];
                } else {
                    $supplierName = null;
                    $supplierEmail = null;
                    $supplierAcct = null;
                }
                $attributes = $order->getExtensionAttributes()->getAmastyOrderAttributes();
                $attributes_Literal = $this->getOrderAttributesData($order);
                $whiteGloveID = $this->_utils->getWhiteGloveID();
                $orderOwner = null;
                $maxTime = null;
                $alertSubmitValue = null;
                $alertAckValue = null;
                $alertShipValue = null;
                $alertRefundValue = null;
                $alertSubmitValue = null;
                $alertSubmitProcessed = null;
                $alertAckProcessed = null;
                $alertShipProcessed = null;
                $alertRefundProcessed = null;
                $updatedShipdate = null;
                $updatedAlertShipProcessed = null;


                if (!empty($attributes_Literal)){
                    foreach ($attributes_Literal as $attribute){
                        $attributeLabel = $attribute['label'];
                        $attributeValue = $attribute['value'];
                        if ($attributeLabel == 'Order Owner'){
                            $orderOwner = $attributeValue;
                        }
                    }
                }
                if (!empty($attributes)) {
                    foreach ($attributes as $attribute) {
                        if ($attribute->getAttributeCode() == 'max_timeout') {
                            $maxTime = $attribute->getValue();
                        }
                        if ($attribute->getAttributeCode() == 'alert_ack') {
                            $alertAckValue = $attribute->getValue();
                        }
                        if ($attribute->getAttributeCode() == 'alert_ship') {
                            $alertShipValue = $attribute->getValue();
                        }
                        if ($attribute->getAttributeCode() == 'alert_refund') {
                            $alertRefundValue = $attribute->getValue();
                        }
                        if ($attribute->getAttributeCode() == 'alert_submit') {
                            $alertSubmitValue = $attribute->getValue();
                        }
                        if ($attribute->getAttributeCode() == 'alert_submit_processed') {
                            $alertSubmitProcessed = $attribute->getValue();
                        }
                        if ($attribute->getAttributeCode() == 'alert_ack_processed') {
                            $alertAckProcessed = $attribute->getValue();
                        }
                        if ($attribute->getAttributeCode() == 'alert_ship_processed') {
                            $alertShipProcessed = $attribute->getValue();
                        }
                        if ($attribute->getAttributeCode() == 'alert_refund_processed') {
                            $alertRefundProcessed = $attribute->getValue();
                        }
                        if($attribute->getAttributeCode() == "updated_shipdate"){
                            $updatedShipdate = $attribute->getValue();
                        }
                    }
                }

            }catch (\Exception $e) {
                $t_logger->info('Caught Exception gathering attributes: '. $e->getMessage());
            }

            //starting checklist


            //manipulating test orders if directed
            try{
                if($this->_utils->Test()&$customerEmail=="tyler.polny@REDACTED.com"&$set_allTestOrders_inFulfillment){
                    $order->setState('processing');
                    $order->setStatus('in_fulfillment');
                    $order->save();
                    $t_logger->info("Order status set to in_fulfillment[processing] for order ".$incrementIdFull." for testing");
                }

            }catch (\Exception $e) {
                $t_logger->info('Caught Exception updating test orders to in fulfillment: '. $e->getMessage());
            }

            //starting ticket owner sync
            try{
                //working on addressing root cause of json exception. PROD ready work flow is attached to ELSE to the below line
                if ($this->_utils->Test()){
                    if ($orderOwner != null & $orderOwner != "WhiteGlove" & $orderOwner != "Recon"){
                        if ($orderOwner == "REDACTED" || $orderOwner == "REDACTED" || $orderOwner == "REDACTED" || $orderOwner == "REDACTED"){
                            if ($this->_utils->Test()){
                                $orderOwnerEmail = "REDACTED@REDACTED.com";
                                $orderOwner = "Operations";
                            }else{
                                $orderOwnerEmail = "operations@REDACTED.com";
                                $orderOwner = "Admin";
                            }
                        }else{
                            $orderOwnerEmail = str_replace(" ",".",strtolower($orderOwner))."@REDACTED.com";
                        }
                        $zendeskAdminsAgents = $this->_zendeskUtils->getZendeskAdminsAndAgents();
                        $zendeskAdminsAgentsEmails = array();
                        foreach ($zendeskAdminsAgents as $user){
                            //role_type: The user's role id. 0 for a custom agent, 1 for a light agent, 2 for a chat agent, 3 for a chat agent added to the Support account as a contributor (Chat Phase 4), 4 for an admin, and 5 for a billing admin
                            $roleType = $user["role_type"];
                            //tickets can only be assigned to 0 and 4
                            if ($roleType == 0 ||$roleType == 4){
                                $email = $user["email"];
                                $zendeskAdminsAgentsEmails[] = $email;

                            }
                        }
                        if (!in_array($orderOwnerEmail,$zendeskAdminsAgentsEmails)){
                            $entity= $this->_entityResolver->GetEntityByOrder($order);
                            $entity->setData('order_owner',null);
                            $this->_saveHandler->execute($entity);

                            $formatOrderInternalNote = "Order Owner was set to %s. However there is not a user in Zendesk associated with %s. Order owner has been set back to NONE. Please set up a user account in zendesk and try again.";
                            $OrderInternalNote = sprintf($formatOrderInternalNote,$orderOwner,$orderOwner);
                            $order->addStatusHistoryComment($OrderInternalNote);
                            $order->save();


                        }else{
                            if ($this->_utils->Test()){
                                $OrderIncrementIdTicketFieldId = "1500012542762";
                            }else{
                                $OrderIncrementIdTicketFieldId = "360041196794";
                            }
                            $tickets = $this->_zendeskUtils->searchZendesk('status<solved custom_field_'.$OrderIncrementIdTicketFieldId.':'.$incrementIdFull);
                            $data = json_decode($tickets,true);
                            $updateIDs = array();
                            $updateIDsString = null;
                            $updateIDsHref = null;

                            foreach ($data['results'] as $ticket){
                                $ID = ($ticket["id"]);
                                $AssigneeID = ($ticket['assignee_id']);
                                if ($AssigneeID != ''){
                                    $UserArray = $this->_zendeskUtils->getUserByID($AssigneeID);
                                    $AssigneeEmail = $UserArray["email"];
                                }else{
                                    $AssigneeEmail = null;
                                }
                                if ($AssigneeID == '' || $AssigneeEmail != $orderOwnerEmail){
                                    array_push($updateIDs,$ID);
                                }
                            }
                            $formatTicketHref = $this->_adminUtils->getTranslationFromKey("HREF_TO_TICKET");
                            foreach ($updateIDs as $updateID){
                                if ($updateIDsString == null){
                                    $updateIDsString = $updateID;
                                }else{
                                    $updateIDsString = $updateIDsString.",".$updateID;
                                }
                                if ($updateIDsHref == null){
                                    $updateIDsHref = sprintf($formatTicketHref,$updateID,$updateID);
                                }else{
                                    $updateIDsHref = $updateIDsHref.", ".sprintf($formatTicketHref,$updateID,$updateID);
                                }
                            }
                            if ($updateIDsString != null){
                                $t_logger->info("\n\n");
                                $t_logger->info("Increment ID ".$incrementIdFull." triggered order owner x ticket assignee sync");
                                $t_logger->info("Order Owner Name [".$orderOwner."]");
                                $t_logger->info("Order Owner Email [".$orderOwnerEmail."]");
                                $t_logger->info("The following ticket IDs will be assigned to ".$orderOwnerEmail.":");
                                $t_logger->info($updateIDsString);
                                $formatTicketInternalNote = $this->_adminUtils->getTranslationFromKey("ORDER_OWNER_SYNC_TICKET_NOTE");
                                $TicketInternalNote = sprintf($formatTicketInternalNote,$incrementIdFull,$orderOwner,$orderOwnerEmail,$incrementIdFull,$orderOwnerEmail);
                                $response = $this->_zendeskUtils->updateTicketAssigneeOfMany($updateIDsString,$orderOwnerEmail,$TicketInternalNote);
                                $t_logger->info("Tickets have been queued to update Assignee. See below for job status:");
                                $t_logger->info(print_r($response,true));
                                $formatOrderInternalNote = $this->_adminUtils->getTranslationFromKey("ORDER_OWNER_SYNC_ORDER_NOTE");
                                $OrderInternalNote = sprintf($formatOrderInternalNote,$orderOwner,$updateIDsHref);
                                $order->addStatusHistoryComment($OrderInternalNote);
                                $order->save();
                            }
                        }
                    }

                }else{
                    if ($orderOwner != null & $orderOwner != "WhiteGlove" & $orderOwner != "Recon"){
                        if ($orderOwner == "REDACTED" || $orderOwner == "REDACTED" || $orderOwner == "REDACTED" || $orderOwner == "REDACTED"){
                            if ($this->_utils->Test()){
                                $orderOwnerEmail = "REDACTED@REDACTED.com";
                                $orderOwner = "Operations";
                            }else{
                                $orderOwnerEmail = "operations@REDACTED.com";
                                $orderOwner = "Admin";
                            }
                        }else {
                            $orderOwnerEmail = str_replace(" ", ".", strtolower($orderOwner)) . "@REDACTED.com";
                        }
                        if ($this->_utils->Test()){
                            $OrderIncrementIdTicketFieldId = "1500012542762";
                        }else{
                            $OrderIncrementIdTicketFieldId = "360041196794";
                        }
                        $tickets = $this->_zendeskUtils->searchZendesk('status<solved custom_field_'.$OrderIncrementIdTicketFieldId.':'.$incrementIdFull);
                        $data = json_decode($tickets,true);
                        $updateIDs = array();
                        $updateIDsString = null;
                        $updateIDsHref = null;

                        foreach ($data['results'] as $ticket){
                            $ID = ($ticket["id"]);
                            $AssigneeID = ($ticket['assignee_id']);
                            if ($AssigneeID != ''){
                                $UserArray = $this->_zendeskUtils->getUserByID($AssigneeID);
                                $AssigneeEmail = $UserArray["email"];
                            }else{
                                $AssigneeEmail = null;
                            }
                            if ($AssigneeID == '' || $AssigneeEmail != $orderOwnerEmail){
                                array_push($updateIDs,$ID);
                            }
                        }
                        $formatTicketHref = $this->_adminUtils->getTranslationFromKey("HREF_TO_TICKET");
                        foreach ($updateIDs as $updateID){
                            if ($updateIDsString == null){
                                $updateIDsString = $updateID;
                            }else{
                                $updateIDsString = $updateIDsString.",".$updateID;
                            }
                            if ($updateIDsHref == null){
                                $updateIDsHref = sprintf($formatTicketHref,$updateID,$updateID);
                            }else{
                                $updateIDsHref = $updateIDsHref.", ".sprintf($formatTicketHref,$updateID,$updateID);
                            }
                        }
                        if ($updateIDsString != null){
                            $t_logger->info("---------------------------------------------------------");
                            $t_logger->info("Order ".$incrementIdFull." triggered for Order Owner/Ticket Assignee Sync");
                            $t_logger->info("Order Owner Name [".$orderOwner."]");
                            $t_logger->info("Order Owner Email [".$orderOwnerEmail."]");
                            $t_logger->info("The following ticket IDs will be assigned to ".$orderOwnerEmail.":");
                            $t_logger->info($updateIDsString);
                            $formatTicketInternalNote = $this->_adminUtils->getTranslationFromKey("ORDER_OWNER_SYNC_TICKET_NOTE");
                            $TicketInternalNote = sprintf($formatTicketInternalNote,$incrementIdFull,$orderOwner,$orderOwnerEmail,$incrementIdFull,$orderOwnerEmail);
                            $response = $this->_zendeskUtils->updateTicketAssigneeOfMany($updateIDsString,$orderOwnerEmail,$TicketInternalNote);
                            $t_logger->info("Tickets have been queued to update Assignee. See below for job status:");
                            $t_logger->info(print_r($response,true));
                            $formatOrderInternalNote = $this->_adminUtils->getTranslationFromKey("ORDER_OWNER_SYNC_ORDER_NOTE");
                            $OrderInternalNote = sprintf($formatOrderInternalNote,$orderOwner,$updateIDsHref);
                            $order->addStatusHistoryComment($OrderInternalNote);
                            $order->save();
                            $t_logger->info($OrderInternalNote);
                        }
                    }
                }
            }catch (\Exception $e) {
                $t_logger->info('Caught Exception syncing ticket owner to order owner: '. $e->getMessage().'Value for User array to follow');
            }
            try{
                if ($status != 'order_cancel_requested') {
                    if ($maxTime != null) {
                        $maxDate = new \DateTime();
                        $maxDate->modify($maxTime . ' days');
                        if ($maxDate < $order->getCreatedAt()) {
                            $t_logger->info("Increment ID ".$incrementIdFull." triggered order_maxtime");
                            $formatBody = $this->_adminUtils->getTranslationFromKey("NEW_CANCEL_REQUEST_TO_SUPPLIER");
                            $body = sprintf($formatBody,$supplierName,$supplierAcct,$incrementId);
                            $formatSubject = $this->_adminUtils->getTranslationFromKey("NEW_CANCEL_REQUEST_TO_SUPPLIER_SUBJECT");
                            $subject = sprintf($formatSubject,$incrementId);
                            $ticket = $this->_zendeskUtils->create_ticket('' . $supplierName . '', '' . $supplierEmail . '', $subject,$body, 'urgent');
                            $ticketID = $ticket['ticket']['id'];
                            $this->_zendeskUtils->updateTicketQueueAssignment($ticketID,"cancellation_request");
                            $this->_zendeskUtils->updateTicketOrderIncrementID($ticketID,$incrementIdFull);
                            if ($order->getState() != 'holded') {
                                $order->hold();
                            }
                            $order->setStatus('order_cancel_requested');
                            $format = $this->_adminUtils->getTranslationFromKey("NEW_CANCEL_REQUEST_INTERNAL_NOTE_ORDER");
                            $body = sprintf($format,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                            $order->addStatusHistoryComment($body);
                            $order->save();
                        }
                    }
                }
            }catch (\Exception $e) {
                $t_logger->info('Caught Exception checking max_time: '. $e->getMessage());
            }

            //alert_ack test passed on test inc id 000130339
            try{
                if($alertAckValue == 1){
                    if ($alertAckProcessed != 1){
                        if($supplierKey!="310-"){
                            if ($this->_utils->Test()){
                                $QueueFieldID = "1500011234061";
                                $OrderIncrementIDFieldID = "1500012542762";
                                $vendorCodeFieldID = "1500005678702";
                            }else{
                                $QueueFieldID = "1500011596361";
                                $OrderIncrementIDFieldID = "360041196794";
                                $vendorCodeFieldID = "360055641194";
                            }

                            if ($supplierName != null){
                                $t_logger->info("\n\n");
                                $t_logger->info("Increment ID ".$incrementIdFull." triggered alert_ack");
                                //Gathering attributes to create a ticket
                                $formatBody = $this->_adminUtils->getTranslationFromKey("RED_ACK_TO_SUPPLIER");
                                $body = sprintf($formatBody, $supplierName, $supplierAcct, $incrementId);
                                $formatSubject = $this->_adminUtils->getTranslationFromKey("RED_ACK_TO_SUPPLIER_SUBJECT");
                                $subject = sprintf($formatSubject,$incrementId);
                                if ($this->_utils->Test()==false){
                                    if ($supplierKey == "281-"){
                                        $supplierEmail = "REDACTED@REDACTEDSUPPLIERF.com";
                                    }
                                }

                                //creating ticket
                                $ticket = array(
                                    "comment" => array(
                                        "body" => $body,
                                        "public" => 'true'
                                    ),
                                    "requester" => array(
                                        "name" => $supplierName,
                                        "email" => $supplierEmail
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
                                        )
                                    ),
                                    "priority" => "normal",
                                    "subject" => $subject,
                                    "status" => "pending"
                                );
                                $alertAckTickets[] = $ticket;
                                $alertAckOrders[] = $incrementIdFull;
                                $t_logger->info("ticket queued to be created in batch");

                                //setting alert_ack_processed to true
                                $entity= $this->_entityResolver->GetEntityByOrder($order);
                                $entity->setData('alert_ack_processed',1);
                                $this->_saveHandler->execute($entity);
                                $t_logger->info("alert_ack_processed value has been set to true");
                            }else{
                                $t_logger->info("\n\n");
                                $t_logger->info("Increment ID ".$incrementIdFull." triggered alert_ack but is for a non automated supplier");
                                $requesterEmail =  $this->_adminUtils->getTranslationFromKey("EMAIL_INFO");
                                $requesterName = $this->_adminUtils->getTranslationFromKey("NAME_INFO");
                                $formatSubject = $this->_adminUtils->getTranslationFromKey("ALERT_ACK_NON_AUTOMATED_SUPPLIER_SUBJECT");
                                $subject = sprintf($formatSubject,$incrementId);
                                $formatBody = $this->_adminUtils->getTranslationFromKey("ALERT_ACK_NON_AUTOMATED_SUPPLIER_BODY");
                                $body = sprintf($formatBody,$incrementIdFull);

                                //creating ticket
                                $ticket = array(
                                    "comment" => array(
                                        "body" => $body,
                                        "public" => 'true'
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
                                        )
                                    ),
                                    "priority" => "normal",
                                    "subject" => $subject,
                                    "status" => "pending"
                                );
                                $alertAckTickets[] = $ticket;
                                $alertAckOrders[] = $incrementIdFull;
                                $t_logger->info("ticket queued to be created in batch");


                                $entity= $this->_entityResolver->GetEntityByOrder($order);
                                $entity->setData('alert_ack_processed',1);
                                $this->_saveHandler->execute($entity);
                                $t_logger->info("alert_ack_processed value has been set to true");
                            }

                        }else{
                            //setting alert_ack_processed to true -- No alert ack workflow for REDACTED SUPPLIER NAME
                            $entity = $this->_entityResolver->GetEntityByOrder($order);
                            $entity->setData('alert_ack_processed', 1);
                            $this->_saveHandler->execute($entity);
                        }

                    }else{
                        if ($customerEmail == "tyler.polny@REDACTED.com" & $this->_utils->Test() & $set_AlertAckProcessed_false){
                            //setting alert_ack_processed to false
                            $entity= $this->_entityResolver->GetEntityByOrder($order);
                            $entity->setData('alert_ack_processed',0);
                            $this->_saveHandler->execute($entity);
                            $t_logger->info("alert_ack_processed value for order ".$incrementIdFull." has been set to false for testing");
                        }
                    }
                }
            }catch (\Exception $e) {
                $t_logger->info('Caught Exception checking alert_ack: '. $e->getMessage());
            }

            //updated_shipdate workflow
            try{
                if($updatedShipdate != null && $today>$updatedShipdate && $updatedAlertShipProcessed != 1){
                    $t_logger->info("\n\n");
                    $t_logger->info("Increment ID ".$incrementIdFull." triggered updated_shipdate");
                    $entity= $this->_entityResolver->GetEntityByOrder($order);
                    $dateETA = date("Y-m-d H:i:s",strtotime("+14 days"));
                    $entity->setData('updated_shipdate',$dateETA);
                    $entity->setData('alert_ship_processed',0);
                    $entity->setData('alert_ship',1);
                    $this->_saveHandler->execute($entity);
                    $t_logger->info("alert_ship_processed set to false");
                    $t_logger->info("alert_ship set to true");
                    $t_logger->info("updated_shipdate set to (today + 14 days)");

                    $t_logger->info("attributes have been adjusted so that order will be processed as alert ship.");
                }
            }catch (\Exception $e) {
                $t_logger->info('Caught Exception checking updated_shipdate: '. $e->getMessage());
            }
            // alert_ship tested on test po 130351
            try {
                if ($alertShipValue == 1){
                    if ($alertShipProcessed != 1){
                        $t_logger->info("\n\n");
                        $t_logger->info("Increment ID ".$incrementIdFull." triggered alert_ship");

                        //Gathering attributes to create a ticket
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


                        //creating ticket
                        $formatBody = $this->_adminUtils->getTranslationFromKey("CREATE_ORDER_STATUS_REQUEST_TICKET_NOTE");
                        $body = sprintf($formatBody,$incrementIdFull,$incrementIdFull,$customerEmail);
                        $ticket = array(
                            "comment" => array(
                                "body" => $body,
                                "public" => 'false'
                            ),
                            "requester" => array(
                                "name" => $customerName,
                                "email" => $customerEmail
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
                                )
                            ),
                            "priority" => "normal",
                            "subject" => "REDACTED Form Submission",
                            "status" => "pending"
                        );
                        $alertShipTickets[] = $ticket;
                        $alertShipOrders[] = $incrementIdFull;
                        $t_logger->info("ticket queued to be created in batch");

                        //setting alert_ship_processed to true
                        $entity= $this->_entityResolver->GetEntityByOrder($order);
                        $entity->setData('alert_ship_processed',1);
                        $this->_saveHandler->execute($entity);
                        $t_logger->info("alert_ship_processed value has been set to true");

                    }else{
                        if ($customerEmail == "tyler.polny@REDACTED.com" & $this->_utils->Test() & $set_AlertShipProcessed_false){
                            //setting alert_ship_processed to false
                            $entity= $this->_entityResolver->GetEntityByOrder($order);
                            $entity->setData('alert_ship_processed',0);
                            $this->_saveHandler->execute($entity);
                            $t_logger->info("alert_ship_processed value for order ".$incrementIdFull." has been set to false for testing");
                        }
                    }
                }
            }catch (\Exception $e) {
                $t_logger->info('Caught Exception checking alert_ship: '. $e->getMessage());
            }


//TP alert refund block muted to prevent clutter being sent to accounting inbox
            //alert_refund tested on PO# 000130354
            //try {
            //    if ($alertRefundValue == 1 & $alertRefundProcessed != 1){
            //        $t_logger->info("Increment ID ".$incrementIdFull." triggered alert_refund");

            //        $formatBody = $this->_adminUtils->getTranslationFromKey("RED_REFUND_TO_ACCOUNTING");
            //        $body = sprintf($formatBody,$incrementIdFull);
            //        if ($this->_utils->Test() == true){
            //            $body = "PLEASE NOTE THAT THE FOLLOWING IS ONLY A TEST. DO NOT TAKE ACTION ON THE FOLLOWING EMAIL. THANK YOU".$body;
            //        }
            //        $formatSubject = $this->_adminUtils->getTranslationFromKey("RED_REFUND_TO_ACCOUNTING_SUBJECT");
            //        $subject = sprintf($formatSubject,$incrementIdFull);
            //        $ticket = $this->_zendeskUtils->create_ticket($customerName,"accounting@REDACTED.com",$subject,$body,"urgent");
            //        $ticketID = $ticket["ticket"]["id"];
            //        $this->_zendeskUtils->updateTicketQueueAssignment($ticketID,"urgent");
            //        $this->_zendeskUtils->updateTicketOrderIncrementID($ticketID,$incrementIdFull);

            //        $t_logger->info("Accounting has been notified of over due refund on Zendesk Ticket#: ".$ticketID);


            //        $this->_zendeskUtils->setTicketToSolved($ticketID);
            //        $t_logger->info("Ticket ".$ticketID." set to solved, to be reopened and set to urgent if Accounting asks for further help.");

            //        $entity= $this->_entityResolver->GetEntityByOrder($order);
            //        $entity->setData('alert_refund_processed',1);
            //        $this->_saveHandler->execute($entity);
            //        $t_logger->info("alert_refund_processed value has been set to true");

            //        $formatOrderNote = $this->_adminUtils->getTranslationFromKey("RED_REFUND_ORDER_NOTE");
            //        $orderNote = sprintf($formatOrderNote,$ticketID);
            //        $order->addStatusHistoryComment($orderNote);
            //        $order->save();
            //        $t_logger->info("internal note left on order.");
            //    }
            //}catch (\Exception $e) {
            //    $t_logger->info('Caught Exception checking alert_refund: '. $e->getMessage());
            //}

            //alert_submit tested on po# 000130366
            try {
                if ($alertSubmitValue == 1 & $alertSubmitProcessed != 1){
                    $t_logger->info("\n\n");
                    $t_logger->info("Increment ID ".$incrementIdFull." triggered alert_submit");

                    $entity= $this->_entityResolver->GetEntityByOrder($order);
                    $entity->setData('alert_submit_processed',1);
                    $this->_saveHandler->execute($entity);
                    $t_logger->info("alert_submit_processed value has been set to true");

                    $requesterEmail =  $this->_adminUtils->getTranslationFromKey("EMAIL_INFO");
                    $requesterName = $this->_adminUtils->getTranslationFromKey("NAME_INFO");
                    $formatSubject = $this->_adminUtils->getTranslationFromKey("RED_SUBMIT_TO_MANAGEMENT_SUBJECT");
                    $subject = sprintf($formatSubject,$incrementIdFull);
                    $formatBody = $this->_adminUtils->getTranslationFromKey("RED_SUBMIT_TO_MANAGEMENT_BODY");
                    $body = sprintf($formatBody,$incrementIdFull);
                    $ticket = $this->_zendeskUtils->CreateTicketCCManagement($requesterName,$requesterEmail,$subject,$body,"urgent");
                    $ticketID = $ticket["ticket"]["id"];
                    $this->_zendeskUtils->updateTicketQueueAssignment($ticketID,"urgent");
                    $this->_zendeskUtils->updateTicketOrderIncrementID($ticketID,$incrementIdFull);
                    $formatInternalNote = $this->_adminUtils->getTranslationFromKey("RED_SUBMIT_ORDER_NOTE");
                    $internalNote = sprintf($formatInternalNote,sprintf("%'.09d", $incrementId),$ticketID,$ticketID);
                    $order->addStatusHistoryComment($internalNote);
                    $order->save();
                    $t_logger->info("internal note left on order.");
                }
            }catch (\Exception $e) {
                $t_logger->info('Caught Exception checking alert_submit: '. $e->getMessage());
            }
        }


        // creating needed tickets in batch

        //alert_ship
        try{
            $ordersXtickets_alertShip = array();
            if (!empty($alertShipTickets)){
                $t_logger->info("Making batch API call to Zendesk to create tickets for Alert Ship...");
                $tickets_alertShip = array(
                    "tickets" => $alertShipTickets
                );
                $job_alertShip = $this->_zendeskUtils->batchCreateTickets($tickets_alertShip);
                $jobURL_alertShip = $job_alertShip['job_status']['url'];
                $ticketIDs_alertShip = $this->_zendeskUtils->getTicketIDsFromBatchCall($jobURL_alertShip);
                $ordersXtickets_alertShip = array_combine($alertShipOrders,$ticketIDs_alertShip);
            }
        }catch (\Exception $e) {
            $t_logger->info('Caught Exception batch creating tickets for alert_ship: '. $e->getMessage());
        }

        //alert_ack
        try{
            $ordersXtickets_alertAck = array();
            if (!empty($alertAckTickets)){
                $t_logger->info("Making batch API call to Zendesk to create tickets for Alert Ack...");
                $tickets_alertAck = array(
                    "tickets" => $alertAckTickets
                );
                $job_alertAck = $this->_zendeskUtils->batchCreateTickets($tickets_alertAck);
                $jobURL_alertAck = $job_alertAck['job_status']['url'];
                $ticketIDs_alertAck = $this->_zendeskUtils->getTicketIDsFromBatchCall($jobURL_alertAck);
                $ordersXtickets_alertAck = array_combine($alertAckOrders,$ticketIDs_alertAck);
            }
        }catch (\Exception $e) {
            $t_logger->info('Caught Exception batch creating tickets for alert_ack: '. $e->getMessage());
        }


        //attaching each ticket to its order via an internal note

        //alert_ship
        try{
            if(!empty($ordersXtickets_alertShip)){
                $t_logger->info("Notating each order with link to created ticket for Alert Ship...");
                foreach ($ordersXtickets_alertShip as $orderID => $ticketID){
                    $formatInternalNote = $this->_adminUtils->getTranslationFromKey("CREATE_ORDER_STATUS_REQUEST_ORDER_NOTE");
                    $internalNote = sprintf($formatInternalNote,$ticketID,$ticketID);
                    $this->_searchCriteriaBuilder->addFilter('increment_id', $orderID);
                    $searchCriteria = $this->_searchCriteriaBuilder->create();
                    $orderList = $this->_orderRepository->getList($searchCriteria);
                    $orders = $orderList->getItems();
                    foreach ($orders as $order){
                        $order->addStatusHistoryComment($internalNote);
                        $order->save();
                    }
                }
            }
        }catch (\Exception $e) {
            $t_logger->info('Caught Exception notating orders for alert_ship: '. $e->getMessage());
        }

        //alert_ack
        try{
            if(!empty($ordersXtickets_alertAck)){
                $t_logger->info("Notating each order with link to created ticket for Alert Ack...");
                foreach ($ordersXtickets_alertAck as $orderID => $ticketID){
                    $formatInternalNote = $this->_adminUtils->getTranslationFromKey("RED_ACK_TO_SUPPLIER_ORDER_NOTE");
                    $internalNote = sprintf($formatInternalNote,$orderID,$ticketID,$ticketID);
                    $this->_searchCriteriaBuilder->addFilter('increment_id', $orderID);
                    $searchCriteria = $this->_searchCriteriaBuilder->create();
                    $orderList = $this->_orderRepository->getList($searchCriteria);
                    $orders = $orderList->getItems();
                    foreach ($orders as $order){
                        $order->addStatusHistoryComment($internalNote);
                        $order->save();
                    }
                }
            }
        }catch (\Exception $e) {
            $t_logger->info('Caught Exception notating orders for alert_ack: '. $e->getMessage());
        }
        $t_logger->info("MagentoEventAgent Finish\n\n");
    }
}