<?php

require_once 'vendor/autoload.php';
require_once 'config.php';

use NetSuite\NetSuiteService;

$service = new NetSuiteService($config);

$service->logRequests(true);

use NetSuite\Classes\AddRequest;
use NetSuite\Classes\InventoryAdjustment;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\InventoryAdjustmentInventoryList;
use NetSuite\Classes\InventoryAdjustmentInventory;

$invAdj = new InventoryAdjustment();

$invAdj->memo = "From auto-processing Exchange.";
$invAdj->account = new RecordRef();
$invAdj->account->internalId = 235;
$invAdj->adjLocation = new RecordRef();
$invAdj->adjLocation->internalId = 1;

//$invAdj->createdDate = date("Y-m-d\Th:i:s-07:00");

$invAdjInv = new InventoryAdjustmentInventory();
$invAdjInv->item = new RecordRef();
$invAdjInv->item->internalId = '3685';
$invAdjInv->adjustQtyBy = 1;
$invAdjInv->binNumbers = 'UT';
$invAdjInv->location = new RecordRef();
$invAdjInv->location->internalId = 1;

$invAdj->inventoryList = new InventoryAdjustmentInventoryList();
$invAdj->inventoryList->inventory[] = $invAdjInv;

$request = new AddRequest();
$request->record = $invAdj;

$response = $service->add($request);
if (!$response->writeResponse->status->isSuccess) {
	echo ('ERROR');
	var_dump($response->writeResponse->status->statusDetail);
} 
else {
	var_dump($response->writeResponse);
}