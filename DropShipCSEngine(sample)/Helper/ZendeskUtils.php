<?php
/**
 * Copyright (c) 2022, Tyler Polny
 * All rights reserved.
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree.
 */

namespace REDACTED\OrderManagement\Helper;


class ZendeskUtils{

    protected $_utils;
    protected $_adminUtils;
    private \Zend\Log\Logger $_logger;
    private $ZendeskAuth = 'Authorization: Basic REDACTED KEY';
    private $ZendeskDomainPROD = 'https://REDACTEDDOMAIN.zendesk.com/api/v2/';
    private $ZendeskDomainTEST = 'https://REDACTEDTESTDOMAIN.zendesk.com/api/v2/';
    private $ZendeskDomain;
    private $ticketEndPoint = 'tickets';
    private $userEndPoint = "users";
    private $suspendedTicketsEndPoint = "suspended_tickets";
    private $updateManyEndPoint = "/update_many.json?ids=";
    private $destroyManyEndPoint = "/destroy_many?ids=";
    private $recoverManyEndPoint = "/recover_many?ids=";
    private $createManyEndPoint = "/create_many";
    private $recoverEndPoint = "/recover";
    private $sideConvoEndPoint = "/side_conversations";
    private $eventsEndPoint = "/events";
    private $searchEndPoint = "/search?query=";



    public function __construct(\REDACTED\OrderManagement\Helper\Utils $utils,
                                \REDACTED\AdminSupportTools\Helper\Data $adminUtils)
    {
        $this->_utils = $utils;
        $this->_adminUtils = $adminUtils;
        if ($this->_utils->Test()){
            $this->ZendeskDomain = $this->ZendeskDomainTEST;
        }else{
            $this->ZendeskDomain = $this->ZendeskDomainPROD;
        }


        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/zendeskUtils.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->_logger = $logger;

    }

    /**
     * @param $TicketID string ticket we want to determine if duplicate exist for
     * if a ticket is located with an identical requester and ticket subject it will close all but the oldest ticket and leave an internal note on each linking to the oldest ticket
     * exception: if the subject is "REDACTED Form Submission" the value for "request type" must match to trigger ticket merge
     * @return array duplicate ticket id's being closed, if no id's returns false.
     */
    public function SearchZendeskForDuplicateTicket($TicketID){
        try {
            $DuplicateTicketIDs = array();
            $DuplicateTicketIDsString = null;
            $OriginalTicketJson = $this->getSingleTicket($TicketID);
            $OriginalTicketArray = json_decode($OriginalTicketJson,true);
            $OriginalTicket = $OriginalTicketArray["ticket"];
            $OriginalTicketBody = $OriginalTicket['description'];
            $OriginalTicketSubject = $OriginalTicket["subject"];
            $OriginalTicketRequesterID = ($OriginalTicket["requester_id"]);
            $OriginalTicketRequesterArray = $this->getUserByID($OriginalTicketRequesterID);
            $OriginalTicketRequesterEmail = $OriginalTicketRequesterArray["email"];
            $doNotMergeRequestersString = $this->_adminUtils->getTranslationFromKey('DO_NOT_MERGE_REQUESTERS');
            $doNotMergeRequesters = explode(";",$doNotMergeRequestersString);
            if (in_array($OriginalTicketRequesterEmail,$doNotMergeRequesters)){
                return false;
            }
            $serviceFormTrigger = $this->_adminUtils->getTranslationFromKey("NEW_FORM_TRIGGER");
            $failedPaymentTrigger = "Payment Transaction Failed Reminder";
            $bulkRequestTrigger = $this->_adminUtils->getTranslationFromKey("BULK_REQUEST_TRIGGER");
            $orderListIndicator = $this->_adminUtils->getTranslationFromKey("ORDER_LIST_INDICATOR");
            $orderNumberIndicator = $this->_adminUtils->getTranslationFromKey("ORDER_NUMBER_INDICATOR");
            $requestTypeIndicator = $this->_adminUtils->getTranslationFromKey("REQUEST_TYPE_INDICATOR");

            if ($OriginalTicketSubject == $serviceFormTrigger){
                if(str_contains($OriginalTicketBody,$bulkRequestTrigger)){
                    $IncrementIDProvided = $this->_utils->findSubStr($OriginalTicketBody,$orderListIndicator,"\n");
                }else{
                    $IncrementIDProvided = $this->_utils->findSubStr($OriginalTicketBody,$orderNumberIndicator,"\n");
                }
                $OriginalTicketRequest = $this->_utils->findSubStr($OriginalTicketBody,$requestTypeIndicator,"\n");

            }else{
                $OriginalTicketRequest = null;
            }

            if ($OriginalTicketSubject == $failedPaymentTrigger){
                $OriginalTicketEmail = $this->_utils->findSubStr($OriginalTicketBody," <",">\n- **Items**");
            }
            //searching zendesk for tickets with same subject and requester that are not in solved or closed state
            $queryJson = $this->searchZendesk('type:ticket "'.$OriginalTicketSubject.'" requester:'.$OriginalTicketRequesterEmail.' status<solved -tags:closed_by_merge -tags:do_not_merge');
            $query = json_decode($queryJson,true);
            $tickets = $query["results"];
            $queryCount = $query["count"];

            //iterating through found tickets to see if any match the ticket being verified
            if ($queryCount>2){
                foreach ($tickets as $ticket){
                    $QueryTicketID = $ticket["id"];
                    $QueryTicketSubject = $ticket["subject"];
                    $QueryTicketBody = $ticket['description'];

                    if ($QueryTicketSubject == $serviceFormTrigger){
                        if(str_contains($OriginalTicketBody,$bulkRequestTrigger)){
                            $QueryTicketIncrementID = $this->_utils->findSubStr($QueryTicketBody,$orderListIndicator,"\n");
                        }else{
                            $QueryTicketIncrementID = $this->_utils->findSubStr($QueryTicketBody,$orderNumberIndicator,"\n");
                        }
                        $QueryTicketRequest = $this->_utils->findSubStr($OriginalTicketBody,$requestTypeIndicator,"\n");
                        if ($QueryTicketRequest != null && $IncrementIDProvided == $QueryTicketIncrementID && $QueryTicketRequest == $OriginalTicketRequest && $QueryTicketID != $TicketID){
                            $this->_logger->info("Ticket located with identical subject, request type, requester, and order.");
                            $this->_logger->info("Ticket ".$QueryTicketID." queued to be merged.");
                            array_push($DuplicateTicketIDs,$QueryTicketID);
                        }

                    }
                    if ($QueryTicketSubject == $failedPaymentTrigger){
                        $QueryTicketEmail = $this->_utils->findSubStr($QueryTicketBody," <",">\n- **Items**");
                        if($QueryTicketEmail == $OriginalTicketEmail && $QueryTicketID != $TicketID){
                            $this->_logger->info("Ticket located with identical subject, and requester");
                            $this->_logger->info("Ticket ".$QueryTicketID." queued to be merged.");
                            array_push($DuplicateTicketIDs,$QueryTicketID);
                        }
                    }
                    if ($OriginalTicketSubject == $QueryTicketSubject && $QueryTicketSubject != $serviceFormTrigger && $QueryTicketSubject != $failedPaymentTrigger && $QueryTicketID != $TicketID){
                        $this->_logger->info("Ticket located with identical subject, and requester.");
                        $this->_logger->info("Ticket ".$QueryTicketID." queued to be merged.");
                        array_push($DuplicateTicketIDs,$QueryTicketID);
                    }
                }
            }
            if (!empty($DuplicateTicketIDs)){
                $this->_logger->info("Duplicates were identified in search. Merging duplicates now.");
                $this->_logger->info(print_r($DuplicateTicketIDs,true));
                sort($DuplicateTicketIDs);
                $oldestTicketID = $DuplicateTicketIDs[0];
                unset($DuplicateTicketIDs[0]);

                foreach ($DuplicateTicketIDs as $DuplicateTicketID){
                    if ($DuplicateTicketIDsString == null){
                        $DuplicateTicketIDsString = $DuplicateTicketID;
                    }else{
                        $DuplicateTicketIDsString = $DuplicateTicketIDsString.",".$DuplicateTicketID;
                    }
                }
                if ($DuplicateTicketIDsString == null){
                    return false;
                }
                $this->_logger->info("Ticket that duplicate tickets will be merged to: ");
                $this->_logger->info("[".$oldestTicketID."]");

                $this->_logger->info("Tickets that will be solved and notated:");
                $this->_logger->info("[".$DuplicateTicketIDsString."]");
                $DuplicateTicketIDsNote = null;
                foreach ($DuplicateTicketIDs as $DuplicateTicketID){
                    if ($DuplicateTicketIDsNote == null){
                        $DuplicateTicketIDsNote = "#".$DuplicateTicketID;
                    }else{
                        $DuplicateTicketIDsNote = $DuplicateTicketIDsNote."\n#".$DuplicateTicketID;
                    }
                }
                $oldestTicketNoteFormat = $this->_adminUtils->getTranslationFromKey("DUPLICATE_TICKET_OLDEST");
                $closedTicketNoteFormat = $this->_adminUtils->getTranslationFromKey("DUPLICATE_TICKET_CLOSED");
                $oldestTicketNote = sprintf($oldestTicketNoteFormat,$DuplicateTicketIDsNote,$oldestTicketID);
                $closedTicketNote = sprintf($closedTicketNoteFormat,$oldestTicketID,$oldestTicketID);
                //closing and notating all but oldest duplicate tickets
                //$this->bulkUpdateTickets($DuplicateTicketIDsString,"solved",$closedTicketNote,"closed_by_merge");
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $closedTicketNote,
                            "public" => "false"
                        ),
                        "status" => "solved"
                    )
                );
                $this->bulkUpdateTicketsWithArray($DuplicateTicketIDsString,$update);
                //notating oldest duplicate ticket
                $this->addInternalNoteTicket($oldestTicketID,$oldestTicketNote);
                $returnArray = array();
                $returnArray["closed-tickets"] = $DuplicateTicketIDs;
                $returnArray["target-ticket"] = $oldestTicketID;
                return $returnArray;
            }else{
                return false;
            }


        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }

    }

    /**
     * @param $jobID string ID for job you would like to get
     * @return array json decoded job status
     */
    public function getTicketIDsFromBatchCall($jobURL){

        try {
            $this->_logger->info("getting job status...");
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $jobURL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=9465cdc0f04b497c8ab327426af3356fc232c31e-1651243958; _zendesk_cookie=BAhJIhl7ImRldmljZV90b2tlbnMiOnt9fQY6BkVU--459ed01949a36415c1716b5711271c3d08918307'
                ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);

            $jobStatus = json_decode($response,true)["job_status"];
            $status = $jobStatus["status"];
            if ($status == "completed"){
                $this->_logger->info("job completed. Status below:");
                $this->_logger->info(print_r($jobStatus,true));
                $ticketIDs = array();
                if (array_key_exists("results",$jobStatus)){
                    $this->_logger->info("array_key_exists shows that there is a key for results");
                }
                $results = $jobStatus["results"];
                foreach ($results as $ticket){
                    if (array_key_exists("error",$ticket)){
                        $error = $ticket["error"];
                        $details = $ticket["details"];
                        $ticketIDs[] = "error creating ticket [".$error.":".$details."]";
                    }elseif (array_key_exists("id",$ticket)){
                        $newTicketID = $ticket["id"];
                        $ticketIDs[] = $newTicketID;
                    }
                }
                $this->_logger->info("ticket IDs :");
                $this->_logger->info(print_r($ticketIDs,true));
                return $ticketIDs;
            }elseif($status == "failed"||$status == "killed") {
                $this->_logger->info("Job status: ".$status);
                $this->_logger->info("returning NULL");
                return null;
            }elseif($status == "queued"||$status == "working"){
                $this->_logger->info("Job status: ".$status."...");
                sleep(5);
                return $this->getTicketIDsFromBatchCall($jobURL);
            }
        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }

    }
    /**
     * @param $tickets array must be in format {"tickets": [{"comment": {"body" : $body}, "priority": $priority, "subject": $subject}]}
     * @return string  json_decoded response
     */
    public function batchCreateTickets($tickets){

        try{
            $tickets = json_encode($tickets);
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint.$this->createManyEndPoint;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $tickets,
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=0666a931702dbf7eafa4bc87bdf911d870e78f1c-1651168459; _zendesk_cookie=BAhJIhl7ImRldmljZV90b2tlbnMiOnt9fQY6BkVU--459ed01949a36415c1716b5711271c3d08918307'
                ),
            ));
            $response = json_decode(curl_exec($curl),true);
            $this->_logger->info("JSON response:");
            $this->_logger->info(print_r($response,true));
            curl_close($curl);
            return $response;
        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }
    /**
     * @param $tickets string a coma delimited list of ticket ID's with out space that you would like to update in bulk
     * @param $status string the status you would like to place each order in "tickets" to ["new","open","pending","solved","closed"]
     * @param $body string Body of internal note left on each ticket updated.
     * @param $tags string tag added to each ticket to easily search and track each bulk ticket update
     * @return string json response from call to zendesk
     */

    // ALL CALLS TO THIS FUNCITON HAVE BEEN REPLACED WITH bulkUpdateTicketsWithArray
    public function bulkUpdateTickets($tickets,$status,$body,$tags){
        try {

            $bodyClean = json_encode($body);


            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint.$this->updateManyEndPoint.$tickets;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS =>'{
  "ticket": {
    "status": "'.$status.'",
    "comment": {
      "body": "'.$bodyClean.'",
      "public": false
    },
    "additional_tags": ["'.$tags.'"]
  }
}',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=7de23d10122b7d4726834b93f495bea0cd28c46f-1637097516'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response,true);
            $this->_logger->info(print_r($data,true));

            return $response;
        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }

    }

    /**
     * @param $ticketIDs string a coma delimited list of ticket ID's with out space that you would like to update in bulk
     * @param $update array changes that will be made to all tickets in $ticketIDs
     */

    public function bulkUpdateTicketsWithArray($ticketIDs,$update){
        try {

            $CURLOPT_POSTFIELDS = json_encode($update);


            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint.$this->updateManyEndPoint.$ticketIDs;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS =>$CURLOPT_POSTFIELDS,
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=7de23d10122b7d4726834b93f495bea0cd28c46f-1637097516'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response,true);
            $this->_logger->info(print_r($data,true));

            return $response;
        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }

    }

    /**
     * @param $ticketID string ID of ticket
     */

    public function getSingleTicket($ticketID){
        $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Cookie: __cfruid=468ae2dd1bfe3c70c6b964894a12a2bbe1619908-1636998398'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return $response;

    }


    /**
     * @param $ticketID string ticket ID for ticket we are updating
     * @param $QueueTag string requested queue
     */
    //todo any call to this function in REDACTED should be updated to updateTicket function
    public function updateTicketQueueAssignment($ticketID,$QueueTag){
        try {
            $QueueValue = $this->getQueueValue($ticketID);
            if (!empty($QueueValue)){
                return "Queue value has already been set.";
            }
            if ($QueueTag == "other"){
                $body = $this->_adminUtils->getTranslationFromKey("REQUEST_TO_SUBMIT_TICKET_BODY");
                $this->replyToTicketMainConvo($ticketID,$body);
            }
            if ($this->_utils->Test()){
                $fieldID = "1500011234061";
            }else{
                $fieldID = "1500011596361";
            }

            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS =>'{
      "ticket": {
        "comment": {
          "body": "REDACTED has set the ticket request type to '.$QueueTag.'",
          "public": false
        },
        "custom_fields": [
            {
            "id": '.$fieldID.',
            "value": "'.$QueueTag.'"
            }
        ]
        
      }
    }',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=d2b0896f7ec7bfe2bf387c8d49248fba0dc75d6f-1635882663'
                ),
            ));

            $response = curl_exec($curl);
            $info = curl_getinfo($curl);

            curl_close($curl);
            return $response;

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }
    public function getQueueValue($ticketID){

        try{
            if ($this->_utils->Test() == true) {
                $fieldID = "1500011234061";
            } else {
                $fieldID = "1500011596361";
            }
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=dacccafd8373f35da3f5e6e48918a4cfe544d94c-1634821798'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response,true);
            $customFields = $data["ticket"]["custom_fields"];
            foreach ($customFields as $customField){
                $fieldIDValue = $customField['id'];
                if ($fieldIDValue == $fieldID){
                    $customFieldValue = $customField['value'];
                    return $customFieldValue;
                }
            }

        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }



    public function getTicketOrderIncrementIDValue($ticketID){

        try{
            if ($this->_utils->Test() == true) {
                $fieldID = "1500012542762";
            } else {
                $fieldID = "360041196794";
            }

            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=dacccafd8373f35da3f5e6e48918a4cfe544d94c-1634821798'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response,true);
            $customFields = $data["ticket"]["custom_fields"];
            foreach ($customFields as $customField){
                $fieldIDValue = $customField['id'];
                if ($fieldIDValue == $fieldID){
                    $customFieldValue = $customField['value'];
                    return $customFieldValue;
                }
            }

        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * @param $ticketID string ticket ID for ticket we are updating
     * @param $incrementID string requested incrementID
     */
    //todo any call to this function in REDACTED should be updated to updateTicket function
    public function updateTicketOrderIncrementID($ticketID,$incrementID){
        $TicketOrderIncrementIDValue = $this->getTicketOrderIncrementIDValue($ticketID);
        if (empty($TicketOrderIncrementIDValue)== false){
            $this->_logger->info("Ticket Order Increment ID Value has already been set for Ticket #".$ticketID);
            return "Ticket Order Increment ID Value has already been set.";
        }

        try {

            $curl = curl_init();
            if ($this->_utils->Test() == true) {
                $fieldID = "1500012542762";
            } else {
                $fieldID = "360041196794";
            }
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => '{
      "ticket": {
        "comment": {
          "body": "REDACTED has set the ticket order increment ID to ' . $incrementID . '",
          "public": false
        },
        "custom_fields": [
            {
            "id": '.$fieldID.',
            "value": "' . $incrementID . '"
            }
        ]
        
      }
    }',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=6dbb395af2081824bdbd05cc4f2a198bb9fbd69b-1633628173; _zendesk_session=BAh7CEkiD3Nlc3Npb25faWQGOgZFVEkiJTQxNmY4ODUyZmNjMzkyNzQ2ZmNkZTY4YTJhODY0ZDNhBjsAVEkiDGFjY291bnQGOwBGaQPR4adJIgpyb3V0ZQY7AEZpA12SRw%3D%3D--311a32e94d7bebface13abb26350e6446e98abf9'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);

            return $response;
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    public function getSupplierOrderNumberValue($ticketID){

        try{
            if ($this->_utils->Test()) {
                $fieldID = "1500015543821";
            } else {
                $fieldID = "1500015544161";
            }
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=dacccafd8373f35da3f5e6e48918a4cfe544d94c-1634821798'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response,true);
            $customFields = $data["ticket"]["custom_fields"];
            foreach ($customFields as $customField){
                $fieldIDValue = $customField['id'];
                if ($fieldIDValue == $fieldID){
                    $customFieldValue = $customField['value'];
                    return $customFieldValue;
                }
            }

        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * @param $ticketID string ticket ID for ticket we are updating
     * @param $supplierOrderNumber string data to set for supplier order number field on ticket
     */
    //todo any call to this function in REDACTED should be updated to updateTicket function
    public function updateSupplierOrderNumber($ticketID,$supplierOrderNumber){
        $TicketSupplierOrderNumberValue = $this->getSupplierOrderNumberValue($ticketID);
        if (empty($TicketSupplierOrderNumberValue)== false){
            $this->_logger->info("Ticket supplier oder number value has already been set for Ticket #".$ticketID);
            return "Ticket supplier oder number value has already been set.";
        }

        try {

            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;
            if ($this->_utils->Test()) {
                $fieldID = "1500015543821";
            } else {
                $fieldID = "1500015544161";
            }

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => '{
      "ticket": {
        "comment": {
          "body": "REDACTED has set the ticket supplier order number to to ' . $supplierOrderNumber . '",
          "public": false
        },
        "custom_fields": [
            {
            "id": '.$fieldID.',
            "value": "' . $supplierOrderNumber . '"
            }
        ]
        
      }
    }',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=6dbb395af2081824bdbd05cc4f2a198bb9fbd69b-1633628173; _zendesk_session=BAh7CEkiD3Nlc3Npb25faWQGOgZFVEkiJTQxNmY4ODUyZmNjMzkyNzQ2ZmNkZTY4YTJhODY0ZDNhBjsAVEkiDGFjY291bnQGOwBGaQPR4adJIgpyb3V0ZQY7AEZpA12SRw%3D%3D--311a32e94d7bebface13abb26350e6446e98abf9'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);

            return $response;
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    public function getVendorCodeValue($ticketID){

        try{
            if ($this->_utils->Test() == true) {
                $fieldID = "1500005678702";
            } else {
                $fieldID = "360055641194";
            }

            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=dacccafd8373f35da3f5e6e48918a4cfe544d94c-1634821798'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response,true);
            $customFields = $data["ticket"]["custom_fields"];
            foreach ($customFields as $customField){
                $fieldIDValue = $customField['id'];
                if ($fieldIDValue == $fieldID){
                    $customFieldValue = $customField['value'];
                }
            }
            return $customFieldValue;

        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    //todo any call to this function in REDACTED should be changed to updateTicket function
    public function updateTicketVendorCode($ticketID,$vendorCode){
        $VendorCodeValue = $this->getVendorCodeValue($ticketID);
        if (empty($VendorCodeValue)== false){
            $this->_logger->info("Vendor Code Value value has already been set for Ticket #".$ticketID);
            return "Vendor Code Value has already been set.";
        }
        try {

            $curl = curl_init();
            if ($this->_utils->Test() == true) {
                $fieldID = "1500005678702";
            } else {
                $fieldID = "360055641194";
            }
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => '{
      "ticket": {
        "comment": {
          "body": "REDACTED has set the ticket Vendor Code to ' . $vendorCode . '",
          "public": false
        },
        "custom_fields": [
            {
            "id": '.$fieldID.',
            "value": "' . $vendorCode . '"
            }
        ]
        
      }
    }',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=6dbb395af2081824bdbd05cc4f2a198bb9fbd69b-1633628173; _zendesk_session=BAh7CEkiD3Nlc3Npb25faWQGOgZFVEkiJTQxNmY4ODUyZmNjMzkyNzQ2ZmNkZTY4YTJhODY0ZDNhBjsAVEkiDGFjY291bnQGOwBGaQPR4adJIgpyb3V0ZQY7AEZpA12SRw%3D%3D--311a32e94d7bebface13abb26350e6446e98abf9'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            return $response;
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * returns content of a URL
     * @param $URL
     * @return bool|string
     */
    public function getContent($URL){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $URL);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }


    /**
     * @param string $toName  name of main recipient of email created by ticket.
     * @param string $toEmail
     * @param string $subject
     * @param string $body
     * @param string $priority
     * @param array $ccEmails list of emails to cc on ticket.
     */
    public function CreateTicketCCManagement($requesterName,$requesterEmail,$subject,$bodyRaw,$priority){

        $curl = curl_init();
        if ($this->_utils->Test() == true){
            $TestDisclaimer = '!!Please note that the following email has been sent out of our test instance of REDACTED!!';
        }else{
            $TestDisclaimer = '';
        }
        $body = json_encode($TestDisclaimer.$bodyRaw);

        $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint;
        $CURLOPT_POSTFIELDS = '{
  "ticket": {
    "email_ccs": [
        {"user_email": "REDACTED@REDACTED.com", "user_name": "REDACTED REDACTED", "action": "put"},
        {"user_email": "REDACTED@REDACTED.com", "user_name": "REDACTED REDACTED", "action": "put"},
        {"user_email": "REDACTED@REDACTED.com", "user_name": "REDACTED REDACTED", "action": "put"},
        {"user_email": "REDACTED@REDACTED.com", "user_name": "REDACTED REDACTED", "action": "put"},
        {"user_email": "REDACTED@REDACTED.com", "user_name": "REDACTED REDACTED", "action": "put"}
    ],
    "comment": {
      "body": "'.$body.'"
    },
    "requester": {
        "name": "'.$requesterName.'",
        "email": "'.$requesterEmail.'"
    },
    "priority": "'.$priority.'",
    "subject": "'.$subject.'"
  }
}';
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $CURLOPT_POSTFIELDS,
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Content-Type: application/json',
                'Cookie: __cfruid=26d57378ae38eb7ea84823d4fd089af0e50ca095-1631652240'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $data = json_decode($response,true);
        $ticketID = $data["ticket"]["id"];
        $this->replyToTicketMainConvo($ticketID,$TestDisclaimer.$bodyRaw);
        return $data;


    }
    
    public function createTicketWithArray($ticket){
        try {
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($ticket),
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfduid=d61083c589b2fe4b8e4f7bd5342cead721619724884; __cfruid=2f5145508a55a284486d926932ec6b701543c159-1619724885'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response, true);
            return $data;
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: ' . $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }

    }
    /**
     * Creates a ticket sending an email to $toEmail
     * @param string $toName
     * @param string $toEmail
     * @param string $subject
     * @param string $body
     * @return array json_decode($response,true);
     */
    //todo update function to be given an array rather than several strings
    public function create_ticket($toName,$toEmail,$subject,$body,$priority)
    {

        try {
            $body = json_encode($body);
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{
  "ticket": {
    "comment": {
      "body": "' . $body . '"
    },
    "requester": {
        "name": "' . $toName . '",
        "email": "' . $toEmail . '"
    },
    "priority": "' . $priority . '",
    "subject": "' . $subject . '"
  }
}',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfduid=d61083c589b2fe4b8e4f7bd5342cead721619724884; __cfruid=2f5145508a55a284486d926932ec6b701543c159-1619724885'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $data = json_decode($response, true);
            $this->_logger->info("Ticket Created. Subject: " . $subject . " sent to " . $toEmail);

            return $data;
        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: ' . $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");

        }
    }

    /**
     * @return array containing all needed supplier data
     */
    public function getSupplierArray(){
        $supplier_array_262_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_262_NAME');
        $supplier_array_262_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_262_EMAIL');
        $supplier_array_262_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_262_ACCOUNT_NUM');
        $supplier_array_306_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_306_NAME');
        $supplier_array_306_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_306_EMAIL');
        $supplier_array_306_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_306_ACCOUNT_NUM');
        $supplier_array_276_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_276_NAME');
        $supplier_array_276_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_276_EMAIL');
        $supplier_array_276_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_276_ACCOUNT_NUM');
        $supplier_array_312_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_312_NAME');
        $supplier_array_312_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_312_EMAIL');
        $supplier_array_312_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_312_ACCOUNT_NUM');
        $supplier_array_226_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_226_NAME');
        $supplier_array_226_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_226_EMAIL');
        $supplier_array_226_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_226_ACCOUNT_NUM');
        $supplier_array_310_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_310_NAME');
        $supplier_array_310_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_310_EMAIL');
        $supplier_array_310_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_310_ACCOUNT_NUM');
        $supplier_array_dvg_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_DVG_NAME');
        $supplier_array_dvg_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_DVG_EMAIL');
        $supplier_array_dvg_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_DVG_ACCOUNT_NUM');
        $supplier_array_277_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_277_NAME');
        $supplier_array_277_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_277_EMAIL');
        $supplier_array_277_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_277_ACCOUNT_NUM');
        $supplier_array_268_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_268_NAME');
        $supplier_array_268_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_268_EMAIL');
        $supplier_array_268_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_268_ACCOUNT_NUM');
        $supplier_array_281_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_281_NAME');
        $supplier_array_281_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_281_EMAIL');
        $supplier_array_281_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_281_ACCOUNT_NUM');
        $supplier_array_401_name = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_401_NAME');
        $supplier_array_401_email = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_401_EMAIL');
        $supplier_array_401_account_num = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_401_ACCOUNT_NUM');
        $supplier_array_312_email_alt = $this->_adminUtils->getTranslationFromKey('SUPPLIER_ARRAY_312_EMAIL_ALT');

        $supplierArray = array();
        $supplierArray['262-'] = array(
            "name" => $supplier_array_262_name,
            "email" => $supplier_array_262_email,
            "account" => $supplier_array_262_account_num);
        $supplierArray['306-'] = array(
            "name" => $supplier_array_306_name,
            "email" => $supplier_array_306_email,
            "account" => $supplier_array_306_account_num);
        $supplierArray['276-'] = array(
            "name" => $supplier_array_276_name,
            "email" => $supplier_array_276_email,
            "account" => $supplier_array_276_account_num);
        $supplierArray['312-'] = array(
            "name" => $supplier_array_312_name,
            "email" => $supplier_array_312_email,
            "account" => $supplier_array_312_account_num,
            "emailEscelation" => $supplier_array_312_email_alt);
        $supplierArray['226-'] = array(
            "name" => $supplier_array_226_name,
            "email" => $supplier_array_226_email,
            "account" => $supplier_array_226_account_num);
        $supplierArray['310-'] = array(
            "name" => $supplier_array_310_name,
            "email" => $supplier_array_310_email,
            "account" => $supplier_array_310_account_num);
        $supplierArray['DVG-'] = array(
            "name" => $supplier_array_dvg_name,
            "email" => $supplier_array_dvg_email,
            "account" => $supplier_array_dvg_account_num);
        $supplierArray['DGV-'] = array(
            "name" => $supplier_array_dvg_name,
            "email" => $supplier_array_dvg_email,
            "account" => $supplier_array_dvg_account_num);
        $supplierArray['277-'] = array(
            "name" => $supplier_array_277_name,
            "email" => $supplier_array_277_email,
            "account" => $supplier_array_277_account_num);
        $supplierArray['268-'] = array(
            "name" => $supplier_array_268_name,
            "email" => $supplier_array_268_email,
            "account" => $supplier_array_268_account_num);
        $supplierArray['281-'] = array(
            "name" => $supplier_array_281_name,
            "email" => $supplier_array_281_email,
            "account" => $supplier_array_281_account_num);
        $supplierArray['401-'] = array(
            "name" => $supplier_array_401_name,
            "email" => $supplier_array_401_email,
            "account" => $supplier_array_401_account_num);
        return $supplierArray;
    }

    /**
     * creates a search in zendesk for tickets, agents, or users
     * @param string $query
     * @return string json_encoded string needing to be decoded
     */
    public function searchZendesk($query){
        $CURLOPT_URL = $this->ZendeskDomain.$this->searchEndPoint.urlencode($query);
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Cookie: __cfduid=d61083c589b2fe4b8e4f7bd5342cead721619724884; __cfruid=545c6c5fccb34e1fa622d9224e3767b167d639a6-1620247547'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        return $response;
    }



    /**
     * given the requester ID value return an array for all other values for that requester
     * @param string $requester_id
     * @return array $user contains all user values
     */

    public function getUserByID($requester_id){

        try{
            $CURLOPT_URL = $this->ZendeskDomain.$this->userEndPoint."/".$requester_id;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=67ebf2f2155c8489088269f7a5769fa9117dadeb-1622665156'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $array = json_decode($response,true);

            return $array["user"];
        }catch (\Exception $e) {
            $this->_logger->info('Caught exception: ' . $e->getMessage());
        }
    }

    /**
     * Adds a tag to the ticket ID provided
     * @param string $ticket_ID
     * @param string $tag
     * @return string json response
     */
    public function add_tag_ticket($ticket_ID,$tag){
        try {
            $body = array(
                "ticket" => array(
                    "additional_tags" => $tag
                )
            );
            $CURLOPT_POSTFIELDS = json_encode($body);
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint.$this->updateManyEndPoint.$ticket_ID;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS =>$CURLOPT_POSTFIELDS,
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=362b2f6957d675600b63fa85e8b2fc403c48251d-1623965455'
                ),
            ));
            $response = curl_exec($curl);
            curl_close($curl);
            $this->_logger->info(print_r(json_decode($response,true),true));

            return $response;
        }catch (\Exception $e) {
            $this->_logger->info('Caught exception: ' . $e->getMessage());
        }
    }

    /**
     * Creates a new side conversation on the ticket ID provided.
     * @param string $ticket_ID the ticket that the side conversation will be tacked to
     * @param string $subject subject of the email being sent
     * @param string $body body to the email being sent
     * @param string $email email address to send the side conversation to
     * @return string json response
     */
    //todo update function to be given an array rather than several strings

    public function create_side_convo($ticket_ID,$subject,$body,$email){
        try{
            if($this->_utils->Test()){
                $email = "tyler.polny96@gmail.com";
            }
            $SideConvo = array(
                "message" => array(
                    "subject" => $subject,
                    "body" => $body,
                    "to" => array(
                        array(
                            "email" => $email
                        )
                    )
                )
            );
            $this->_logger->info("json encoded array:");
            $this->_logger->info(json_encode($SideConvo));
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticket_ID.$this->sideConvoEndPoint;
            $this->_logger->info("Value for CURLOPT_URL:");
            $this->_logger->info($CURLOPT_URL);
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>json_encode($SideConvo),
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfduid=d80b8366ecf1b6f66a77afadebfb9cdaf1615313767; __cfruid=093377f80a8f8bb710c527f593aeba0fc2b333dd-1617802152; _zendesk_session=BAh7CEkiD3Nlc3Npb25faWQGOgZFVEkiJTljNDhkYWQzMGI5NzE5YzVhYmE5NTZmYTZkZmMwNGUzBjsAVEkiDGFjY291bnQGOwBGaQOtuZRJIgpyb3V0ZQY7AEZpA2RTMg%3D%3D--2273415a1347089963b33ae3543c1f68933c22ca'
                ),
            ));
            $response = curl_exec($curl);
            curl_close($curl);
            $this->_logger->info("Side Conversation Created on ".$ticket_ID.". Subject: ".$subject." sent to ".$email);
            $this->_logger->info("json response to follow");
            $this->_logger->info(print_r(json_decode($response,true),true));
            return $response;

        }catch (\Exception $e) {
            $this->_logger->info('Caught Exception: '. $e->getMessage());
        }
    }

    /**
     * called to get user ID associated with an email in zendesk
     * @param $ticketIDarray array of ticket IDs to be updated
     * @param $requesterID string Requester that the tickets will be updated to
     * @return array json response
     */
    public function updateRequesterOfMany($ticketIDarray,$requesterID){
        try{
            if(is_array($ticketIDarray)){
                $ticketIDarrayStr = implode(",",$ticketIDarray);
            }else{
                $ticketIDarrayStr = $ticketIDarray;
            }
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint.$this->updateManyEndPoint.$ticketIDarrayStr;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS =>'{
  "ticket": {
    "requester_id": "'.$requesterID.'"
  }
}',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=4ea84bba58574b434725e8d176c49445810cb55c-1625676910'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            return json_decode($response,true);
        }catch (\Exception $e) {
            $this->_logger->info('Caught Exception: '. $e->getMessage());
        }
    }
    /**
     * called to get user ID associated with an email in zendesk
     * @param $email string the requester's email address
     * @return string the requester's user ID
     */
    public function getUserIDforEmail($email){

        $query = "%22".$email."%22";
        $CURLOPT_URL = $this->ZendeskDomain.$this->userEndPoint.$this->searchEndPoint.$query;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Cookie: __cfruid=4ea84bba58574b434725e8d176c49445810cb55c-1625676910'
            ),
        ));

        $response = curl_exec($curl);
        $response_decoded = json_decode($response,true);
        curl_close($curl);
        if (!empty($response_decoded['users'])){
            $users = $response_decoded['users'];
            foreach ($users as $user){
                $ID = $user["id"];
            }
        }else{
            $ID = "NO USER";
        }
        $this->_logger->info('User ID associated with Email ['.$email.'] is ID ['.$ID.']');
        return $ID;

    }

    /**
     * @param $email string email address of new user
     * @param $name string name of new user
     * @return string user ID if created
     */
    public function createNewEndUser($email,$name){
        $this->_logger->info('making new user for '.$email);

        $CURLOPT_URL = $this->ZendeskDomain.$this->userEndPoint;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
  "user": {
      "email": "'.$email.'",
      "name": "'.$name.'",
      "verified": true,
      "role": "end-user"
    }
}',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Content-Type: application/json',
                'Cookie: __cfruid=ebab5e779c50aa4bc8347523566bc878e68ce065-1648146880; _zendesk_cookie=BAhJIhl7ImRldmljZV90b2tlbnMiOnt9fQY6BkVU--459ed01949a36415c1716b5711271c3d08918307; _zendesk_session=BAh7CEkiD3Nlc3Npb25faWQGOgZFVEkiJTE2NWIxMDI1YjJmZDUzMTZmMzU3Mjk3ZjE4ZTY3ZGI1BjsAVEkiDGFjY291bnQGOwBGaQPR4adJIgpyb3V0ZQY7AEZpA12SRw%3D%3D--b88a703031cc4fb0afbdf7f6866fbfdc43a06bc8'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $responseArray = json_decode($response,true);
        if (in_array("user",$responseArray)){
            $userID = $responseArray["user"]["id"];
        }else{
            $userID = "USER EXISTS";
        }
        $this->_logger->info("User ID for new user [".$userID."]");
        return $userID;
    }


    /**
     * Called to recover list of suspended tickets
     * @param $ListOfID array of suspended ticket ID's that must be recovered
     * @return json response
     */
    public function recoverSuspendedTicketList($ListOfID){
        $ListOfIDStr = implode(",",$ListOfID);
        $this->_logger->info('Recovering the following suspended ticket IDs: '.$ListOfIDStr);
        try{
            $CURLOPT_URL = $this->ZendeskDomain.$this->suspendedTicketsEndPoint.$this->recoverManyEndPoint.$ListOfIDStr;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=bfc89dd7dd2c0b96b98ca710e5745cd134ec5b3b-1625583756'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            return json_decode($response,true);

        } catch (\Exception $e) {
            $this->_logger->info('Caught exception: ' . $e->getMessage());
        }

    }

    /**
     * Called to recover list of suspended tickets
     * @param $ListOfID array of suspended ticket ID's that must be recovered
     * @return json response
     */
    public function recoverSuspendedTicket($ID){

        try{

            $CURLOPT_URL = $this->ZendeskDomain.$this->suspendedTicketsEndPoint."/".$ID.$this->recoverEndPoint;
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=bfc89dd7dd2c0b96b98ca710e5745cd134ec5b3b-1625583756'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            return json_decode($response,true);

        } catch (\Exception $e) {
            $this->_logger->info('Caught exception: ' . $e->getMessage());
        }

    }

    /**
     * Called to recover list of suspended tickets
     * @param $ListOfID array of suspended ticket ID's that must be recovered
     * @return json response
     */
    public function deleteSuspendedTicketList($ListOfID){
        $ListOfIDStr = implode(",",$ListOfID);
        $this->_logger->info('Deleting the following suspended ticket IDs: '.$ListOfIDStr);
        $CURLOPT_URL = $this->ZendeskDomain.$this->suspendedTicketsEndPoint.$this->destroyManyEndPoint.$ListOfIDStr;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Cookie: __cfruid=bfc89dd7dd2c0b96b98ca710e5745cd134ec5b3b-1625583756'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $this->_logger->info("JSON response:");
        $this->_logger->info($response);

        return $response;
    }

    /**
     * Called to return the last 50 suspended tickets. Newest first
     * @return array list of suspended tickets
     */
    public function getSuspendedTickets(){
        $query = "?q=sort_by+created_at+desc";
        $CURLOPT_URL = $this->ZendeskDomain.$this->suspendedTicketsEndPoint.$query;
        $curl = curl_init();
        curl_setopt_array($curl, array(

            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Cookie: __cfruid=bfc89dd7dd2c0b96b98ca710e5745cd134ec5b3b-1625583756'
            ),
        ));

        $response = curl_exec($curl);
        $suspendedTickets = json_decode($response,true)['suspended_tickets'];
        curl_close($curl);
        return $suspendedTickets;
    }
    /**
     * Updates the subject of the ticket ID provided
     * @param string $ticket_ID the ticket that the side conversation will be tacked to
     * @param string $subject subject of the email being sent
     * @return string json response
     */
    //todo any call to this function in REDACTED should be changed to updateTicket function
    public function changeTicketSubject($ticket_ID,$subject){

        $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticket_ID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => '{
  "ticket": {
    "subject": "'.$subject.'"
  }
}',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Content-Type: application/json',
                'Cookie: __cfduid=d61083c589b2fe4b8e4f7bd5342cead721619724884; __cfruid=9d90e29df05fc82ee9825a0f2a3be997c86381f7-1621614427'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $this->_logger->info("Ticket ".$ticket_ID." Subject updated to: ".$subject);
        return $response;
    }

    /**
     * Replies to the main thread of a ticket, sending an email to the requester
     * @param string $ticket_ID
     * @param string $reply
     * @return string json response
     */
    //todo any call to this function in REDACTED should be changed to updateTicket function
    public function replyToTicketMainConvo($ticket_ID,$reply){
        try{
            if ($this->_utils->Test()){
                $this->_logger->info("to prevent emails from going out of test. any reply to main convo is made as an internal note on the ticket.");
                $response = $this->addInternalNoteTicket($ticket_ID,"{the following would be a public reply to this ticket if this was run on PROD}\n".$reply);
                return $response;
            }
            $reply = json_encode($reply);
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticket_ID;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS =>'{
  "ticket": {
    "comment": {
      "body": '.$reply.',
      "public": true
    }
  }
}',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfduid=d61083c589b2fe4b8e4f7bd5342cead721619724884; __cfruid=1b84705aa8a33b229ed95d6299beb50aa988364d-1621615268'
                ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);
            $this->_logger->info("Public reply sent out on Ticket# ".$ticket_ID);

            return $response;
        }catch (\Exception $e) {
            $this->_logger->info('Caught Exception: '. $e->getMessage());
        }
    }

    /**
     * adds an internal note to the ticket ID
     * @param string $ticket_ID
     * @param string $note
     * @return string json response
     */
    //todo any call to this function in REDACTED should be changed to updateTicket function
    public function addInternalNoteTicket($ticket_ID,$note){
        try{
            $note = json_encode($note);
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticket_ID;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER, true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS =>'{
  "ticket": {
    "comment": {
      "body": '.$note.',
      "public": false
    }
  }
}',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=acd379e7c790bd8211d171b13b764168564a20f5-1623945681'
                ),
            ));

            $response = curl_exec($curl);
            $info = curl_getinfo($curl);
            $headers = get_headers($info['url']);
            curl_close($curl);
            $data = json_decode($response,true);
            return $headers;
        }catch (\Exception $e) {
            $this->_logger->info('Caught Exception: '. $e->getMessage());
        }
    }

    /**
     * sets ticket ID to pending
     * @param string $ticket_ID
     * @return string json response
     */
    //todo any call to this function in REDACTED should be changed to updateTicket function
    public function setTicketToPending($ticket_ID){
        $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticket_ID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS =>'{
  "ticket": {
    "status": "pending"
  }
}
',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Content-Type: application/json',
                'Cookie: __cfdid=d80b8366ecf1b6f66a77afadebfb9cdaf1615313767; __cfruid=093377f80a8f8bb710c527f593aeba0fc2b333dd-1617802152'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $this->_logger->info("######################################");
        $this->_logger->info("#Ticket ".$ticket_ID." set to PENDING#");
        $this->_logger->info("######################################");

        $data = json_decode($response,true);
        return $response;

    }

    /**
     * provides all messages on a side conversation
     * note: with out this the body that returns on a side conversation is only the first 100 characters of the first email of the side conversation. denoted as "preview" in api object.
     * @param string $ticketID
     * @param string $sideID the ID to the specific side conversation
     * @return array emails to and from on the side conversation
     */
    public function getSideConvoEventsBySideID($ticketID,$sideID){
        $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID.$this->sideConvoEndPoint."/".$sideID.$this->eventsEndPoint;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Cookie: __cfduid=d61083c589b2fe4b8e4f7bd5342cead721619724884; __cfruid=37fe239cbdf323841de50681ea4a03d0183ba217-1621891929'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $this->_logger->info("Function Applied: getSideConvoEventsBySideID");

        return json_decode($response,true);

    }

    /**
     * sends a new email on the side conversation for the Side ID provided
     * @param string $ticketID
     * @param string $sideID the ID to the specific side conversation
     * @param string $reply
     * @return string json response
     */
    public function replyToSideConversation($ticketID,$sideID,$reply){
        $sideConvo_CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID.$this->sideConvoEndPoint."/".$sideID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $sideConvo_CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic dHlsZXIucG9sbnlAdmVyYXRpY3MuY29tOkZlcnJldF4yMDAx',
                'Cookie: __cfduid=d61083c589b2fe4b8e4f7bd5342cead721619724884; __cfruid=16fd5787c45ddd562e873a230fecfb96e844e3fd-1622048108'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $sideConvo = json_decode($response,true);
        $participants = $sideConvo["side_conversation"]["participants"];
        foreach($participants as $participant) {
            $testEmail = $participant["email"];
            if ((str_contains($testEmail, "@REDACTED.com")) == false) {
                $email = $testEmail;
            }
        }
        $curl = curl_init();
        $reply_CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID.$this->sideConvoEndPoint."/".$sideID."/reply";
        curl_setopt_array($curl, array(
            CURLOPT_URL => $reply_CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
    "message": {
    "body": "'.$reply.'",
    "to": [
      { "email": "'.$email.'" }
    ]
  }
}',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Content-Type: application/json',
                'Cookie: __cfduid=d61083c589b2fe4b8e4f7bd5342cead721619724884; __cfruid=16fd5787c45ddd562e873a230fecfb96e844e3fd-1622048108'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $this->_logger->info(json_decode($response,true));
        $this->_logger->info("Reply sent to side conversation to ".$email." on Ticket# ".$ticketID);

        return $response;

    }

    /**
     * Given a key to search for and a ticket ID returns the side conversation that contains $subjectSubstr in the subject line
     * @param string $ticketID
     * @param string $sideID the ID to the specific side conversation
     * @param string $reply
     * @return array side conversation (email,body,ID)
     */
    public function getSideConvoBySubjectSubstr($ticketID,$subjectSubstr){
        try{
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID.$this->sideConvoEndPoint;

            $this->_logger->info("value for CURLOPT_URL:");
            $this->_logger->info($CURLOPT_URL);
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Cookie: __cfruid=7bf6ed343b8cebcc249103d397a2be8071757dea-1635059536'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);

            $response_decode = json_decode($response,true);
            $this->_logger->info("side conversation object parsed:");
            $this->_logger->info(print_r($response_decode,true));


            $sideConversations = $response_decode["side_conversations"];
            $sideID = null;
            $body = null;
            $email = null;
            foreach ($sideConversations as $sideConvo){
                $subject = $sideConvo["subject"];
                if (str_contains($subject,$subjectSubstr)){
                    $participants = $sideConvo["participants"];
                    foreach($participants as $participant) {
                        $testEmail = $participant["email"];
                        if ((str_contains($testEmail, "@REDACTEDCOMPANY.com")) == false) {
                            $email = $testEmail;
                            $this->_logger->info('Side Email: '.$email);
                        }
                    }
                    $sideID = $sideConvo["id"];
                    $sideConvoEvents = $this->getSideConvoEventsBySideID($ticketID,$sideID);
                    $events = $sideConvoEvents["events"];
                    foreach ($events as $event){
                        $eventEmail = $event["actor"]["email"];
                        if ($email == $eventEmail){
                            $body = $event["message"]["body"].$body;

                        }
                    }
                    $sideConversation = array(
                        "email" => $email,
                        "body" => $body,
                        "ID" => $sideID);
                }
            }
            if ($body == null || $sideID == null || $email == null){
                return null;
            }else{
                return $sideConversation;
            }
        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }

    /**
     * remove a ticket from being included in future iterations of REDACTED by adding the tag rejected.
     * @param string $ticketID
     * @param string $reason this string will be added to a template note REDACTED adds to the internal notes of the ticket provided. Explaining the issue to agents coming to the ticket.
     * @return string json response
     */
    public function rejectTicket($ticketID,$reason){
        try{
            $ticket_json = $this->getSingleTicket($ticketID);
            $ticket_decode = json_decode($ticket_json,true);
            $ticket = $ticket_decode["ticket"];
            $status = $ticket["status"];
            $formatBody = "Hello Customer Service,\n\nREDACTED has removed ticket #%s from automation to prevent possible errors.\n\nReason for ticket removal:\n%s\n\nPlease process this request to completion as you normally would. You can refer to the side conversation for any supplier discussions in progress.\n\nThank you,\n-REDACTED";
            $Body = sprintf($formatBody,$ticketID,$reason);
            $tags = ["rejected","REDACTED_rejected"];
            $this->add_tag_ticket($ticketID,$tags);
            if ($status == "pending"){
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $Body,
                            "public" => "false"
                        ),
                        "status" => "open"
                    )
                );
            }else{
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $Body,
                            "public" => "false"
                        )
                    )
                );
            }
            $this->updateTicket($ticketID,$update);

            $this->_logger->info("######################################");
            $this->_logger->info("#   Ticket ".$ticketID." REJECTED    #");
            $this->_logger->info("######################################");
            $this->_logger->info("Reject Reason:");
            $this->_logger->info($reason);

        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }

    }

    /**
     * remove a tag from the ticket ID provided
     * @param string $ticketID
     * @param string $tag
     * @return string json response
     */
    //todo udpate function to handle being given multiple tags/ticketIDs. see add_tag_ticket
    public function removeTagFromTicket($ticketID,$tag){
        $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint.$this->updateManyEndPoint.$ticketID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS =>'{
    "ticket": {
        "remove_tags":["'.$tag.'"]
    }
}',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Content-Type: application/json',
                'Cookie: __cfruid=da8692dfcd603512bd3941160fa5df586f1f5a40-1626877639'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return $response;

    }

    /**
     * set ticket provided to solved
     * @param string $ticketID
     * @return string json response
     */
    //todo any call to this function in REDACTED should be changed to updateTicket function
    public function setTicketToSolved($ticket_ID){
        $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticket_ID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS =>'{
  "ticket": {
    "status": "solved"
  }
}
',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Content-Type: application/json',
                'Cookie: __cfduid=d80b8366ecf1b6f66a77afadebfb9cdaf1615313767; __cfruid=093377f80a8f8bb710c527f593aeba0fc2b333dd-1617802152'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $this->_logger->info("######################################");
        $this->_logger->info("#Ticket ".$ticket_ID." set to SOLVED #");
        $this->_logger->info("######################################");
        return $response;
    }

    public function getZendeskAdminsAndAgents(){

        $query = "users.json?role%5B%5D=agent&role%5B%5D=admin";
        $CURLOPT_URL = $this->ZendeskDomain.$query;
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Cookie: __cfruid=8884e3e1167df314e5ff2c3329477665ffcb8755-1651598361; _zendesk_cookie=BAhJIhl7ImRldmljZV90b2tlbnMiOnt9fQY6BkVU--459ed01949a36415c1716b5711271c3d08918307'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return json_decode($response,true)["users"];
    }


    /**
     * Updates $ticketID to have assignee of $assigneeEmail, and leaves $internalNote on ticket
     * @param $ticketID string ticket to be updated
     * @param $AssigneeEmail string asignee we are setting the ticket to
     * @param $InternalNote string internal note left on ticket
     */
    public function updateTicketAssigneeOfMany($ticketIDs,$AssigneeEmail,$InternalNote){
        try{
            $curl = curl_init();
            $InternalNote = json_encode($InternalNote);
            $AssigneeEmail = json_encode($AssigneeEmail);

            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint.$this->updateManyEndPoint.$ticketIDs;

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS =>'{
  "ticket": {
    "comment": {
      "body": '.$InternalNote.',
      "public": false
    },
    "assignee_email": '.$AssigneeEmail.'
  }
}',
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=e08b07fda1e784985b68e8c4d01c325b899e2861-1650383733; _zendesk_cookie=BAhJIhl7ImRldmljZV90b2tlbnMiOnt9fQY6BkVU--459ed01949a36415c1716b5711271c3d08918307'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            return json_decode($response,true);;

        } catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }
    /**
     * set ticket provided to open
     * @param string $ticketID
     * @return string json response
     */
    //todo any call to this function in REDACTED should be changed to updateTicket function
    public function setTicketToOpen($ticket_ID){
        $curl = curl_init();
        $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticket_ID;

        curl_setopt_array($curl, array(
            CURLOPT_URL => $CURLOPT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS =>'{
  "ticket": {
    "status": "open"
  }
}
',
            CURLOPT_HTTPHEADER => array(
                $this->ZendeskAuth,
                'Content-Type: application/json',
                'Cookie: __cfduid=d80b8366ecf1b6f66a77afadebfb9cdaf1615313767; __cfruid=093377f80a8f8bb710c527f593aeba0fc2b333dd-1617802152'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $this->_logger->info("######################################");
        $this->_logger->info("# Ticket ".$ticket_ID." set to OPEN  #");
        $this->_logger->info("######################################");

        return $response;
    }

    /**
     * @param $ticketID string ticket to be udpated
     * @param $update array changes made to ticket
     * @return string json response
     */
    public function updateTicket($ticketID,$update){
        try{
            $CURLOPT_URL = $this->ZendeskDomain.$this->ticketEndPoint."/".$ticketID;

            $CURLOPT_POSTFIELDS = json_encode($update);

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $CURLOPT_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $CURLOPT_POSTFIELDS,
                CURLOPT_HTTPHEADER => array(
                    $this->ZendeskAuth,
                    'Content-Type: application/json',
                    'Cookie: __cfruid=7b4ae8225634cf6c6269cd2c7911d448063d9f2e-1651780442; _zendesk_cookie=BAhJIhl7ImRldmljZV90b2tlbnMiOnt9fQY6BkVU--459ed01949a36415c1716b5711271c3d08918307'
                ),
            ));
            $response = curl_exec($curl);
            curl_close($curl);
            $this->_logger->info(print_r(json_decode($response,true),true));
            return $response;
        }catch (\Exception $e) {
            $this->_logger->info("//////////////////////////////////////");
            $this->_logger->info('Caught Exception: '. $e->getMessage());
            $this->_logger->info("//////////////////////////////////////");
        }
    }
}
