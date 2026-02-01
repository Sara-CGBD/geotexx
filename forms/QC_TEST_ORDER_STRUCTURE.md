# QC Test Order - File Structure Documentation

## Overview
**File:** `forms/qc_test_order.php`  
**Total Lines:** 12,292  
**Type:** Monolithic file (Backend + Frontend combined)  
**Form Action:** Self-submitting (`action=""`)

---

## File Structure Breakdown

### 📋 **BACKEND SECTION (Lines 1-2311)**
All PHP server-side code that runs before HTML output.

#### **1. AJAX Endpoints (Lines 1-52)**
- **Purpose:** Handle AJAX requests before any HTML output
- **Key Endpoint:**
  - `generate_external_ref` - Generates external reference IDs (EXT-YYYYMMDD-XXX)
- **Location:** Lines 4-52
- **Important:** Must be before HTML to prevent JSON contamination

#### **2. Security & Initialization (Lines 54-105)**
- **Session Management:** Lines 2, 67-81
- **Security Checks:**
  - Session timeout check
  - Account lock check
  - Access control (QC module permission)
- **Database Connection:** Line 96
- **User Variables:**
  - `$reporter_id` - Current user ID
  - `$reporter_name` - Current user name
  - `$user_role` - Current user role
- **Role Permissions:**
  - `$is_tester` - Can submit tests
  - `$is_checker` - Can review/approve tests
  - `$is_admin` - Full admin access

#### **3. Helper Functions (Lines 106-116)**
- `getStatusLabel($status)` - Converts status codes to readable labels

#### **4. Message Handling (Lines 118-143)**
- Error messages from session
- Success messages from session/GET
- Pre-selected reference from Roll Entry

#### **5. Edit Mode Logic (Lines 145-639)**
- **Purpose:** Handle editing existing test orders
- **Key Variables:**
  - `$edit_mode` - Boolean flag
  - `$edit_id` - ID of report being edited
  - `$existing_report` - Full report data
  - `$existing_test_data` - Parsed test data JSON
  - `$edit_external_forward` - Flag for external forwarded tests
- **Edit Permissions:**
  - Rejected reports (owner or any tester for external)
  - External forwarded tests (any tester)
- **Bulk Reference Handling:** Lines 228-400
  - Finds related reports in bulk submissions
  - Tracks submitted test methods

#### **6. Form Submission Handler (Lines 640-1944)**
**Main POST Handler:** `if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order']))`

**Key Functions:**
- Validates form data
- Handles new submissions
- Handles edits/resubmissions
- Generates reference IDs
- Saves to `qc_test_orders` table
- Updates status workflow
- Handles bulk reference submissions
- Transaction management (rollback on error)

**Status Workflow:**
1. New submission → `pending_checker`
2. Checker approves → `pending_approval`
3. Admin approves → `approved`
4. Rejected → `rejected_by_checker` or `rejected_by_approver`

#### **7. Checker Action Handler (Lines 1946-2022)**
**POST Handler:** `if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checker_action']))`

**Actions:**
- Approve → Sets status to `pending_approval`
- Reject → Sets status to `rejected_by_checker`
- Bulk actions supported
- Stores checker remarks

#### **8. Admin Action Handler (Lines 2024-2113)**
**POST Handler:** `if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action']))`

**Actions:**
- Approve → Sets status to `approved`
- Reject → Sets status to `rejected_by_approver`
- Stores roll destination
- Bulk actions supported

#### **9. Data Fetching (Lines 2114-2310)**
- **Pending Reports for Checker:** Lines 2114-2169
- **Pending Reports for Admin:** Lines 2170-2192
- **Rejected Reports for Tester:** Lines 2193-2205

#### **10. Reference Generation Functions (Lines 2206-2310)**
- `generateSampleReferenceId()` - Generates sample reference (TOKEN-YYYYMMDD-XXX)
- `generateReportNumber()` - Generates report number (resets at 8 AM daily)
- `generateExternalReference()` - Generates external reference (EXT-YYYYMMDD-XXX)

---

### 🎨 **FRONTEND SECTION (Lines 2312-12292)**
All HTML, CSS, and JavaScript code.

#### **1. HTML Structure (Lines 2312-3388)**
- **DOCTYPE & Head:** Lines 2312-2313
- **CSS Styles:** Embedded in `<style>` tags
- **Page Header:** Title, navigation, messages
- **Role-Based UI:**
  - Testers see form
  - Checkers see pending reports
  - Admins see all reports

#### **2. Main Form (Lines 3389-12200+)**
**Form Tag:** `<form method="POST" action="" id="qc_test_form" novalidate>`

**Key Form Sections:**
- **Reference Selection:**
  - Product reference dropdown
  - External reference input
  - Bulk reference range
- **Test Selection:**
  - Checkboxes for test standards
  - Method selection per test
  - Dynamic test fields
- **Test Data Input:**
  - Test-specific input fields
  - Calculated values
  - Validation rules
- **Submission:**
  - Submit button
  - Edit mode indicators
  - Status badges

#### **3. Reports Display (Lines 3000-4000+)**
- **Pending Reports Table:** For checkers/admins
- **Rejected Reports Table:** For testers
- **Bulk Actions:** Checkboxes for multiple approvals
- **Filters:** Date, status, reference filters

#### **4. JavaScript Functions (Lines 4000-12289)**
**Key JavaScript Functions:**

**Form Validation:**
- `validateQCFormBeforeSubmit()` - Pre-submission validation
- `syncAllSummaryData()` - Syncs calculated values

**Dynamic UI:**
- `loadUserPreferences()` - Loads saved user preferences
- `loadFiberReferenceData()` - Loads reference data on selection
- `updateTestFields()` - Updates test-specific fields
- `calculateValues()` - Calculates test results

**Reference Management:**
- `generateExternalReference()` - AJAX call for external ref
- `handleBulkReference()` - Handles bulk reference selection
- `updateReferenceDisplay()` - Updates reference UI

**Modal Management:**
- `openCheckerRejectModal()` - Opens rejection modal
- `closeCheckerRejectModal()` - Closes rejection modal
- `openAdminRejectModal()` - Opens admin rejection modal
- `closeAdminRejectModal()` - Closes admin rejection modal

**Data Loading:**
- `loadPendingReports()` - Loads pending reports via AJAX
- `loadRejectedReports()` - Loads rejected reports
- `refreshReports()` - Refreshes report tables

**Event Handlers:**
- Form submission handlers
- Checkbox change handlers
- Reference selection handlers
- Test method selection handlers

---

## Data Flow

### **New Test Order Submission:**
1. User fills form (Frontend)
2. JavaScript validates (Client-side)
3. Form submits via POST to same file
4. Backend validates (Server-side) - Line 641
5. Data saved to `qc_test_orders` table
6. Status set to `pending_checker`
7. Redirect with success message
8. Page reloads showing updated data

### **Edit/Resubmission:**
1. User clicks edit on rejected report
2. `?edit=ID` added to URL
3. Backend loads existing data - Line 152
4. Form pre-filled with existing data
5. User modifies and submits
6. Backend updates existing record - Line 641
7. Status workflow continues

### **Checker Approval:**
1. Checker views pending reports
2. Clicks approve/reject
3. POST to `checker_action` - Line 1947
4. Status updated
5. Redirect with message

### **Admin Approval:**
1. Admin views pending reports
2. Clicks approve/reject
3. POST to `admin_action` - Line 2025
4. Status updated to `approved` or `rejected_by_approver`
5. Redirect with message

---

## Key Database Tables

### **qc_test_orders**
- `id` - Primary key
- `sample_reference_id` - Reference number
- `test_standard_id` - Test standard ID
- `chosen_method` - Selected test method
- `test_data` - JSON data of test results
- `status` - Current status
- `report_number` - Report number
- `inspector_id` - Tester user ID
- `checked_by` - Checker name
- `approved_by` - Admin name
- `roll_destination` - Where roll goes after approval

### **test_standards**
- Test definitions
- Test methods
- Standard codes

---

## Important Variables

### **Session Variables:**
- `$_SESSION['user_id']` - Current user ID
- `$_SESSION['username']` - Current username
- `$_SESSION['role']` - User role
- `$_SESSION['qc_success_message']` - Success message
- `$_SESSION['error_message']` - Error message
- `$_SESSION['last_roll_entry_reference']` - Pre-selected reference

### **POST Variables:**
- `submit_order` - Form submission flag
- `edit_id` - ID for edit mode
- `checker_action` - Checker approve/reject
- `admin_action` - Admin approve/reject
- `reference_number` - Selected reference
- `test_standards[]` - Selected tests
- `test_data` - Test results JSON

### **GET Variables:**
- `edit` - Edit mode ID
- `msg` - Success message (legacy)
- `action` - AJAX action type

---

## Status Workflow

```
New Submission
    ↓
pending_checker (Checker reviews)
    ↓
pending_approval (Admin reviews)
    ↓
approved (Final approval)
    OR
rejected_by_checker (Checker rejects)
    OR
rejected_by_approver (Admin rejects)
    ↓
(Can be edited and resubmitted)
```

---

## File Organization Notes

### **Why Monolithic?**
- All logic in one place
- Easy to track data flow
- No file dependencies
- Self-contained

### **Maintenance Tips:**
1. **Backend Changes:** Lines 1-2311
2. **Frontend Changes:** Lines 2312-12292
3. **Form Submission:** Line 641
4. **JavaScript:** Lines 4000-12289
5. **CSS:** Embedded in HTML section

### **Testing Checklist:**
- [ ] New test submission
- [ ] Edit rejected report
- [ ] Checker approval/rejection
- [ ] Admin approval/rejection
- [ ] Bulk actions
- [ ] External product flow
- [ ] Bulk reference flow
- [ ] AJAX endpoints

---

## Common Modifications

### **Add New Test:**
1. Add to test standards in database
2. Add checkbox in form (Frontend)
3. Add test fields in JavaScript
4. Add validation in backend

### **Change Status Workflow:**
1. Modify status assignments (Line 641, 1947, 2025)
2. Update status labels (Line 107)
3. Update UI badges (Frontend)

### **Add New Field:**
1. Add column to `qc_test_orders` table
2. Add input in form (Frontend)
3. Add to POST handler (Line 641)
4. Add to edit mode loading (Line 152)

---

## Security Considerations

- ✅ Session validation
- ✅ Role-based access control
- ✅ SQL prepared statements
- ✅ Input sanitization
- ✅ CSRF protection (via session)
- ✅ Transaction rollback on errors

---

**Last Updated:** 2026-01-27  
**File Version:** Monolithic (12,292 lines)
