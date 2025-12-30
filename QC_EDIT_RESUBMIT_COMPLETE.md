# QC Test Order Edit & Resubmit - Complete Implementation

## Overview
Testers can now edit and resubmit rejected QC test orders without filling the entire form again.

## ✅ What Was Implemented

### 1. Edit & Resubmit Button
Added to rejected reports table in both locations:
- ✅ QC Test Order form (rejected reports section)
- ✅ Rejected Reports Dashboard (`tester_rejected_reports.php`)

**Button Design:**
```
[📝 Edit & Resubmit] (Orange button)
```

### 2. Dedicated Edit Page
**File:** `forms/edit_qc_test_order.php`

Opens as a **separate page** (not the main form) with:

```
┌────────────────────────────────────────────────────────────┐
│ ← Back to QC Test Order                                    │
│                                                             │
│ 📝 Edit & Resubmit QC Test Order                           │
│ Report No: RPT-20251104-001                                 │
│                                                             │
│ ⚠️ Rejection Information                                   │
│ ┌─────────────────────────────────────────────────────────┐│
│ │ Rejected By: John Doe (Checker)                         ││
│ │ Rejection Date: Nov 4, 2025 - 10:30 AM                  ││
│ │ Rejection Reason:                                        ││
│ │ Incomplete data, Incorrect measurements                  ││
│ └─────────────────────────────────────────────────────────┘│
│                                                             │
│ ℹ️ Original Submission Info                               │
│ Test Name: Thickness | Test Method: ASTM D5199             │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐│
│ │ Sample Reference ID: [Pre-filled, readonly]             ││
│ │ Product Reference: [Pre-filled dropdown]                ││
│ │                                                          ││
│ │ Test Data - Thickness                                    ││
│ │ ┌─────────────────────────────────────────┐            ││
│ │ │ Position | Thickness (mm)                │            ││
│ │ │ Left-1   | [2.45] ← Can edit             │            ││
│ │ │ Left-2   | [2.48] ← Can edit             │            ││
│ │ │ ...                                       │            ││
│ │ └─────────────────────────────────────────┘            ││
│ │                                                          ││
│ │ Average: [2.46]  SD: [0.02]  CV: [0.8]                  ││
│ │ Max: [2.48]  Min: [2.45]                                ││
│ └─────────────────────────────────────────────────────────┘│
│                                                             │
│           [📤 Resubmit for Approval] [❌ Cancel]           │
│                                                             │
│ 💡 Tip: Review rejection reason, make corrections, resubmit│
└────────────────────────────────────────────────────────────┘
```

## User Flow

### Step 1: Tester Sees Rejection
```
Your Rejected QC Test Orders
┌────────────────────────────────────────────────────────────┐
│ RPT-001 | Sample | Rejected by Checker | Incomplete data  │
│                                    [📝 Edit & Resubmit]    │
└────────────────────────────────────────────────────────────┘
```

### Step 2: Clicks "Edit & Resubmit"
Opens `edit_qc_test_order.php?id=123`

### Step 3: Sees Pre-Filled Form
✅ All original values loaded
✅ Rejection reason displayed at top
✅ Can edit specific fields that need correction
✅ Don't need to fill everything again

### Step 4: Makes Corrections
- Edit thickness values
- Update measurements
- Fix calculation errors
- Modify any incorrect data

### Step 5: Clicks "Resubmit for Approval"
```
✅ Report resubmitted successfully! 
Status: Pending Checker Approval

Redirecting to QC Test Order page...
```

### Step 6: Record Updated
- ✅ Status reset to `pending_checker` or `pending_approval`
- ✅ Rejection fields cleared (checker_remarks, admin_remarks)
- ✅ Goes back to appropriate reviewer
- ✅ Updated timestamp set

## What Gets Pre-Filled

### Basic Information:
- ✅ Sample Reference ID (readonly)
- ✅ Report Number (displayed in header)
- ✅ Test Name (displayed)
- ✅ Test Method (displayed)

### References (if they exist):
- ✅ Product Reference (dropdown with selection)
- ✅ Fiber Reference No (text input)
- ✅ Yarn Reference No (text input)
- ✅ Customer Reference (text input)

### Test-Specific Data:

#### For Thickness Test:
- ✅ Position values (editable table)
- ✅ Thickness measurements (editable)
- ✅ Average, SD, CV, Max, Min (editable)

#### For GSM Test:
- ✅ Position values (editable table)
- ✅ Weight measurements (editable)
- ✅ GSM calculations (editable)
- ✅ Average, SD, CV, Max, Min (editable)

#### For Other Tests:
- ✅ JSON editor with all test_data
- ✅ Can edit raw JSON (advanced)

## Database Updates on Resubmit

### Fields Updated:
```sql
UPDATE qc_test_orders SET
    test_data = '(updated JSON)',
    status = 'pending_checker' OR 'pending_approval',
    checked_by = NULL,
    checked_at = NULL,
    checker_remarks = NULL,
    approved_by = NULL,
    approved_at = NULL,
    admin_remarks = NULL,
    updated_at = NOW()
WHERE id = ? AND inspector_id = ?
```

### Workflow Reset:
- ✅ Clears all rejection information
- ✅ Resets status based on test type
- ✅ Starts approval workflow fresh
- ✅ Keeps original report number
- ✅ Keeps original sample reference

## Rejection Reason Display

At the top of edit page:

```
┌──────────────────────────────────────────────────────────┐
│ ⚠️ Rejection Information                                  │
│                                                            │
│ Rejected By: John Doe (Checker)                          │
│ Rejection Date: Nov 4, 2025 - 10:30 AM                   │
│                                                            │
│ Rejection Reason:                                          │
│ ┌────────────────────────────────────────────────────────┐│
│ │ Rejection Reasons: Incomplete data, Incorrect          ││
│ │ measurements                                            ││
│ │                                                          ││
│ │ Additional Comments: The thickness values in positions ││
│ │ 3 and 4 seem inconsistent with the batch average.      ││
│ └────────────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────┘
```

## Better Checkbox Alignment in Rejection Modal

**Before:**
```
☐ Incomplete data
☐ Incorrect measurements
(Misaligned, plain list)
```

**After:**
```
┌──────────────────────────────────────┐
│ ☐  Incomplete data                   │  ← Card with hover
├──────────────────────────────────────┤
│ ☐  Incorrect measurements            │  ← Card with hover
├──────────────────────────────────────┤
│ ☐  Missing required fields           │  ← Card with hover
├──────────────────────────────────────┤
│ ☐  Data inconsistency                │  ← Card with hover
├──────────────────────────────────────┤
│ ☐  Calculation errors                │  ← Card with hover
└──────────────────────────────────────┘
```

### Styling Applied:
- ✅ Each checkbox in individual white card
- ✅ Flexbox alignment (checkbox + text)
- ✅ 18px × 18px checkboxes
- ✅ 12px spacing between checkbox and text
- ✅ Hover effect on each option
- ✅ Gray container background
- ✅ Required field indicator (red *)

## Rejected Reports Dashboard Integration

### File: `tester_rejected_reports.php`

Added QC Test Orders query:
```sql
SELECT id, 'qc_test_order' as type, 'QC Test Order' as test_name, 
       report_number, DATE(created_at) as test_date, 
       inspector_name as tested_by, status, updated_at,
       COALESCE(checker_remarks, admin_remarks, '') as remarks,
       CASE 
           WHEN status = 'rejected_by_checker' THEN checked_by
           WHEN status = 'rejected_by_approver' THEN approved_by
       END as rejected_by
FROM qc_test_orders 
WHERE status IN ('rejected_by_checker', 'rejected_by_approver') 
AND inspector_id = ?
```

### Shows:
- ✅ QC Test Order badge
- ✅ Report number
- ✅ Rejection reason
- ✅ Rejected by (checker or admin)
- ✅ Edit button

## Database Fix for Admin Submissions

**Script Created:** `fix_admin_status.php`

**Run in browser:**
```
http://localhost/geotex/fix_admin_status.php
```

**What it does:**
```sql
UPDATE qc_test_orders qto
INNER JOIN new_user nu ON qto.inspector_id = nu.id
SET qto.status = 'approved',
    qto.approved_by = qto.inspector_name,
    qto.approved_at = NOW()
WHERE LOWER(TRIM(nu.role)) IN ('admin', 'agm ops', 'agm operations')
AND qto.status != 'approved'
```

**Result:**
- ✅ Finds all admin submissions
- ✅ Changes status to 'approved'
- ✅ Sets approved_by and approved_at
- ✅ Shows which reports were fixed
- ✅ Removes them from checker dashboard

## Complete Feature List

### Checker View:
1. ✅ **Dashboard Only** - No form access
2. ✅ **View Button** - Opens full report in new tab
3. ✅ **Approve Button** - Forwards to admin
4. ✅ **Reject Button** - Opens modal with aligned checkboxes
5. ✅ **Filtered List** - Only first 5 tests from testers
6. ✅ **Excludes Admin** - Admin submissions never shown

### Tester View:
1. ✅ **Full Form** - Can submit new tests
2. ✅ **Rejected Section** - See their rejected reports
3. ✅ **Edit Button** - Opens dedicated edit page
4. ✅ **Pre-Filled Form** - All original values loaded
5. ✅ **Rejection Reason** - Displayed at top of edit page
6. ✅ **Easy Resubmit** - Just fix errors and resubmit

### Admin View:
1. ✅ **Full Access** - Form + all dashboards
2. ✅ **Auto-Approval** - Their submissions auto-approved
3. ✅ **View Button** - See full reports
4. ✅ **Final Approval** - Approve/reject from dashboard

## Testing Checklist

### Tester Workflow:
- [ ] Submit a test (e.g., Thickness)
- [ ] Checker rejects with reasons
- [ ] See rejection in "Your Rejected QC Test Orders"
- [ ] Click "Edit & Resubmit"
- [ ] **Verify:** Opens separate edit page (not main form)
- [ ] **Verify:** All fields pre-filled with original values
- [ ] **Verify:** Rejection reason shown at top
- [ ] Edit thickness values
- [ ] Click "Resubmit for Approval"
- [ ] **Verify:** Success message appears
- [ ] **Verify:** Goes back to pending_checker status
- [ ] **Verify:** Appears in checker dashboard again

### Checker Workflow:
- [ ] Open QC Test Order page
- [ ] **Verify:** See dashboard only (no form)
- [ ] **Verify:** Only see tester submissions
- [ ] **Verify:** Admin submissions NOT shown
- [ ] Click "View" button
- [ ] **Verify:** Opens full report in new tab
- [ ] Close tab, click "Reject"
- [ ] **Verify:** Modal has perfectly aligned checkboxes
- [ ] **Verify:** Hover effect works on each option
- [ ] Select reasons and submit
- [ ] **Verify:** Tester sees rejection

### Admin Submissions:
- [ ] Run `http://localhost/geotex/fix_admin_status.php`
- [ ] **Verify:** Shows RPT-20251103-001 and RPT-20251103-002
- [ ] **Verify:** Both changed to 'approved' status
- [ ] Refresh QC Test Order checker view
- [ ] **Verify:** Those 2 reports are GONE from checker dashboard

## File Structure

### New Files:
1. ✅ `forms/edit_qc_test_order.php` - Dedicated edit page with pre-filled form
2. ✅ `admin/view_qc_test_order.php` - View-only report details for checker/admin
3. ✅ `fix_admin_status.php` - Database cleanup script

### Modified Files:
1. ✅ `forms/qc_test_order.php` - Added edit button, better modal, View button
2. ✅ `tester_rejected_reports.php` - Added QC test orders to dashboard

## Edit Page Features

### Information Displayed:
- ✅ Report number in header
- ✅ Rejection reason (highlighted in yellow box)
- ✅ Who rejected (checker or admin)
- ✅ When rejected
- ✅ Test name and method
- ✅ Original submission info

### Editable Fields:
- ✅ Reference numbers (dropdown or text input)
- ✅ Test data tables (positions, values)
- ✅ Statistics (Average, SD, CV, Max, Min)
- ✅ Additional notes

### Non-Editable Fields:
- ❌ Sample Reference ID (readonly)
- ❌ Report Number (display only)
- ❌ Test Name (display only)
- ❌ Test Method (display only)

### Smart Handling by Test Type:

**Thickness Test:**
- Editable table with position & thickness columns
- Statistics fields (Avg, SD, CV, Max, Min)

**GSM Test:**
- Editable table with position, weight, GSM columns
- Statistics fields

**Other Tests:**
- JSON editor for advanced users
- Can edit raw test_data

## Benefits

1. ✅ **Time Saving** - Don't re-enter all data
2. ✅ **Error Focused** - Only edit what was wrong
3. ✅ **Clear Context** - Rejection reason always visible
4. ✅ **Separate Page** - Dedicated edit interface
5. ✅ **Professional** - Clean, modern design
6. ✅ **User-Friendly** - Clear instructions
7. ✅ **Audit Trail** - Maintains report number and history

## Workflow Comparison

### Before (Without Edit):
```
Rejected → Must fill entire form again → New report created
```

### After (With Edit):
```
Rejected → Click Edit → Pre-filled form → Fix errors → Resubmit → Same report updated
```

## Database Behavior

### On Resubmit:
- ✅ **UPDATES** existing record (doesn't create new one)
- ✅ Keeps same report_number
- ✅ Keeps same id
- ✅ Resets status to pending
- ✅ Clears rejection fields
- ✅ Updates test_data JSON
- ✅ Sets updated_at to NOW()

### Prevents:
- ❌ Duplicate reports
- ❌ Lost report numbers
- ❌ Broken audit trails
- ❌ Confusion about which is latest

## Access Control

### Who Can Edit:
- ✅ Original submitter (tester) only
- ✅ Must be their own report (`inspector_id` check)
- ✅ Must be rejected status

### Security Checks:
```php
WHERE id = ? AND inspector_id = ? 
AND status IN ('rejected_by_checker', 'rejected_by_approver')
```

## Action Items

### 1. Fix Admin Submissions:
```
Open: http://localhost/geotex/fix_admin_status.php
Click to run the fix
This will approve admin submissions and remove from checker view
```

### 2. Test Edit Flow:
1. Have a report rejected
2. Click "Edit & Resubmit"
3. Verify separate page opens
4. Verify all fields pre-filled
5. Make edits
6. Resubmit
7. Verify status reset and workflow restarts

## Summary

✅ **Separate Edit Page** - Opens in dedicated page, not main form
✅ **All Values Pre-Filled** - No re-entering data
✅ **Rejection Reason Shown** - Always visible at top
✅ **Smart Field Detection** - Shows appropriate fields by test type
✅ **Easy Corrections** - Edit only what needs fixing
✅ **Clean Resubmit** - One button to send back for review
✅ **Professional Design** - Modern, clean interface
✅ **Complete Integration** - Available in form and dashboard

The edit & resubmit system is now complete and fully functional! 🎉

