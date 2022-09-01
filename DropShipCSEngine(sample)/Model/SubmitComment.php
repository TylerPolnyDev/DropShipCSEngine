<?php
namespace Veratics\OrderManagement\Model;


class SubmitComment {

    protected $_order;
    protected $_historyRepository;
    protected $_historyFactory;

     public function __construct(
            \Magento\Sales\Api\Data\OrderInterface $order,
            \Magento\Sales\Api\OrderStatusHistoryRepositoryInterface $historyRepository,
            \Magento\Sales\Model\Order\Status\HistoryFactory $historyFactory) {
        $this->_order = $order;
        $this->_historyRepository = $historyRepository;
        $this->_historyFactory = $historyFactory;
    }

    
    /**
     * GET for Post api
     * @param string $orderId
     * @param string $body
     * @return string
     */

    public function submitComment($orderId, $body){
$writer = new \Zend\Log\Writer\Stream(BP . '/var/log/josh.log');
$j_logger = new \Zend\Log\Logger();
$j_logger->addWriter($writer);
$j_logger->info('starting add comment');
$j_logger->info('params: '.$orderId.': '.$body);

        $order = $this->_order->loadByIncrementId(sprintf("%'.09d", $orderId));
        $order->addStatusToHistory($order->getStatus(), $body, true);
        $order->save();
try{
        //$this->_state->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $orderCommentSender = $objectManager->create('Magento\Sales\Model\Order\Email\Sender\OrderCommentSender');
        $orderCommentSender->send($order, true, 'Here is a comment that will be displayed in the email');
}
catch(\Exception $e){
$j_logger->info('EX: '.$e->getMessage());
    }

        /*
        $history = $this->_historyFactory->create();
        $history->setParentId($order->getId())
            ->setComment($body)
            ->setIsCustomerNotified(1)
            ->setEntityName('order')
            ->setStatus($order->getStatus());

        $this->_historyRepository->save($history);
        */

$j_logger->info('returning: '.$order->getCustomerEmail());
        return $order->getCustomerEmail();
        
        
        
    }
}
