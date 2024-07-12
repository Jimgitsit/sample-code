# Average Orders Per Minute
DROP TEMPORARY TABLE IF EXISTS temp;
CREATE TEMPORARY TABLE temp
	SELECT (TIME_TO_SEC(B.created_at) - TIME_TO_SEC(A.created_at)) / 60 AS time_diff
	FROM sales_order A INNER JOIN sales_order B ON B.entity_id = (A.entity_id + 1)
	WHERE A.grand_total > 50 AND A.created_at >= '2020-01-01' AND A.created_at <= '2020-08-01'
    HAVING time_diff > 0
	ORDER BY A.entity_id ASC;
SELECT 1 / AVG(time_diff) AS orders_per_min, MIN(time_diff) AS min_time_between_orders, MAX(time_diff) AS max_time_between_orders FROM temp;
