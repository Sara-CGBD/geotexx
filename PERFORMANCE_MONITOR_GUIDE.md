# 📊 Performance Monitor - User Guide

## 🎯 What Is This?

The **Performance Monitor** tracks how fast your pages load, how much memory they use, and how many database queries they run. This helps you:

- ✅ **Find slow pages** that need optimization
- ✅ **Track performance** over time
- ✅ **Identify bottlenecks** before they become problems
- ✅ **Make data-driven** optimization decisions

---

## 🚀 Quick Start

### **Step 1: Access the Performance Dashboard**

Login as **Admin** and navigate to:
```
Admin Panel → Performance Dashboard
```

Or visit directly: `http://localhost/geotex/admin/performance_dashboard.php`

---

### **Step 2: Add Monitoring to Pages**

**Edit any PHP page and add these 2 lines:**

```php
<?php
session_start();

// ⭐ ADD THIS - Start monitoring
require_once 'config/PerformanceMonitor.php';
PerformanceMonitor::start();

// ... rest of your page code ...

// ⭐ ADD THIS - End monitoring (before </body>)
<?php PerformanceMonitor::end('your_page_name'); ?>
</body>
</html>
```

**That's it!** The page is now being monitored.

---

## 📋 Complete Example

**Before (No Monitoring):**
```php
<?php
session_start();
require_once 'config/security_config.php';

// Your code here
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Page</title>
</head>
<body>
    <!-- Page content -->
</body>
</html>
```

**After (With Monitoring):**
```php
<?php
session_start();

// ⭐ START MONITORING
require_once 'config/PerformanceMonitor.php';
PerformanceMonitor::start();

require_once 'config/security_config.php';

// Your code here
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Page</title>
</head>
<body>
    <!-- Page content -->
    
    <?php 
    // ⭐ END MONITORING
    PerformanceMonitor::end('my_page'); 
    ?>
</body>
</html>
```

---

## 📊 What Gets Tracked

For each page load, the monitor tracks:

| Metric | Description |
|--------|-------------|
| **Response Time** | How long the page took to load (in seconds) |
| **Memory Used** | RAM consumed during page load |
| **Memory Peak** | Maximum RAM used |
| **Query Count** | Number of database queries executed |
| **Timestamp** | When the request happened |
| **User ID** | Who accessed the page |
| **URL** | Full request URL |
| **Method** | GET or POST |

---

## 🎨 Performance Dashboard Features

### **1. Overall Statistics**

Shows at-a-glance metrics:
- Total requests in date range
- Average response time
- Maximum response time
- Average memory usage

### **2. Performance Distribution**

Visual breakdown:
- **Excellent** (< 0.5s): Green
- **Good** (0.5-1s): Light Green
- **Fair** (1-2s): Yellow
- **Poor** (> 2s): Red

### **3. Slowest Pages**

Table showing:
- Which pages are slowest
- How many requests they received
- Average, max, min response times
- Memory and query counts
- Performance status

### **4. Recent Slow Requests**

Lists all requests that took > 2 seconds:
- When it happened
- Which page
- How slow
- Memory usage
- Query count

### **5. Performance Timeline**

Daily trends showing:
- Total requests per day
- Average response time per day
- Number of slow requests
- Percentage of slow requests

### **6. Optimization Recommendations**

Automatic suggestions based on your data:
- Slow average response time → Add indexes, caching
- High query count → Combine queries
- Too many slow requests → Urgent optimization needed
- High memory usage → Use pagination

---

## 🎯 Recommended Pages to Monitor

### **High Priority (Do First):**

1. **admin/qc_reports_dashboard.php**
   - Heavy dashboard with lots of queries
   - Add monitoring here first!

2. **admin/management_kpi_dashboard.php**
   - Complex calculations
   - Multiple database queries
   - Critical for performance

3. **forms/qc_test_order.php**
   - Large form with lots of data
   - Pre-fill logic runs on load
   - Users spend lots of time here

4. **admin/lab_testing_dashboard.php**
   - Lists many pending reports
   - Queries multiple tables

5. **admin/view_qc_test_order.php**
   - Views detailed test data
   - JSON decoding overhead

### **Medium Priority:**

6. index.php - Main dashboard
7. tester_rejected_reports.php - Rejected reports
8. All report pages (reports/*.php)

### **Low Priority:**

9. Simple forms (fiber_entry, roll_entry, etc.)
10. Static pages

---

## 🔧 Advanced Usage

### **Disable in Production (If Needed)**

```php
<?php
// Only monitor in development
$is_development = ($_SERVER['SERVER_NAME'] === 'localhost');
PerformanceMonitor::setEnabled($is_development);
?>
```

### **Show Live Performance Badge**

For development, show real-time metrics on the page:

```php
<?php
// At the end of page, before </body>
if ($_SERVER['SERVER_NAME'] === 'localhost') {
    PerformanceMonitor::displayBadge();
}
?>
```

Shows a badge like:
```
⏱️ 0.45s | 💾 12.5 MB | 🔍 8 queries
```

Color-coded:
- 🟢 Green (< 1s) - Fast
- 🟡 Yellow (1-2s) - Medium
- 🔴 Red (> 2s) - Slow

### **Get Metrics Programmatically**

```php
<?php
$metrics = PerformanceMonitor::getCurrentMetrics();

echo "Page loaded in: " . $metrics['elapsed_time'] . "s\n";
echo "Memory used: " . $metrics['memory_used'] . " bytes\n";
echo "Queries run: " . $metrics['query_count'] . "\n";
?>
```

---

## 📈 Interpreting Results

### **Response Time Goals:**

| User Count | Target Response Time |
|------------|---------------------|
| < 20 users | < 2 seconds |
| 20-50 users | < 1 second |
| 50-100 users | < 0.5 seconds |
| 100+ users | < 0.3 seconds |

### **Common Bottlenecks:**

**Slow Response Time (> 2s):**
- ❌ No database indexes
- ❌ Too many queries (N+1 problem)
- ❌ Large dataset without pagination
- ❌ No caching
- ✅ Fix: Add indexes, implement caching

**High Memory (> 50 MB):**
- ❌ Loading entire tables into memory
- ❌ Not using LIMIT in queries
- ❌ Keeping large arrays
- ✅ Fix: Use pagination, optimize data loading

**Many Queries (> 20):**
- ❌ Fetching data in loops
- ❌ Separate queries instead of JOINs
- ❌ Not using prepared statements efficiently
- ✅ Fix: Combine queries, use JOINs

---

## 🔥 Quick Optimization Tips

### **If Dashboard Shows:**

**"Slow Average Response Time"**
→ Check SCALING_GUIDE_100_USERS.md - Phase 1 (Database Indexes)

**"High Query Count"**
→ Combine queries using JOINs, implement caching

**"Too Many Slow Requests"**
→ Focus on top 3 slowest pages from the dashboard

**"High Memory Usage"**
→ Add pagination, use LIMIT, don't load all data at once

---

## 📊 Example Dashboard Output

```
Overall Statistics:
- Total Requests: 1,234
- Avg Response Time: 0.85s
- Max Response Time: 4.23s
- Avg Memory: 24.5 MB

Performance Distribution:
- Excellent (< 0.5s): 45% (556 requests) ✅
- Good (0.5-1s): 32% (395 requests) ✅
- Fair (1-2s): 18% (222 requests) ⚠️
- Poor (> 2s): 5% (61 requests) ❌

Slowest Pages:
1. management_kpi_dashboard - 2.34s avg ❌ Poor
2. qc_reports_dashboard - 1.67s avg ⚠️ Fair
3. view_qc_test_order - 0.92s avg ✅ Good
```

---

## 🎯 Action Plan

### **Week 1: Set Up Monitoring**

1. ✅ Add monitoring to top 5 pages
2. ✅ Collect data for 7 days
3. ✅ Identify slowest pages
4. ✅ Review Performance Dashboard daily

### **Week 2: Optimize**

1. ✅ Focus on pages showing "Poor" or "Critical"
2. ✅ Add database indexes
3. ✅ Optimize slow queries
4. ✅ Implement caching for heavy pages

### **Week 3: Verify**

1. ✅ Compare before/after metrics
2. ✅ Target: 90%+ requests < 1 second
3. ✅ Continue monitoring
4. ✅ Fine-tune as needed

---

## 💡 Pro Tips

1. **Monitor Before Optimizing**
   - Collect 3-7 days of data first
   - Make decisions based on real metrics
   - Don't guess - measure!

2. **Focus on High-Traffic Pages**
   - Optimize pages with most requests first
   - 80/20 rule: 20% of pages get 80% of traffic

3. **Set Performance Budgets**
   - Define acceptable response times
   - Alert when pages exceed budgets
   - Track improvements over time

4. **Compare Peak vs Off-Peak**
   - Check performance during busy hours
   - Identify capacity issues
   - Plan infrastructure upgrades

---

## 🔍 Troubleshooting

**"Performance table doesn't exist"**
→ Run `create_enterprise_tables.php` to create the table

**"No data showing"**
→ Make sure you've added monitoring to pages and used them

**"All pages showing 0s"**
→ Check if `PerformanceMonitor::end()` is being called

**"Data not updating"**
→ Check error logs, database connection might have issues

---

## 📚 Related Documentation

- **SCALING_GUIDE_100_USERS.md** - Full scaling guide
- **AUTO_RELOAD_GUIDE.md** - Auto-reload system
- **ENTERPRISE_FEATURES.md** - Enterprise features overview

---

## 🎉 Summary

**Performance Monitor helps you:**

1. ✅ See which pages are slow
2. ✅ Track performance trends
3. ✅ Identify what to optimize
4. ✅ Measure improvements
5. ✅ Scale confidently to 100+ users

**Start using it today to make your system faster!** 🚀

---

*Last Updated: November 5, 2025*

