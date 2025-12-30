# 🚀 Enterprise Features - GeoTex QC Management System v2.0

## Overview
The GeoTex QC Management System has been upgraded to enterprise-level standards with advanced features for performance, security, monitoring, and scalability.

---

## 📋 Table of Contents
1. [Quick Start](#quick-start)
2. [Core Features](#core-features)
3. [Security Enhancements](#security-enhancements)
4. [Performance Optimization](#performance-optimization)
5. [Monitoring & Analytics](#monitoring--analytics)
6. [Error Handling](#error-handling)
7. [API Foundation](#api-foundation)
8. [Backup & Recovery](#backup--recovery)
9. [Configuration](#configuration)

---

## 🎯 Quick Start

### Installation

1. **Run Enterprise Upgrade:**
   ```
   http://localhost/geotex/run_enterprise_upgrade.php
   ```

2. **Access System Monitor:**
   ```
   http://localhost/geotex/admin/system_monitor.php
   ```
   (Admin access only)

3. **Configure Enterprise Settings:**
   Edit `config/EnterpriseConfig.php` to customize:
   - Environment (production/staging/development)
   - Security settings
   - Performance parameters
   - Logging preferences

---

## 🌟 Core Features

### 1. **Enhanced Security**
- ✅ CSRF Protection on all forms
- ✅ SQL Injection prevention (prepared statements)
- ✅ XSS Protection (input sanitization)
- ✅ Rate Limiting (60 requests/minute)
- ✅ Strong password requirements
- ✅ Session timeout management
- ✅ Login attempt monitoring

### 2. **Performance Optimization**
- ✅ Database query optimization
- ✅ Composite indexes on frequently queried columns
- ✅ Query caching (5-minute lifetime)
- ✅ Connection pooling
- ✅ Optimized table structures
- ✅ Database views for complex queries

### 3. **Audit & Compliance**
- ✅ Comprehensive audit logging
- ✅ User activity tracking
- ✅ Change history
- ✅ IP address logging
- ✅ Login/logout tracking
- ✅ 90-day log retention

### 4. **System Monitoring**
- ✅ Real-time system health checks
- ✅ Performance metrics dashboard
- ✅ Database size monitoring
- ✅ User activity analytics
- ✅ Error tracking
- ✅ Auto-refresh every 30 seconds

### 5. **Error Management**
- ✅ Global error handler
- ✅ Exception tracking
- ✅ Database error logging
- ✅ User-friendly error pages
- ✅ Stack trace capture (dev mode)
- ✅ Email notifications (configurable)

---

## 🔒 Security Enhancements

### CSRF Protection

**Enable in forms:**
```php
require_once 'config/EnterpriseConfig.php';

// Generate token
$csrf_token = EnterpriseConfig::generateCSRFToken();

// Add to form
echo '<input type="hidden" name="csrf_token" value="' . $csrf_token . '">';

// Verify on submission
if (!EnterpriseConfig::verifyCSRFToken($_POST['csrf_token'])) {
    die('CSRF validation failed');
}
```

### Input Sanitization

```php
// Sanitize user input
$safe_input = EnterpriseConfig::sanitizeInput($_POST['data']);

// Validate email
if (!EnterpriseConfig::validateEmail($email)) {
    $error = 'Invalid email';
}

// Validate password strength
if (!EnterpriseConfig::validatePassword($password)) {
    $error = 'Weak password';
}
```

### Rate Limiting

```php
// Check rate limit
$user_id = $_SESSION['user_id'];
if (!EnterpriseConfig::checkRateLimit($user_id)) {
    http_response_code(429);
    die('Too many requests');
}
```

---

## ⚡ Performance Optimization

### Database Indexes

**Automatically created indexes:**
- `idx_qc_status_inspector` - QC test orders by status & inspector
- `idx_qc_report_status` - Report lookup
- `idx_audit_user_event` - Audit log queries
- `idx_user_role_active` - User management
- Full-text search on test_data

### Query Optimization

**Use prepared views:**
```php
// Fast pending reports query
$result = $conn->query("SELECT * FROM v_pending_checker_reports");

// User activity summary
$result = $conn->query("SELECT * FROM v_user_activity_summary");
```

### Performance Monitoring

```php
// Start monitoring
EnterpriseConfig::startPerformanceMonitor();

// Your code here...

// Get metrics
$metrics = EnterpriseConfig::endPerformanceMonitor();
echo "Duration: {$metrics['duration']}s";
echo "Queries: {$metrics['queries']}";
echo "Memory: {$metrics['memory']}";
```

---

## 📊 Monitoring & Analytics

### System Monitor Dashboard

**Access:** `admin/system_monitor.php` (Admin only)

**Features:**
- Real-time system health status
- Database connection status
- User statistics (total, active, today)
- QC test order metrics
- Recent activity (last 7 days)
- Error log viewer
- Auto-refresh every 30 seconds

### Health Checks

```php
require_once 'config/EnterpriseConfig.php';

$health = EnterpriseConfig::checkDatabaseHealth($conn);
if ($health['status'] !== 'ok') {
    // Alert admin
    error_log("DB Health Issue: " . $health['message']);
}
```

### Activity Logging

```php
// Log user activity
EnterpriseConfig::logActivity(
    $conn,
    $user_id,
    'report_submitted',
    'User submitted QC Test Order RPT-20251104-001',
    $_SERVER['REMOTE_ADDR']
);
```

---

## 🚨 Error Handling

### Initialize Error Handler

**Add to main entry point:**
```php
require_once 'config/ErrorHandler.php';
ErrorHandler::init($conn);
```

### Features

**Development Mode:**
- Detailed error messages
- Stack traces
- File and line numbers
- Interactive debugging

**Production Mode:**
- User-friendly error pages
- No sensitive information exposed
- Automatic error logging
- Admin notifications

### Error Logging

All errors are automatically logged to `error_log` table:
- Error type and message
- File path and line number
- User ID and IP address
- Request URL and user agent
- Timestamp

**View recent errors:**
```sql
SELECT * FROM error_log 
ORDER BY occurred_at DESC 
LIMIT 20;
```

---

## 🔌 API Foundation

### API Access Logging

Ready for future API endpoints with `api_access_log` table:
- Endpoint tracking
- Method logging (GET, POST, etc.)
- Response time monitoring
- User authentication tracking
- Rate limiting integration

---

## 💾 Backup & Recovery

### Automated Backup System

**Configuration:**
```php
const AUTO_BACKUP_ENABLED = true;
const BACKUP_RETENTION_DAYS = 30;
```

### Backup Log

All backups are tracked in `backup_log` table:
- Backup type (full/incremental)
- File name and size
- Status (started/completed/failed)
- Timestamps
- Error messages

### Data Archiving

**Automatic archiving settings:**
- QC test orders: 730 days (2 years)
- Audit logs: 180 days (6 months)
- Login attempts: 90 days
- Error logs: 90 days

**Run cleanup manually:**
```sql
CALL sp_cleanup_old_data();
```

---

## ⚙️ Configuration

### EnterpriseConfig.php

**Environment Settings:**
```php
const APP_VERSION = '2.0.0';
const ENV = 'production'; // production, staging, development
```

**Performance:**
```php
const ENABLE_QUERY_CACHE = true;
const CACHE_LIFETIME = 300; // 5 minutes
const MAX_CONNECTIONS = 100;
const QUERY_TIMEOUT = 30; // seconds
```

**Security:**
```php
const ENABLE_CSRF_PROTECTION = true;
const SESSION_TIMEOUT = 1800; // 30 minutes
const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_DURATION = 300; // 5 minutes
const PASSWORD_MIN_LENGTH = 8;
const REQUIRE_STRONG_PASSWORD = true;
```

**Logging:**
```php
const ENABLE_AUDIT_LOG = true;
const LOG_QUERIES = false; // Dev only
const LOG_ERRORS = true;
const LOG_RETENTION_DAYS = 90;
```

**File Uploads:**
```php
const MAX_UPLOAD_SIZE = 10485760; // 10MB
const ALLOWED_FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'csv'];
```

**Rate Limiting:**
```php
const ENABLE_RATE_LIMIT = true;
const MAX_REQUESTS_PER_MINUTE = 60;
```

---

## 📈 Database Tables

### New Enterprise Tables

1. **system_performance** - Performance metrics
2. **system_health_checks** - Health check results
3. **error_log** - Comprehensive error tracking
4. **api_access_log** - API usage tracking
5. **backup_log** - Backup history
6. **archive_settings** - Data retention policies
7. **slow_query_log** - Query performance tracking

### Views

1. **v_pending_checker_reports** - Optimized pending reports query
2. **v_user_activity_summary** - User activity analytics

---

## 🎓 Best Practices

### 1. **Always Use Prepared Statements**
```php
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
```

### 2. **Sanitize All User Input**
```php
$safe_data = EnterpriseConfig::sanitizeInput($_POST['data']);
```

### 3. **Log Important Activities**
```php
EnterpriseConfig::logActivity($conn, $user_id, 'action', 'details');
```

### 4. **Monitor Performance**
```php
EnterpriseConfig::startPerformanceMonitor();
// code...
$metrics = EnterpriseConfig::endPerformanceMonitor();
```

### 5. **Check Rate Limits**
```php
if (!EnterpriseConfig::checkRateLimit($identifier)) {
    // throttle request
}
```

### 6. **Regular Health Checks**
```php
$health = EnterpriseConfig::checkDatabaseHealth($conn);
```

---

## 🔧 Troubleshooting

### Issue: Error Log Table Not Found
**Solution:** Run `run_enterprise_upgrade.php` again

### Issue: Performance Issues
**Solution:** Check slow_query_log table and optimize queries

### Issue: Session Timeouts
**Solution:** Adjust SESSION_TIMEOUT in EnterpriseConfig.php

### Issue: Rate Limiting Too Strict
**Solution:** Increase MAX_REQUESTS_PER_MINUTE

---

## 📞 Support

For enterprise support and custom features:
- Email: support@geotex.com
- Documentation: /docs/
- System Monitor: /admin/system_monitor.php

---

## 📝 Version History

### v2.0.0 (Enterprise Release)
- ✅ Enterprise-level security
- ✅ Performance optimization
- ✅ System monitoring dashboard
- ✅ Advanced error handling
- ✅ Comprehensive audit logging
- ✅ Automated backup system
- ✅ API foundation
- ✅ Data archiving

### v1.0.0 (Initial Release)
- Basic QC test management
- User authentication
- Report generation

---

## 🚀 Future Enhancements

- Mobile application
- RESTful API endpoints
- Machine learning for quality prediction
- Real-time notifications
- Advanced analytics dashboard
- Multi-language support
- Cloud deployment ready
- Microservices architecture

---

**© 2025 GeoTex QC Management System - Enterprise Edition**
