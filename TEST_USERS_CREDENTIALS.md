# 🔐 Test User Credentials - Role-Based Access Control

## ✅ **ALL TEST USERS CREATED SUCCESSFULLY!**

---

## 📋 **LOGIN CREDENTIALS**

### **Password for ALL test users: `Test@123`**

| # | Role | Username | Access Level | What They Can See |
|---|------|----------|--------------|-------------------|
| 1 | **Planning User** | `planning_test` | Entry | Planning module (Target, Project, BOM) + View production reports |
| 2 | **Production User** | `prod_test` | Entry | Roll Production, Production, Scrap, Recycle modules |
| 3 | **QC Inspector** | `qc_test` | Entry | All QC forms (entry only) |
| 4 | **Lab Tester** | `tester_test` | Entry | Lab testing forms only (Sewing, UV, Fiber, Fabric, Sun tests) |
| 5 | **Checker** | `checker_test` | Check | Fabric Pre-Production Test only |
| 6 | **AGM Operations** | `agm_test` | Approve | QC approval + Production monitoring |
| 7 | **Finance User** | `finance_test` | Full | Finance module + BOM access |
| 8 | **Management** | `mgmt_test` | View Only | All reports and analytics (read-only) |
| 9 | **Admin** | `admin` | Full Access | Everything (all 9 modules) |

---

## 🚀 **QUICK TEST**

### **To Test Planning Module:**
1. Go to: `http://localhost/geotex/login.html`
2. Username: `planning_test`
3. Password: `Test@123`
4. You should see:
   - ✅ Dashboard Overview
   - ✅ Planning Module
     - Target Setting
     - Project Planning
     - BOM Entry
     - Target vs Actual
   - ✅ View Access (Reports)
     - Roll Production Summary
     - FG Stock Summary

---

## 🎯 **TEST EACH ROLE**

### **1. Planning User Test**
```
Username: planning_test
Password: Test@123
Expected: Planning module + View reports
```

### **2. Production User Test**
```
Username: prod_test
Password: Test@123
Expected: Roll Production, Production, Scrap, Recycle
```

### **3. QC Inspector Test**
```
Username: qc_test
Password: Test@123
Expected: All QC forms
```

### **4. Lab Tester Test**
```
Username: tester_test
Password: Test@123
Expected: Lab testing forms only
```

### **5. Checker Test**
```
Username: checker_test
Password: Test@123
Expected: Fabric Pre-Production Test only
```

### **6. AGM Operations Test**
```
Username: agm_test
Password: Test@123
Expected: QC approval + Production monitoring
```

### **7. Finance User Test**
```
Username: finance_test
Password: Test@123
Expected: Finance module + BOM
```

### **8. Management Test**
```
Username: mgmt_test
Password: Test@123
Expected: View all reports (no editing)
```

---

## 🔍 **VERIFY IN DATABASE**

To see all test users in phpMyAdmin:

```sql
SELECT username, full_name, email, role, is_active 
FROM new_user 
WHERE username LIKE '%_test';
```

---

## 🎨 **ACCESS MATRIX**

| Module | Planning | Prod | QC Insp | Tester | Checker | AGM | Finance | Mgmt | Admin |
|--------|:--------:|:----:|:-------:|:------:|:-------:|:---:|:-------:|:----:|:-----:|
| QC | ❌ | ❌ | ✏️ | ✏️ | ✔️ | ✅ | ❌ | 👁️ | ✅ |
| Roll Prod | 👁️ | ✏️ | ❌ | ❌ | ❌ | 👁️ | ❌ | 👁️ | ✅ |
| Production | 👁️ | ✏️ | ❌ | ❌ | ❌ | 👁️ | ❌ | 👁️ | ✅ |
| Scrap | ❌ | ✏️ | ❌ | ❌ | ❌ | 👁️ | ❌ | 👁️ | ✅ |
| FG | 👁️ | ✏️ | ❌ | ❌ | ❌ | 👁️ | 👁️ | 👁️ | ✅ |
| Recycle | ❌ | ✏️ | ❌ | ❌ | ❌ | 👁️ | ❌ | 👁️ | ✅ |
| Planning | ✏️ | ❌ | ❌ | ❌ | ❌ | 👁️ | ❌ | 👁️ | ✅ |
| Finance | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | 👁️ | ✅ |
| Admin | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

**Legend:**
- ✅ Full Access
- ✏️ Entry/Submit
- ✔️ Check/Verify  
- 👁️ View Only
- ❌ No Access

---

## 🛠️ **CHANGE PASSWORD**

To change a test user's password:

1. Generate new hash:
```php
<?php
echo password_hash("YourNewPassword", PASSWORD_DEFAULT);
?>
```

2. Update in database:
```sql
UPDATE new_user 
SET password = '$2y$10$YOUR_NEW_HASH_HERE' 
WHERE username = 'planning_test';
```

---

## 🗑️ **DELETE TEST USERS**

To remove all test users:

```sql
DELETE FROM new_user 
WHERE username IN (
    'planning_test', 'prod_test', 'qc_test', 'tester_test', 
    'checker_test', 'agm_test', 'finance_test', 'mgmt_test'
);
```

---

## ✨ **READY TO USE!**

All test users are now active and ready for testing. Login with any of the credentials above to explore the role-based access control system!

**Start with Planning User to test the Planning Module:**
- Username: `planning_test`
- Password: `Test@123`

🎉 **Happy Testing!**

