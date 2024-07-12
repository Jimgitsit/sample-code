<?php
/**
 * @category     Kurufootwear
 * @package      Kurufootwear\Framework
 * @author       Jim McGowen <jim@kurufootwear.com>
 * @copyright    Copyright (c) 2020 KURU Footwear. All rights reserved.
 */

namespace Kurufootwear\Rma\Model;

use Aheadworks\Rma\Model\Request as AwRequest;
use Aheadworks\Rma\Model\Request\IncrementIdGenerator;
use Exception;
use Magento\Framework\Exception\IntegrationException;
use Magento\Framework\Exception\NoSuchEntityException;
use \Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Aheadworks\Rma\Model\CustomFieldFactory;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Catalog\Api\ProductRepositoryInterface;
use \Mirasvit\Rewards\Helper\Balance as RewardsBalanceHelper;
use \Magento\Backend\Model\UrlInterface;
use Aheadworks\Rma\Model\Request\Validator as RequestValidator;
use Kurufootwear\Rma\Model\Service\RequestService;
use Aheadworks\Rma\Api\CustomFieldRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Kurufootwear\Rma\Model\ResourceModel\Request as ResourceRequest;

/**
 * Class Request
 *
 * @method int getStatusId()
 * @method void setStatusId(int $statusId)
 * 
 * @package Kurufootwear\Rma\Model
 */
class Request extends AwRequest
{
    /**
     * @var InspectionFactory
     */
    protected $inspectionFactory;
    
    /**
     * @var Inspection
     */
    protected $inspection = null;
    
    /**
     * @var CustomerExchangeFactory
     */
    protected $customerExchangeFactory;
    
    /**
     * @var CustomerExchange
     */
    protected $customerExchange = null;
    
    /**
     * @var CustomFieldFactory
     */
    protected $customFieldFactory;
    
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;
    
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderItemRepository;
    
    /**
     * @var RewardsBalanceHelper
     */
    protected $rewardsBalanceHelper;
    
    /**
     * @var UrlInterface
     */
    protected $urlInterface;

    /**
     * @var RequestService
     */
    protected $requestService;
    
    /**
     * @var CustomFieldRepositoryInterface
     */
    protected $customFieldRepository;

    /**
     * @var SearchCriteriaBuilder 
     */
    protected $searchCriteriaBuilder;
    
    /**
     * @var ResourceRequest
     */
    protected $resourceRequest;

    /**
     * Request constructor.
     * @param  Context  $context
     * @param  Registry  $registry
     * @param  OrderItemRepositoryInterface  $orderItemRepository
     * @param  InspectionFactory  $inspectionFactory
     * @param  CustomerExchangeFactory  $customerExchangeFactory
     * @param  CustomFieldFactory  $customFieldFactory
     * @param  ProductRepositoryInterface  $productRepository
     * @param  RewardsBalanceHelper  $rewardsBalanceHelper
     * @param  UrlInterface  $urlInterface
     * @param  RequestService  $requestService
     * @param  RequestValidator  $validator
     * @param  IncrementIdGenerator  $incrementIdGenerator
     * @param  CustomFieldRepositoryInterface  $customFieldRepository
     * @param  SearchCriteriaBuilder  $searchCriteriaBuilder
     * @param  AbstractResource|null  $resource
     * @param  AbstractDb|null  $resourceCollection
     * @param  ResourceRequest  $resourceRequest
     * @param  array  $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        OrderItemRepositoryInterface $orderItemRepository,
        InspectionFactory $inspectionFactory,
        CustomerExchangeFactory $customerExchangeFactory,
        CustomFieldFactory $customFieldFactory,
        ProductRepositoryInterface $productRepository,
        RewardsBalanceHelper $rewardsBalanceHelper,
        UrlInterface $urlInterface,
        RequestService $requestService,
        RequestValidator $validator,
        IncrementIdGenerator $incrementIdGenerator,
        CustomFieldRepositoryInterface $customFieldRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        ResourceRequest $resourceRequest,
        array $data = []
    ) {
        $this->inspectionFactory = $inspectionFactory;
        $this->customerExchangeFactory = $customerExchangeFactory;
        $this->customFieldFactory = $customFieldFactory;
        $this->productRepository = $productRepository;
        $this->orderItemRepository = $orderItemRepository;
        $this->rewardsBalanceHelper = $rewardsBalanceHelper;
        $this->urlInterface = $urlInterface;
        $this->requestService = $requestService;
        $this->customFieldRepository = $customFieldRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->resourceRequest = $resourceRequest;
        
        parent::__construct(
            $context,
            $registry,
            $validator,
            $incrementIdGenerator,
            $resource,
            $resourceCollection,
            $data
        );
    }
    
    /**
     * Returns the Inspection associated with this request or null if there isn't one.
     * @param bool $forceReload
     *
     * @return Inspection
     */
    public function getInspection($forceReload = false)
    {
        if (empty($this->inspection) || $forceReload) {
            $inspection = $this->inspectionFactory->create()->loadByRequestId($this->getId());
            if (!empty($inspection->getId())) {
                $this->inspection = $inspection;
            }
        }
        return $this->inspection;
    }
    
    /**
     * Returns the customer exchange record for this RMA.
     * @return null|CustomerExchange
     */
    public function getCustomerExchange()
    {
        if (empty($this->customerExchange)) {
            $customerExchange = $this->customerExchangeFactory->create()->loadByRequestId($this->getId());
            if (!empty($customerExchange->getId())) {
                $this->customerExchange = $customerExchange;
            }
        }
        return $this->customerExchange;
    }
    
    /**
     * Sets the value of a custom field for this request.
     * $customField can be an id or an object
     * @param $customField int|\Aheadworks\Rma\Model\CustomField The CustomField or it's ID.
     * @param $value int|string|array The ID or the value to set the custom field to.
     * For multiselects this will be an array of IDs or values.
     * @throws Exception
     */
    public function setCustomFieldValue($customField, $value)
    {
        if (is_numeric($customField)) {
            $this->searchCriteriaBuilder->addFilter('id', $customField);
            $customField = $this->customFieldRepository->getList($this->searchCriteriaBuilder->create())->getItems()[0];
        }
        
        $customFieldId = $customField->getId();
        
        $customFields = $this->getCustomFields();
        if ($customFields !== null) {
            foreach ($customFields as $index => $customFieldValue) {
                if ($customFieldValue->getFieldId() == $customFieldId) {
                    $customFieldValue->setValue($value);
                    break;
                }
            }
        }
    }
    
    /**
     * @param string $name The name or identifier of a CustomField
     * @param int|string|array $value The ID or the value to set the custom field to.
     * For multiselects this will be an array of IDs or values.
     *
     * @throws Exception
     */
    public function setCustomFieldValueByName($name, $value)
    {
        $customField = $this->getCustomFieldByName($name);
        $this->setCustomFieldValue($customField, $value);
    }
    
    /**
     * @param string $name The name or identifier of a CustomField
     *
     * @return \Aheadworks\Rma\Api\Data\CustomFieldInterface
     * @throws \Magento\Framework\Exception\LocalizedException|\Exception
     */
    public function getCustomFieldByName(
        $name
    ) {
        $customField = null;
        $this->searchCriteriaBuilder->addFilter('name', $name);
        $items = $this->customFieldRepository->getList($this->searchCriteriaBuilder->create())->getItems();
        if (count($items) == 0 || is_null($items[0]->getId())) {
            // Try by identifier
            $this->searchCriteriaBuilder->addFilter('identifier', $name);
            $items = $this->customFieldRepository->getList($this->searchCriteriaBuilder->create())->getItems();
            if (count($items) == 0 || is_null($items[0]->getId())) {
                throw new \Exception(__("Custom field '$name' does not exist."));
            } else {
                $customField = $items[0];
            }
        } else {
            $customField = $items[0];
        }
        
        return $customField;
    }
    
    /**
     * @param string $name The name or identifier of a CustomField
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCustomFieldValueByName($name)
    {
        $customFieldDef = $this->getCustomFieldByName($name);
        $customFields = $this->getCustomFields();
        foreach ($customFields as $customField) {
            if (is_array($customField)) {
                if ($customField['field_id'] == $customFieldDef->getId()) {
                    return $customField['value'];
                }
            }
            else {
                if ($customField->getFieldId() == $customFieldDef->getId()) {
                    return $customField->getValue();
                }
            }
        }
        
        return null;
    }
    
    /**
     * Return the RMA type ID.
     * @return int Returns one of CustomField::TYPE_*
     * @throws Exception
     */
    public function getTypeId()
    {
        $id = $this->getCustomFieldByName('type')->getId();
        $customFields = $this->getCustomFields();
        if ($customFields) {
            foreach ($customFields as $customField) {
                if ($customField->getFieldId() == $id) {
                    return $customField->getValue();
                }
            }
        } else {
            $typeId = $this->resourceRequest->getCustomFieldValueByName($this, 'type');
            if ($typeId) {
                return $typeId;
            }
        }
        return null;
    }
    
    /**
     * Set the RMA type.
     * @param $typeId int One of RequestService::REQUEST_TYPE_*
     * @return $this
     */
    public function setType($typeId)
    {
        $this->setCustomFieldValueByName('type', $typeId);
        return $this;
    }

    /**
     * Get type code
     *
     * @return null|string
     */
    public function getTypeCode()
    {
        return $this->requestService->getFieldOptionCodeById('type', $this->getTypeId());
    }

    /**
     * @return string
     */
    public function getAdminNotes()
    {
        return $this->getCustomFieldValueByName('admin_notes');
    }
    
    /**
     * @param $value
     *
     * @return $this
     */
    public function setAdminNotes($value)
    {
        $this->setCustomFieldValueByName('admin_notes', $value);
        return $this;
    }
    
    /**
     * Issues exchange reward points for this RMA. Creates or updates the
     * CustomerExchange. Throws an exception if the customer has already been issued points.
     * @param $createCustomerExchange bool
     * @param $rmaTypeLabel string The label to use for the points transaction comment.
     * @return int The number of points issued.
     * @throws IntegrationException|NoSuchEntityException
     */
    public function issueExchangePoints(
        bool $createCustomerExchange = true,
        string $rmaTypeLabel = 'exchange'
    ) {
        $points = $this->calcExchangePoints();
        if ($points == 0) {
            throw new IntegrationException(__('0 points calculated for RMA.'));
        }
        
        // Make the rewards points transaction
        $result = $this->rewardsBalanceHelper->changePointsBalance(
            $this->getCustomerId(),
            $points,
            'Points issued for ' . $rmaTypeLabel . ' RMA ' . $this->getIncrementId() . '.',
            false
        );
        if ($result === false) {
            throw new IntegrationException(__('Could not issue exchange points'));
        }
        
        if ($createCustomerExchange) {
            // Create a CustomerExchange record
            $customerExchange = $this->customerExchangeFactory->create()->loadByRequestId($this->getId());
            $customerExchange->setRequestId($this->getId());
            $customerExchange->setCustomerId($this->getCustomerId());
            $customerExchange->setPointsIssued($points);
            $customerExchange->setRmaComplete(false);
            $customerExchange->save();
    
            $this->customerExchange = $customerExchange;
        }
        
        return $points;
    }

    /**
     * Returns the reward points for this RMA based on the items.
     * Typically the customer will keep the points they earned from the original purchase
     * because when they make a new purchase the total will be $0 so they wont earn any
     * more points.
     *
     * @throws NoSuchEntityException
     *
     * @return int
     */
    protected function calcExchangePoints()
    {
        $points = 0;
        
        // Sanity check, RMA type must be an exchange or a warranty
        if ($this->getTypeId() == RequestService::REQUEST_TYPE_EXCHANGE ||
            $this->getTypeId() == RequestService::REQUEST_TYPE_WARRANTY) {
            
            // If we have an inspection the points will be based on the received items
            // otherwise it will be based on the request items.
            $inspection = $this->getInspection();
            $receivedItems = null;
            if (!empty($inspection)) {
                $receivedItems = $inspection->getReceivedItems();
            }
            
            /** @var \Kurufootwear\Rma\Model\Request\Item $requestItems */
            $requestItems = $this->getOrderItems();
            foreach ($requestItems as $requestItem) {
                if ($receivedItems === null || array_key_exists($requestItem->getItemId(), $receivedItems)) {
                    // Get the price from the product in case it changed since the purchase was made
                    $product = $this->productRepository->getById($requestItem->getProductId());
                    $points += $product->getPrice() * $requestItem->getQty() * 100;
                }
            }
        }
        
        return $points;
    }
    
    /**
     * Returns the URL for editing the RMA in the admin.
     * @return string
     */
    public function getAdminUrl()
    {
        if ($this->getId()) {
            return $this->urlInterface->getUrl('aw_rma_admin/rma/edit', ['id' => $this->getId()]);
        }
        
        return '';
    }
}
