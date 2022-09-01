<?php
/**
 * Copyright (c) 2022, Tyler Polny
 * All rights reserved.
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree.
 */

namespace REDACTED\OrderManagement\Cron;

class CSZendeskEventAgent
{
    protected $_utils;
    protected $_agentActions;
    protected $_zendeskActions;
    protected $_adminUtils;


    public function __construct(\REDACTED\OrderManagement\Helper\AgentActions $agentActions,
                                \REDACTED\OrderManagement\Helper\Utils $utils,
                                \REDACTED\OrderManagement\Helper\ZendeskUtils $zendeskActions,
                                \REDACTED\AdminSupportTools\Helper\Data $adminUtils)
    {

        $this->_agentActions = $agentActions;
        $this->_zendeskActions = $zendeskActions;
        $this->_utils = $utils;
        $this->_adminUtils = $adminUtils;
    }


    /**
     * Called by cron to start dropShipCSEngine.
     */
    public function RunZDEventAgent(){
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/dropShipCS.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        $t_logger->info("\n\n");
        $t_logger->info("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%");
        $t_logger->info("%%%%%ZenDeskEventAgent Start%%%%%%%");
        $t_logger->info("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%");

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// Test Control Panel
        if ($this->_utils->Test()){
            $Run_DuplicateTicketCheck = true;// if set to true, duplicate tickets will be closed out in test
            $Run_PopulateTicketQueueValue = true;// if set to true, a query will be made to set the queue value on all tickets in new status
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
        }else{
            //DO NOT change below values
            $Run_DuplicateTicketCheck = true;
            $Run_PopulateTicketQueueValue = true;
        }

        try{
            $t_logger->info("starting aged ticket auto close work flow");
            $oldestPendingTickets = json_decode($this->_zendeskActions->searchZendesk("type:ticket status:pending order_by:updated_at sort:asc"),true)["results"];
            $triggerDate = date("Y-m-d",strtotime("-14 days"));
            $today = date("Y-m-d");
            $t_logger->info("looking for any ticket that has a last updated date older than ".$triggerDate." and is in pending status.");
            $BulkClosedTickets = array();
            foreach($oldestPendingTickets as $ticket){
                $updatedAt = date("Y-m-d",strtotime($ticket["updated_at"]));
                $dueDate = date("Y-m-d",strtotime($ticket["due_at"]));
                if(($updatedAt<$triggerDate) && ($dueDate<$today || is_null($dueDate))){
                    $ID = strval($ticket["id"]);
                    settype($BulkClosedTickets,"array");
                    array_push($BulkClosedTickets,$ID);
                }
            }
            if(!empty($BulkClosedTickets)){
                $IDs = implode(",",$BulkClosedTickets);
                $t_logger->info("The following Ticket IDs will be updated to closed due to their age and current status.");
                $t_logger->info($IDs);
                $body = $this->_adminUtils->getTranslationFromKey("AUTOCLOSE_TICKET_NOTE");
                $update = array(
                    "ticket" => array(
                        "comment" => array(
                            "body" => $body,
                            "public" => "false"
                        ),
                        "status" => "closed"
                    )
                );
                $update = $this->_zendeskActions->bulkUpdateTicketsWithArray($IDs,$update);
                $job_status = json_decode($update,true);
                $t_logger->info("job status for bulk update to follow:");
                $t_logger->info(print_r($job_status,true));
            }else{
                $t_logger->info("0 tickets triggered to be closed due to age.");
            }
        }catch (\Exception $e) {
            $t_logger->info("//////////////////////////////////////");
            $t_logger->info('Caught Exception auto-closing aged tickets: '. $e->getMessage());
            $t_logger->info("//////////////////////////////////////");
        }

        try{
            if ($Run_DuplicateTicketCheck or !$this->_utils->Test()){
                $t_logger->info("#####Processing Duplicate Ticket Clean up####################################");
//PROCESSING DUPLICATE TICKET CLEAN UP################################################################################
                $query = 'type:ticket status<solved -tags:closed_by_merge -tags:do_not_merge order_by:created_at sort:desc';
                $tickets = $this->_zendeskActions->searchZendesk($query);
                $data = json_decode($tickets,true);
                $queryCount = $data["count"];
                $mergeTargetTicketIDs = array();
                $solvedDuplicateTicketIDs = array();
                $t_logger->info("tickets returned for query being used on duplicate ticket clean up:");
                $t_logger->info($queryCount);
                $t_logger->info("If any duplicate tickets are located, their details will be listed below...");

                //$t_logger->info(print_r($data,true));
                foreach ($data['results'] as $ticket){
                    $ID = ($ticket["id"]);

                    $requesterID = ($ticket["requester_id"]);
                    $requesterArry = $this->_zendeskActions->getUserByID($requesterID);
                    $requesterEmail = $requesterArry["email"];
                    if (is_null($requesterEmail)){
                        $requesterEmail = $requesterArry["name"];
                    }
                    $doNotMergeRequestersString = $this->_adminUtils->getTranslationFromKey('DO_NOT_MERGE_REQUESTERS');
                    $doNotMergeRequesters = explode(";",$doNotMergeRequestersString);
                    if (in_array($requesterEmail,$doNotMergeRequesters)){
                        //$t_logger->info("Ticket ID ".$ID." - ticket requester ".$requesterEmail." has been listed as a requester that should never be merged.");
                        continue;
                    }

                    settype($solvedDuplicateTicketIDs,"array");
                    if (in_array($ID,$solvedDuplicateTicketIDs)){
                        //$t_logger->info("Ticket #".$ID." already queued to be merged.\nTickets that have already been merged:\n");
                        //$t_logger->info(print_r($solvedDuplicateTicketIDs,true));
                        continue;
                    }

                    settype($mergeTargetTicketIDs,"array");
                    if (in_array($ID,$mergeTargetTicketIDs)){
                        //$t_logger->info("Ticket #".$ID." is already a target for a merge already queued.\nTickets that have been merge targets:\n");
                        //$t_logger->info(print_r($mergeTargetTicketIDs,true));
                        continue;

                    }

                    if(str_contains($requesterEmail,"@REDACTED.com")){
                        $this->_zendeskActions->rejectTicket($ID,"All emails from REDACTED.com must be processed manually");
                        continue;
                    }
                    $duplicateSearchReturn = $this->_zendeskActions->SearchZendeskForDuplicateTicket($ID);
                    if ($duplicateSearchReturn != false){
                        $solvedDuplicateTicketIDsAppend = $duplicateSearchReturn["closed-tickets"];
                        $mergeTargetTicketIDsAppend = $duplicateSearchReturn["target-ticket"];
                        $t_logger->info("duplicate tickets located");
                        $t_logger->info("the following tickets will be merged to ticket #".$mergeTargetTicketIDsAppend.":");
                        $t_logger->info(print_r($solvedDuplicateTicketIDsAppend,true));
                        $solvedDuplicateTicketIDs = array_push($solvedDuplicateTicketIDs,$solvedDuplicateTicketIDsAppend);
                        $mergeTargetTicketIDs = array_push($mergeTargetTicketIDs,$mergeTargetTicketIDsAppend);
                    }else{
                        //$t_logger->info("0 duplicates found for ticket ".$ID.".");
                    }
                }
            }else{
                $t_logger->info("Duplicate Ticket Workflow Disabled For TEST");
            }
        }catch (\Exception $e) {
            $t_logger->info("//////////////////////////////////////");
            $t_logger->info('Caught Exception processing duplicate ticket cleaning: '. $e->getMessage());
            $t_logger->info("//////////////////////////////////////");
        }
        try{
            $t_logger->info("#####Processing Suspended Ticket Queue####################################");
//PROCESSING SUSPENDED TICKETS################################################################################
            $recoverEmailArray = $this->getArrayRecoverEmails();
            $recoverSubjectArray = $this->getArrayRecoverSubjects();
            $deleteEmailArray = $this->getArrayDeleteEmails();
            $deleteSubjectArray = $this->getArrayDeleteSubjects();
            $suspendedTickets = $this->_zendeskActions->getSuspendedTickets();
            //$t_logger->info(print_r($suspendedTickets,true));
            $recoverTickets = array();
            $deleteTickets = array();
            $manualRecover = array();

            foreach ($suspendedTickets as $suspendedTicket){
                $id = $suspendedTicket['id'];
                $subject = $suspendedTicket['subject'];
                $content = $suspendedTicket['content'];
                $author = $suspendedTicket['author'];
                $email = $author['email'];
                $name = $author['name'];

                foreach ($recoverEmailArray as $trigger){
                    if (str_contains($email,$trigger)){
                        $t_logger->info("\n");
                        $t_logger->info('ticket ID '.$id);
                        $t_logger->info($email);
                        $t_logger->info('triggered recover for $email');
                        array_push($recoverTickets,$id);
                        break;
                    }
                }
                foreach ($recoverSubjectArray as $trigger){
                    if (str_contains($subject,$trigger)){
                        $t_logger->info("\n");
                        $t_logger->info('ticket ID '.$id);
                        $t_logger->info($subject);
                        $t_logger->info('triggered recover for $subject');
                        array_push($recoverTickets,$id);
                        break;
                    }
                }
                foreach ($deleteEmailArray as $trigger){
                    if (str_contains($email,$trigger)){
                        $t_logger->info("\n");
                        $t_logger->info('ticket ID '.$id);
                        $t_logger->info($email);
                        $t_logger->info('triggered destroy for $email');
                        array_push($deleteTickets,$id);
                        break;
                    }
                }
                foreach ($deleteSubjectArray as $trigger){
                    if (str_contains($subject,$trigger)){
                        $t_logger->info("\n");
                        $t_logger->info('ticket ID '.$id);
                        $t_logger->info($subject);
                        $t_logger->info('triggered destroy for $subject');
                        array_push($deleteTickets,$id);
                        break;
                    }
                }
            }
            if (empty($recoverTickets) == false){
                $response = $this->_zendeskActions->recoverSuspendedTicketList($recoverTickets);
                if(array_key_exists("tickets",$response)){
                    $tickets = $response["tickets"];
                    if ($tickets != null){
                        foreach ($tickets as $ticket){
                            //$t_logger->info(print_r($ticket,true));
                            $via = $ticket["via"];
                            $source = $via["source"];
                            $from = $source["from"];
                            $email = $from["address"];
                            if ($email == "support@REDACTED.com"){
                                $ticketID = $ticket["id"];
                                array_push($manualRecover,$ticketID);
                            }
                            if ($this->_utils->Test()){
                                if ($email == "REDACTED@gmail.com"){
                                    $ticketID = $ticket["id"];
                                    array_push($manualRecover,$ticketID);

                                }
                            }
                        }
                    }
                }
                if(array_key_exists("suspended_tickets",$response)){
                    $tickets = $response["suspended_tickets"];
                    if ($tickets != null){
                        foreach ($tickets as $ticket){
                            //$t_logger->info(print_r($ticket,true));
                            $via = $ticket["via"];
                            $source = $via["source"];
                            $from = $source["from"];
                            $email = $from["address"];
                            if ($email == "support@REDACTED.com"){
                                $ticketID = $ticket["id"];
                                array_push($manualRecover,$ticketID);
                            }
                            if ($this->_utils->Test()){
                                if ($email == "REDACTED@gmail.com"){
                                    $ticketID = $ticket["id"];
                                    array_push($manualRecover,$ticketID);

                                }
                            }
                        }
                    }
                }

                if (empty($manualRecover) ==false){
                    $requesterID = $this->_zendeskActions->getUserIDforEmail("no-reply@REDACTED.com");
                    $updateRequester = array();
                    foreach ($manualRecover as $suspendedID){
                        $result = $this->_zendeskActions->recoverSuspendedTicket($suspendedID);
                        $ticketID = $result["ticket"]["id"];
                        array_push($updateRequester,$ticketID);
                    }
                    $this->_zendeskActions->updateRequesterOfMany($updateRequester,$requesterID);

                }else{
                    $t_logger->info('No tickets have a requester of support@REDACTED.com');
                }
            }else{
                $t_logger->info('0 emails qualify to be recovered.');
            }

            if (empty($deleteTickets) == false){
                $this->_zendeskActions->deleteSuspendedTicketList($deleteTickets);
            }else{
                $t_logger->info('0 emails qualify to be deleted.');
            }
        }catch (\Exception $e) {
            $t_logger->info("//////////////////////////////////////");
            $t_logger->info('Caught Exception processing suspended ticket queue: '. $e->getMessage());
            $t_logger->info("//////////////////////////////////////");
        }



        try{
            $t_logger->info("#####Processing Pending Ticket Queue####################################");
//PROCESSING PENDING TICKETS################################################################################
            $tickets = $this->_zendeskActions->searchZendesk('type:ticket status:pending tags:csb2_pending_queue -tags:rejected');
            $data = json_decode($tickets,true);
            foreach ($data['results'] as $ticket){
                $ID = ($ticket["id"]);
                settype($solvedDuplicateTicketIDs,"array");
                if (!empty($solvedDuplicateTicketIDs)){
                    if (in_array($ID,$solvedDuplicateTicketIDs)){
                        $t_logger->info("Ticket ".$ID." was closed in duplicate clean up. Skipping ticket.");
                        continue;
                    }
                }
                if ($this->_utils->Test() == true){
                    $tags = $ticket["tags"];
                    if (in_array("rejected",$tags)){
                        continue;
                    }
                    if (in_array("csb2_review_reject",$tags)){
                        continue;
                    }
                }
                $status = $ticket["status"];
                if ($status != 'pending'){
                    continue;
                }
                $Subject = ($ticket["subject"]);
                $t_logger->info("\n\n");
                $t_logger->info("******************************************************************************");
                $t_logger->info("***".$Subject."***");
                $requesterID = ($ticket["requester_id"]);
                $requesterArry = $this->_zendeskActions->getUserByID($requesterID);
                $requesterEmail = $requesterArry["email"];
                if (is_null($requesterEmail)){
                    $requesterEmail = $requesterArry["name"];
                }
                if(str_contains($requesterEmail,"postmaster@")) {
                    $this->_zendeskActions->rejectTicket($ID, "All emails from postmaster addresses must be processed manually");
                    continue;
                }elseif(str_contains($requesterEmail,"@REDACTED.com")){
                    $this->_zendeskActions->rejectTicket($ID,"All emails from REDACTED.com must be processed manually");
                    continue;
                }elseif(str_contains($Subject, " status changed to red_ship_deliver (URGENT)")) {
                    $this->_agentActions->contactSupplierRedShip($ID);
                }elseif(str_contains($Subject,'Transfer ticket from PROD')){
                    if($this->_utils->Test()){
                        $this->_zendeskActions->transferTicketFromPRODtoTEST($ID);
                    }
                }elseif(str_contains($Subject,'REDACTED Form Submission')){
                    $this->_agentActions->routeCustomerServiceFormData($ID);
                }elseif(str_contains($Subject,' status changed to red_ack (URGENT)')){
                    $this->_agentActions->contactSupplierRedAck($ID);
                }elseif(str_contains($Subject,' status changed to complete_order_shipped_again (URGENT)')){
                    $this->_agentActions->contactSupplierCompleteOrderShippedAgain($ID);
                }elseif(str_contains($Subject,' status changed to closed_order_shipped (URGENT)')){
                    $this->_agentActions->contactSupplierClosedOrderShipped($ID);
                }else{
                    $t_logger->info('Ticket '.$ID.' Does not match any known triggers for this queue. Removing queue tags.');
                    $this->_zendeskActions->removeTagFromTicket($ID,"csb2_pending_queue");
                }
                $t_logger->info("******************************************************************************");
                $t_logger->info("\n\n");
            }
            $t_logger->info("#####Pending Queue Finished##########################################");
            $t_logger->info("\n\n\n");
        }catch (\Exception $e) {
            $t_logger->info("//////////////////////////////////////");
            $t_logger->info('Caught Exception Processing Pending Ticket Queue: '. $e->getMessage());
            $t_logger->info("//////////////////////////////////////");
        }


        try{
            $t_logger->info("\n\n\n");
            $t_logger->info("#####Processing Open Ticket Queue####################################");
//PROCESSING OPEN TICKETS################################################################################
            $tickets = $this->_zendeskActions->searchZendesk('type:ticket status:open tags:csb2_open_queue -tags:rejected');
            $data = json_decode($tickets,true);
            foreach ($data['results'] as $ticket){
                $ID = ($ticket["id"]);
                settype($solvedDuplicateTicketIDs,"array");
                if (!empty($solvedDuplicateTicketIDs)){
                    if (in_array($ID,$solvedDuplicateTicketIDs)){
                        $t_logger->info("Ticket ".$ID." was closed in duplicate clean up. Skipping ticket.");
                        continue;
                    }
                }
                if ($this->_utils->Test() == false){
                    $tags = $ticket["tags"];
                    if (in_array("rejected",$tags)){
                        continue;
                    }
                }
                if ($this->_utils->Test() == true){
                    $tags = $ticket["tags"];
                    if (in_array("rejected",$tags)){
                        continue;
                    }
                    if (in_array("csb2_review_reject",$tags)){
                        continue;
                    }
                }

                $status = $ticket["status"];
                if ($status != 'open') {
                    continue;
                }
                $Subject = ($ticket["subject"]);
                $t_logger->info("\n\n");
                $t_logger->info("******************************************************************************");
                $t_logger->info("***".$Subject."***");
                $requesterID = ($ticket["requester_id"]);
                $requesterArry = $this->_zendeskActions->getUserByID($requesterID);
                $requesterEmail = $requesterArry["email"];
                if (is_null($requesterEmail)){
                    $requesterEmail = $requesterArry["name"];
                }
                if(str_contains($requesterEmail,"postmaster@")) {
                    $this->_zendeskActions->rejectTicket($ID, "All emails from postmaster addresses must be processed manually");
                    continue;
                }elseif(str_contains($requesterEmail,"@REDACTED.com")){
                    $this->_zendeskActions->rejectTicket($ID,"All emails from REDACTED.com must be processed manually");
                    continue;
                }elseif(str_contains($Subject,"[Cancellation Request]")){
                    $this->_agentActions->routeSupplierReplyCancel($ID);
                }elseif(str_contains($Subject,"[Return Request]")){
                    $this->_agentActions->routeSupplierReplyReturn($ID);
                }elseif(str_contains($Subject,"[Order Status Request]")) {
                    $this->_agentActions->routeSupplierReplyOrderStatus($ID);
                }elseif(str_contains($Subject,"Payment Transaction Failed Reminder")){
                    $this->_agentActions->PaymentTransactionFailedReminder($ID);
                }else{
                    $t_logger->info('Ticket '.$ID.' Does not match any known triggers for this queue. Removing queue tags.');
                    $this->_zendeskActions->removeTagFromTicket($ID,"csb2_open_queue");
                }
                $t_logger->info("******************************************************************************");
                $t_logger->info("\n\n");
            }
            $t_logger->info("#####Open Queue Finished############################################");
            $t_logger->info("\n\n\n");
        }catch (\Exception $e) {
            $t_logger->info("//////////////////////////////////////");
            $t_logger->info('Caught Exception Processing Open Ticket Queue: '. $e->getMessage());
            $t_logger->info("//////////////////////////////////////");
        }



        try{
            $t_logger->info("\n\n\n");
            $t_logger->info("#####Processing New Ticket Queue####################################");
//PROCESSING NEW TICKETS################################################################################
            $tickets = $this->_zendeskActions->searchZendesk('type:ticket tags:csb2_new_queue -tags:csb2_review_reject');
            $data = json_decode($tickets,true);
            foreach ($data['results'] as $ticket){
                $ID = ($ticket["id"]);
                settype($solvedDuplicateTicketIDs,"array");
                if (!empty($solvedDuplicateTicketIDs)){
                    if (in_array($ID,$solvedDuplicateTicketIDs)){
                        $t_logger->info("Ticket ".$ID." was closed in duplicate clean up. Skipping ticket.");
                        continue;
                    }
                }
                if ($this->_utils->Test() == false){
                    $tags = $ticket["tags"];
                    if (in_array("csb2_rejected",$tags)){
                        continue;
                    }
                    if (in_array("csb2_review_reject",$tags)){
                        continue;
                    }
                }
                if ($this->_utils->Test() == true){
                    $tags = $ticket["tags"];
                    if (in_array("rejected",$tags)){
                        continue;
                    }
                    if (in_array("csb2_review_reject",$tags)){
                        continue;
                    }
                }

                $status = $ticket["status"];
                if (!$this->_utils->Test()){
                    if ($status != 'new'){
                        continue;
                    }
                }
                $tags = $ticket["tags"];
                $Subject = ($ticket["subject"]);
                $Body = ($ticket["description"]);
                $FullTicketAsString = $Subject.$Body;
                $t_logger->info("\n\n");
                $t_logger->info("******************************************************************************");
                $t_logger->info("***".$Subject."***");
                $t_logger->info("Ticket ID: ".$ID);
                //if ($this->_utils->Test()){
                //    if(str_contains($requesterEmail,"tyler.polny@REDACTED.com")) {
                //        $this->_agentActions->routeNewSupplierEmail226($ID);
                //    }
                //}
                $requesterID = ($ticket["requester_id"]);
                $requesterArry = $this->_zendeskActions->getUserByID($requesterID);
                $requesterEmail = $requesterArry["email"];
                if (is_null($requesterEmail)){
                    $requesterEmail = $requesterArry["name"];
                }
                if(str_contains($requesterEmail,"postmaster@")) {
                    $this->_zendeskActions->rejectTicket($ID, "All emails from postmaster addresses must be processed manually");
                    continue;
                }elseif(str_contains($requesterEmail,"@REDACTED.com")){
                    $this->_zendeskActions->rejectTicket($ID,"All emails from REDACTED.com must be processed manually");
                    continue;
                }elseif(str_contains($requesterEmail,"@REDACTED")){
                    $this->_agentActions->routeNewSupplierEmail312($ID);
                }elseif(str_contains($requesterEmail,"REDACTED@REDACTED.com")){
                    $this->_agentActions->routeNewSupplierEmailTBond($ID);
                }elseif(str_contains($requesterEmail,"@REDACTED")){
                    $this->_agentActions->routeNewSupplierEmail281($ID);
                }elseif(str_contains($requesterEmail,"REDACTED")){
                    $this->_agentActions->routeNewSupplierEmail226($ID);
                }elseif(str_contains($requesterEmail,"REDACTED")){
                    $this->_agentActions->routeNewSupplierEmail310($ID);
                }elseif(str_contains($Subject,"Payment Transaction Failed Reminder")){
                    $this->_agentActions->PaymentTransactionFailedReminder($ID);

                }else{
                    $t_logger->info('Ticket '.$ID.' Does not match any known triggers for this queue. adding to Other Queue');
                    $this->_zendeskActions->removeTagFromTicket($ID,"csb2_new_queue");
                }
                $t_logger->info("******************************************************************************");
                $t_logger->info("\n\n");
            }
            $t_logger->info("#####New Queue Finished############################################");
            $t_logger->info("\n\n\n");

        }catch (\Exception $e) {
            $t_logger->info("//////////////////////////////////////");
            $t_logger->info('Caught Exception: '. $e->getMessage());
            $t_logger->info("//////////////////////////////////////");
        }


//Populating Ticket Queue Values################################################################################

        if($Run_PopulateTicketQueueValue or !$this->_utils->Test()){
            try{
                $t_logger->info("\n\n");
                $t_logger->info("#####Populating Ticket Queue Values For New Tickets####################################");
                $tickets = $this->_zendeskActions->searchZendesk('type:ticket status:new');
                $data = json_decode($tickets,true);

                $UrgentTickets = array();
                $SupplierTickets = array();
                $CancelTickets = array();
                $ReturnTickets = array();
                $OrderStatusTickets = array();
                $OtherTickets = array();
                if ($this->_utils->Test()){
                    $QueueFieldID = "1500011234061";
                }else{
                    $QueueFieldID = "1500011596361";
                }


                foreach ($data['results'] as $ticket){
                    $status = $ticket["status"];
                    if ($status != 'new'){
                        continue;
                    }
                    $tags = $ticket["tags"];
                    $requesterID = ($ticket["requester_id"]);
                    $requesterArry = $this->_zendeskActions->getUserByID($requesterID);
                    $requesterEmail = $requesterArry["email"];
                    if (is_null($requesterEmail)){
                        $requesterEmail = $requesterArry["name"];
                    }
                    $ID = ($ticket["id"]);
                    if(str_contains($requesterEmail,"postmaster@")) {
                        $this->_zendeskActions->rejectTicket($ID, "All emails from postmaster addresses must be processed manually");
                        continue;
                    }
                    $customFields = $ticket["custom_fields"];
                    foreach($customFields as $customField){
                        $fieldID = $customField["id"];
                        if ($fieldID == $QueueFieldID){
                            $QueueFieldValue = $customField["value"];
                        }
                    }
                    if(!is_null($QueueFieldValue)){
                        //field has already been set, can not set queue = null as a search perimeter.
                        continue;
                    }
                    $Subject = ($ticket["subject"]);
                    $Body = ($ticket["description"]);
                    $FullTicketAsString = $Subject.$Body;
                    $UrgentTriggersString =  $this->_adminUtils->getTranslationFromKey("URGENT_EMAIL_TRIGGERS");
                    $UrgentTriggers = explode(";",$UrgentTriggersString);
                    $SupplierTriggersString = $this->_adminUtils->getTranslationFromKey("SUPPLIER_EMAIL_SUBSTRINGS");
                    $SupplierTriggers = explode(";",$SupplierTriggersString);
                    $triggered = null;


                    foreach ($UrgentTriggers as $trigger){
                        if (str_contains($FullTicketAsString,$trigger)){
                            $t_logger->info("Ticket ID: ".$ID." queue set to URGENT - trigger: ".$trigger);
                            array_push($UrgentTickets,$ID);
                            //$this->_zendeskActions->updateTicketQueueAssignment($ID,"urgent");
                            $triggered = true;
                            break;
                        }
                    }
                    if ($triggered == true){
                        continue;
                    }
                    foreach ($SupplierTriggers as $trigger){
                        if (str_contains($requesterEmail,$trigger)){
                            $t_logger->info("Ticket ID: ".$ID." queue set to new_supplier_email - trigger: ".$trigger);
                            array_push($SupplierTickets,$ID);
                            //$this->_zendeskActions->updateTicketQueueAssignment($ID,"new_supplier_email");
                            $triggered = true;
                        }
                    }
                    if ($triggered == true){
                        continue;
                    }elseif ($triggered != true){
                        $t_logger->info("Ticket ID: ".$ID." queue set to other - trigger not found");
                        array_push($OtherTickets,$ID);
                        //$this->_zendeskActions->updateTicketQueueAssignment($ID,"other");
                    }

                }

            }catch (\Exception $e) {
                $t_logger->info("//////////////////////////////////////");
                $t_logger->info('Caught Exception: ' . $e->getMessage());
                $t_logger->info("//////////////////////////////////////");
            }

            try{
                $t_logger->info("\n\n");
                $t_logger->info("#####Populating Ticket Queue Values For Open Tickets####################################");
                $tickets = $this->_zendeskActions->searchZendesk('type:ticket status:open');
                $data = json_decode($tickets,true);
                foreach ($data['results'] as $ticket){
                    $status = $ticket["status"];
                    if ($status != 'new'){
                        continue;
                    }
                    $tags = $ticket["tags"];
                    $requesterID = ($ticket["requester_id"]);
                    $requesterArry = $this->_zendeskActions->getUserByID($requesterID);
                    $requesterEmail = $requesterArry["email"];
                    if (is_null($requesterEmail)){
                        $requesterEmail = $requesterArry["name"];
                    }
                    $ID = ($ticket["id"]);
                    if(str_contains($requesterEmail,"postmaster@")) {
                        $this->_zendeskActions->rejectTicket($ID, "All emails from postmaster addresses must be processed manually");
                        continue;
                    }
                    $Subject = ($ticket["subject"]);
                    if(str_contains($Subject,"[Cancellation Request]")){
                        $t_logger->info("Ticket ID: ".$ID." queue set to cancellation_request - trigger subject: [Cancellation Request]");
                        array_push($CancelTickets,$ID);
                        //$this->_zendeskActions->updateTicketQueueAssignment($ID,"cancellation_request");

                    }elseif(str_contains($Subject,"[Return Request]")){
                        $t_logger->info("Ticket ID: ".$ID." queue set to return_request - trigger subject: [Return Request]");
                        array_push($ReturnTickets,$ID);

                        //$this->_zendeskActions->updateTicketQueueAssignment($ID,"return_request");

                    }elseif(str_contains($Subject,"[Order Status Request]")){
                        $t_logger->info("Ticket ID: ".$ID." queue set to order_status_request - trigger subject: [Order Status Request]");
                        array_push($OrderStatusTickets,$ID);
                        //$this->_zendeskActions->updateTicketQueueAssignment($ID,"order_status_request");

                    }else{
                        $t_logger->info("Ticket ID: ".$ID." queue set to other - trigger not found");
                        array_push($OtherTickets,$ID);
                        //$this->_zendeskActions->updateTicketQueueAssignment($ID,"other");
                    }

                }
            }catch (\Exception $e) {
                $t_logger->info("//////////////////////////////////////");
                $t_logger->info('Caught Exception: ' . $e->getMessage());
                $t_logger->info("//////////////////////////////////////");
            }

            try{
                $t_logger->info("Making bulk API calls to Zendesk update ticket queue values...");
                //urgent
                if(!empty($UrgentTickets)){
                    $IDs = implode(",",$UrgentTickets);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => "our system has assigned this ticket to the queue: URGENT",
                                "public" => false
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $QueueFieldID,
                                    "value" => "urgent"
                                )
                            )
                        )
                    );
                    $job = $this->_zendeskActions->bulkUpdateTicketsWithArray($IDs,$update);
                    $job_status = json_decode($job,true);
                    $t_logger->info("job created to assign tickets to URGENT queue in bulk");
                    $t_logger->info("job status to follow...");
                    $t_logger->info(print_r($job_status,true));
                }else{
                    $t_logger->info("0 tickets added to URGENT queue");
                }

                //supplier
                if(!empty($SupplierTickets)){
                    $IDs = implode(",",$SupplierTickets);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => "our system has assigned this ticket to the queue: New Supplier Email",
                                "public" => false
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $QueueFieldID,
                                    "value" => "new_supplier_email"
                                )
                            )
                        )
                    );
                    $job = $this->_zendeskActions->bulkUpdateTicketsWithArray($IDs,$update);
                    $job_status = json_decode($job,true);
                    $t_logger->info("job created to assign tickets to New Supplier Email queue in bulk");
                    $t_logger->info("job status to follow...");
                    $t_logger->info(print_r($job_status,true));
                }else{
                    $t_logger->info("0 tickets added to New Supplier Email queue");
                }


                //cancel
                if(!empty($CancelTickets)){
                    $IDs = implode(",",$CancelTickets);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => "our system has assigned this ticket to the queue: Cancellation Request",
                                "public" => false
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $QueueFieldID,
                                    "value" => "cancellation_request"
                                )
                            )
                        )
                    );
                    $job = $this->_zendeskActions->bulkUpdateTicketsWithArray($IDs,$update);
                    $job_status = json_decode($job,true);
                    $t_logger->info("job created to assign tickets to Cancellation Request queue in bulk");
                    $t_logger->info("job status to follow...");
                    $t_logger->info(print_r($job_status,true));
                }else{
                    $t_logger->info("0 tickets added to Cancellation Request queue");
                }


                //return
                if(!empty($ReturnTickets)){
                    $IDs = implode(",",$ReturnTickets);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => "our system has assigned this ticket to the queue: Return Request",
                                "public" => false
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $QueueFieldID,
                                    "value" => "return_request"
                                )
                            )
                        )
                    );
                    $job = $this->_zendeskActions->bulkUpdateTicketsWithArray($IDs,$update);
                    $job_status = json_decode($job,true);
                    $t_logger->info("job created to assign tickets to Return Request queue in bulk");
                    $t_logger->info("job status to follow...");
                    $t_logger->info(print_r($job_status,true));
                }else{
                    $t_logger->info("0 tickets added to Return Request queue");
                }


                //order status
                if(!empty($OrderStatusTickets)){
                    $IDs = implode(",",$OrderStatusTickets);
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => "our system has assigned this ticket to the queue: Order Status Request",
                                "public" => false
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $QueueFieldID,
                                    "value" => "order_status_request"
                                )
                            )
                        )
                    );
                    $job = $this->_zendeskActions->bulkUpdateTicketsWithArray($IDs,$update);
                    $job_status = json_decode($job,true);
                    $t_logger->info("job created to assign tickets to Order Status Request queue in bulk");
                    $t_logger->info("job status to follow...");
                    $t_logger->info(print_r($job_status,true));
                }else{
                    $t_logger->info("0 tickets added to Order Status Request queue");
                }


                //other
                if(!empty($OtherTickets)){
                    $IDs = implode(",",$OtherTickets);
                    $body = $this->_adminUtils->getTranslationFromKey("REQUEST_TO_SUBMIT_TICKET_BODY");
                    $update = array(
                        "ticket" => array(
                            "comment" => array(
                                "body" => $body,
                                "public" => "true"
                            ),
                            "custom_fields" => array(
                                array(
                                    "id" => $QueueFieldID,
                                    "value" => "other"
                                )
                            )
                        )
                    );
                    $job = $this->_zendeskActions->bulkUpdateTicketsWithArray($IDs,$update);
                    $job_status = json_decode($job,true);
                    $t_logger->info("job created to assign tickets to other queue in bulk");
                    $t_logger->info("job status to follow...");
                    $t_logger->info(print_r($job_status,true));
                }else{
                    $t_logger->info("0 tickets added to other queue");
                }



            }catch (\Exception $e) {
                $t_logger->info("//////////////////////////////////////");
                $t_logger->info('Caught Exception: ' . $e->getMessage());
                $t_logger->info("//////////////////////////////////////");
            }

        }else{
            $t_logger->info("populate ticket queue value work flow has been disabled in test.");
        }



        $t_logger->info("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%");
        $t_logger->info("%%%%%ZenDeskEventAgent Finish%%%%%%%");
        $t_logger->info("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%");
    }

//suspended ticket queue trigger arrays
    public function getArrayRecoverEmails(){
        $ArrayRecoverEmailsstring = $this->_adminUtils->getTranslationFromKey("ARRAY_RECOVER_EMAILS");
        $ArrayRecoverEmails = explode(";",$ArrayRecoverEmailsstring);
        return $ArrayRecoverEmails;
    }
    public function getArrayRecoverSubjects(){
        $ArrayRecoverSubjectsstring = $this->_adminUtils->getTranslationFromKey("ARRAY_RECOVER_SUBJECTS");
        $ArrayRecoverSubjects = explode(";",$ArrayRecoverSubjectsstring);

        return $ArrayRecoverSubjects;
    }
    public function getArrayDeleteEmails(){
        $ArrayDeleteEmailsstring = $this->_adminUtils->getTranslationFromKey("ARRAY_DELETE_EMAILS");
        $ArrayDeleteEmails = explode(";",$ArrayDeleteEmailsstring);
        return $ArrayDeleteEmails;
    }
    public function getArrayDeleteSubjects(){
        $ArrayDeleteSubjectsstring = $this->_adminUtils->getTranslationFromKey("ARRAY_DELETE_SUBJECTS");
        $ArrayDeleteSubjects = explode(";",$ArrayDeleteSubjectsstring);
        return $ArrayDeleteSubjects;
    }
}
