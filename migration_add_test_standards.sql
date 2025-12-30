-- Migration: Add missing test standards for fiber pre-testing
-- Date: 2025-01-27
-- Description: Inserts missing test standards and ensures proper linking with report_tests

-- Start transaction for data integrity
START TRANSACTION;

-- Insert missing test standards (only if they don't already exist)
-- Using INSERT IGNORE to prevent duplicate key errors

-- 1. Thickness under 2kPa (ASTM D5199)
INSERT IGNORE INTO test_standards (
    product, 
    test_name, 
    standard_code, 
    detection_min, 
    detection_max, 
    detection_unit, 
    iso_code, 
    description
) VALUES (
    'Geotextile/Geosynthetics',
    'Thickness under 2kPa Pressure',
    'ASTM D5199',
    0.010,
    25.000,
    'mm',
    NULL,
    'Standard test method for measuring thickness of geosynthetics under 2kPa pressure'
);

-- 2. Thickness under 2kPa (ISO 9863-1)
INSERT IGNORE INTO test_standards (
    product, 
    test_name, 
    standard_code, 
    detection_min, 
    detection_max, 
    detection_unit, 
    iso_code, 
    description
) VALUES (
    'Geotextile/Geosynthetics',
    'Thickness under 2kPa Pressure',
    'ISO 9863-1',
    0.010,
    25.000,
    'mm',
    'ISO 9863-1',
    'ISO standard for determination of thickness at specified pressures - Part 1: Single layers'
);

-- 3. Cut Length of Fiber (ASTM D5199 - alternate usage)
INSERT IGNORE INTO test_standards (
    product, 
    test_name, 
    standard_code, 
    detection_min, 
    detection_max, 
    detection_unit, 
    iso_code, 
    description
) VALUES (
    'Geotextile/Geosynthetics',
    'Cut Length of Fiber',
    'ASTM D5199',
    1.000,
    1000.000,
    'mm',
    NULL,
    'Standard test method for measuring cut length of fiber using ASTM D5199 methodology'
);

-- 4. Cut Length (ASTM D5103)
INSERT IGNORE INTO test_standards (
    product, 
    test_name, 
    standard_code, 
    detection_min, 
    detection_max, 
    detection_unit, 
    iso_code, 
    description
) VALUES (
    'Geotextile/Geosynthetics',
    'Cut Length',
    'ASTM D5103',
    1.000,
    1000.000,
    'mm',
    NULL,
    'Standard test method for measuring cut length of geotextiles'
);

-- Verify the inserted records
SELECT 
    id,
    test_name,
    standard_code,
    detection_unit,
    description
FROM test_standards 
WHERE test_name IN (
    'Thickness under 2kPa Pressure',
    'Cut Length of Fiber',
    'Cut Length'
)
ORDER BY test_name, standard_code;

-- Check if report_tests table has proper foreign key constraints
-- (This is informational - actual FK constraints should be managed separately)
SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = 'geobagg' 
    AND TABLE_NAME = 'report_tests' 
    AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Commit the transaction
COMMIT;

-- Display summary
SELECT 
    'Migration completed successfully' as status,
    COUNT(*) as total_test_standards
FROM test_standards;

