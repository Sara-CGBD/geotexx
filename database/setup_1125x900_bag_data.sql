-- Setup bag_size_master data for 1125mmX900mm with multiple GSM and thickness options
-- This ensures the dynamic GSM/thickness selection works in CNC and Branding entry

USE geobagg;

-- Insert/Update 1125mmX900mm configurations
-- If records exist, they won't be duplicated due to unique constraint

INSERT INTO bag_size_master (bag_size, gsm, thickness, bag_capacity, unit_price)
VALUES 
    ('1125mmX900mm', 450, 3.3, '175kg', 0),
    ('1125mmX900mm', 400, 3, '175kg', 0),
    ('1125mmX900mm', 300, 2.3, '175kg', 0),
    ('1125mmX900mm', 300, 2.5, '200kg', 0)
ON DUPLICATE KEY UPDATE bag_capacity = VALUES(bag_capacity);

-- Verify the data
SELECT 'Current 1125mmX900mm configurations:' AS Info;
SELECT bag_size, gsm, thickness, bag_capacity, unit_price 
FROM bag_size_master 
WHERE bag_size = '1125mmX900mm'
ORDER BY gsm, thickness;


