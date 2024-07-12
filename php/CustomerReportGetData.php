<?php

namespace Kurufootwear\Tools\Console\Command\CustomerReport;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\State;
use Magento\Framework\File\Csv;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Aheadworks\Rma\Api\RequestRepositoryInterface;
use Kurufootwear\Rma\Model\CustomerExchangeFactory;

use Aheadworks\Rma\Model\RequestFactory;

class GetData extends Command
{
    protected $state;
    
    protected $csv;
    
    protected $orderItemRepository;
    
    protected $orderRepository;
    
    protected $productRepository;
    
    protected $attributeRepository;
    
    protected $requestFactory;
    
    protected $customerRepository;
    
    protected $searchCriteriaBuilder;
    
    protected $connection;
    
    protected $requestRepository;
    
    protected $customerExchangeFactory;
    
    const BATCH_SIZE = 10000;
    
    public function __construct(
        State $state,
        Csv $csv,
        OrderItemRepositoryInterface $orderItemRepository,
        OrderRepositoryInterface $orderRepository,
        ProductRepositoryInterface $productRepository,
        AttributeRepositoryInterface $attributeRepository,
        RequestFactory $requestFactory,
        CustomerRepositoryInterface $customerRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ResourceConnection $connection,
        RequestRepositoryInterface $requestRepository,
        CustomerExchangeFactory $customerExchangeFactory,
        $name = null
    ) {
        parent::__construct($name);
        
        $this->state = $state;
        $this->csv = $csv;
        $this->orderItemRepository = $orderItemRepository;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->attributeRepository = $attributeRepository;
        $this->requestFactory = $requestFactory;
        $this->customerRepository = $customerRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->connection = $connection;
        $this->requestRepository = $requestRepository;
        $this->customerExchangeFactory = $customerExchangeFactory;
    }
    
    protected function configure()
    {
        $this->setName('customer:getdata')
            ->setDescription('Get Customer Journey Report.')
            ->addArgument('start_date')
            ->addArgument('end_date')
            ->addArgument('output_file');
        
        parent::configure();
    }
    
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = date_create();
        $output->writeln('Started at ' . $startTime->format('Y-m-d H:i:s'));
        
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        
        $args = $input->getArguments();
        $startDate = $args['start_date'];
        $endDate = $args['end_date'];
        $outFilePath = $args['output_file'];
    
        $headers = [
            'customer_id',
            'customer_name',
            'customer_created_at',
            'customer_email',
            'order_date',
            'customer_order_increment',
            'customer_days_since_last_order',
            'customer_total_orders',
            'customer_total_items',
            'order_billing_address',
            'order_id',
            'order_increment_id',
            'order_item_count',
            'order_item_id',
            'item_sku',
            'item_name',
            'item_gender',
            'item_price',
            'item_discount',
            'rma_type',
            'rma_date',
            'rma_reason',
            'rma_reason_other',
            'rma_exchange_sku',
        ];
        
        // Open output file and write headers
        $outFile = fopen($outFilePath, 'a');
        
        if (!file_exists($outFilePath)) {
            fputcsv($outFile, $headers, ',', '"');
        }
        
        $mainQuery = "SELECT ce.entity_id AS customer_id, CONCAT(ce.firstname, ' ', ce.lastname) AS customer_name, ce.created_at AS customer_created_at, ce.email AS customer_email, 
                        so.created_at AS order_date,
                        (SELECT COUNT(*) + 1 FROM sales_order soz
                            WHERE soz.customer_id = ce.entity_id
                            AND soz.status IN ('complete', 'closed', 'import_to_netsuit')
                            AND soz.grand_total > 50
                            AND soz.entity_id < so.entity_id
                        ) AS 'customer_order_increment', 
                        IFNULL((SELECT DATEDIFF(so.created_at, soy.created_at) FROM sales_order soy
                            WHERE soy.customer_id = ce.entity_id
                            AND soy.entity_id < so.entity_id
                            ORDER BY soy.entity_id DESC
                            LIMIT 1
                        ), 0) AS 'customer_days_since_last_order',
                        (SELECT COUNT(*) FROM sales_order sot
                            WHERE sot.customer_id = ce.entity_id
                            AND sot.status IN ('complete', 'closed', 'import_to_netsuit')
                            AND sot.grand_total > 50
                        ) AS 'customer_total_orders', 
                        (SELECT COUNT(*) FROM sales_order_item soit
                            INNER JOIN sales_order sot ON sot.entity_id = soit.order_id
                            WHERE sot.customer_id = ce.entity_id
                            AND sot.status IN ('complete', 'closed', 'import_to_netsuit')
                            AND sot.grand_total > 50
                            AND soit.product_type = 'simple'
                            AND soit.sku NOT IN ('410001','410008','410009','430001-L', '430001-M', '430001-S', '430001-XL', '430001-XS', '430002-L', '430002-M', '430002-S', '430002-XL', '430002-XS')
                        ) AS 'customer_total_items', 
                        CONCAT(soa.street, ', ', soa.city, ', ', soa.region, ' ', soa.postcode) AS order_billing_address, 
                        so.entity_id AS order_id, so.increment_id AS order_increment_id, 
                        (SELECT COUNT(*) FROM sales_order_item soix
                            WHERE soix.order_id = so.entity_id
                            AND soix.product_type = 'simple'
                            AND soix.sku NOT IN ('410001','410008','410009','430001-L', '430001-M', '430001-S', '430001-XL', '430001-XS', '430002-L', '430002-M', '430002-S', '430002-XL', '430002-XS')
                        ) AS order_item_count, 
                        soi.item_id AS order_item_id, soi.sku AS item_sku, soi.name AS item_name, IF(SUBSTRING(soi.sku, 1, 1) = '1', 'male', 'female') AS item_gender, 
                        IF(soi.parent_item_id IS NOT NULL, 
                            (SELECT price FROM sales_order_item soiy WHERE soiy.item_id = soi.parent_item_id),
                            soi.price
                        ) AS item_price,
                        (SELECT discount_amount FROM sales_order_item soiy
                            WHERE soiy.item_id = soi.parent_item_id
                        ) AS item_discount,
                        '' AS 'rma_type', '' AS 'rma_date', '' AS 'rma_reason', '' AS 'rma_reason_other', '' AS 'rma_exchange_sku'
                    FROM customer_entity ce
                    LEFT JOIN sales_order so ON so.customer_id = ce.entity_id
                    LEFT JOIN sales_order_address soa ON soa.entity_id = so.billing_address_id
                    LEFT JOIN sales_order_item soi ON soi.order_id = so.entity_id
                    WHERE so.created_at >= '$startDate' AND so.created_at < '$endDate'
                        AND so.status IN ('complete', 'closed', 'import_to_netsuit')
                        AND so.grand_total > 50
                        AND soi.product_type = 'simple'
                        AND soi.sku NOT IN ('410001','410008','410009','430001-L', '430001-M', '430001-S', '430001-XL', '430001-XS', '430002-L', '430002-M', '430002-S', '430002-XL', '430002-XS')
                    ORDER BY ce.entity_id, order_id  DESC ";
        
        // Get first batch
        $offset = 0;
        $limit = "LIMIT $offset, " . self::BATCH_SIZE;
        $conn = $this->connection->getConnection();
        
        $output->writeln('Getting ' . self::BATCH_SIZE . ' records from offset ' . $offset . '...');
        $customerOrderItems = $conn->fetchAll($mainQuery . $limit);
        //$stmt = $conn->prepare($mainQuery);
        //$stmt->bindParam(1, $startDate, PDO::PARAM_STR);
        //$stmt->bindParam(2, $endDate, PDO::PARAM_STR);
        //$stmt->queryString
        //$debug = $stmt->debugDumpParams();
        //$customerOrderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        while (!empty($customerOrderItems)) {
            $output->write('  Processing data');
    
            $count = 0;
            
            foreach ($customerOrderItems as &$customerOrderItem) {
                
                // See if there are any rma items for this order item
                $rmaItemQuery = "SELECT * FROM aw_rma_request_item WHERE item_id = " . $customerOrderItem['order_item_id'] . " LIMIT 1";
                $rmaItem = $conn->fetchAll($rmaItemQuery);
                if (!empty($rmaItem)) {
    
                    $itemId = end($rmaItem)['item_id'];
                    $rmaId = end($rmaItem)['request_id'];
                    
                    try {
                        
                        /** @var \Kurufootwear\Rma\Model\Request $rma */
                        $rma = $this->requestRepository->get($rmaId);
    
                        /** @var \Kurufootwear\Rma\Model\Request\Item $rmaItemObj */
                        $rmaItemObj = null;
                        $rmaItems = $rma->getOrderItems();
                        foreach ($rmaItems as $rmaItemObji) {
                            if ($rmaItemObji->getItemId() == $itemId) {
                                $rmaItemObj = $rmaItemObji;
                                break;
                            }
                        }
    
                        $customerOrderItem['rma_type'] = strtolower($rma->getTypeCode());
                        $customerOrderItem['rma_date'] = $rma->getCreatedAt();
    
                        if (!empty($rmaItemObj)) {
                            $customerOrderItem['rma_reason'] = $rmaItemObj->getReason();
                            $customerOrderItem['rma_reason_other'] = $rmaItemObj->getOtherDescription();
                        }
    
                        if ($customerOrderItem['rma_type'] == 'exchange' || $customerOrderItem['rma_type'] == 'warranty') {
                            $exchange = $this->customerExchangeFactory->create()->loadByRequestId($rma->getId());
                            if (!empty($exchange) && $exchange->hasData()) {
                                $exchangeOrderId = $exchange->getPendingOrderId();
                                if (!empty($exchangeOrderId)) {
                                    $exchangeOrder = $this->orderRepository->get($exchangeOrderId);
                                    if (!empty($exchangeOrder) && $exchangeOrder->hasData()) {
                                        $exchangeItems = $exchangeOrder->getItems();
                                        $customerOrderItem['rma_exchange_sku'] = end($exchangeItems)->getSku();
                                    }
                                }
                            }
                        }
                    }
                    catch(\Exception $e) {
                        $customerOrderItem['rma_type'] = 'error';
                        $output->writeln("Error processing RMA data for order item ID $itemId and RMA ID $rmaId");
                        continue;
                    }
                }
                
                // Get rid of newlines in addresses
                $customerOrderItem['order_billing_address'] = str_replace(["\n", "\r"], ' ', $customerOrderItem['order_billing_address']);
                
                // Write line to csv
                fputcsv($outFile, $customerOrderItem, ',', '"');
                
                if (++$count % 100 == 0) {
                    $output->write('.');
                }
            }
            
            $endTime = date_create();
            $diff = $startTime->diff($endTime);
            $output->writeln('  Done processing data. Took ' . $diff->format('%H:%I:%S'));
            
            // Get the next batch
            $offset += self::BATCH_SIZE;
            $limit = "LIMIT $offset, " . self::BATCH_SIZE;
            $output->writeln('Getting ' . self::BATCH_SIZE . ' records from position ' . $offset);
            $customerOrderItems = $conn->fetchAll($mainQuery . $limit);
        }
        
        fclose($outFile);
    
        $endTime = date_create();
        $output->writeln('Finished at ' . $endTime->format('Y-m-d H:i:s'));
    
        $diff = $startTime->diff($endTime);
        $output->writeln('Took ' . $diff->format('%H:%I:%S'));
    }
}
