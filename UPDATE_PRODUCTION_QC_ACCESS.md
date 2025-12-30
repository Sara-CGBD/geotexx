# Production User QC Access Update

## Summary
Updated access control to allow production users to access QC-related forms.

## Changes Made

### 1. AccessControl.php
- Added `ROLE_PRODUCTION_USER => PERMISSION_ENTRY` to the QC module
- This grants production users the ability to enter data in QC forms

### 2. Forms Now Accessible to Production Role
The following forms are now accessible to users with the `production_user` role:

1. **Length Calibration Entry** (`forms/length_calibration_entry.php`)
   - Production users can enter length calibration data
   - Record reference length, set in machine, actual length, and calibration length
   - Track by roll number and line number

2. **Daily GSM Check** (`forms/daily_gsm_check.php`)
   - Production users can perform daily GSM checks on the floor
   - Monitor GSM values for quality control
   - Track by roll number and line number

3. **Roll QC Report** (`forms/roll_qc_report.php`)
   - Production users can create QC reports for rolls
   - Document roll quality information
   - Reference tracking and line assignments

## Permission Level
- **PERMISSION_ENTRY**: Production users can create and submit data
- They cannot approve or perform checker functions (those remain with QC Inspector, Tester, Checker roles)

## Testing
To verify the changes:
1. Login as a user with `production_user` role
2. Navigate to:
   - Length Calibration Entry form
   - Daily GSM Check form
   - Roll QC Report form
3. Verify you can access and submit data

## Existing Access
These forms were previously accessible only to:
- Admin (full access)
- Management (view only)
- QC Inspector (entry)
- AGM Ops (approval)
- Tester (entry)
- Checker (check/verify)

Now **Production User** also has entry access to these QC forms.

## Notes
- Production users still cannot access other QC forms like test reports or approval workflows
- They only have data entry permission, not approval or checking permissions
- All other module permissions remain unchanged
- This aligns with the production workflow where production staff need to perform QC checks on the floor

