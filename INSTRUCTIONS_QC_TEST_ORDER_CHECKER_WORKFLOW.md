# QC Test Order Checker Workflow Implementation

## Overview
A complete two-tier approval workflow has been implemented for QC Test Orders:
1. **Tester** submits QC test orders (first 5 tests only)
2. **Checker** reviews and approves/rejects
3. **Admin** gives final approval
4. Approved orders appear in Admin's QC Reports Dashboard

## Database Migration

### Step 1: Run the Migration Script
Execute the following command to add checker workflow columns to the `qc_test_orders` table:

```bash
php apply_qc_checker_migration.php
```

This will:
- Create the `qc_test_orders` table if it doesn't exist
- Add checker workflow columns: `status`, `checked_by`, `checked_at`, `checker_remarks`, `approved_by`, `approved_at`, `admin_remarks`, `inspector_name`
- Add indexes for performance
- Update existing records to `pending_checker` status

### Alternative: Manual SQL Execution
If the PHP script doesn't work, manually execute the SQL commands in:
`database/add_qc_test_order_checker_workflow.sql`

## Features Implemented

### 1. Tester Role (QC Inspector, Tester)
- **Submit Test Orders**: Can submit QC test orders for the first 5 tests:
  - Thickness (Under 2kPa Pressure)
  - Mass Per Unit Area (GSM)
  - Strip Tensile Test
  - CBR Puncture Resistance
  - Grab Tensile Test
- **View Rejected Reports**: See all rejected reports with rejection reasons
- **Resubmit**: Can review rejection comments and edit/resubmit forms
- Orders are saved with status `pending_checker`

### 2. Checker Role
- **Dashboard View**: See all pending QC test orders awaiting checking
- **Approve**: Forward reports to admin with status `pending_approval`
- **Reject with Reasons**: Reject with checkbox reasons:
  - Incomplete data
  - Incorrect measurements
  - Missing required fields
  - Data inconsistency
  - Calculation errors
  - Additional comments field
- Rejected reports go back to tester with status `rejected_by_checker`

### 3. Admin Role (Admin, AGM Ops)
- **Dashboard View**: See all checker-approved QC test orders
- **Final Approval**: Approve with status `approved`
- **Final Rejection**: Reject with comments, status `rejected_by_approver`
- View approved QC test orders in **Admin QC Reports Dashboard**

## User Interface

### QC Test Order Form (`forms/qc_test_order.php`)
The form now displays different sections based on user role:

#### For Checkers:
- **Pending Orders Section**: Table showing all orders pending checking
- **Approve/Reject Actions**: Inline buttons for each order
- **Rejection Modal**: Popup with checkboxes for standard rejection reasons

#### For Admin:
- **Approved Orders Section**: Table showing checker-approved orders
- **Final Approve/Reject**: Buttons to give final approval

#### For Testers:
- **Rejected Reports Section**: Table showing their rejected reports
- **View Rejection Reasons**: See who rejected and why
- **Resubmit Capability**: Can edit and resubmit the same report

### Admin QC Reports Dashboard (`admin/qc_reports_dashboard.php`)
- Now includes QC Test Orders in the pending reports list
- Shows QC Test Orders that are `pending_approval` (approved by checker)
- Admin can approve/reject from dashboard
- Approved QC Test Orders appear with report number and test method

## Status Flow

```
Tester Submits
      ↓
[pending_checker]
      ↓
Checker Reviews
      ↓
   Approve? ──No──> [rejected_by_checker] ──> Tester sees rejection ──> Can resubmit
      ↓
    Yes
      ↓
[pending_approval]
      ↓
Admin Reviews
      ↓
   Approve? ──No──> [rejected_by_approver] ──> Tester sees rejection
      ↓
    Yes
      ↓
  [approved]
      ↓
Appears in QC Reports Dashboard
```

## Files Modified

### 1. Database
- `database/add_qc_test_order_checker_workflow.sql` - Migration script
- `apply_qc_checker_migration.php` - PHP migration runner

### 2. Forms
- `forms/qc_test_order.php` - Main form with checker/tester/admin dashboards
  - Added role checks
  - Added checker approval/rejection handling
  - Added admin approval/rejection handling
  - Added pending orders dashboard for checker
  - Added approved orders dashboard for admin
  - Added rejected reports view for tester
  - Added rejection modals with reasons
  - Updated INSERT to include status and inspector_name

### 3. Configuration
- `config/AccessControl.php` - Updated to allow checker access to `qc_test_order`

### 4. Admin Dashboard
- `admin/qc_reports_dashboard.php` - Added QC Test Orders to pending reports
  - Added query for `pending_approval` status
  - Added to table map
  - Added view link
  - Updated approval/rejection logic for QC Test Orders

## Testing Checklist

### Tester Workflow
- [ ] Login as tester
- [ ] Submit a QC test order (any of the first 5 tests)
- [ ] Verify order appears in checker's dashboard
- [ ] After rejection, verify rejected report appears in tester's view
- [ ] Verify rejection reasons are displayed

### Checker Workflow
- [ ] Login as checker
- [ ] Verify pending orders appear in dashboard
- [ ] Approve an order
- [ ] Verify it moves to admin's dashboard
- [ ] Reject an order with reasons
- [ ] Verify tester sees rejection

### Admin Workflow
- [ ] Login as admin
- [ ] Verify checker-approved orders appear
- [ ] Approve an order
- [ ] Verify it appears in QC Reports Dashboard
- [ ] Reject an order
- [ ] Verify tester sees rejection

## Notes

- The first 5 tests of QC Test Order are:
  1. Thickness (Under 2kPa Pressure)
  2. Mass Per Unit Area (GSM)
  3. Strip Tensile Test
  4. CBR Puncture Resistance
  5. Grab Tensile Test

- Rejection reasons are stored in `checker_remarks` (for checker) and `admin_remarks` (for admin)

- The form maintains all existing functionality while adding the approval workflow

- Only users with appropriate roles can access their respective dashboards

## Troubleshooting

### Migration Issues
If the migration script doesn't run:
1. Check PHP version compatibility
2. Verify database connection in `config/security_config.php`
3. Manually run SQL commands from `database/add_qc_test_order_checker_workflow.sql`
4. Verify table exists: `DESCRIBE qc_test_orders;`

### Permission Issues
- Ensure checker users have `role = 'checker'` in the `new_user` table
- Verify AccessControl module is loaded properly
- Check session variables are set correctly

### Display Issues
- Clear browser cache
- Check if JavaScript is enabled
- Verify modals appear on rejection button click
- Check console for JavaScript errors

## Future Enhancements

Potential improvements:
1. Email notifications for rejections
2. View detailed test data in dashboards
3. Export approved reports
4. Audit log for approval actions
5. Bulk approval functionality
6. Search and filter in dashboards

