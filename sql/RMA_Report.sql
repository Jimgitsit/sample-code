SELECT 
	t.request_id, 
	t.order_id, 
    t.order_increment_id, 
    t.order_item_id, 
    t.order_created_at AS order_date,
	t.created_at AS rma_date, 
    DATEDIFF(t.created_at, t.order_created_at) AS days_to_return,
	t.customer_name, 
	t.customer_email, 
    t.customer_id,
    t.customer_total_order_count,
    t.number_of_items_in_request,
    t.product_name,
	t.sku, 
    t.product_id,
    t.order_sub_total, 
    t.order_discount_amount, 
    IF(t.coupon_code IS NULL, '', t.coupon_code) AS coupon_code,
    t.order_grand_total,
    t.item_price, 
    IF((t.item_refunded_amount + t.item_tax_refunded + t.item_discount_refunded) IS NULL, 0, (t.item_refunded_amount + t.item_tax_refunded + t.item_discount_refunded)) AS item_total_refunded, 
    # Need to correctly label warranties
	IF(type = 18, 'Exchange', 'Return') AS type, 
    t.rma_status,
    CASE SUBSTRING_INDEX(t.reason_and_comment, ',', 1)
		WHEN 20 THEN 'Length too large'
        WHEN 21 THEN 'Length too small'
        WHEN 22 THEN 'Width too large'
        WHEN 23 THEN 'Width too small'
        WHEN 24 THEN 'Unhappy with color'
        WHEN 25 THEN 'Unhappy with style'
        WHEN 26 THEN 'Other'
        ELSE REPLACE(t.reason_and_comment, ',20', '')
	END AS reason
    #IF(LOCATE(',', t.reason_and_comment) > 0, IF(SUBSTRING(t.reason_and_comment, LOCATE(',', t.reason_and_comment) + 1) = 20, '', SUBSTRING(t.reason_and_comment, LOCATE(',', t.reason_and_comment) + 1)), '') as comment
FROM (
	SELECT 
		ri.request_id, 
        r.order_id, 
        so.increment_id AS order_increment_id, 
        soi.item_id as order_item_id, 
        so.created_at AS order_created_at,
        r.created_at, 
        r.customer_name, 
        r.customer_email, 
        r.customer_id,
        (SELECT COUNT(*) FROM sales_order AS so 
			WHERE so.customer_id = r.customer_id
            # This takes MUCH longer but should account for the bad customer ids.
            #WHERE/AND/OR so.customer_email = r.customer_email
		) AS customer_total_order_count,
        (SELECT COUNT(*) FROM aw_rma_request_item rix 
			INNER JOIN sales_order_item AS soix ON soix.item_id = rix.item_id
			WHERE rix.request_id = ri.request_id 
				# # Only shoes. Do not include socks, sprays, or cleaning kits
				AND soix.sku NOT IN ('410001','410008','410009','430001-L', '430001-M', '430001-S', '430001-XL', '430001-XS', '430002-L', '430002-M', '430002-S', '430002-XL', '430002-XS')
		) AS number_of_items_in_request,
        so.base_subtotal AS order_sub_total, 
        so.base_discount_amount AS order_discount_amount,
        so.base_grand_total AS order_grand_total, 
        # The order item refereneced in the RMA could be the simple or the configurable, hence all the IFs here
        IF (psoi.base_price IS NULL, soi.base_price, psoi.base_price) AS item_price, 
        IF (psoi.base_tax_amount IS NULL, soi.base_tax_amount, psoi.base_tax_amount) AS item_tax,
        IF (psoi.base_discount_amount IS NULL, soi.base_discount_amount, psoi.base_discount_amount) AS item_discount_amount,
        IF (psoi.base_row_total_incl_tax IS NULL, soi.base_row_total_incl_tax, psoi.base_row_total_incl_tax) AS item_total, 
        IF (psoi.base_amount_refunded IS NULL, soi.base_amount_refunded, psoi.base_amount_refunded) AS item_refunded_amount, 
        IF (psoi.base_tax_refunded IS NULL, soi.base_tax_refunded, psoi.base_tax_refunded) AS item_tax_refunded,
        IF (psoi.base_discount_refunded IS NULL, soi.base_discount_refunded, psoi.base_discount_refunded) AS item_discount_refunded, 
        so.coupon_code,
        soi.name as product_name,
        soi.sku, 
        soi.product_id,
        rcfv.value AS `type`, 
        rs.name as rma_status,
        (SELECT GROUP_CONCAT(ricfv.value SEPARATOR ',') AS reason_and_comment
			FROM aw_rma_request_item_custom_field_value AS ricfv
			WHERE (ricfv.field_id = 5 || ricfv.field_id = 8) AND ricfv.entity_id = ri.id
		) AS reason_and_comment
	FROM aw_rma_request_item AS ri
	INNER JOIN aw_rma_request AS r ON r.id = ri.request_id
    INNER JOIN sales_order AS so ON so.entity_id = r.order_id
	INNER JOIN sales_order_item AS soi ON soi.item_id = ri.item_id 
    LEFT JOIN sales_order_item AS psoi ON psoi.item_id = soi.parent_item_id
    LEFT JOIN aw_rma_request_status AS rs ON rs.id = r.status_id 
	LEFT JOIN aw_rma_request_custom_field_value AS rcfv ON rcfv.field_id = 6 AND rcfv.entity_id = ri.request_id
    #WHERE r.created_at >= '2020-01-01' AND r.created_at <= '2020-12-31'
    WHERE so.created_at >= '2020-01-01' AND so.created_at <= '2020-12-31'
    # Only shoes
    AND soi.sku NOT IN ('410001','410008','410009','430001-L', '430001-M', '430001-S', '430001-XL', '430001-XS', '430002-L', '430002-M', '430002-S', '430002-XL', '430002-XS')
	GROUP BY 
		ri.id, 
        ri.request_id, 
        r.order_id, 
        so.increment_id, 
        so.entity_id,
        order_item_id, 
        so.created_at,
        r.created_at, 
        r.customer_name, 
        r.customer_email, 
        r.customer_id, 
        product_name, 
        order_sub_total, 
        order_discount_amount, 
        order_grand_total,
        item_price, 
        item_tax, 
        item_discount_amount, 
        item_total, 
        item_refunded_amount, 
        item_tax_refunded, 
        item_discount_refunded, 
        so.coupon_code,
        soi.sku, 
        soi.product_id, 
        `type`, 
        rma_status
    #ORDER BY ri.request_id DESC # Make ASC
    ORDER BY so.created_at ASC
) AS t
#HAVING type = 'Exchange'
#WHERE t.order_id = 975995
#WHERE t.coupon_code IS NOT NULL
#WHERE t.request_id = '239093'
#WHERE t.order_item_id = 1683451
#WHERE customer_total_order_count = 0