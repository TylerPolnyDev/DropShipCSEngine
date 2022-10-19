<?php 
namespace REDACTED\OrderManagement\Api;
 
 
interface SubmitCommentInterface {


	/**
	 * GET for Post api
	 * @param string $orderId
     * @param string $body
	 * @return string
	 */
	
	public function submitComment($orderId, $body);
}
