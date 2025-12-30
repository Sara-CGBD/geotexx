# ðŸš€ Role-Based Access Control - Quick Deployment Guide

## **âœ… SYSTEM IS READY FOR DEPLOYMENT!**

---

## **ðŸ“‹ PRE-DEPLOYMENT CHECKLIST**

- [x] Core AccessControl system created
- [x] Role-specific navigation menus implemented
- [x] 14+ form pages protected with access control
- [x] Database schema SQL script prepared
- [x] Documentation completed

---

## **ðŸŽ¯ STEP-BY-STEP DEPLOYMENT**

### **Step 1: Backup Your Database** âš ï¸
```bash
# Create a backup before making changes
mysqldump -u root -p geobagg > backup_before_rbac_$(date +%Y%m%d).sql
```

### **Step 2: Update Database Schema** ðŸ“Š
```bash
# Navigate to your project directory
cd c:\xampp\htdocs\geotex

# Run the SQL script
mysql -u root -p geobagg < database/add_new_roles.sql
```

Or manually run the SQL:
```sql
-- Update role column to support all roles
ALTER TABLE new_user MODIFY COLUMN role VARCHAR(50) DEFAULT 'user';

-- Add index for performance
CREATE INDEX IF NOT EXISTS idx_user_role ON new_user(role);
```

### **Step 3: Restart Apache** ðŸ”„
```bash
# Restart Apache to ensure all changes are loaded
# In XAMPP Control Panel: Stop Apache, then Start Apache
```

### **Step 4: Test with Admin Account** ðŸ§ª
1. Login as `admin`
2. Verify you can see all 9 modules in the navigation
3. Test accessing each module
4. Confirm no access denied errors for admin

---

## **ðŸ‘¥ CREATE TEST USERS FOR EACH ROLE**

### **Option 1: Using phpMyAdmin**
1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Select `geobagg` database
3. Go to `new_user` table
4. Insert test users for each role

### **Option 2: Using SQL** (Recommended)
```sql
-- Generate password hash first (use PHP):
-- php -r "echo password_hash('Test@123', PASSWORD_DEFAULT);"

-- Insert test users (replace $2y$10$... with your actual password hash)
INSERT INTO new_user (username, password, full_name, email, role, is_active) VALUES
('prod_test', '$2y$10$YourHashedPasswordHere', 'Production Test User', 'prod@test.com', 'production_user', 1),
('qc_test', '$2y$10$YourHashedPasswordHere', 'QC Inspector Test', 'qc@test.com', 'qc_inspector', 1),
('tester_test', '$2y$10$YourHashedPasswordHere', 'Lab Tester Test', 'tester@test.com', 'tester', 1),
('checker_test', '$2y$10$YourHashedPasswordHere', 'Checker Test User', 'checker@test.com', 'checker', 1),
('agm_test', '$2y$10$YourHashedPasswordHere', 'AGM Ops Test', 'agm@test.com', 'agm ops', 1),
('finance_test', '$2y$10$YourHashedPasswordHere', 'Finance Test User', 'finance@test.com', 'finance_user', 1),
('planning_test', '$2y$10$YourHashedPasswordHere', 'Planning Test User', 'planning@test.com', 'planning_user', 1),
('mgmt_test', '$2y$10$YourHashedPasswordHere', 'Management Test', 'mgmt@test.com', 'management', 1);
```

### **Generate Password Hashes:**
```php
<?php
// Run this PHP script to generate password hashes
$password = "Test@123";
echo password_hash($password, PASSWORD_DEFAULT);
?>
```

---

## **ðŸ§ª TESTING PROTOCOL**

### **Test 1: Admin Access** (5 min)
- [ ] Login as admin
- [ ] Verify all 9 modules visible in navigation
- [ ] Access 2-3 pages from each module
- [ ] Confirm no access denied errors

### **Test 2: Production User** (3 min)
- [ ] Login as production_user
- [ ] Verify ONLY see: Roll Production, Production, Scrap, Recycle
- [ ] Try accessing `forms/fiber_entry.php` â†’ âœ… Should work
- [ ] Try accessing `forms/qc_entry.php` (directly via URL) â†’ âŒ Should get Access Denied

### **Test 3: QC Inspector** (3 min)
- [ ] Login as qc_inspector
- [ ] Verify ONLY see: QC module
- [ ] Access QC Entry form â†’ âœ… Should work
- [ ] Try accessing `forms/fiber_entry.php` â†’ âŒ Should get Access Denied

### **Test 4: Tester** (3 min)
- [ ] Login as tester
- [ ] Verify ONLY see: Lab Testing forms
- [ ] Access Sewing Thread Report â†’ âœ… Should work
- [ ] Try accessing `forms/fiber_entry.php` â†’ âŒ Should get Access Denied

### **Test 5: Checker** (2 min)
- [ ] Login as checker
- [ ] Verify ONLY see: Fabric Pre-Production Test
- [ ] Access Fabric Pre-Production Test â†’ âœ… Should work
- [ ] Try accessing other QC forms â†’ âŒ Should get Access Denied

### **Test 6: AGM Ops** (3 min)
- [ ] Login as agm ops
- [ ] Verify see: QC module + Production monitoring
- [ ] Can approve QC reports â†’ âœ… Should work
- [ ] View reports â†’ âœ… Should work

### **Test 7: Finance User** (2 min)
- [ ] Login as finance_user
- [ ] Verify ONLY see: Finance module
- [ ] Access BOM Management â†’ âœ… Should work
- [ ] Try accessing production forms â†’ âŒ Should get Access Denied

### **Test 8: Planning User** (2 min)
- [ ] Login as planning_user
- [ ] Verify see: Planning module + view access
- [ ] Access Target Entry â†’ âœ… Should work
- [ ] Try editing production data â†’ âŒ Should get Access Denied (view only)

### **Test 9: Management** (2 min)
- [ ] Login as management
- [ ] Verify can view all reports
- [ ] Access QC reports â†’ âœ… Should work (view only)
- [ ] Try editing any data â†’ âŒ Should not have edit buttons

---

## **ðŸ”§ TROUBLESHOOTING**

### **Issue: "Access Denied" for Admin**
**Solution:**
- Check `$_SESSION['role']` value
- Ensure it's exactly `'admin'` (lowercase)
- Clear browser cookies and re-login

### **Issue: Menu Items Not Showing**
**Solution:**
1. Check `index.php` - verify role name matches
2. Clear browser cache
3. Check for JavaScript errors in browser console

### **Issue: "Class 'AccessControl' not found"**
**Solution:**
- Verify `config/AccessControl.php` exists
- Check `require_once` path in form files
- Ensure file permissions are correct (755)

### **Issue: Database Error on Role Update**
**Solution:**
```sql
-- Check current role column type
DESCRIBE new_user;

-- If it's ENUM, convert to VARCHAR
ALTER TABLE new_user MODIFY COLUMN role VARCHAR(50) DEFAULT 'user';
```

---

## **ðŸ“Š MONITORING & MAINTENANCE**

### **Check Active Users by Role:**
```sql
SELECT role, COUNT(*) as count, is_active
FROM new_user
GROUP BY role, is_active
ORDER BY role;
```

### **View Access Logs:**
```sql
-- If you have an access_log table
SELECT * FROM access_log
WHERE action LIKE '%access_denied%'
ORDER BY created_at DESC
LIMIT 20;
```

### **Update User Role:**
```sql
UPDATE new_user
SET role = 'production_user'
WHERE username = 'john_doe';
```

---

## **ðŸŽ“ TRAINING MATERIALS**

### **For Users:**
1. **Login Credentials** - Provide username and initial password
2. **Role Description** - Explain what modules they can access
3. **Navigation Guide** - Show where to find their forms
4. **Support Contact** - Who to contact for access issues

### **For Admins:**
1. **How to Create New Users** - Admin > Add User
2. **How to Assign Roles** - Select from dropdown
3. **How to Handle Access Issues** - Check role assignment
4. **How to Modify Permissions** - Edit `config/AccessControl.php`

---

## **ðŸ” SECURITY BEST PRACTICES**

1. âœ… **Change Default Passwords** - Force password change on first login
2. âœ… **Regular Audits** - Review user access quarterly
3. âœ… **Principle of Least Privilege** - Give minimum required access
4. âœ… **Monitor Failed Access Attempts** - Check for unauthorized access
5. âœ… **Keep System Updated** - Apply security patches

---

## **ðŸ“ˆ POST-DEPLOYMENT CHECKLIST**

- [ ] All test users created successfully
- [ ] All 9 roles tested and working
- [ ] Access denial working correctly
- [ ] Navigation menus displaying correctly for each role
- [ ] No errors in Apache error log
- [ ] Backup verified and stored safely
- [ ] User training materials prepared
- [ ] Support process established
- [ ] Admin access verified
- [ ] Documentation distributed to team

---

## **ðŸŽ‰ SUCCESS CRITERIA**

Your deployment is successful when:

1. âœ… All 9 roles have distinct navigation menus
2. âœ… Users can only access modules assigned to their role
3. âœ… Direct URL access to unauthorized pages shows "Access Denied"
4. âœ… No errors in system logs
5. âœ… All test cases pass
6. âœ… Users can perform their job functions without issues

---

## **ðŸ“ž SUPPORT**

### **For Technical Issues:**
- Check `IMPLEMENTATION_SUMMARY.md` for detailed architecture
- Review `ROLE_BASED_ACCESS_CONTROL_IMPLEMENTATION.md` for implementation details
- Examine `config/AccessControl.php` for permission logic

### **To Modify Permissions:**
- Edit `config/AccessControl.php`
- Update `$modulePermissions` array
- No need to modify individual pages

### **To Add New Role:**
1. Add constant in `AccessControl.php`
2. Define permissions in `$modulePermissions`
3. Add menu in `index.php`
4. Test thoroughly

---

**ðŸŽŠ Your Role-Based Access Control System is Ready!**

**Deployment Time:** ~30 minutes  
**Testing Time:** ~25 minutes  
**Total Implementation Time:** ~1 hour

Good luck with your deployment! ðŸš€


