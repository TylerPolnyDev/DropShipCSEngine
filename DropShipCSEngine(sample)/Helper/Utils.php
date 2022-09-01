<?php
/**
 * Copyright (c) 2022, Tyler Polny
 * All rights reserved.
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree.
 */
namespace REDACTED\OrderManagement\Helper;


class Utils
{
    protected $_orderRepository;
    protected $_searchCriteriaBuilder;
    public function __construct(\Magento\Sales\Model\OrderRepository $orderRepository,
                                \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder)
    {
        $this->_orderRepository = $orderRepository;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
    }
    /**
     * called any time a function needs to determine if this is a test run or prod run.
     * Changing the below value to "true" will:
     * set the point of contact to all functions as "tyler.polny96@gmail.com"
     * point our system to the zendesk sandbox.
     * allow "rejected" tickets to be processed
     * Changing the below value to "false" will:
     * allow the point of contact to be the customer, supplier, or department intended for full functionality
     * point our system to our Zendesk Production domain
     * will direct our system to skip tickets tagged "rejected"
     * @return bool
     */
    public function Test(){
        return true;
    }

    /**
     * @return string correct ID for order owner Whiteglove depending on TEST or PROD run
     */
    public function getWhiteGloveID(){
        if ($this->Test()){
            return "33918";
        }else{
            return "37729";
        }
    }
    /**
     * @return string correct ID for order owner Business Recovery depending on TEST or PROD run
     */
    public function getBusinessRecoveryID(){
        if ($this->Test()){
            return "34003";
        }else{
            return "37731";
        }
    }

    /**
     * @param $order object PO of order you need supplier order number for (no preceeeding 000's)
     * @return string supplier order number if available, NULL if not available.
     */
    public function getSupplierOrderNumberFromOrder($order){
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSVerbose.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        try {
            $attributes = $order->getExtensionAttributes()->getAmastyOrderAttributes();
            $supplierOrderNumber = null;
            if (!empty($attributes)) {
                foreach ($attributes as $attribute) {
                    if ($attribute->getAttributeCode() == 'supplier_order_number') {
                        $supplierOrderNumber = $attribute->getValue();
                    }
                }
            }
            return $supplierOrderNumber;

        } catch (\Exception $e) {
            $t_logger->info('Caught exception: ' . $e->getMessage());
        }
    }

    /**
     * @param $sku string the full sku including "-" and prefix that you would like to check availability on.
     * @param $zipCode string the 5 digit zip code that the sku would be shipped to
     * @return
     */
    public function GetExternalSkuAvailability($sku,$zipCode){
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSVerbose.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        try{
            //BELOW FUNCTION USED CALL TO PROPRIETARY SYSTEM. URL REDACTED AND FUNCTION UPDATED TO RETURN NO AVAIL

            //$curl = curl_init();
            //curl_setopt_array($curl, array(
            //    CURLOPT_URL => 'REDACTED',
            //    CURLOPT_RETURNTRANSFER => true,
            //    CURLOPT_ENCODING => '',
            //    CURLOPT_MAXREDIRS => 10,
            //    CURLOPT_TIMEOUT => 0,
            //    CURLOPT_FOLLOWLOCATION => true,
            //    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            //    CURLOPT_CUSTOMREQUEST => 'GET',
            //));

            //$response = curl_exec($curl);
            //if((str_starts_with($response,"###ERROR"))||(str_starts_with($response,"###ALERT"))){
                //return "NO AVAILABILITY";
            //}
            //return $response;
            return "NO AVAILABILITY";

        }catch (\Exception $e) {
            $t_logger->info('Caught exception: ' . $e->getMessage());
        }
    }

    /**
     * @param $incrementID
     * @param $zipCode
     * @return false|string
     */
    public function GetExternalOrderStatus($incrementID,$zipCode){
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSVerbose.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        try{

            //BELOW FUNCTION MADE CALL TO PROPRIETARY SYSTEM. REDACTED URL AND HAD FUNCTION RETURN NO ORDER STATUS
            //$curl = curl_init();
            //curl_setopt_array($curl, array(
            //    CURLOPT_URL => 'REDACTED',
            //    CURLOPT_RETURNTRANSFER => true,
            //    CURLOPT_ENCODING => '',
            //    CURLOPT_MAXREDIRS => 10,
            //    CURLOPT_TIMEOUT => 0,
            //    CURLOPT_FOLLOWLOCATION => true,
            //    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            //    CURLOPT_CUSTOMREQUEST => 'GET',
            //));

            //$response = curl_exec($curl);
            //$t_logger->info("raw json response:");
            //$t_logger->info($response);

            //curl_close($curl);
            //if((str_contains($response,"###ERROR"))||(str_contains($response,"###ALERT"))){
            //    return "NO ORDER STATUS";
            //}
            //if(str_ends_with($response,".")){
            //    $response = mb_substr($response,0,-1);
            //}
            //return $response;

            return "NO ORDER STATUS";
        }catch (\Exception $e) {
            $t_logger->info('Caught exception: ' . $e->getMessage());
        }
    }


    /**
     * searches a string for a increment ID. If found confirms that it is a valid order in our system.
     * note: this is an improved regex that will be used in the future once we are done porting.
     * @param string $string text we are parsing the PO# from
     * @return string the PO# found excluding preceding 0's
     */
    public function ParsePO_future_implemention($string){
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tyler.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        $t_logger->info('ParsePO function fired');
        try{
            if (preg_match('/(?:[\D]+)(?:[\s]*)(?:[#]?)(?:[0]*)([1][3-9][0-9]{4})(?:[\s]?)(?:[.]?)/',$string)){
                preg_match('/(?:[\D]+)(?:[\s]*)(?:[#]?)(?:[0]*)([1][3-9][0-9]{4})(?:[\s]?)(?:[.]?)/',$string,$matches);
            }
            if(!empty($matches)){
                $purchseOrderNum = $matches[1];

            }else{
                $purchseOrderNum = null;
            }
            $t_logger->info('ParsePO function finished PO#: '.$purchseOrderNum);
            return $purchseOrderNum;


        }catch (\Exception $e) {
            $t_logger->info('Caught exception: ' . $e->getMessage());
        }
    }

    /**
     * searches a string for a increment ID. If found confirms that it is a valid order in our system.
     * @param string $string text we are parsing the PO# from
     * @return string the PO# found excluding preceding 0's
     */
    public function ParsePO($string){
//TODO: rather than regex we should call Magento to get a list of all po# for orders placed in the last few months
//TODO: it would search a string and return any number strings in the text that match an ID in the list.

        //$writer = new \Zend\Log\Writer\Stream(BP . '/var/log/test.log');
        //$t_logger = new \Zend\Log\Logger();
        //$t_logger->addWriter($writer);
        try{
            if (preg_match('/(?:[0]{3})([1-3][0-9]{5})/',$string)){
                preg_match('/(?:[0]{3})([1-3][0-9]{5})/',$string,$matches);
            }elseif (preg_match('/(?:[0])([1-3][0-9]{5})/',$string)){
                preg_match('/(?:[0])([1-3][0-9]{5})/',$string,$matches);
            }elseif (preg_match('/(?:\s)([1-3][0-9]{5})(?:\s)/',$string)){
                preg_match('/(?:\s)([1-3][0-9]{5})(?:\s)/',$string,$matches);
            }elseif (preg_match('/(?:\s)([1-3][0-9]{5})(?:[.])/',$string)){
                preg_match('/(?:\s)([1-3][0-9]{5})(?:[.])/',$string,$matches);
            }
            if(!empty($matches)){
                $testPurchaseOrderNum = $matches[1];
                //$t_logger->info("test PO #");
                //$t_logger->info($testPurchaseOrderNum);
                $this->_searchCriteriaBuilder->addFilter('increment_id', (sprintf("%'.09d", $testPurchaseOrderNum)));
                $searchCriteria = $this->_searchCriteriaBuilder->create();
                $orderList = $this->_orderRepository->getList($searchCriteria);
                if ($orderList->getTotalCount() != 0){
                    //$t_logger->info("Test po passed");
                    $purchaseOrderNum = $testPurchaseOrderNum;

                }else{
                    //$t_logger->info("Test po failed");
                    $purchaseOrderNum = null;
                }
            }else{
                //$t_logger->info("no matches found");
                $purchaseOrderNum = null;
            }
            return $purchaseOrderNum;
        }catch (\Exception $e) {
            //$t_logger->info('Caught exception: ' . $e->getMessage());
        }
    }

    /**
     * confirms that the date provided is between today and today +1year
     * @param string $date
     * @return boolean
     */
    public function checkDateForETAuse($date){
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSVerbose.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        $testDate = date_create($date);
        $testDate = date_format($testDate,'Y-m-d');
        $aYearFromToday = date('Y-m-d', strtotime('+1 year'));
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $t_logger->info("Tomorrow = ".$tomorrow." a year from today = ".$aYearFromToday." Test date = ".$testDate);
        if ($tomorrow<$testDate && $testDate<$aYearFromToday){
            $t_logger->info("Date located is between today and a year from now.");
            return true;
        }else
            $t_logger->info("Date located is in the past or over a year out.");
        return false;
    }

    /**
     * Parses a string to find a date, tests using checkDateForETAuse.
     * @param string $string
     * @return string $date in the format it was in when parsed if found
     * else returns null on fails
     */
    public function ParseDate($string){
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSVerbose.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        try{
            $t_logger->info("string we are searching for a date in:");
            $t_logger->info($string);

            if ($string == null){
                return null;
            }
            if (str_contains($string,"Valid through July 25, 2021")){
                $string = str_replace("Valid through July 25, 2021","",$string);
            }
            if (preg_match("/\bJanuary\b | \bFebruary\b | \bMarch\b | \bApril\b | \bMay\b | \bJune\b | \bJuly\b | \bAugust\b | \bSeptember\b | \bOctober\b | \bNovember\b | \bDecember\b/",$string)){
                preg_match("/\bJanuary\b | \bFebruary\b | \bMarch\b | \bApril\b | \bMay\b | \bJune\b | \bJuly\b | \bAugust\b | \bSeptember\b | \bOctober\b | \bNovember\b | \bDecember\b/",$string,$matches);
                $month = $matches[0];
                $t_logger->info("month parsed: ");
                $t_logger->info("[".$month."]");
                if(preg_match("/($month)([0-9]{2},\s[0-9]{4})/",$string)){
                    preg_match("/($month)([0-9]{2},\s[0-9]{4})/",$string,$matches);
                    //$dayYear = $matches[0];
                    //$testDate = $month.$dayYear;
                    $testDate = $matches[0];
                    if ($this->checkDateForETAuse($testDate)){
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif(preg_match("/($month)([0-9]{2},[0-9]{4})/",$string)){
                    preg_match("/($month)([0-9]{2},[0-9]{4})/",$string,$matches);
                    //$dayYear = $matches[0];
                    //$testDate = $month.$dayYear;
                    $testDate = $matches[0];
                    if ($this->checkDateForETAuse($testDate)){
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif(preg_match("/($month)([0-9],\s[0-9]{4})/",$string)) {
                    preg_match("/($month)([0-9],\s[0-9]{4})/", $string, $matches);
                    //$dayYear = $matches[0];
                    //$testDate = $month.$dayYear;
                    $testDate = $matches[0];
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }

                }elseif(preg_match("/($month)([0-9],[0-9]{4})/",$string)){
                    preg_match("/($month)([0-9],[0-9]{4})/",$string,$matches);
                    $dayYear = $matches[0];
                    $testDate = $month.$dayYear;
                    if ($this->checkDateForETAuse($testDate)){
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif(preg_match("/($month)([1-2][0-9]\s|\s3[0-1]\s)/",$string)) {
                    preg_match("/($month)([1-2][0-9]\s|\s3[0-1]\s)/", $string, $matches);
                    $day = $matches[0];
                    if (preg_match("/202[0-9]/",$string)){
                        preg_match("/202[0-9]/",$string,$matches);
                        $year = $matches[0];
                        $testDate = $day.$year;
                        if ($this->checkDateForETAuse($testDate)) {
                            $date = $testDate;
                            $t_logger->info("ParseDate has returned: [".$date."]");
                            return $date;
                        }
                    }
                }elseif(preg_match("/($month)([1-2][0-9]\s|\s3[0-1],)/",$string)) {
                    preg_match("/($month)([1-2][0-9]\s|\s3[0-1],)/", $string, $matches);
                    $day = $matches[0];
                    if (preg_match("/202[0-9]/",$string)){
                        preg_match("/202[0-9]/",$string,$matches);
                        $year = $matches[0];
                        $testDate = $day.$year;
                        if ($this->checkDateForETAuse($testDate)) {
                            $date = $testDate;
                            $t_logger->info("ParseDate has returned: [".$date."]");
                            return $date;
                        }
                    }
                }
            }
            if (str_contains($string,"/")) {
                if (preg_match('/[0-9]{2}\/[0-9]{2}\/[0-9]{4}/',$string)){
                    $t_logger->info("Regex triggered for [0-9]{2}\/[0-9]{2}\/[0-9]{4}");
                    preg_match('/[0-9]{2}\/[0-9]{2}\/[0-9]{4}/',$string,$matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)){
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/[0-9]{2}\/[0-9]{2}\/[0-9]{2}/',$string)) {
                    preg_match('/[0-9]{2}\/[0-9]{2}\/[0-9]{2}/', $string, $matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/[0-9]\/[0-9]{2}\/[0-9]{4}/',$string)) {
                    preg_match('/[0-9]\/[0-9]{2}\/[0-9]{4}/', $string, $matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/[0-9]\/[0-9]\/[0-9]{4}/',$string)) {
                    preg_match('/[0-9]\/[0-9]\/[0-9]{4}/', $string, $matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/[0-9]\/[0-9]{2}\/[0-9]{2}/',$string)) {
                    preg_match('/[0-9]\/[0-9]{2}\/[0-9]{2}/', $string, $matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/[0-9]\/[0-9]\/[0-9]{2}/',$string)) {
                    preg_match('/[0-9]\/[0-9]\/[0-9]{2}/', $string, $matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/(?:\s)([0-9]{2}\/[0-9]{2})/',$string)) {
                    preg_match('/(?:\s)([0-9]{2}\/[0-9]{2})/', $string, $matches);
                    $yearInt = date("Y");
                    $DayMonth = $matches[0];
                    $testDate = $DayMonth."/".$yearInt;
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/([0-9]{2}\/[0-9]{2})/',$string)) {
                    preg_match('/([0-9]{2}\/[0-9]{2})/', $string, $matches);
                    $yearInt = date("Y");
                    $DayMonth = $matches[0];
                    $testDate = $DayMonth."/".$yearInt;
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/([0-9]{2}\/[0-9])/',$string)) {
                    preg_match('/([0-9]{2}\/[0-9])/', $string, $matches);
                    $yearInt = date("Y");
                    $DayMonth = $matches[0];
                    $testDate = $DayMonth."/".$yearInt;
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/([0-9]\/[0-9]{2})/',$string)) {
                    preg_match('/([0-9]\/[0-9]{2})/', $string, $matches);
                    $yearInt = date("Y");
                    $DayMonth = $matches[0];
                    $testDate = $DayMonth."/".$yearInt;
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/([0-9]\/[0-9])/',$string)) {
                    $t_logger->info("Regex triggered for ([0-9]\/[0-9])");
                    preg_match('/([0-9]\/[0-9])/', $string, $matches);
                    $yearInt = date("Y");
                    $DayMonth = $matches[0];
                    $testDate = $DayMonth."/".$yearInt;
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }
            }if (str_contains($string,"-")) {
                $t_logger->info("Regex triggered for - search");
                if (preg_match('/[0-9]{2}-[0-9]{2}-[0-9]{4}/', $string)) {
                    preg_match('/[0-9]{2}-[0-9]{2}-[0-9]{4}/', $string, $matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', $string)) {
                    preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', $string, $matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }elseif (preg_match('/[0-1][0-9]-[0-9]{2}-[0-9]{2}/', $string)) {
                    preg_match('/[0-1][0-9]-[0-9]{2}-[0-9]{2}/', $string, $matches);
                    $testDate = $matches[0];
                    $t_logger->info("test date:");
                    $t_logger->info($testDate);
                    if ($this->checkDateForETAuse($testDate)) {
                        $date = $testDate;
                        $t_logger->info("ParseDate has returned: [".$date."]");
                        return $date;
                    }
                }
            }else{
                $t_logger->info("ParseDate was not able to locate a date in the provided string");
                return null;
            }
        }catch (\Exception $e) {
            $t_logger->info('Caught exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * parse substring from string using the substring before and after the target
     * @param string $string string we are searching in (haystack)
     * @param string $subBefore substring that appears directly before the desired string
     * @param string $subAfter substring that appears directly after the desired string
     * @return string desired string (needle)
     */
    public function findSubStr($string,$subBefore,$subAfter){
        $subStrRaw = substr($string,strpos($string,$subBefore)+strlen($subBefore));
        $subStrBoom = explode($subAfter,$subStrRaw,2);
        $subStringDirty = $subStrBoom[0];
        $subString = trim($subStringDirty);
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/CSVerbose.log');
        $t_logger = new \Zend\Log\Logger();
        $t_logger->addWriter($writer);
        return $subString;
    }

    public function deleteAllBetween($beginning, $end, $string)
    {
        $beginningPos = strpos($string, $beginning);
        $endPos = strpos($string, $end);
        if ($beginningPos === false || $endPos === false) {
            return $string;
        }
        $textToDelete = substr($string, $beginningPos, ($endPos + strlen($end)) - $beginningPos);

        return $this->deleteAllBetween($beginning, $end, str_replace($textToDelete, '', $string)); // recursion to ensure all occurrences are replaced

    }

    /**
     * parse substring from string using the substring before and after the target
     * @param string $string string we are searching in (haystack)
     * @param string $subBefore substring that appears directly before the desired string
     * @return string desired string (needle)
     */
    public function findAllAfterSubStr($string,$subBefore){
        $subStr = substr(strtolower($string),strpos(strtolower($string),$subBefore)+1);
        return $subStr;
    }
}
