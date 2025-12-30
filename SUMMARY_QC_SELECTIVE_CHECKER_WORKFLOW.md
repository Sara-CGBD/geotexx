# QC Test Order Selective Checker Workflow - Complete Implementation

## ✅ Implementation Complete

The QC Test Order workflow has been updated to implement **selective checker approval** based on test type.

## 📋 Test Classification

### **First 5 Tests** - REQUIRE Checker Approval:

| # | Test Name | Test Methods | Workflow |
|---|-----------|--------------|----------|
| 1 | Thickness (Under 2kPa Pressure) | ASTM D5199, ISO 9863-1 | Tester → **Checker** → Admin |
| 2 | Mass Per Unit Area (GSM) | ASTM D5261, ISO 9864 | Tester → **Checker** → Admin |
| 3 | Strip Tensile Test | ASTM D4595, ISO 10319 | Tester → **Checker** → Admin |
| 4 | CBR Puncture Resistance | ASTM D6241, ISO 12236 | Tester → **Checker** → Admin |
| 5 | Grab Tensile Test | ASTM D4632 | Tester → **Checker** → Admin |

**Status on Submit:** `pending_checker`

### **Tests 6-11** - SKIP Checker (Direct to Admin):

| # | Test Name | Test Methods | Workflow |
|---|-----------|--------------|----------|
| 6 | Weathering Exposure Test | ASTM D4533 | Tester → Admin (no checker) |
| 7 | Seam/Joint Test | ISO 10321 | Tester → Admin (no checker) |
| 8 | Fineness of Fiber | ISO 1973 | Tester → Admin (no checker) |
| 9 | Cut Length of Fiber | ASTM D5103, ASTM D5199 | Tester → Admin (no checker) |
| 10 | Tenacity of Fiber | ISO 5079 | Tester → Admin (no checker) |
| 11 | Tenacity of Yarn | ASTM D2256 | Tester → Admin (no checker) |

**Status on Submit:** `pending_approval`

---

## 🔄 Complete Workflow Diagrams

### First 5 Tests (With Checker Review):

```
┌─────────────────────────────────────────────────────────────────┐
│ TESTER submits test (Thickness/GSM/Strip/CBR/Grab)             │
└────────────────────────┬────────────────────────────────────────┘
                         ↓
                 ✅ Status: pending_checker
                         ↓
┌─────────────────────────────────────────────────────────────────┐
│ CHECKER Dashboard (only shows first 5 tests)                    │
│ - View test name, method, tester, date                         │
│ - Review test data values                                       │
└────────────────────────┬────────────────────────────────────────┘
                         ↓
                 Approve or Reject?
                         ↓
        ┌────────────────┴────────────────┐
        │                                  │
    APPROVE                            REJECT
        │                                  │
        ↓                                  ↓
Status: pending_approval      Status: rejected_by_checker
        │                                  │
        ↓                                  ↓
┌──────────────────┐              ┌──────────────────┐
│ ADMIN Dashboard  │              │ TESTER sees:     │
│ (QC Reports)     │              │ - Rejection      │
│                  │              │ - Reasons (✓)    │
│ Final approval   │              │ - Can edit       │
└────────┬─────────┘              │ - Resubmit       │
         ↓                         └──────────────────┘
    Approve/Reject                         │
         │                                 │
         ↓                                 │
   Status: approved            (Resubmit → back to Checker)
   or rejected_by_approver
```

### Tests 6-11 (Skip Checker):

```
┌─────────────────────────────────────────────────────────────────┐
│ TESTER submits test (Weathering/Seam/Fineness/Cut/Tenacity)    │
└────────────────────────┬────────────────────────────────────────┘
                         ↓
         ✅ Status: pending_approval (skips checker!)
                         ↓
┌─────────────────────────────────────────────────────────────────┐
│ ADMIN Dashboard (QC Reports) - receives directly               │
│ - No checker review needed                                      │
│ - Admin approves/rejects                                        │
└────────────────────────┬────────────────────────────────────────┘
                         ↓
                 Approve or Reject?
                         ↓
        ┌────────────────┴────────────────┐
        │                                  │
    APPROVE                            REJECT
        │                                  │
        ↓                                  ↓
Status: approved            Status: rejected_by_approver
                                          │
                                          ↓
                                  ┌──────────────────┐
                                  │ TESTER sees:     │
                                  │ - Rejection      │
                                  │ - Admin reasons  │
                                  │ - Can edit       │
                                  │ - Resubmit       │
                                  └──────────────────┘
                                          │
                          (Resubmit → back to Admin, not Checker)
```

---

## 💬 User Messages

### Tester Success Messages:

**First 5 Tests:**
```
✅ Successfully submitted 1 test(s): Thickness (Under 2kPa Pressure)
   | Report No: RPT-20251104-001
   | Status: Pending Checker Approval
```

**Tests 6-11:**
```
✅ Successfully submitted 1 test(s): Tenacity of Yarn
   | Report No: RPT-20251104-002
   | Status: Pending Admin Approval (Checker review not required)
```

---

## 👁️ Dashboard Views

### Checker Dashboard (`role: checker`):

**Header:**
```
📋 Pending QC Test Orders for Checking (3)
First 5 tests only: Thickness, GSM, Strip Tensile, CBR, Grab Tensile
```

**Table Columns:**
- Report Number
- Test Name (NEW!)
- Test Method
- Tester
- Date
- Actions (Approve/Reject)

**Filtering:**
- ✅ Only shows first 5 test types
- ✅ Tests 6-11 never appear here
- ✅ Joins with `test_standards` table to get test name

**When Empty:**
```
✅ No QC Test Orders Pending for Checking
All first 5 tests have been checked.
Note: Tests 6-11 go directly to admin.
```

---

### Admin Dashboard (`admin/qc_reports_dashboard.php`):

**Shows:**
- ✅ First 5 tests after checker approval (`pending_approval`)
- ✅ Tests 6-11 directly from tester (`pending_approval`)
- ✅ Both types appear in same dashboard

**Can:**
- Approve any test → `approved`
- Reject any test → `rejected_by_approver`

---

### Tester Dashboard (Rejected Reports Section):

**Shows:**
- Tests rejected by checker (`rejected_by_checker`)
- Tests rejected by admin (`rejected_by_approver`)

**Display:**
| Report Number | Status | Rejected By | Rejection Reason | Date |
|---------------|--------|-------------|------------------|------|
| RPT-xxx-001 | Rejected by Checker | John Doe | Incomplete data, Incorrect measurements | Nov 4 |
| RPT-xxx-002 | Rejected by Admin | Admin Name | Values don't match specifications | Nov 4 |

**Can:**
- View rejection reasons
- Edit the test
- Resubmit (goes back to appropriate reviewer)

---

## 🚫 Rejection Process

### Checker Rejection (First 5 Tests Only):

**Modal Opens With:**
- ☑️ **Checkbox Reasons (Mandatory - at least 1):**
  - Incomplete data
  - Incorrect measurements
  - Missing required fields
  - Data inconsistency
  - Calculation errors
- 📝 **Additional Comments** (Optional text area)

**Result:**
- Status → `rejected_by_checker`
- Saved in `checker_remarks` field
- Tester can edit and resubmit
- Resubmit → goes back to checker

### Admin Rejection (All Tests):

**Modal Opens With:**
- 📝 **Rejection Reason** (Required text area)

**Result:**
- Status → `rejected_by_approver`
- Saved in `admin_remarks` field
- Tester can edit and resubmit
- Resubmit → goes to:
  - First 5 tests → back to checker
  - Tests 6-11 → back to admin

---

## 🗄️ Database Schema

### Status Values:

| Status | Description | Who Sees It |
|--------|-------------|-------------|
| `pending_checker` | Submitted by tester, awaiting checker | Checker Dashboard |
| `pending_approval` | Checker approved OR tests 6-11 submitted | Admin QC Reports Dashboard |
| `approved` | Final approval by admin | Completed |
| `rejected_by_checker` | Rejected by checker | Tester (Rejected Reports) |
| `rejected_by_approver` | Rejected by admin | Tester (Rejected Reports) |

### Tracking Fields:

| Field | Type | Purpose |
|-------|------|---------|
| `test_standard_id` | INT | Links to test_standards table |
| `chosen_method` | VARCHAR(100) | Test method code |
| `inspector_id` | INT | Tester's user ID |
| `inspector_name` | VARCHAR(255) | Tester's full name |
| `checked_by` | VARCHAR(100) | Checker's name |
| `checked_at` | DATETIME | When checker reviewed |
| `checker_remarks` | TEXT | Checker rejection reasons |
| `approved_by` | VARCHAR(100) | Admin's name |
| `approved_at` | DATETIME | When admin reviewed |
| `admin_remarks` | TEXT | Admin rejection reasons |
| `status` | ENUM | Current workflow status |

---

## ✨ Key Features

1. **✅ Smart Routing**
   - First 5 tests automatically go to checker
   - Tests 6-11 automatically skip checker

2. **✅ Clear Communication**
   - Success messages indicate next step
   - Dashboard headers explain filtering

3. **✅ Mandatory Feedback**
   - Checker must select rejection reasons
   - Admin must provide rejection text

4. **✅ Efficient Workflow**
   - No bottleneck on simple tests
   - Expert review for critical tests

5. **✅ Edit & Resubmit**
   - Testers see rejection reasons
   - Can fix and resubmit
   - Proper routing on resubmit

6. **✅ Complete Tracking**
   - All actions timestamped
   - Full audit trail
   - Status always visible

---

## 🧪 Testing Scenarios

### Scenario 1: First 5 Test (Thickness)
1. ✅ Tester submits → "Pending Checker Approval"
2. ✅ Checker dashboard shows test with name
3. ✅ Checker approves → moves to admin
4. ✅ Admin approves → test complete

### Scenario 2: First 5 Test (GSM) - Rejection
1. ✅ Tester submits → "Pending Checker Approval"
2. ✅ Checker rejects with reasons
3. ✅ Tester sees rejection with checkbox reasons
4. ✅ Tester edits and resubmits
5. ✅ Goes back to checker

### Scenario 3: Test 6-11 (Tenacity)
1. ✅ Tester submits → "Pending Admin Approval (no checker)"
2. ✅ Does NOT appear in checker dashboard
3. ✅ Appears directly in admin dashboard
4. ✅ Admin approves → test complete

### Scenario 4: Test 6-11 (Tenacity) - Rejection
1. ✅ Tester submits → "Pending Admin Approval (no checker)"
2. ✅ Admin rejects with text reason
3. ✅ Tester sees rejection
4. ✅ Tester edits and resubmits
5. ✅ Goes back to admin (still skips checker)

---

## 🎯 Benefits

| Benefit | Description |
|---------|-------------|
| **Efficiency** | Tests that don't need technical review skip checker |
| **Quality** | Critical tests get expert checker review |
| **Clarity** | Users always know where their test is in the workflow |
| **Flexibility** | Easy to add/remove tests from checker list |
| **Accountability** | Full audit trail with names and timestamps |
| **User-Friendly** | Clear messages and mandatory feedback |

---

## 📚 Files Modified

1. ✅ `forms/qc_test_order.php`
   - Added test classification
   - Dynamic status assignment
   - Filtered checker dashboard
   - Updated success messages

2. ✅ `admin/qc_reports_dashboard.php`
   - Already handles both test types
   - Shows all `pending_approval` tests

3. ✅ Database migration
   - Added checker workflow columns
   - Status ENUM with all values

---

## 🚀 Ready to Use!

The selective checker workflow is now fully implemented and operational!

**Quick Reference:**
- **Tests 1-5**: Checker → Admin
- **Tests 6-11**: Admin only
- **Rejections**: Always bounce back to tester with reasons
- **Resubmit**: Routes back to appropriate reviewer

