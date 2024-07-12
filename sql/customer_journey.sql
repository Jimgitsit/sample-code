SELECT ce.entity_id AS customer_id, CONCAT(ce.firstname, ' ', ce.lastname) AS customer_name, ce.created_at AS customer_created_at, ce.email AS customer_email,
	'' AS 'customer_order_increment', '' AS 'customer_total_orders', '' AS 'customer_total_items', '' AS 'customer_days_since_last_order', 
	CONCAT(soa.street, ', ', soa.city, ', ', soa.region, ' ', soa.postcode) AS order_billing_address, so.created_at AS order_date, so.increment_id AS order_increment_id, 
    (SELECT COUNT(*) FROM sales_order_item soix
        WHERE soix.order_id = so.entity_id
        AND soix.product_type = 'simple'
		AND soix.sku NOT IN ('410001','410008','410009','430001-L', '430001-M', '430001-S', '430001-XL', '430001-XS', '430002-L', '430002-M', '430002-S', '430002-XL', '430002-XS')
	) AS order_item_count, 
    soi.item_id AS order_item_id, soi.sku AS item_sku, soi.name AS item_name, IF(SUBSTRING(soi.sku, 1, 1) = '1', 'male', 'female') AS item_gender, 
    (SELECT price FROM sales_order_item soiy
        WHERE soiy.item_id = soi.parent_item_id
    ) AS item_price,
    (SELECT discount_amount FROM sales_order_item soiy
        WHERE soiy.item_id = soi.parent_item_id
    ) AS item_discount,
    '' AS 'rma', '' AS 'rma_type', '' AS 'rma_date', '' AS 'rma_reason', '' AS 'rma_reason_other', '' AS 'rma_exchange_sku'
FROM customer_entity ce
LEFT JOIN sales_order so ON so.customer_id = ce.entity_id
LEFT JOIN sales_order_address soa ON soa.entity_id = so.billing_address_id
LEFT JOIN sales_order_item soi ON soi.order_id = so.entity_id
WHERE so.created_at >= '2020-01-01 00:00:00' AND so.created_at < '2021-01-01 00:00'
	AND so.status IN ('complete', 'closed')
    AND soi.product_type = 'simple'
    AND soi.sku NOT IN ('410001','410008','410009','430001-L', '430001-M', '430001-S', '430001-XL', '430001-XS', '430002-L', '430002-M', '430002-S', '430002-XL', '430002-XS')
ORDER BY ce.entity_id DESC;

