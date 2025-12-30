-- SQL Script to fix existing FG delivery data
-- Run this in phpMyAdmin or MySQL command line

USE geobagg;

-- Step 1: Fix NULL or invalid delivery_date by using created_at
UPDATE fg_deliveries 
SET delivery_date = created_at
WHERE (delivery_date IS NULL 
       OR delivery_date = '0000-00-00' 
       OR delivery_date = '0000-00-00 00:00:00'
       OR delivery_date = '0')
  AND created_at IS NOT NULL;

-- Step 2: For records without created_at, use CURRENT_TIMESTAMP
UPDATE fg_deliveries 
SET delivery_date = CURRENT_TIMESTAMP
WHERE (delivery_date IS NULL 
       OR delivery_date = '0000-00-00' 
       OR delivery_date = '0000-00-00 00:00:00'
       OR delivery_date = '0');

-- Step 3: Fix client_name = '0' by looking up from clients table
UPDATE fg_deliveries d
INNER JOIN clients c ON d.client_id = c.id
SET d.client_name = c.client_name
WHERE (d.client_name = '0' OR d.client_name IS NULL OR d.client_name = '')
  AND c.client_name IS NOT NULL;

-- Step 4: Display fixed data
SELECT 'Fixed FG Deliveries:' AS Status;
SELECT 
    delivery_id,
    delivery_date,
    shift,
    client_id,
    client_name,
    delivery_quantity
FROM fg_deliveries
ORDER BY id DESC
LIMIT 20;

-- Step 5: Show summary
SELECT 
    COUNT(*) as total_deliveries,
    SUM(CASE WHEN client_name = '0' OR client_name IS NULL OR client_name = '' THEN 1 ELSE 0 END) as missing_client_names,
    SUM(CASE WHEN delivery_date IS NULL OR delivery_date = '0000-00-00' OR delivery_date = '0000-00-00 00:00:00' THEN 1 ELSE 0 END) as missing_dates
FROM fg_deliveries;


