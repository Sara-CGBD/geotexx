# QC Test Order Checker Workflow Update

## Changes Summary
Updated the QC Test Order workflow to implement selective checker approval based on test type.

## New Workflow Logic

### First 5 Tests (Require Checker Approval):
1. ✅ **Thickness (Under 2kPa Pressure)** - ASTM D5199, ISO 9863-1
2. ✅ **Mass Per Unit Area (GSM)** - ASTM D5261, ISO 9864
3. ✅ **Strip Tensile Test** - ASTM D4595, ISO 10319
4. ✅ **CBR Puncture Resistance** - ASTM D6241, ISO 12236
5. ✅ **Grab Tensile Test** - ASTM D4632

**Workflow:** Tester → **Checker** → Admin → Approved/Rejected

### Tests 6-11 (Skip Checker, Direct to Admin):
6. ⏭️ **Weathering Exposure Test** - ASTM D4533
7. ⏭️ **Seam/Joint Test** - ISO 10321
8. ⏭️ **Fineness of Fiber** - ISO 1973
9. ⏭️ **Cut Length of Fiber** - ASTM D5103, ASTM D5199
10. ⏭️ **Tenacity of Fiber** - ISO 5079
11. ⏭️ **Tenacity of Yarn** - ASTM D2256

**Workflow:** Tester → **Admin** → Approved/Rejected (no checker review)

## Detailed Workflow

### For First 5 Tests:

```
Tester submits → status: pending_checker
      ↓
Checker Dashboard (shows only first 5 tests)
      ↓
Checker reviews test data
      ↓
   Approve? ──No──> status: rejected_by_checker
      |                ↓
     Yes          Tester sees rejection with reasons
      |                ↓
      |          Tester edits and resubmits
      ↓
status: pending_approval
      ↓
Admin QC Reports Dashboard
      ↓
Admin approves/rejects
      ↓
status: approved / rejected_by_approver
```

### For Tests 6-11:

```
Tester submits → status: pending_approval (skip checker)
      ↓
Admin QC Reports Dashboard (directly)
      ↓
Admin approves/rejects
      ↓
status: approved / rejected_by_approver
      ↓
If rejected → Tester sees rejection with reasons
```

## Implementation Details

### 1. Test Classification Array
```php
$tests_requiring_checker = [
    'Thickness (Under 2kPa Pressure)',
    'Mass Per Unit Area (GSM)',
    'Strip Tensile Test',
    'CBR Puncture Resistance',
    'Grab Tensile Test'
];
```

### 2. Dynamic Status Assignment
```php
// Determine status based on test type
$status = in_array($selected['test_name'], $tests_requiring_checker) 
    ? 'pending_checker'     // First 5 tests
    : 'pending_approval';    // Tests 6-11
```

### 3. Checker Dashboard Filtering
- **SQL Query**: Joins with `test_standards` table to get test name
- **Filter**: Only displays tests from the first 5 tests list
- **Display**: Shows test name, method, tester, and date

### 4. Success Messages
```php
// First 5 tests
"Status: Pending Checker Approval"

// Tests 6-11
"Status: Pending Admin Approval (Checker review not required)"
```

## User Experience

### Tester Perspective:
**Submitting First 5 Tests:**
```
✅ Successfully submitted 1 test(s): Thickness (Under 2kPa Pressure) 
   | Report No: RPT-20251104-001 
   | Status: Pending Checker Approval
```

**Submitting Other Tests:**
```
✅ Successfully submitted 1 test(s): Tenacity of Yarn 
   | Report No: RPT-20251104-002 
   | Status: Pending Admin Approval (Checker review not required)
```

### Checker Perspective:
- Dashboard header: "First 5 tests only: Thickness, GSM, Strip Tensile, CBR, Grab Tensile"
- Only sees the first 5 test types
- Tests 6-11 never appear in checker dashboard
- Can approve/reject with mandatory checkbox reasons for rejection

### Admin Perspective:
- Sees ALL tests in QC Reports Dashboard
- First 5 tests arrive after checker approval (status: `pending_approval`)
- Tests 6-11 arrive directly from tester (status: `pending_approval`)
- Can approve/reject any test
- Rejections bounce back to tester with reasons

## Rejection Handling

### Rejection by Checker (First 5 Tests Only):
1. Checker clicks "Reject" button
2. Modal opens with checkboxes for rejection reasons:
   - Incomplete data
   - Incorrect measurements
   - Missing required fields
   - Data inconsistency
   - Calculation errors
3. Checker must select at least one reason (mandatory)
4. Optional: Additional comments field
5. Status changes to `rejected_by_checker`
6. Tester sees rejection in "Your Rejected QC Test Orders" section
7. Tester can edit and resubmit

### Rejection by Admin (All Tests):
1. Admin clicks "Reject" button
2. Modal opens with text area for rejection reason
3. Rejection reason is required
4. Status changes to `rejected_by_approver`
5. Tester sees rejection in "Your Rejected QC Test Orders" section
6. Tester can edit and resubmit

## Database Fields Used

### Status Values:
- `pending_checker` - Waiting for checker (first 5 tests only)
- `pending_approval` - Waiting for admin (all tests after checker approval OR tests 6-11 directly)
- `approved` - Fully approved
- `rejected_by_checker` - Rejected by checker (first 5 tests only)
- `rejected_by_approver` - Rejected by admin (any test)

### Tracking Fields:
- `checked_by` - Checker's name (for first 5 tests)
- `checked_at` - Checking timestamp
- `checker_remarks` - Rejection reasons from checker
- `approved_by` - Admin's name
- `approved_at` - Approval timestamp
- `admin_remarks` - Rejection reasons from admin

## Benefits

1. ✅ **Efficient Workflow** - Tests that don't need technical review skip checker
2. ✅ **Quality Control** - Critical tests (first 5) get expert checker review
3. ✅ **Clear Communication** - Status messages tell users what's happening
4. ✅ **Reduced Bottleneck** - Admin doesn't wait for checker on simple tests
5. ✅ **Mandatory Feedback** - Rejected tests always include reasons
6. ✅ **Edit & Resubmit** - Testers can fix and resubmit rejected tests

## Testing Checklist

### Test First 5 Tests Workflow:
- [ ] Submit Thickness test → should show "Pending Checker Approval"
- [ ] Checker dashboard shows the test
- [ ] Checker approves → moves to admin dashboard
- [ ] Checker rejects with reasons → tester sees rejection
- [ ] Tester edits and resubmits → back to checker

### Test Other Tests Workflow:
- [ ] Submit Tenacity of Yarn → should show "Pending Admin Approval (Checker review not required)"
- [ ] Checker dashboard does NOT show the test
- [ ] Admin dashboard shows the test directly
- [ ] Admin approves → test is approved
- [ ] Admin rejects → tester sees rejection
- [ ] Tester edits and resubmits → goes to admin again

### Test Admin Dashboard:
- [ ] Shows both checker-approved tests AND direct tests
- [ ] Can approve/reject any test type
- [ ] Rejections show proper status

