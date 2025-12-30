-- Database schema for Weathering Exposure Test Reports
CREATE TABLE IF NOT EXISTS weathering_exposure_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(100),
    sample_received_from VARCHAR(255) NOT NULL,
    sample_collected_from VARCHAR(255) NOT NULL,
    reference VARCHAR(255) NOT NULL,
    sample_description TEXT NOT NULL,
    recipe VARCHAR(255) NOT NULL,
    received_date DATETIME NOT NULL,
    test_start_date DATE NOT NULL,
    test_end_date DATE NOT NULL,
    testing_method VARCHAR(255) NOT NULL,
    test_name VARCHAR(255) NOT NULL,
    test_speed VARCHAR(255) NOT NULL,
    gauge_length VARCHAR(255) NOT NULL,
    specimen_size VARCHAR(255) NOT NULL,
    note TEXT,
    temperature DECIMAL(10,2) NOT NULL,
    rh_percent DECIMAL(5,2) NOT NULL,
    test_performed_by VARCHAR(100) NOT NULL,
    approved_by VARCHAR(100) NOT NULL,
    test_results JSON,
    reporter_id INT,
    reporter_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Counter table for report numbering
CREATE TABLE IF NOT EXISTS weathering_exposure_counters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_key VARCHAR(8) UNIQUE,
    counter INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
