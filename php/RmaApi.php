<?php
/**
 * @category     Kurufootwear
 * @package      Kurufootwear\Rma
 * @author       Jim McGowen <jim@kurufootwear.com>
 * @copyright    Copyright (c) 2021 KURU Footwear. All rights reserved.
 */

namespace Kurufootwear\Rma\Model;

use Kurufootwear\Rma\Api\RmaApiInterface;
use Kurufootwear\Rma\Model\Request\Item;
use Kurufootwear\Rma\Model\Source\Request\Status;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Aheadworks\Rma\Model\RequestRepository;
use Aheadworks\Rma\Model\ResourceModel\Request\CollectionFactory as RequestCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Controller\Result\JsonFactory;
use Kurufootwear\Rma\Model\Service\RequestService;
use Kurufootwear\Rma\Model\ResourceModel\ReturnFacility\CollectionFactory as ReturnFacilityCollectionFactory;
use Aheadworks\Rma\Model\StatusRepository;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Aheadworks\Rma\Model\CustomFieldRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Kurufootwear\Rma\Logger\ApiLogger;
use Kurufootwear\Rma\Helper\ReturnFacilityHelper;

/**
 * Class RmaApi
 * 
 * API interface for warehouse integration. 
 * 
 * The public methods in this class will only every be used for REST API calls 
 * expecting a JSON response. Hence they echo the JSON response and exit. This 
 * saves having to create a model for the response object.
 * 
 * @package Kurufootwear\Rma\Model
 */
class RmaApi implements RmaApiInterface
{
    /** @var float|int 7 days in seconds */
    const MAX_DATE_RANGE = 24 * 60 * 60 * 7;
    
    /** @var RestRequest  */
    protected $request;
    
    /** @var JsonSerializer  */
    protected $jsonSerializer;
    
    /** @var JsonFactory  */
    protected $jsonFactory;
    
    /** @var RequestRepository  */
    protected $requestRepository;
    
    /** @var ReturnFacilityCollectionFactory  */
    protected $returnFacilityCollectionFactory;
    
    /** @var StatusRepository  */
    protected $statusRepository;
    
    /** @var RequestCollectionFactory  */
    protected $requestCollectionFactory;
    
    /** @var OrderItemRepositoryInterface  */
    protected $orderItemRepository;
    
    /** @var CustomFieldRepository  */
    protected $customFieldRepository;
    
    /** @var String */
    protected $defaultReturnFacility;
    
    /** @var array ReturnFacility */
    protected $returnFacilities = [];
    
    /** @var SearchCriteriaBuilder  */
    protected $searchCriteriaBuilder;
    
    /** @var CustomField */
    protected $reasonField;
    
    /** @var CustomField */
    protected $warrantyReasonField;
    
    /** @var OrderResource  */
    protected $orderResource;
    
    /** @var InspectionFactory  */
    protected $inspectionFactory;
    
    /** @var DateTime  */
    protected $dateTime;
    
    /** @var ApiLogger  */
    protected $logger;
    
    protected $returnFacilityHelper;
    
    /**
     * RmaApi constructor.
     *
     * @param RestRequest $request
     * @param JsonSerializer $jsonSerializer
     * @param JsonFactory $jsonFactory
     * @param RequestRepository $requestRepository
     * @param ReturnFacilityCollectionFactory $returnFacilityCollectionFactory
     * @param StatusRepository $statusRepository
     * @param RequestCollectionFactory $requestCollectionFactory
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param CustomFieldRepository $customFieldRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderResource $orderResource
     * @param InspectionFactory $inspectionFactory
     * @param DateTime $dateTime
     * @param ApiLogger $logger
     */
    public function __construct(
        RestRequest $request,
        JsonSerializer $jsonSerializer,
        JsonFactory $jsonFactory,
        RequestRepository $requestRepository,
        ReturnFacilityCollectionFactory $returnFacilityCollectionFactory,
        StatusRepository $statusRepository,
        RequestCollectionFactory $requestCollectionFactory,
        OrderItemRepositoryInterface $orderItemRepository,
        CustomFieldRepository $customFieldRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderResource $orderResource,
        InspectionFactory $inspectionFactory,
        DateTime $dateTime,
        ApiLogger $logger,
        ReturnFacilityHelper $returnFacilityHelper
    ) {
        $this->request = $request;
        $this->jsonSerializer = $jsonSerializer;
        $this->jsonFactory = $jsonFactory;
        $this->requestRepository = $requestRepository;
        $this->returnFacilityCollectionFactory = $returnFacilityCollectionFactory;
        $this->statusRepository = $statusRepository;
        $this->requestCollectionFactory = $requestCollectionFactory;
        $this->orderItemRepository = $orderItemRepository;
        $this->customFieldRepository = $customFieldRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderResource = $orderResource;
        $this->inspectionFactory = $inspectionFactory;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
        $this->returnFacilityHelper = $returnFacilityHelper;
    }
    
    /**
     * @inheridoc
     */
    public function getRmaById()
    {
        try {
            $this->logger->info('Called getRmaById');
            if (is_array($this->request->getParams())) {
                $this->logger->info('Request data: ' . $this->jsonSerializer->serialize($this->request->getParams()));
            }
            
            $rmaId = (int)trim($this->request->getParam('id'));
            if (!empty($rmaId)) {
                $rma = null;
                try {
                    /** @var \Kurufootwear\Rma\Model\Request $rma */
                    $rma = $this->requestRepository->get($rmaId);
                }
                catch (NoSuchEntityException $e) {
                    $json = $this->jsonSerializer->serialize($this->getErrorData('ERROR_NOT_FOUND', "RMA not with ID $rmaId found"));
                    $this->logger->error($json);
                    echo $json;
                    exit();
                }
        
                $data['rma'] = $this->prepareData($rma);
            }
            else {
                $data = $this->getErrorData('ERROR_MISSING_PARAMETER', "Did not get required parameter 'id' from request.");
            }
    
            if (empty($data['error']['code'])) {
                $data = array_merge($this->getSuccessData(), $data);
            }
            
            $json = $this->jsonSerializer->serialize($data);
            $this->logger->info('Response data: ' . $json);
            
            echo $json;
            exit();
        }
        catch (\Exception $e) {
            $json = $this->jsonSerializer->serialize($this->getErrorData('UNCAUGHT_EXCEPTION', $e->getMessage()));
            $this->logger->error($json);
            echo $json;
            exit();
        }
    }
    
    /**
     * @inheridoc
     */
    public function getRmasByDate()
    {
        try {
            $this->logger->info('Called getRmaById');
            if (is_array($this->request->getParams())) {
                $this->logger->info('Request data: ' . $this->jsonSerializer->serialize($this->request->getParams()));
            }
            
            // Set timeout to 5 minutes
            set_time_limit(5 * 60);
            
            $startDate = trim($this->request->getParam('start'));
            $endDate = trim($this->request->getParam('end'));
            
            $valid = $this->validateDateRange($startDate, $endDate);
            if ($valid !== true) {
                $json = $this->jsonSerializer->serialize($this->getErrorData($valid['code'], $valid['message']));
                $this->logger->error($json);
                echo $json;
                exit();
            }
        
            $collection = $this->requestCollectionFactory->create()
                ->addFieldToFilter('created_at', ['gteq' => $startDate])
                ->addFieldToFilter('created_at', ['lteq' => $endDate])
                ->setPageSize(100);
            
            $type = $this->request->getParam('type');
            $typeFilters = $type === null ? [] : explode(',', $type);
            if (!empty($typeFilters)) {
                $collection->join(
                    ['cfv' => $collection->getTable('aw_rma_request_custom_field_value')],
                    'cfv.entity_id = main_table.id'
                );
                
                foreach ($typeFilters as $typeFilter) {
                    $typeId = '';
                    switch ($typeFilter) {
                        case 'return':
                            $typeId = RequestService::REQUEST_TYPE_RETURN;
                            break;
                        case 'exchange':
                            $typeId = RequestService::REQUEST_TYPE_EXCHANGE;
                            break;
                        case 'warranty':
                            $typeId = RequestService::REQUEST_TYPE_WARRANTY;
                            break;
                    }
        
                    $collection->addFieldToFilter('value', ['eq', $typeId]);
                }
            }
            
            $data = [];
            $pageCount = $collection->getLastPageNumber();
            $currentPage = 1;
            do {
                $collection->setCurPage($currentPage)->clear()->load();
                foreach ($collection as $rma) {
                    $data['rmas'][] = $this->prepareData($rma);
                }
                
                $currentPage++;
            } while ($currentPage <= $pageCount);
        
            if (empty($data['error']['code'])) {
                $data = array_merge($this->getSuccessData(), $data);
            }
    
            $json = $this->jsonSerializer->serialize($data);
            $this->logger->info('Response data: ' . substr($json, 0, 100) . '...');
    
            echo $json;
            exit();
        }
        catch (\Exception $e) {
            $json = $this->jsonSerializer->serialize($this->getErrorData('UNCAUGHT_EXCEPTION', $e->getMessage()));
            $this->logger->error($json);
            echo $json;
            exit();
        }
    }
    
    /**
     * @inheridoc
     */
    public function submitRmaInspection()
    {
        try {
            $this->logger->info('Called getRmaById');
            if (is_array($this->request->getParams())) {
                $this->logger->info('Request data: ' . $this->jsonSerializer->serialize($this->request->getRequestData()));
            }
            
            $reqData = $this->trimAll($this->request->getRequestData());
            if (empty($reqData)) {
                $json = $this->jsonSerializer->serialize($this->getErrorData('ERROR_INVALID_DATA', 'Missing or invalid POST data.'));
                $this->logger->error($json);
                echo $json;
                exit();
            }
            
            $valid = $this->validateInspectionData($reqData);
            if ($valid !== true) {
                $json = $this->jsonSerializer->serialize($this->getErrorData($valid['code'], $valid['message']));
                $this->logger->error($json);
                echo $json;
                exit();
            }
            
            try {
                $reqData['rma_id'] = trim($reqData['rma_id']);
                $rma = $this->requestRepository->get($reqData['rma_id']);
            }
            catch (NotFoundException $e) {
                $json = $this->jsonSerializer->serialize($this->getErrorData('ERROR_NOT_FOUND', 'RMA with ID ' . $reqData['rma_id'] . ' was not found.'));
                $this->logger->error($json);
                echo $json;
                exit();
            }
            
            // Create the RMA Inspection
            $inspection = $this->inspectionFactory->create()->loadByRequestId($reqData['rma_id']);
            $inspection->setRequestId($reqData['rma_id']);
            $inspection->setInspectedDate($this->dateTime->gmtDate());
            $orderItems = $rma->getOrderItems();
            $items = [];
            foreach ($reqData['items_received'] as $item) {
                $rmaItemId = $item['item_id'];
                $orderItemId = null;
                foreach ($orderItems as $orderItem) {
                    if ($orderItem->getId() == $rmaItemId) {
                        $orderItemId = $orderItem->getItemId();
                        break;
                    }
                }
                
                if ($orderItemId !== null) {
                    $items[$orderItemId] = [
                        'grade' => $item['grade'],
                        'qty' => $item['qty']
                    ];
                }
            }
            $inspection->setReceivedItems($items);
            if (count($items) == count($rma->getOrderItems()) && empty($reqData['other_skus'])) {
                $inspection->setItemsMatch(true);
            } else {
                $inspection->setItemsMatch(false);
            }
            
            $inspection->setInspectorInitials(strtoupper($reqData['inspector_initials']));
            $inspection->setOtherSkus($reqData['other_skus']);
            $inspection->setWarehouseNotes($reqData['warehouse_notes']);
            $inspection->save();
            
            // Change the status of the RMA to warehouse approved
            try {
                if ($rma->getStatusId() !== Status::WAREHOUSE_APPROVED) {
                    $rma->setStatusId(Status::WAREHOUSE_APPROVED);
                    $this->requestRepository->save($rma);
                }
            }
            catch(LocalizedException $e) {
                $json = $this->jsonSerializer->serialize($this->getErrorData('UNCAUGHT_EXCEPTION', $e->getMessage()));
                $this->logger->error($json);
                echo $json;
                exit();
            }
            
            $status = $this->statusRepository->get($rma->getStatusId());
            $data = [];
            $data['new_status'] = [
                'status_id' => $status->getId(),
                'status_label' => $status->getName()
            ];
            
            $data = array_merge($this->getSuccessData(), $data);
            $json = $this->jsonSerializer->serialize($data);
            $this->logger->info('Response data: ' . $json);
    
            echo $json;
            exit();
        }
        catch (\Exception $e) {
            $json = $this->jsonSerializer->serialize($this->getErrorData('UNCAUGHT_EXCEPTION', $e->getMessage()));
            $this->logger->error($json);
            echo $json;
            exit();
        }
    }
    
    /**
     * Validates inspection data and trims all strings in $data.
     * 
     * @param $data array Reference to request data array.
     * 
     * @return bool | array
     */
    protected function validateInspectionData($data)
    {
        $error = [];
        $error['code'] = 'ERROR_INVALID_DATA';
        
        if (empty($data['rma_id'])) {
            $error['message'] = 'Missing "rma_id"';
            return $error;
        }
    
        if (empty($data['inspector_initials'])) {
            $error['message'] = 'Missing "inspector_initials"';
            return $error;
        }
        
        if (strlen($data['inspector_initials']) > 3) {
            $error['message'] = 'Maximum length for "inspector_initials" is 3';
            return $error;
        }
    
        if (strlen($data['inspector_initials']) < 2) {
            $error['message'] = 'Minimum length for "inspector_initials" is 2';
            return $error;
        }
    
        if (!isset($data['items_received']) || !is_array($data['items_received'])) {
            $error['message'] = 'Missing "items_received"';
            return $error;
        }
    
        if (empty($data['inspector_initials'])) {
            $error['message'] = 'Missing "inspector_initials"';
            return $error;
        }
        
        foreach ($data['items_received'] as $item) {
            if (empty($item['item_id']) || empty($item['qty']) || empty($item['grade'])) {
                $error['message'] = 'Missing data in items_received: "item_id", "qty", "grade"';
                return $error;
            }
            
            if (!is_numeric($item['qty']) || (int)$item['qty'] > 99) {
                $error['message'] = 'Invalid "qty". Maximum value = 99';
                return $error;
            }
        }
        
        if ($data['other_skus'] == '' && empty($data['items_received'])) {
            $error['message'] = 'There must be "items_received" or a value for "other_skus"';
            return $error;
        }
        
        return true;
    }
    
    /**
     * Returns a structured array for the RMA.
     * 
     * @param $rma Request
     *
     * @return array[]
     */
    protected function prepareData($rma)
    {
        $data = [];
        
        try {
            $status = $this->statusRepository->get($rma->getStatusId());
            $data['status_id'] = $status->getId();
            $data['status_label'] = $status->getName();
        }
        catch (LocalizedException $e) {
            $data['status_id'] = '';
            $data['status_label'] = '';
        }
    
        $typeCode = $rma->getCustomFieldValueByName('type');
        $data['type'] = $this->getRmaTypeName($typeCode);
        
        $data['id'] = $rma->getId();
        $data['return_facility'] = $this->getReturnFacility($rma);
        $data['order_id'] = $rma->getOrderId();
        $data['order_increment_id'] = $this->getIncrementIdFromOrderId($rma->getOrderId());
        
        $data['items'] = [];
        $items = $rma->getOrderItems();
        foreach ($items as $item) {
            $data['items'][] = $this->prepareItemData($item);
        }
        
        return $data;
    }
    
    /**
     * Returns a structured array for the RMA item.
     * 
     * @param $item Item RMA request item to get the reason label from.
     *          Can be an instance of Item (which is what you get from the rmaRepository)
     *          or an array (which is what you get from an rmaCollection).
     *
     * @return array
     */
    protected function prepareItemData($item)
    {
        if (is_object($item)) {
            $orderItem = $this->orderItemRepository->get($item->getItemId());
            
            $data['item_id'] = $item->getId();
            $data['qty'] = $item->getQty();
        }
        else {
            $orderItem = $this->orderItemRepository->get($item['item_id']);
    
            $data['item_id'] = $item['id'];
            $data['qty'] = $item['qty'];
        }
    
        $data['sku'] = $orderItem->getSku();
        $data['reason_code'] = $this->getReasonLabel($item, true);
        
        return $data;
    }
    
    /**
     * Returns the RMA reason label for the RMA item.
     * 
     * @param $item Item RMA request item to get the reason label from.
     *          Can be an instance of Item (which is what you get from the rmaRepository) 
     *          or an array (which is what you get from an rmaCollection).
     * @param $getWarrantyReason bool If true and there is a value for warranty_reason 
     *          the warranty code will be returned. Default is false.
     *
     * @return string
     */
    protected function getReasonLabel($item, $getWarrantyReason = false)
    {
        $reasonLabel = '';
        
        try {
            if (empty($this->reasonField)) {
                $this->searchCriteriaBuilder->addFilter('identifier', 'reason');
                $this->reasonField = $this->customFieldRepository
                    ->getList($this->searchCriteriaBuilder->create())
                    ->getItems()[0];
            }
            
            if ($getWarrantyReason) {
                if (empty($this->warrantyReasonField)) {
                    $this->searchCriteriaBuilder->addFilter('identifier', 'warranty_reason');
                    $this->warrantyReasonField = $this->customFieldRepository
                        ->getList($this->searchCriteriaBuilder->create())
                        ->getItems()[0];
                }
            }
    
            if (is_object($item)) {
                $reason = $item->getCustomFieldValue($this->reasonField->getId());
                if (empty($reason)) {
                    $reason = $item->getCustomFieldValue($this->warrantyReasonField->getId());
                }
        
                return $reason;
            }
    
            if (!empty($item['custom_fields'][$this->reasonField->getId()]['value'])) {
                $optionId = $item['custom_fields'][$this->reasonField->getId()]['value'];
                foreach ($this->reasonField->getOptions() as $option) {
                    if ($option->getId() == $optionId) {
                        $reasonLabel = $option->getStorefrontLabel();
                        break;
                    }
                }
            }
            elseif ($getWarrantyReason && !empty($item['custom_fields'][$this->warrantyReasonField->getId()]['field_id'])) {
                $optionId = $item['custom_fields'][$this->warrantyReasonField->getId()]['value'];
                foreach ($this->warrantyReasonField->getOptions() as $option) {
                    if ($option->getId() == $optionId) {
                        $reasonLabel = $option->getStorefrontLabel();
                        break;
                    }
                }
            }
    
            return $reasonLabel;
        }
        catch (\Exception $e) {
            // Let it be blank for any exception
            return $reasonLabel;
        }
    }
    
    /**
     * Return structured array for error code and message.
     * 
     * @param $code string
     * @param $message string
     *
     * @return array
     */
    protected function getErrorData($code, $message)
    {
        $data = [];
        $data['success'] = false;
        $data['error']['code'] = $code;
        $data['error']['message'] = $message;
        
        return $data;
    }
    
    /**
     * Return structured array for success.
     * 
     * @return array
     */
    protected function getSuccessData()
    {
        $data = [];
        $data['success'] = true;
        $data['error']['code'] = '';
        $data['error']['message'] = '';
    
        return $data;
    }
    
    /**
     * Get the RMA type name from the type code.
     * 
     * @param $typeCode int
     *
     * @return string
     */
    protected function getRmaTypeName($typeCode)
    {
        switch ($typeCode) {
            case RequestService::REQUEST_TYPE_RETURN:
                return 'return';
            case RequestService::REQUEST_TYPE_EXCHANGE:
                return 'exchange';
            case RequestService::REQUEST_TYPE_WARRANTY:
                return 'warranty';
        }
    }
    
    /**
     * Get the return facility identifier for the RMA. Returns the default 
     * if the return facility is not set on the RMA.
     * 
     * @param $rma Request
     *
     * @return \Magento\Framework\DataObject
     */
    protected function getReturnFacility($rma)
    {
        $returnFacilityId = $rma->getCustomFieldValueByName('return_facility');
        if ($returnFacilityId === null) {
            if (empty($this->defaultReturnFacility)) {
                $this->defaultReturnFacility = $this->returnFacilityHelper->getDefaultReturnFacility()->getData('identifier');
            }
            return $this->defaultReturnFacility;
        }
        
        return $returnFacilityId;
    }
    
    /**
     * Returns the increment ID from an orderID.
     * 
     * (This is the fastest way to do this tht I could find)
     * 
     * @param $orderId
     *
     * @return string
     */
    protected function getIncrementIdFromOrderId($orderId)
    {
        $result = '';
    
        try {
            $adapter = $this->orderResource->getConnection();
            $select = $adapter->select();
            
            $bind = [':entity_id' => $orderId];
            $select->from($this->orderResource->getMainTable(), "increment_id")
                ->where('entity_id = :entity_id');
            
            $incrementId = $adapter->fetchOne($select, $bind);
            
            if (!empty($incrementId)) {
                $result = (string)$incrementId;
            }
        } 
        catch (\Exception $e) {
            $result = '';
        }
    
        return $result;
    }
    
    /**
     * Helper function to validate the start and end dates for getRmasByDate.
     * Returns true if the dates and range are valid.
     * Returns an error with the error code and message if not.
     * 
     * @param $startDate
     * @param $endDate
     *
     * @return bool|string[]
     */
    protected function validateDateRange($startDate, $endDate)
    {
        if (empty($startDate) || empty($endDate)) {
            return [
                'code' => 'ERROR_MISSING_PARAMETER',
                'message' => "Missing a required parameter: 'start' or 'end'"
            ];
        }
    
        $startTime = strtotime($startDate);
        $endTime = strtotime($endDate);
        if ($startTime === false || $endTime === false) {
            return [
                'code' => 'ERROR_INVALID_PARAMETER',
                'message' => "Invalid start or end value. Must be in the format YYYY-MM-DD HH:MM:SS."
            ];
        }
        
        $interval = $endTime - $startTime;
        if ($interval <= 0) {
            return [
                'code' => 'ERROR_INVALID_PARAMETER',
                'message' => "'start' must be less than 'end'"
            ];
        }
    
        if ($interval > self::MAX_DATE_RANGE) {
            $max = self::MAX_DATE_RANGE / 60 / 60;
            if ($max >= 24) {
                $max = self::MAX_DATE_RANGE / 60 / 60 / 24;
                $max .= $max > 1 ? ' days' : ' day';
            }
            else {
                $max .= $max > 1 ? ' hours' : ' hour';
            }
            
            return [
                'code' => 'ERROR_INVALID_PARAMETER',
                'message' => "Maximum range between 'start' and 'end' is $max"
            ];
        }
        
        return true;
    }
    
    /**
     * Helper to trim all strings in an array recursively.
     *
     * @param $data
     *
     * @return array|null
     */
    protected function trimAll($data)
    {
        if (!is_array($data)) {
            return null;
        }
        
        foreach ($data as &$value) {
            if (is_string($value)) {
                $value = trim($value);
            }
            
            if (is_array($value)) {
                $value = $this->trimAll($value);
            }
        }
        
        return $data;
    }
}
