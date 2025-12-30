# GEOCIL Automation - Complete Procedure Sequence Guide

This document provides step-by-step procedures for all production and lab testing workflows in the GEOCIL Automation software. Follow these sequences in order to ensure proper data flow and system integrity.

---

## Table of Contents

### Part 1: Production Procedures
1. [Raw Material Management](#1-raw-material-management)
   - Store Received Entry
   - Fiber Entry
   - Fiber Test Report
2. [Roll Production](#2-roll-production)
   - Fiber to Roll Entry
   - Roll Entry (Individual & Bundle)
   - Roll Transfer Entry
   - Roll Received Entry
3. [Production Operations](#3-production-operations)
   - Production Entry
   - Swing Machine Entry
   - CNC Entry
4. [Finished Goods Management](#4-finished-goods-management)
   - FG Entry
   - FG Received Entry
   - Branding Entry
   - FG Delivery Entry

### Part 2: Lab Testing Procedures
1. [Test Order Creation](#1-test-order-creation)
   - QC Test Order
2. [Laboratory Testing](#2-laboratory-testing)
   - Water Permeability Test
   - Characteristics Test
   - Sun Test
   - UV Test
   - Fabric Pre-Production Test
   - Fabric After Production Test
   - Fiber Test
   - Sewing Thread Test
3. [Review & Approval](#3-review--approval)
   - Checker Review
   - Admin/AGM Approval

---

## Part 1: Production Procedures

### 1. Raw Material Management

#### 1.1 Store Received Entry

**Location:** `forms/store_received_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** None

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Raw Material Store → Store Received Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled (current date/time in Asia/Dhaka timezone)
   - **Shift:** Auto-determined (Day: 8 AM - 7 PM, Night: 8 PM - 7 AM)
   - **Entry Number:** Auto-generated (format: `SRE-YYYYMMDD-XXX`, resets at 8 AM daily)
   - **Manufacturer Name:** Select from dropdown (Natpet, APT, Texofib, Hubei Botao, Jiangsu Botao, Taizhu Hailun, PSF, Other)
   - **Material Type:** Enter material type (e.g., "PP Stable Fiber")
   - **Amount (kg):** Enter quantity in kilograms (must be > 0)

3. **Submit Entry**
   - Click "Submit" button
   - System validates all fields
   - Entry is saved to `store_received_entries` table
   - Entry number is generated and displayed

4. **Next Steps**
   - Material must be tested before use in production
   - Proceed to Fiber Test Report or Sewing Thread Report

**Status Flow:**
- Entry created → Available for testing → Test approved → Available for Fiber Entry

**Important Notes:**
- Entry numbers reset daily at 8 AM
- Material must have approved test reports before being used in Fiber Entry
- Only approved materials appear in Fiber Entry dropdown

---

#### 1.2 Fiber Entry

**Location:** `forms/fiber_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** Store Received Entry with approved test reports

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Sheet Production → Fiber Entry
   - Ensure you are logged in with appropriate role

2. **Select Material Source**
   - **Store Entry Reference:** Select from dropdown (only shows entries with approved Fiber Test or Sewing Thread reports)
   - Material details auto-populate (type, amount, manufacturer)

3. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Fiber ID:** Auto-generated (format: `FE-YYYYMMDD-XXX`)
   - **Project:** Select project from dropdown
   - **Material Type:** Auto-filled from store entry (usually "PP Stable Fiber")
   - **Weight (kg):** Enter weight (cannot exceed available amount from store entry)
   - **Origin:** Enter origin information
   - **Remarks:** Optional notes

4. **Submit Entry**
   - Click "Submit" button
   - System validates:
     - Weight does not exceed available store amount
     - All required fields are filled
   - Entry is saved to `fiber_entry` table
   - Available quantity in store entry is reduced

5. **Next Steps**
   - Fiber is now available for Fiber to Roll Entry
   - Proceed to Fiber to Roll Entry to convert fiber to rolls

**Status Flow:**
- Fiber Entry created → Available for Fiber to Roll Entry

**Important Notes:**
- Only materials with approved test reports are available
- Weight is deducted from store entry automatically
- Recycled materials can also be used (from scrap_recycle table)

---

#### 1.3 Fiber Test Report

**Location:** `forms/fiber_test_report.php`  
**Required Role:** `tester`, `qc_inspector`, `admin`  
**Prerequisites:** Store Received Entry exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Sheet Production → Fiber Test Report
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Store Entry Reference:** Select from dropdown (shows store received entries)
   - **Report Number:** Auto-generated
   - **Test Date:** Enter test date
   - **Test Performed By:** Auto-filled from session
   - **Test Parameters:** Fill test-specific fields (tenacity, fineness, cut length, etc.)
   - **Test Results:** Enter test results
   - **Status:** Initially set to "pending"

3. **Submit Test Report**
   - Click "Submit" button
   - Report is saved to `fiber_test_reports` table
   - Status: `pending` → `checked` → `approved` (or `rejected`)

4. **Review & Approval**
   - Checker reviews in Lab Testing Dashboard
   - Admin/AGM approves in QC Reports Dashboard
   - Once approved, material becomes available for Fiber Entry

**Status Flow:**
- `pending` (tester submits) → `checked` (checker reviews) → `approved` (admin approves) or `rejected`

**Important Notes:**
- Test must be approved before material can be used in production
- Rejected tests can be edited and resubmitted by the tester

---

### 2. Roll Production

#### 2.1 Fiber to Roll Entry

**Location:** `forms/fiber_to_roll_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** Fiber Entry exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Sheet Production → Fiber to Roll Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Operator:** Auto-filled from session
   - **Project:** Select project from dropdown
   - **GSM:** Enter GSM value (e.g., 400)
   - **Line Number:** Select Line 1 or Line 2
   - **Bale Opener Number:** Select 1, 2, or 3 (appears after line selection)
   - **Bale Number:** Enter bale number
   - **Bale Weight:** Enter bale weight in kg
   - **Roll Number:** Enter roll number
   - **Batch Info:** Enter batch information
   - **Material Type:** Auto-filled as "PP Stable Fiber"
   - **Origin:** Enter origin
   - **Total Weight:** Enter total weight in kg
   - **Reference Number:** Auto-generated after filling above fields (format: `FTR-YYYYMMDD-XXX`)

3. **Submit Entry**
   - Click "Submit" button
   - System validates all required fields
   - Entry is saved to `fiber_to_roll_entry` table
   - Reference number is generated

4. **Next Steps**
   - Reference must pass Daily GSM Check and Length Calibration tests
   - After tests are approved, proceed to Roll Entry

**Status Flow:**
- Fiber to Roll Entry created → Tests required → Tests approved → Available for Roll Entry

**Important Notes:**
- Reference number is auto-generated based on date, GSM, line, bale opener, bale number, and roll number
- Both Daily GSM Check and Length Calibration must be approved before roll entry

---

#### 2.2 Roll Entry

**Location:** `forms/roll_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** Fiber to Roll Entry with approved Daily GSM Check and Length Calibration tests

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Sheet Production → Roll Entry
   - Ensure you are logged in with appropriate role

2. **Select Entry Type**
   - **Individual Roll:** Single roll entry
   - **Bundle:** Multiple rolls bundled together (enter number of rolls)

3. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Entry ID:** Auto-generated (format: `RE-YYYYMMDD-XXX`, resets at 8 AM daily)
   - **Reference Number:** Select from dropdown (only shows Fiber to Roll entries with approved tests)
   - **Project:** Auto-filled from reference
   - **GSM:** Auto-filled from reference
   - **Line Number:** Auto-filled from reference
   - **Roll Number:** Enter roll number
   - **Batch Number:** Enter batch number
   - **Material Type:** Auto-filled
   - **Width (cm):** Enter roll width
   - **Length (m):** Enter roll length
   - **Weight (kg):** Enter roll weight
   - **Number of Rolls:** Required for bundle entries
   - **Bundle Reference:** Auto-generated for bundles (format: `REF-N` where N is roll number)

4. **Submit Entry**
   - Click "Submit" button
   - System validates:
     - Reference has approved tests
     - Weight does not exceed available weight from Fiber to Roll Entry
   - Entry is saved to `roll_entry` table
   - Reference number is generated (individual or bundle format)

5. **Next Steps**
   - Individual rolls: Available for QC testing and Roll Transfer
   - Bundles: Each roll in bundle gets individual reference (REF-1, REF-2, etc.)
   - Proceed to Roll Transfer Entry or QC Test Order

**Status Flow:**
- Roll Entry created → Available for QC testing → Tests approved → Available for CNC Entry or FG Entry

**Important Notes:**
- Entry ID resets daily at 8 AM
- Bundle entries create multiple individual roll references
- Rolls must pass QC tests before CNC cutting
- Weight is deducted from Fiber to Roll Entry automatically

---

#### 2.3 Roll Transfer Entry

**Location:** `forms/roll_transfer_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** Roll Entry exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Sheet Production → Roll Transfer Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Transfer ID:** Auto-generated
   - **Reference Number:** Select roll reference from dropdown
   - **From Location:** Enter source location
   - **To Location:** Enter destination location
   - **Quantity:** Enter number of rolls
   - **Remarks:** Optional notes

3. **Submit Entry**
   - Click "Submit" button
   - Entry is saved to `roll_transfer_entry` table
   - Roll status is updated

4. **Next Steps**
   - Transferred rolls must be received at destination
   - Proceed to Roll Received Entry

**Status Flow:**
- Roll Transfer created → Roll Received at destination → Available for next stage

---

#### 2.4 Roll Received Entry

**Location:** `forms/roll_received_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** Roll Transfer Entry exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Sheet Production → Roll Received Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Reference Number:** Select from transferred rolls
   - **Received Quantity:** Enter quantity received
   - **Location:** Enter receiving location
   - **Remarks:** Optional notes

3. **Submit Entry**
   - Click "Submit" button
   - Entry is saved to `roll_received_entry` table
   - Roll inventory is updated

4. **Next Steps**
   - Rolls are now available for:
     - CNC Entry (after QC tests are approved)
     - Further QC testing
     - FG Entry (for roll products)

**Status Flow:**
- Roll Received → Available for CNC Entry (if QC tests approved) → Available for production

**Important Notes:**
- Rolls must have all required QC tests approved before CNC Entry
- Required tests: QC Test Orders (excluding UV/Weathering), Water Permeability, Characteristics, Sun Test

---

### 3. Production Operations

#### 3.1 Production Entry

**Location:** `forms/production_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** None (general production tracking)

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Production → Production Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Production ID:** Auto-generated
   - **Project:** Select project from dropdown
   - **Operator:** Auto-filled from session
   - **Machine/Line:** Enter machine or line number
   - **Production Quantity:** Enter quantity produced
   - **Target Quantity:** Enter target (optional)
   - **Remarks:** Optional notes

3. **Submit Entry**
   - Click "Submit" button
   - Entry is saved to `production_entry` table
   - Production data is tracked

4. **Next Steps**
   - Production data is used for reports and analytics
   - No direct workflow dependency

**Status Flow:**
- Production Entry created → Available for reports

---

#### 3.2 Swing Machine Entry

**Location:** `forms/swing_machine_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** None

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Production → Swing Machine Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Project:** Select project
   - **Operator:** Auto-filled
   - **Machine Number:** Enter machine number
   - **Production Quantity:** Enter quantity
   - **Quality Parameters:** Enter quality data
   - **Remarks:** Optional notes

3. **Submit Entry**
   - Click "Submit" button
   - Entry is saved to swing machine table
   - Production tracked

4. **Next Steps**
   - Data used for production reports

---

#### 3.3 CNC Entry

**Location:** `forms/cnc_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** Roll Received Entry with all required QC tests approved

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Bag Production → CNC Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **CNC ID:** Auto-generated (format: `CNC-YYYYMMDD-XXX`)
   - **Reference Number:** Select from dropdown (only shows rolls with all required QC tests approved)
   - **Project:** Auto-filled from reference
   - **Bag Size:** Select bag size from dropdown
   - **Cutting Roll Quantity:** Enter number of rolls being cut
   - **Output Quantity:** Enter number of bags produced
   - **Waste (kg):** Enter waste amount
   - **Operator:** Auto-filled
   - **Cutting Batch:** Auto-generated (format: `CB-YYYYMMDD-XXX`)
   - **Remarks:** Optional notes

3. **Submit Entry**
   - Click "Submit" button
   - System validates:
     - Reference has all required QC tests approved
     - All required fields are filled
   - Entry is saved to `cnc_entries` table
   - Cutting batch number is generated

4. **Next Steps**
   - Cut bags proceed to FG Entry
   - Cutting batch is used in FG Entry for tracking

**Status Flow:**
- CNC Entry created → Cutting Batch generated → Available for FG Entry

**Important Notes:**
- Required QC tests must be approved: QC Test Orders (excluding UV/Weathering), Water Permeability, Characteristics, Sun Test
- Cutting batch links CNC Entry to FG Entry
- Waste is tracked separately

---

### 4. Finished Goods Management

#### 4.1 FG Entry

**Location:** `forms/fg_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** CNC Entry exists (for bags) or Roll Entry exists (for rolls)

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Finished Goods → FG Entry
   - Ensure you are logged in with appropriate role

2. **Select Product Type**
   - **Bag:** From CNC cutting
   - **Roll:** Direct roll product

3. **Fill Required Fields (For Bags)**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **FG ID:** Auto-generated (format: `FG-YYYYMMDD-XXX`, resets at 8 AM daily)
   - **Reference Number:** Select from CNC cutting batches
   - **CNC Cutting Batch:** Auto-filled from reference
   - **Project:** Auto-filled
   - **Bag Size:** Auto-filled from CNC entry
   - **Packaging Type:** Select packaging type
   - **Passed Quantity:** Enter quantity that passed QC
   - **Failed Quantity:** Enter quantity that failed QC
   - **Actual Weight:** Enter actual weight
   - **Recommended Weight:** Auto-filled based on bag size
   - **Remarks:** Optional notes

4. **Fill Required Fields (For Rolls)**
   - **Reference Number:** Select from roll entries
   - **Product Type:** "Roll"
   - **Roll Entry Type:** Individual or Bundle
   - **Actual Weight:** Enter weight
   - **Other fields:** Similar to bag entry

5. **Submit Entry**
   - Click "Submit" button
   - Entry is saved to `fg_entry` table
   - FG ID is generated

6. **Next Steps**
   - Finished goods proceed to FG Received Entry
   - Then to Branding Entry (if needed)
   - Finally to FG Delivery Entry

**Status Flow:**
- FG Entry created → FG Received → Branding (optional) → FG Delivery

**Important Notes:**
- FG ID resets daily at 8 AM
- Reference number links to source (CNC batch or roll)
- Passed quantity is available for delivery

---

#### 4.2 FG Received Entry

**Location:** `forms/fg_received_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** FG Entry exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Finished Goods → FG Received Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Reference Number:** Select from FG entries
   - **Received Quantity:** Enter quantity received
   - **Location:** Enter receiving location
   - **Quality Verification:** Enter verification notes
   - **Remarks:** Optional notes

3. **Submit Entry**
   - Click "Submit" button
   - Entry is saved to `fg_received_entry` table
   - FG stock is updated

4. **Next Steps**
   - Goods are available for branding (if needed)
   - Then proceed to FG Delivery Entry

**Status Flow:**
- FG Received → Available for Branding → Available for Delivery

---

#### 4.3 Branding Entry

**Location:** `forms/branding_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** FG Received Entry exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Finished Goods → Branding Entry
   - Ensure you are logged in with appropriate role

2. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Reference Number:** Select from FG received entries
   - **Branding Type:** Select branding type
   - **Quantity Branded:** Enter quantity
   - **Quality Check:** Enter quality check results
   - **Remarks:** Optional notes

3. **Submit Entry**
   - Click "Submit" button
   - Entry is saved to branding table
   - Branding tracked

4. **Next Steps**
   - Branded goods proceed to FG Delivery Entry

**Status Flow:**
- Branding Entry created → Available for Delivery

---

#### 4.4 FG Delivery Entry

**Location:** `forms/fg_delivery_entry.php`  
**Required Role:** `production_user`, `admin`  
**Prerequisites:** FG Entry with remaining stock

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Finished Goods → FG Delivery Entry
   - Ensure you are logged in with appropriate role

2. **Select Product Type**
   - **Roll:** Individual or Bundle
   - **Bag:** From CNC cutting

3. **Select Entry Type (For Rolls)**
   - **Individual Roll:** Single roll delivery
   - **Bundle:** Bundle delivery

4. **Fill Required Fields**
   - **Date & Time:** Auto-filled
   - **Shift:** Auto-determined
   - **Delivery ID:** Auto-generated (format: `FD-YYYYMMDD-XXX`, resets at 8 AM daily)
   - **Reference Number:** Select from FG entries with remaining stock
   - **CNC Cutting Batch:** Auto-filled for bags
   - **Available Quantity:** Auto-displayed (read-only)
   - **Delivery Quantity:** Enter quantity to deliver (cannot exceed available)
   - **Client:** Search and select client (or enter new client name)
   - **Truck Number:** Enter truck number (optional)
   - **Destination:** Enter destination (optional)
   - **Lighthouse Challan Number:** Auto-generated (format: `CN-YYYYMMDD-XXX`) or manual entry
   - **Unit Price:** Auto-filled from BOM (for predefined bag sizes) or manual entry
   - **Remarks:** Optional notes

5. **Submit Entry**
   - Click "Submit" button
   - System validates:
     - Delivery quantity does not exceed available stock
     - Client name is provided
     - Unit price is valid
   - Entry is saved to `fg_deliveries` table
   - Delivered quantity is deducted from FG entry
   - Delivery ID and Challan number are generated

6. **Next Steps**
   - Delivery is complete
   - Invoice can be generated from delivery data
   - Stock is updated automatically

**Status Flow:**
- FG Delivery created → Stock updated → Delivery complete

**Important Notes:**
- Delivery ID and Challan number reset daily at 8 AM
- Unit price auto-fills from BOM for predefined bag sizes
- Custom bag sizes require manual price entry
- Client can be searched or entered as new
- Delivered quantity is tracked and cannot exceed available stock

---

## Part 2: Lab Testing Procedures

### 1. Test Order Creation

#### 1.1 QC Test Order

**Location:** `forms/qc_test_order.php`  
**Required Role:** `tester`, `qc_inspector`, `admin`  
**Prerequisites:** Roll Entry exists (for production products) or external reference (for external products)

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: QC Module → QC Test Order
   - Ensure you are logged in with tester role

2. **Select Reference Type**
   - **Production Product:** Select from roll_entry references
   - **External Product:** Check "External Product" and enter external reference

3. **Fill Required Fields**
   - **Sample Reference ID:** Auto-generated (format: `SR-YYYYMMDD-XXX-###`) or external reference
   - **Product Reference:** Select from dropdown (for production) or enter (for external)
   - **Fiber Reference:** Optional (if applicable)
   - **Yarn Reference:** Optional (if applicable)
   - **Customer Details:** Enter customer information
   - **GSM:** Enter GSM value
   - **Roll Count:** Enter number of rolls (for bundles)
   - **Test Method:** Select exactly ONE test method:
     - Thickness (Under 2kPa Pressure) - ASTM D5199 / ISO 9863-1
     - Mass Per Unit Area (GSM) - ASTM D5261 / ISO 9864
     - Strip Tensile Test - ASTM D4595 / ISO 10319
     - CBR Puncture Resistance - ASTM D6241 / ISO 12236
     - Grab Tensile Test - ASTM D4632
     - Weathering Exposure Test - ASTM D4533
     - Seam/Joint Test - ISO 10321
   - **Test Standard:** Select standard (ASTM or ISO)
   - **Remarks:** Optional notes
   - **Attachments:** Upload photos or documents (optional)

4. **Submit Order**
   - Click "Submit" button
   - System validates:
     - Exactly one test method selected
     - No duplicate test order for same reference and method
     - All required fields filled
   - Order is saved to `qc_test_orders` table
   - Status: `pending_checker`

5. **Next Steps**
   - Order goes to Checker for review
   - After checker approval, proceed to specific lab test form
   - Test results are entered in corresponding test form

**Status Flow:**
- `pending_checker` (tester submits) → `pending_approval` (checker approves) → `approved` (admin approves) or `rejected_by_checker` / `rejected_by_approver`

**Important Notes:**
- Only one test method per order
- Duplicate prevention: Cannot create same test for same reference
- External products use external reference format
- Bundle references create individual roll references automatically
- Rejected orders can be edited and resubmitted by tester

---

### 2. Laboratory Testing

#### 2.1 Water Permeability Test

**Location:** `forms/water_permeability_test.php`  
**Required Role:** `tester`, `checker`, `admin`, `agm ops`, `management`  
**Prerequisites:** QC Test Order exists (for ISO 11058/12956) or reference exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Lab Testing → Water Permeability Test
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Reference Number:** Select from roll_entry or enter manually
   - **Bundle Reference:** Auto-filled if from bundle
   - **Report Number:** Auto-generated (format: `WPT-YYYYMMDD-#####`, resets at 8 AM daily)
   - **Lab Test Number:** Auto-generated (format: `LT##`, resets per shift at 8 AM)
   - **Sample ID:** Auto-generated (format: `GSM.LYYMONDD-LT##-R##`)
   - **Test Date:** Enter test date
   - **GSM:** Enter GSM value
   - **Roll Number:** Enter roll number
   - **Specimen Area:** Enter specimen area
   - **Water Temperature:** Enter water temperature
   - **Correction Factor:** Enter correction factor
   - **Number of Specimens:** Enter number (up to dynamic rows)
   - **Test Results:** Fill H0, H1, times, corrections for each specimen
   - **Average Values:** Auto-calculated
   - **Remarks:** Optional notes

3. **Submit Test**
   - Click "Submit" button
   - Test is saved to `water_permeability_tests` table
   - Status: `pending`

4. **Review & Approval**
   - Checker reviews in Lab Testing Dashboard
   - Status: `pending` → `checked`
   - Admin/AGM approves in QC Reports Dashboard
   - Status: `checked` → `approved` or `rejected`

5. **Next Steps**
   - Approved test is linked to QC Test Order
   - Test data available for reports

**Status Flow:**
- `pending` (tester submits) → `checked` (checker reviews) → `approved` (admin approves) or `rejected`

**Important Notes:**
- Report number resets daily at 8 AM
- Lab test number resets per shift at 8 AM
- Sample ID includes GSM, date, lab test number, and roll number
- Dynamic specimen rows (up to multiple specimens)
- Bundle-aware: Can test individual rolls from bundle

---

#### 2.2 Characteristics Test

**Location:** `forms/characteristics_test.php`  
**Required Role:** `tester`, `checker`, `admin`, `agm ops`  
**Prerequisites:** Reference exists (from roll_entry or roll_received)

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Lab Testing → Characteristics Test
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Reference Number:** Select from roll_received or roll_entry
   - **Bundle Reference:** Auto-filled if from bundle
   - **Report Number:** Auto-generated (format: `CT-YYYYMMDD-###`, resets at 8 AM daily)
   - **Lab Test Number:** Auto-generated (resets per shift at 8 AM)
   - **Test Date:** Enter test date
   - **GSM:** Enter GSM value
   - **Roll Number:** Enter roll number
   - **Sample ID:** Auto-generated
   - **Sieve Data:** Enter up to 20 rows of sieve data
   - **Sand Weight:** Enter sand weight
   - **Sieving Time:** Enter sieving time
   - **Humidity:** Enter relative humidity
   - **Test Results:** Enter test results (JSON format)
   - **Remarks:** Optional notes

3. **Submit Test**
   - Click "Submit" button
   - Test is saved to `characteristics_tests` table
   - Status: `pending`

4. **Review & Approval**
   - Checker reviews in Lab Testing Dashboard
   - Status: `pending` → `checked`
   - Admin/AGM approves in QC Reports Dashboard
   - Status: `checked` → `approved` or `rejected`

5. **Next Steps**
   - Approved test is required before CNC Entry
   - Test data available for reports

**Status Flow:**
- `pending` (tester submits) → `checked` (checker reviews) → `approved` (admin approves) or `rejected`

**Important Notes:**
- Report number resets daily at 8 AM
- Lab test number resets per shift at 8 AM
- Up to 20 sieve data rows
- Bundle completion tracking: All rolls in bundle must be tested
- Duplicate prevention: Cannot test same roll twice

---

#### 2.3 Sun Test

**Location:** `forms/sun_test_report.php`  
**Required Role:** `tester`, `checker`, `admin`, `agm ops`  
**Prerequisites:** Reference exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Lab Testing → Sun Test Report
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Reference Number:** Select from roll_entry or roll_received
   - **Bundle Reference:** Auto-filled if from bundle
   - **Report Number:** Auto-generated
   - **Test Date:** Enter test date
   - **GSM:** Enter GSM value
   - **Roll Number:** Enter roll number
   - **Sample Description:** Enter sample details
   - **Exposure Cycles:** Enter exposure cycles
   - **Test Results:** Enter test results
   - **Remarks:** Optional notes

3. **Submit Test**
   - Click "Submit" button
   - Test is saved to `sun_test_reports` table
   - Status: `pending`

4. **Review & Approval**
   - Checker reviews (optional)
   - Admin/AGM approves in QC Reports Dashboard
   - Status: `pending` → `approved` or `rejected`

5. **Next Steps**
   - Approved test is required before CNC Entry
   - Test data available for reports

**Status Flow:**
- `pending` (tester submits) → `approved` (admin approves) or `rejected`

**Important Notes:**
- Bundle-aware: Can test individual rolls from bundle
- Duplicate prevention: Cannot test same roll twice

---

#### 2.4 UV Test

**Location:** `forms/uv_test_enhanced.php`  
**Required Role:** `tester`, `checker`, `admin`, `agm ops`  
**Prerequisites:** Reference exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Lab Testing → UV Test Enhanced
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Reference Number:** Select from roll_entry or roll_received
   - **Bundle Reference:** Auto-filled if from bundle
   - **Report Number:** Auto-generated
   - **Test Date:** Enter test date
   - **GSM:** Enter GSM value
   - **Roll Number:** Enter roll number
   - **Sample Description:** Enter sample details
   - **Exposure Cycles:** Enter exposure cycles
   - **Test Results:** Enter test results
   - **Remarks:** Optional notes

3. **Submit Test**
   - Click "Submit" button
   - Test is saved to `weathering_exposure_reports` table
   - Status: `pending`

4. **Review & Approval**
   - Checker reviews (optional)
   - Admin/AGM approves in QC Reports Dashboard
   - Status: `pending` → `approved` or `rejected`

5. **Next Steps**
   - Test data available for reports
   - Not required for CNC Entry (optional test)

**Status Flow:**
- `pending` (tester submits) → `approved` (admin approves) or `rejected`

**Important Notes:**
- UV Test is optional (not required for CNC Entry)
- Bundle-aware: Can test individual rolls from bundle
- Duplicate prevention: Cannot test same roll twice

---

#### 2.5 Fabric Pre-Production Test

**Location:** `forms/fabric_pre_production_test.php`  
**Required Role:** `tester`, `checker`, `admin`, `agm ops`  
**Prerequisites:** Reference exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Lab Testing → Fabric Pre-Production Test
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Reference Number:** Select from roll_entry
   - **Customer:** Enter customer name
   - **Product Reference:** Enter product reference
   - **GSM:** Enter GSM value
   - **Test Date:** Enter test date
   - **Sample Details:** Enter sample information
   - **Seam/Joint Results:** Enter seam test results
   - **Test Results:** Enter test results
   - **Status:** Initially `pending_checker`
   - **Remarks:** Optional notes

3. **Submit Test**
   - Click "Submit" button
   - Test is saved to `fabric_pre_production_tests` table
   - Status: `pending_checker`

4. **Review & Approval**
   - Checker reviews in Lab Testing Dashboard
   - Status: `pending_checker` → `pending_approval`
   - Admin/AGM approves in QC Reports Dashboard
   - Status: `pending_approval` → `approved` or `rejected`

5. **Next Steps**
   - Test data available for reports
   - Used for pre-production validation

**Status Flow:**
- `pending_checker` (tester submits) → `pending_approval` (checker approves) → `approved` (admin approves) or `rejected`

---

#### 2.6 Fabric After Production Test

**Location:** `forms/fabric_after_production_test.php`  
**Required Role:** `tester`, `checker`, `admin`, `agm ops`  
**Prerequisites:** Reference exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Lab Testing → Fabric After Production Test
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Reference Number:** Select from roll_entry or FG entry
   - **Customer:** Enter customer name
   - **Product Reference:** Enter product reference
   - **GSM:** Enter GSM value
   - **Test Date:** Enter test date
   - **Sample Details:** Enter sample information
   - **Seam/Joint Results:** Enter seam test results
   - **Test Results:** Enter test results
   - **Status:** Initially `pending`
   - **Remarks:** Optional notes

3. **Submit Test**
   - Click "Submit" button
   - Test is saved to `fabric_after_production_tests` table
   - Status: `pending`

4. **Review & Approval**
   - Checker reviews (optional)
   - Admin/AGM approves in QC Reports Dashboard
   - Status: `pending` → `approved` or `rejected`

5. **Next Steps**
   - Test data available for reports
   - Used for post-production validation

**Status Flow:**
- `pending` (tester submits) → `approved` (admin approves) or `rejected`

---

#### 2.7 Fiber Test

**Location:** `forms/fiber_test_report.php`  
**Required Role:** `tester`, `checker`, `admin`, `agm ops`  
**Prerequisites:** Store Received Entry exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Sheet Production → Fiber Test Report
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Store Entry Reference:** Select from store_received_entries
   - **Report Number:** Auto-generated
   - **Test Date:** Enter test date
   - **Test Performed By:** Auto-filled from session
   - **Test Parameters:** Fill test-specific fields:
     - Tenacity
     - Fineness
     - Cut Length
     - Other fiber properties
   - **Test Results:** Enter test results
   - **Status:** Initially `pending`
   - **Remarks:** Optional notes

3. **Submit Test**
   - Click "Submit" button
   - Test is saved to `fiber_test_reports` table
   - Status: `pending`

4. **Review & Approval**
   - Checker reviews in Lab Testing Dashboard
   - Status: `pending` → `checked`
   - Admin/AGM approves in QC Reports Dashboard
   - Status: `checked` → `approved` or `rejected`

5. **Next Steps**
   - Approved test makes material available for Fiber Entry
   - Test data available for reports

**Status Flow:**
- `pending` (tester submits) → `checked` (checker reviews) → `approved` (admin approves) or `rejected`

**Important Notes:**
- Test must be approved before material can be used in production
- Rejected tests can be edited and resubmitted

---

#### 2.8 Sewing Thread Test

**Location:** `forms/sewing_thread_report.php`  
**Required Role:** `tester`, `checker`, `admin`, `agm ops`  
**Prerequisites:** Store Received Entry exists

**Step-by-Step Procedure:**

1. **Access the Form**
   - Navigate to: Sheet Production → Sewing Thread Report
   - Ensure you are logged in with tester role

2. **Fill Required Fields**
   - **Store Entry Reference:** Select from store_received_entries
   - **Report Number:** Auto-generated
   - **Test Date:** Enter test date
   - **Test Performed By:** Auto-filled from session
   - **Test Parameters:** Fill test-specific fields
   - **Test Results:** Enter test results
   - **Status:** Initially `pending`
   - **Remarks:** Optional notes

3. **Submit Test**
   - Click "Submit" button
   - Test is saved to `sewing_thread_reports` table
   - Status: `pending`

4. **Review & Approval**
   - Checker reviews in Lab Testing Dashboard
   - Status: `pending` → `checked`
   - Admin/AGM approves in QC Reports Dashboard
   - Status: `checked` → `approved` or `rejected`

5. **Next Steps**
   - Approved test makes material available for Fiber Entry
   - Test data available for reports

**Status Flow:**
- `pending` (tester submits) → `checked` (checker reviews) → `approved` (admin approves) or `rejected`

**Important Notes:**
- Test must be approved before material can be used in production
- Rejected tests can be edited and resubmitted

---

### 3. Review & Approval

#### 3.1 Checker Review

**Location:** `admin/lab_testing_dashboard.php`  
**Required Role:** `checker`, `admin`  
**Prerequisites:** Tests submitted by testers

**Step-by-Step Procedure:**

1. **Access the Dashboard**
   - Navigate to: Admin → Lab Testing Dashboard
   - Ensure you are logged in with checker role

2. **View Pending Reports**
   - Dashboard shows all pending reports:
     - QC Test Orders (status: `pending_checker`)
     - Fabric Pre-Production Tests (status: `pending_checker`)
     - Water Permeability Tests (status: `pending`)
     - Characteristics Tests (status: `pending`)
   - Reports are grouped by bundle reference (if applicable)

3. **Review Test Data**
   - Click on report to view details
   - Verify test data accuracy
   - Check calculations
   - Review test parameters

4. **Take Action**
   - **Approve:** Click "Approve" button
     - Status changes: `pending` → `checked` or `pending_checker` → `pending_approval`
     - Checker name and remarks are recorded
     - Report moves to Admin/AGM approval queue
   - **Reject:** Click "Reject" button
     - Enter rejection reason
     - Status changes to `rejected_by_checker` or `rejected`
     - Tester can edit and resubmit

5. **Next Steps**
   - Approved reports go to Admin/AGM for final approval
   - Rejected reports return to tester for correction

**Status Flow:**
- `pending` / `pending_checker` → `checked` / `pending_approval` (approved) or `rejected_by_checker` / `rejected` (rejected)

**Important Notes:**
- Checker can only review, not final approve
- Bundle reports are grouped together
- Rejection reasons are mandatory
- All numeric fields default to 0 (never N/A)

---

#### 3.2 Admin/AGM Approval

**Location:** `admin/qc_reports_dashboard.php`  
**Required Role:** `admin`, `agm ops`, `agm operations`  
**Prerequisites:** Tests checked by checker (or direct approval for some tests)

**Step-by-Step Procedure:**

1. **Access the Dashboard**
   - Navigate to: Admin → QC Reports Dashboard
   - Ensure you are logged in with admin or AGM ops role

2. **View Pending Approvals**
   - Dashboard shows all reports pending final approval:
     - QC Test Orders (status: `pending_approval`)
     - Sewing Thread Reports (status: `checked`)
     - UV Test Reports (status: `pending`)
     - Fiber Test Reports (status: `checked`)
     - Fabric Tests (status: `pending_approval` or `pending`)
     - Sun Test Reports (status: `pending`)
     - Water Permeability Tests (status: `checked`)
     - Characteristics Tests (status: `checked`)
   - Reports are grouped by bundle reference (if applicable)

3. **Review Test Data**
   - Click on report to view full details
   - Verify test results
   - Check checker's review notes
   - Review test parameters and calculations

4. **Take Action**
   - **Approve:** Click "Approve" button
     - Status changes: `pending_approval` / `checked` / `pending` → `approved`
     - Approver name and timestamp are recorded
     - Report is finalized
     - Material/product becomes available for next stage
   - **Reject:** Click "Reject" button
     - Enter rejection reason
     - Status changes to `rejected_by_approver` or `rejected`
     - Tester can edit and resubmit

5. **Next Steps**
   - Approved tests unlock next production stages:
     - Fiber Test approved → Material available for Fiber Entry
     - QC Tests approved → Rolls available for CNC Entry
     - All tests approved → Production can proceed

**Status Flow:**
- `pending_approval` / `checked` / `pending` → `approved` (approved) or `rejected_by_approver` / `rejected` (rejected)

**Important Notes:**
- Final approval authority
- Bundle reports must be approved together
- Rejection reasons are mandatory
- Approval unlocks production workflow
- All numeric fields default to 0 (never N/A)

---

## Workflow Summary

### Production Workflow Sequence

1. **Raw Material** → Store Received Entry → Fiber Test Report → Fiber Entry
2. **Roll Production** → Fiber to Roll Entry → Daily GSM Check + Length Calibration → Roll Entry
3. **QC Testing** → QC Test Order → Water Permeability + Characteristics + Sun Test → Approval
4. **CNC Cutting** → Roll Received Entry → CNC Entry (after QC approval)
5. **Finished Goods** → FG Entry → FG Received Entry → Branding Entry (optional) → FG Delivery Entry

### Lab Testing Workflow Sequence

1. **Test Order** → QC Test Order (tester) → Checker Review → Admin Approval
2. **Lab Testing** → Specific Test Form (tester) → Checker Review → Admin Approval
3. **Approval** → Test Approved → Material/Product Unlocked for Next Stage

### Key Dependencies

- **Fiber Entry** requires: Store Received Entry + Approved Fiber Test or Sewing Thread Test
- **Roll Entry** requires: Fiber to Roll Entry + Approved Daily GSM Check + Approved Length Calibration
- **CNC Entry** requires: Roll Received Entry + All Required QC Tests Approved (QC Test Orders, Water Permeability, Characteristics, Sun Test)
- **FG Delivery** requires: FG Entry with remaining stock

### Status Transitions

- **Test Reports:** `pending` → `checked` → `approved` (or `rejected`)
- **QC Test Orders:** `pending_checker` → `pending_approval` → `approved` (or `rejected_by_checker` / `rejected_by_approver`)

---

## Important Notes

1. **Daily Reset Times:** Most ID numbers reset daily at 8 AM (Asia/Dhaka timezone)
2. **Shift Determination:** Day shift (8 AM - 7 PM), Night shift (8 PM - 7 AM)
3. **Role-Based Access:** Each procedure requires specific role permissions
4. **Duplicate Prevention:** System prevents duplicate entries for same reference/test combination
5. **Bundle Handling:** Bundle entries create individual roll references automatically
6. **Missing Values:** All numeric fields default to 0 (never N/A)
7. **Session Timeout:** 30 minutes of inactivity logs out user
8. **Edit/Resubmit:** Rejected entries can be edited and resubmitted by original creator

---

## Support & References

- **System Overview:** See `SYSTEM_OVERVIEW.md`
- **Software Functionality:** See `SOFTWARE_FUNCTIONALITY.md`
- **QC Lab Procedures:** See `docs/qc_lab_procedure.md`
- **API Documentation:** See `API_DOCUMENTATION.md`
- **Role-Based Access:** See `README_RBAC.md`

---

*Last Updated: Based on current codebase analysis*  
*For technical support, contact system administrator*

