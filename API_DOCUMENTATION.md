# 📱 Geotex ERP - RESTful API Documentation

## Overview

All reports in the Finance and Planning modules now have separate RESTful API endpoints that are mobile-ready and can be consumed by external applications.

---

## 🔐 Authentication

All API endpoints require session-based authentication:

```javascript
fetch('https://yourdomain.com/geotex/reports/api/endpoint.php', {
  credentials: 'include', // Important: Include session cookies
  headers: {
    'Accept': 'application/json'
  }
})
```

### Response Codes
- `200` - Success
- `401` - Unauthorized (not logged in)
- `403` - Forbidden (insufficient permissions)
- `500` - Server error

---

## 💰 Finance Module APIs

### 1. Material Consumption Cost Report
**Endpoint:** `/reports/api/material_consumption_cost_data.php`

**Method:** GET

**Parameters:**
- `start_date` (optional) - Format: YYYY-MM-DD
- `end_date` (optional) - Format: YYYY-MM-DD
- `material` (optional) - Material ID
- `project` (optional) - Project ID

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "consumption_date": "2025-09-24",
      "material_name": "Polypropylene",
      "project_name": "Sample Project",
      "total_consumed": "150.00",
      "unit_price": "50.00",
      "total_cost": "7500.00",
      "transaction_count": 5,
      "shift": "Day",
      "process_type": "Fiber to Roll"
    }
  ],
  "summary": {
    "total_cost": "25000.00",
    "total_consumed": "500.00",
    "avg_cost_per_kg": "50.00",
    "total_transactions": 15
  }
}
```

---

### 2. Production Cost Report
**Endpoint:** `/reports/api/production_cost_data.php`

**Method:** GET

**Parameters:**
- `start_date` (optional) - Format: YYYY-MM-DD
- `end_date` (optional) - Format: YYYY-MM-DD

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "date": "2025-09-24",
      "shift": "Day",
      "type": "CNC",
      "product": "Sample Product",
      "quantity": 100,
      "labor_cost": 5000,
      "utility_cost": 2000,
      "overhead_cost": 3000,
      "total_cost": 10000
    }
  ],
  "summary": {
    "total_cost": "50000.00",
    "labor_cost": "25000.00",
    "utility_cost": "10000.00",
    "overhead_cost": "15000.00"
  }
}
```

---

### 3. Scrap Loss Report
**Endpoint:** `/reports/api/scrap_loss_data.php`

**Method:** GET

**Parameters:**
- `start_date` (optional) - Format: YYYY-MM-DD (defaults to 3 months ago)
- `end_date` (optional) - Format: YYYY-MM-DD
- `scrap_type` (optional) - Scrap type filter
- `product` (optional) - Product filter

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "date": "2025-09-24",
      "scrap_type": "Material Scrap",
      "scrap_product": "Raw Material",
      "scrap_qty": 312.00,
      "recycled_qty": 24.00,
      "rate_per_kg": 40.00,
      "gross_loss_value": 12480.00,
      "net_loss_qty": 288.00,
      "net_loss_value": 11520.00
    }
  ],
  "summary": {
    "total_scrap_qty": "500.00",
    "total_recycled_qty": "50.00",
    "gross_loss_value": "20000.00",
    "net_loss_qty": "450.00",
    "net_loss_value": "18000.00"
  }
}
```

---

### 4. Profitability Report
**Endpoint:** `/reports/api/profitability_data.php`

**Method:** GET

**Parameters:**
- `start_date` (required) - Format: YYYY-MM-DD
- `end_date` (required) - Format: YYYY-MM-DD

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 11,
      "name": "Woven Polypropylene Roll",
      "revenue": 400000.00,
      "material_cost": 406.00,
      "production_cost": 15000.00,
      "profit": 384594.00,
      "profit_margin": 96.15,
      "total_qty": 500.00,
      "unit_price": 800.00
    }
  ],
  "summary": {
    "total_revenue": "515000.00",
    "total_material_cost": "6632.00",
    "total_production_cost": "25500.00",
    "total_profit": "482868.00",
    "avg_profit_margin": "93.76"
  }
}
```

---

## 📊 Planning Module APIs

### 5. Target vs Actual Report
**Endpoint:** `/reports/api/target_vs_actual_data.php`

**Method:** GET

**Parameters:**
- `date_from` (optional) - Format: YYYY-MM-DD (defaults to first day of current month)
- `date_to` (optional) - Format: YYYY-MM-DD (defaults to today)
- `module` (optional) - Module ID
- `period` (optional) - "Daily", "Weekly", or "Monthly"

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "target_id": 1,
      "module_id": 1,
      "module_name": "Production",
      "target_period": "Daily",
      "target_date": "2025-09-24",
      "target_qty": 1000,
      "production_qty": 950,
      "achievement_percent": 95.0,
      "status": "Near Target"
    }
  ],
  "summary": {
    "total_targets": 150,
    "total_achieved": 120,
    "achievement_rate": "80.0",
    "achieved_count": 120,
    "near_target_count": 20,
    "below_target_count": 10
  },
  "by_module": {
    "Production": {
      "target": 5000,
      "actual": 4500,
      "count": 10
    }
  },
  "by_date": {
    "2025-09-24": {
      "Production": {
        "target": 1000,
        "actual": 950
      }
    }
  }
}
```

---

### 6. Project Value Report
**Endpoint:** `/reports/api/project_value_data.php`

**Method:** GET

**Parameters:**
- `project_id` (optional) - Project ID

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "project_name": "Sample Project",
      "status": "Active",
      "roll_count": 50,
      "total_production": 1500.50,
      "fg_count": 100,
      "fg_produced": 80,
      "total_cost": 50000.00,
      "belt_weight": 200.00,
      "created_at": "2025-09-01 10:00:00",
      "description": "Project description"
    }
  ],
  "summary": {
    "total_projects": 5,
    "total_production": "7500.50",
    "total_cost": "250000.00"
  }
}
```

---

### 7. BOM Entry Log
**Endpoint:** `/reports/api/bom_entry_log_data.php`

**Method:** GET

**Parameters:**
- `date_from` (optional) - Format: YYYY-MM-DD
- `date_to` (optional) - Format: YYYY-MM-DD
- `product_id` (optional) - Product ID

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "product_id": 11,
      "product_name": "Woven Polypropylene Roll",
      "material_id": 1,
      "material_name": "Polypropylene",
      "quantity": 100.00,
      "cost": 5000.00,
      "created_at": "2025-09-24 10:00:00",
      "creator_name": "John Doe"
    }
  ],
  "summary": {
    "total_entries": 50,
    "total_cost": "250000.00"
  }
}
```

---

### 8. Material Requirement Report
**Endpoint:** `/reports/api/material_requirement_data.php`

**Method:** GET

**Parameters:**
- `material` (optional) - Material ID
- `status` (optional) - "OK", "Low Stock", or "Shortage"

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "material_name": "Polypropylene",
      "products_using": 5,
      "bom_entries": 10,
      "available_stock": 5000.00,
      "used_stock": 1000.00,
      "net_available": 4000.00,
      "total_required": 3000.00,
      "difference": 1000.00,
      "status": "OK"
    }
  ],
  "summary": {
    "total_materials": 15,
    "shortage_count": 2,
    "low_stock_count": 3,
    "ok_count": 10
  }
}
```

---

## 🔒 Security Features

1. **Session-based Authentication** - All endpoints check for valid user session
2. **Role-based Access Control** - Different roles have different access levels
3. **SQL Injection Protection** - All queries use prepared statements
4. **XSS Protection** - All output is properly escaped
5. **CSRF Protection** - Session validation on every request

---

## 📱 Mobile App Integration Example

### React Native Example

```javascript
import AsyncStorage from '@react-native-async-storage/async-storage';

class GeotexAPI {
  constructor(baseUrl) {
    this.baseUrl = baseUrl;
    this.sessionCookie = null;
  }

  async login(username, password) {
    const response = await fetch(`${this.baseUrl}/login.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: `username=${username}&password=${password}`,
      credentials: 'include'
    });
    
    // Store session cookie
    const cookies = response.headers.get('set-cookie');
    if (cookies) {
      await AsyncStorage.setItem('sessionCookie', cookies);
    }
    
    return response.json();
  }

  async getTargetVsActual(dateFrom, dateTo, module = '', period = '') {
    const params = new URLSearchParams({
      date_from: dateFrom,
      date_to: dateTo
    });
    
    if (module) params.append('module', module);
    if (period) params.append('period', period);
    
    const response = await fetch(
      `${this.baseUrl}/reports/api/target_vs_actual_data.php?${params}`,
      {
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      }
    );
    
    const data = await response.json();
    
    if (!data.success) {
      throw new Error(data.error || 'API Error');
    }
    
    return data;
  }

  async getProfitability(startDate, endDate) {
    const response = await fetch(
      `${this.baseUrl}/reports/api/profitability_data.php?start_date=${startDate}&end_date=${endDate}`,
      {
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      }
    );
    
    return response.json();
  }
}

// Usage
const api = new GeotexAPI('https://yourdomain.com/geotex');

// Login
await api.login('username', 'password');

// Fetch data
const targetData = await api.getTargetVsActual('2025-01-01', '2025-12-31');
console.log('Achievement Rate:', targetData.summary.achievement_rate);

const profitData = await api.getProfitability('2025-01-01', '2025-12-31');
console.log('Total Profit:', profitData.summary.total_profit);
```

---

## 🧪 Testing APIs

### Using cURL

```bash
# Login first
curl -c cookies.txt -X POST \
  -d "username=your_username&password=your_password" \
  https://yourdomain.com/geotex/login.php

# Then call API with cookies
curl -b cookies.txt \
  "https://yourdomain.com/geotex/reports/api/target_vs_actual_data.php?date_from=2025-01-01&date_to=2025-12-31"
```

### Using Postman

1. Create a POST request to `/login.php` with form data:
   - `username`: your_username
   - `password`: your_password

2. Postman will automatically save the session cookie

3. Create GET requests to any API endpoint - session will be included automatically

---

## 📝 Error Handling

All APIs return consistent error format:

```json
{
  "error": "Error message here",
  "success": false
}
```

Common error messages:
- `"Unauthorized"` - User not logged in
- `"Access Denied"` - User role doesn't have permission
- `"Server error: [details]"` - Database or server error

---

---

## ♻️ Scrap Module APIs

### 1. Scrap Entry
**Endpoint:** `/forms/api/scrap_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "scrap_id": "SC-20251013-001",
  "scrap_products": [
    "Woven Polypropylene Roll",
    "Non-Woven Polypropylene Roll",
    "Valve Bags",
    "FIBC (Jumbo Bags)",
    "Finished Geo Bags",
    "Other"
  ],
  "scrap_types": [
    "Edge Trim",
    "Defective Material",
    "Production Waste",
    "Off-spec Product",
    "Contaminated Material",
    "Other"
  ],
  "current_datetime": "2025-10-13 10:30:00"
}
```

**Method:** POST (Submit scrap entry)

**Request Body:**
```json
{
  "scrap_id": "SC-20251013-001",
  "scrap_product": "Woven Polypropylene Roll",
  "scrap_type": "Edge Trim",
  "scrap_qty": 15.5,
  "dateTime": "2025-10-13 10:30:00",
  "remarks": "Optional notes"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Scrap entry saved successfully",
  "scrap_id": "SC-20251013-001",
  "date_time": "2025-10-13 10:30:00"
}
```

---

### 2. Scrap Loss Report
**Endpoint:** `/reports/api/scrap_loss_data.php`

**Method:** GET

**Parameters:**
- `start_date` (optional) - Format: YYYY-MM-DD
- `end_date` (optional) - Format: YYYY-MM-DD
- `scrap_type` (optional) - Filter by scrap type

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "scrap_date": "2025-10-13",
      "scrap_type": "Edge Trim",
      "scrap_product": "Woven Polypropylene Roll",
      "scrap_qty": "78.00",
      "recycled_qty": "24.00",
      "cost_per_kg": "50.00",
      "gross_loss_value": "3900.00",
      "net_loss_qty": "54.00",
      "net_loss_value": "2700.00"
    }
  ],
  "summary": {
    "total_scrap_qty": "250.00",
    "total_recycled_qty": "75.00",
    "total_net_loss_qty": "175.00",
    "total_gross_loss_value": "12500.00",
    "total_net_loss_value": "8750.00"
  }
}
```

---

### 3. Scrap Cost Settings
**Endpoint:** `/admin/api/scrap_cost_settings_api.php`

**Method:** GET (Fetch all cost settings)

**Parameters:**
- `scrap_type` (optional) - Filter by type
- `scrap_product` (optional) - Filter by product

**Response:**
```json
{
  "success": true,
  "costs": [
    {
      "id": 1,
      "scrap_type": "Edge Trim",
      "scrap_product": "Woven Polypropylene Roll",
      "cost_per_kg": "50.00",
      "salvage_percentage": "30.00",
      "updated_by": 1,
      "updated_by_name": "Admin User",
      "updated_at": "2025-10-13 10:00:00"
    }
  ],
  "scrap_products": ["Woven Polypropylene Roll", "..."],
  "scrap_types": ["Edge Trim", "..."]
}
```

**Method:** POST (Create/Update cost setting)

**Request Body:**
```json
{
  "scrap_type": "Edge Trim",
  "scrap_product": "Woven Polypropylene Roll",
  "cost_per_kg": 50.00,
  "salvage_percentage": 30.00
}
```

**Response:**
```json
{
  "success": true,
  "message": "Scrap cost created successfully",
  "action": "created"
}
```

**Method:** DELETE (Remove cost setting)

**Request Body:**
```json
{
  "id": 1
}
```

**Response:**
```json
{
  "success": true,
  "message": "Scrap cost deleted successfully"
}
```

---

## 🏭 Production Module APIs

### 1. Production Entry
**Endpoint:** `/forms/api/production_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "projects": [{"id": 1, "project_name": "Sample Project"}],
  "operators": [{"id": 1, "operator_name": "John Doe"}],
  "next_roll_number": 101,
  "next_batch_number": 50,
  "current_datetime": "2025-10-14 10:30:00",
  "shift": "Day"
}
```

**Method:** POST (Submit production entry)

**Request Body:**
```json
{
  "project_id": 1,
  "gsm": 150.5,
  "line_no": "Line 1",
  "fiber_type": "PP",
  "roll_no": 101,
  "total_weight": 500.0,
  "batch_number": 50,
  "date_time": "2025-10-14 10:30:00",
  "shift": "Day",
  "operator_id": 1
}
```

**Response:**
```json
{
  "success": true,
  "message": "Production entry saved successfully",
  "entry_id": 123,
  "roll_number": 101,
  "batch_number": 50
}
```

---

### 2. CNC Entry
**Endpoint:** `/forms/api/cnc_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "projects": [{"id": 1, "project_name": "Sample Project"}],
  "cnc_id": "CNC-20251014-001",
  "current_datetime": "2025-10-14 10:30:00",
  "shift": "Day",
  "reporter_id": 1,
  "reporter_name": "admin"
}
```

**Method:** POST (Submit CNC entry)

**Request Body:**
```json
{
  "cnc_id": "CNC-20251014-001",
  "project_id": 1,
  "bag_size": "50x80",
  "recommended_weight": 100.0,
  "actual_weight": 98.5,
  "date_time": "2025-10-14 10:30:00",
  "shift": "Day",
  "reporter_id": 1
}
```

**Response:**
```json
{
  "success": true,
  "message": "CNC entry saved successfully",
  "entry_id": 45,
  "cnc_id": "CNC-20251014-001"
}
```

---

### 3. Swing Machine Entry
**Endpoint:** `/forms/api/swing_machine_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "projects": [{"id": 1, "project_name": "Sample Project"}],
  "operators": [{"id": 1, "operator_name": "John Doe"}],
  "helpers": [{"id": 1, "helper_name": "Jane Smith"}],
  "swing_id": "SWING-20251014-001",
  "current_datetime": "2025-10-14 10:30:00",
  "shift": "Day",
  "reporter_id": 1
}
```

**Method:** POST (Submit swing machine entry)

**Request Body:**
```json
{
  "swing_id": "SWING-20251014-001",
  "project_id": 1,
  "operator_id": 1,
  "helper_id": 1,
  "line_no": "Line 1",
  "sewing_qty": 500,
  "ncp_piece": 10,
  "date_time": "2025-10-14 10:30:00",
  "shift": "Day",
  "reporter_id": 1
}
```

**Response:**
```json
{
  "success": true,
  "message": "Swing machine entry saved successfully",
  "entry_id": 67,
  "swing_id": "SWING-20251014-001"
}
```

---

### 4. Branding Entry
**Endpoint:** `/forms/api/branding_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "projects": [{"id": 1, "project_name": "Sample Project"}],
  "machines": [{"id": 1, "machine_name": "Machine 1"}],
  "branding_id": "BR-20251014-001",
  "current_datetime": "2025-10-14 10:30:00",
  "shift": "Day",
  "reporter_id": 1,
  "reporter_name": "admin"
}
```

**Method:** POST (Submit branding entry)

**Request Body:**
```json
{
  "dateTime": "2025-10-14 10:30:00",
  "shiftIncharge": "John Manager",
  "projectId": 1,
  "machineId": 1,
  "bagSize": "50x80",
  "printQty": 1000,
  "ncpPcs": 5,
  "reporterId": 1,
  "reporterName": "admin"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Branding entry saved successfully",
  "entry_id": 89,
  "branding_id": "BR-20251014-001"
}
```

---

### 5. Roll Production Summary Report
**Endpoint:** `/reports/api/roll_production_summary_data.php`

**Method:** GET

**Parameters:**
- `date_from` (optional) - Format: YYYY-MM-DD
- `date_to` (optional) - Format: YYYY-MM-DD
- `palk_id` (optional) - Filter by palk ID
- `next_stage` (optional) - Filter by next stage
- `shift` (optional) - Day or Night

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "palk_id": "P001",
      "date": "2025-10-14 10:30:00",
      "qty": 500,
      "next_stage": "CNC",
      "calculated_shift": "Day"
    }
  ],
  "summary": {
    "total_quantity": 5000,
    "total_records": 50,
    "shift_breakdown": {
      "Day": {"count": 30, "qty": 3000},
      "Night": {"count": 20, "qty": 2000}
    },
    "stage_breakdown": {
      "CNC": {"count": 25, "qty": 2500},
      "Storage": {"count": 25, "qty": 2500}
    },
    "date_breakdown": {
      "2025-10-14": {"count": 10, "qty": 1000}
    }
  }
}
```

---

### 6. Target vs Actual Report
**Endpoint:** `/reports/api/target_vs_actual_data.php`

**Method:** GET

**Parameters:**
- `date_from` (optional) - Format: YYYY-MM-DD (default: first day of month)
- `date_to` (optional) - Format: YYYY-MM-DD (default: today)
- `module` (optional) - Module ID
- `period` (optional) - Target period

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "target_id": 1,
      "module_id": 1,
      "module_name": "Roll Production",
      "target_period": "Daily",
      "target_date": "2025-10-14",
      "target_qty": 1000,
      "production_qty": 950,
      "achievement_percent": 95.0,
      "status": "Near Target"
    }
  ],
  "modules": {
    "1": "Roll Production",
    "2": "CNC Machine"
  },
  "summary": {
    "total_target_qty": 10000,
    "total_production_qty": 9500,
    "overall_achievement_percent": 95.0,
    "total_records": 15,
    "by_module": {
      "Roll Production": {"target": 5000, "actual": 4800, "count": 10}
    },
    "by_date": {
      "2025-10-14": {"target": 1000, "actual": 950, "count": 1}
    },
    "by_status": {
      "Achieved": 8,
      "Near Target": 5,
      "Below Target": 2
    }
  }
}
```

---

## 🎚️ Roll Production Module APIs

### 1. Roll Entry
**Endpoint:** `/forms/api/roll_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "projects": [{"id": 1, "project_name": "Sample Project"}],
  "next_roll_number": 1,
  "next_batch_number": 50,
  "current_datetime": "2025-10-14 10:30:00",
  "shift": "Day",
  "operator_id": 1,
  "operator_name": "admin"
}
```

**Method:** POST (Submit roll entry)

**Request Body:**
```json
{
  "date_time": "2025-10-14 10:30:00",
  "operator_id": 1,
  "project_id": 1,
  "gsm": 150,
  "line_no": "Line 1",
  "fiber_type": "PP",
  "roll_number": 1,
  "total_weight": 500.0
}
```

**Response:**
```json
{
  "success": true,
  "message": "Roll entry saved successfully",
  "entry_id": 123,
  "roll_number": 1,
  "batch_number": "150_Line1_PP_25Oct14_D_1"
}
```

---

### 2. Fiber Entry
**Endpoint:** `/forms/api/fiber_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "projects": [{"id": 1, "project_name": "Sample Project"}],
  "entry_code": "FE-1729000000",
  "current_datetime": "2025-10-14 10:30:00",
  "shift": "Day",
  "reporter_id": 1,
  "reporter_name": "admin"
}
```

**Method:** POST (Submit fiber entry)

**Request Body:**
```json
{
  "dateTime": "2025-10-14 10:30:00",
  "shiftIncharge": "John Manager",
  "project": 1,
  "amount": 1000.0,
  "materialType": "PP",
  "origin": "China",
  "beltWeight": 500,
  "beltNumber": "B001"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Fiber entry saved successfully",
  "entry_id": 45,
  "entry_code": "FE-1729000000"
}
```

---

### 3. Fiber to Roll Entry
**Endpoint:** `/forms/api/fiber_to_roll_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "projects": [{"id": 1, "project_name": "Sample Project"}],
  "current_datetime": "2025-10-14 10:30:00",
  "shift": "Day",
  "operator_id": 1
}
```

**Method:** POST (Submit fiber to roll entry)

**Request Body:**
```json
{
  "date_time": "2025-10-14 10:30:00",
  "operator_id": 1,
  "project_id": 1,
  "bale_opener_number": 1,
  "bale_number": 100,
  "bale_weight": 500,
  "gsm": 150,
  "line_no": 1,
  "fiber_type": "PP",
  "origin": "China",
  "roll_number": 1,
  "total_weight": 450.0
}
```

**Response:**
```json
{
  "success": true,
  "message": "Fiber to roll entry saved successfully",
  "entry_id": 67
}
```

---

### 4. Roll Received Entry
**Endpoint:** `/forms/api/roll_received_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "projects": [{"id": 1, "project_name": "Sample Project"}],
  "current_datetime": "2025-10-14 10:30:00",
  "reporter_id": 1
}
```

**Method:** POST (Submit roll received entry)

**Request Body:**
```json
{
  "reporting_time": "2025-10-14 10:30:00",
  "reporter_id": 1,
  "receiver_name": "John Receiver",
  "project_id": 1,
  "bag_roll_type": "Roll",
  "roll_size": "1.5m",
  "roll_quantity": 10,
  "gsm": 150,
  "line_no": 1,
  "fiber_type": "PP",
  "roll_number": 1,
  "batch_number": "B001"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Roll received entry saved successfully",
  "entry_id": 89
}
```

---

### 5. Roll Transfer Entry
**Endpoint:** `/forms/api/roll_transfer_entry_api.php`

**Method:** GET (Fetch form data)

**Response:**
```json
{
  "success": true,
  "next_transfer_id": 1,
  "current_datetime": "2025-10-14 10:30:00",
  "operator_id": 1
}
```

**Method:** POST (Submit roll transfer entry)

**Request Body:**
```json
{
  "transfer_id": 1,
  "date_time": "2025-10-14 10:30:00",
  "operator_id": 1,
  "batch_number": "B001",
  "sender_name": "John Sender",
  "driver_name": "Driver Name",
  "amount": 500.0,
  "from_location": "Warehouse A",
  "to_location": "Warehouse B"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Roll transfer entry saved successfully",
  "entry_id": 111,
  "transfer_id": 1
}
```

---

### 6. Fiber to Roll Production Report
**Endpoint:** `/reports/api/fiber_to_roll_production_data.php`

**Method:** GET

**Parameters:**
- `start_date` (optional) - Format: YYYY-MM-DD (default: first day of month)
- `end_date` (optional) - Format: YYYY-MM-DD (default: today)

**Response:**
```json
{
  "success": true,
  "fiber_entries": [
    {
      "id": 1,
      "date_time": "2025-10-14 10:30:00",
      "project_id": 1,
      "amount": 1000.0,
      "origin": "China"
    }
  ],
  "conversion_entries": [
    {
      "id": 1,
      "date_time": "2025-10-14 11:00:00",
      "roll_number": 1,
      "total_weight": 950.0,
      "origin": "China"
    }
  ],
  "summary": {
    "total_fiber_entries": 10,
    "total_fiber_weight": 10000.0,
    "total_conversion_entries": 8,
    "total_roll_weight": 9500.0,
    "conversion_efficiency": 95.0,
    "fiber_by_origin": {
      "China": {"count": 5, "weight": 5000.0}
    },
    "conversion_by_origin": {
      "China": {"count": 4, "weight": 4750.0}
    },
    "fiber_by_date": {
      "2025-10-14": {"count": 2, "weight": 2000.0}
    },
    "conversion_by_date": {
      "2025-10-14": {"count": 2, "weight": 1900.0}
    }
  }
}
```

---

## 🎯 Best Practices

1. **Always check `success` field** in response
2. **Handle errors gracefully** in your app
3. **Cache data locally** when appropriate
4. **Use proper date formats** (YYYY-MM-DD)
5. **Validate user input** before sending to API
6. **Implement retry logic** for network failures
7. **Show loading states** while fetching data

---

## 📊 Rate Limiting

Currently, there are no rate limits, but consider implementing:
- Max 100 requests per minute per user
- Max 1000 requests per hour per user

---

## 🔄 API Versioning

Current version: **v1** (implicit)

Future versions will be prefixed: `/reports/api/v2/endpoint.php`

---

## 🔧 Admin Panel Module APIs

### 1. Product Pricing API
**Endpoint:** `/admin/api/product_pricing_api.php`

**Method:** GET  
**Access:** Admin only

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "product_name": "Woven Polypropylene Roll",
      "unit_price": 2500.00
    }
  ],
  "total": 1
}
```

**Method:** POST  
**Access:** Admin only

**Request Body:**
```json
{
  "prices": [
    {
      "id": 1,
      "unit_price": 2600.00
    },
    {
      "id": 2,
      "unit_price": 1800.00
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Successfully updated pricing for 2 product(s)",
  "updated_count": 2,
  "errors": []
}
```

---

### 2. Production Cost Settings API
**Endpoint:** `/admin/api/production_cost_settings_api.php`

**Method:** GET  
**Access:** Admin only

**Response:**
```json
{
  "success": true,
  "data": {
    "labor_cost": {
      "cost_type": "labor_cost",
      "cost_per_unit": 15.00,
      "updated_by": 1,
      "updated_at": "2025-10-14 10:30:00"
    },
    "utility_cost": {
      "cost_type": "utility_cost",
      "cost_per_unit": 5.00,
      "updated_by": 1,
      "updated_at": "2025-10-14 10:30:00"
    },
    "overhead_cost": {
      "cost_type": "overhead_cost",
      "cost_per_unit": 10.00,
      "updated_by": 1,
      "updated_at": "2025-10-14 10:30:00"
    }
  },
  "total_cost_per_unit": 30.00
}
```

**Method:** POST  
**Access:** Admin only

**Request Body:**
```json
{
  "labor_cost": 16.00,
  "utility_cost": 6.00,
  "overhead_cost": 11.00
}
```

**Response:**
```json
{
  "success": true,
  "message": "Production cost settings updated successfully",
  "data": {
    "labor_cost": 16.00,
    "utility_cost": 6.00,
    "overhead_cost": 11.00,
    "total_cost_per_unit": 33.00
  }
}
```

---

### 3. User Management API
**Endpoint:** `/admin/api/user_management_api.php`

**Method:** GET  
**Access:** Admin only

**Fetch All Users:**
```
GET /admin/api/user_management_api.php
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "username": "admin",
      "full_name": "System Administrator",
      "email": "admin@geotex.com",
      "role": "admin",
      "mobile": "01712345678",
      "employee_id": "EMP001",
      "status": "active",
      "created_at": "2025-01-01 00:00:00"
    }
  ],
  "total": 1
}
```

**Fetch Single User:**
```
GET /admin/api/user_management_api.php?id=1
```

**Method:** POST  
**Access:** Admin only  
**Action:** Create new user

**Request Body:**
```json
{
  "username": "newuser",
  "password": "SecurePassword123",
  "full_name": "John Doe",
  "email": "john@geotex.com",
  "role": "production_user",
  "mobile": "01712345679",
  "employee_id": "EMP002",
  "status": "active"
}
```

**Response:**
```json
{
  "success": true,
  "message": "User created successfully",
  "user_id": 2
}
```

**Method:** PUT  
**Access:** Admin only  
**Action:** Update existing user

**Request Body:**
```json
{
  "id": 2,
  "full_name": "John Smith",
  "mobile": "01712345680",
  "password": "NewPassword456"
}
```

**Response:**
```json
{
  "success": true,
  "message": "User updated successfully"
}
```

**Method:** DELETE  
**Access:** Admin only  
**Action:** Delete user

**Request Body:**
```json
{
  "id": 2
}
```

**Response:**
```json
{
  "success": true,
  "message": "User deleted successfully"
}
```

---

### 4. Email Management API
**Endpoint:** `/admin/api/email_management_api.php`

**Method:** GET  
**Access:** Admin only

**Response:**
```json
{
  "success": true,
  "data": {
    "primary": "admin@geotex.com",
    "team": ["user1@geotex.com", "user2@geotex.com"],
    "management": ["manager@geotex.com"],
    "all": ["admin@geotex.com", "user1@geotex.com", "manager@geotex.com"]
  }
}
```

**Method:** POST  
**Access:** Admin only

**Request Body:**
```json
{
  "primary": "admin@geotex.com",
  "team": ["user1@geotex.com", "user2@geotex.com"],
  "management": ["manager@geotex.com"],
  "all": ["admin@geotex.com", "user1@geotex.com", "manager@geotex.com"]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Email recipients updated successfully",
  "data": {
    "primary": "admin@geotex.com",
    "team": ["user1@geotex.com", "user2@geotex.com"],
    "management": ["manager@geotex.com"],
    "all": ["admin@geotex.com", "user1@geotex.com", "manager@geotex.com"]
  }
}
```

---

### 5. Security Dashboard API
**Endpoint:** `/admin/api/security_dashboard_api.php`

**Method:** GET  
**Access:** Admin only

**Get Security Summary:**
```
GET /admin/api/security_dashboard_api.php?type=summary
```

**Response:**
```json
{
  "success": true,
  "data": {
    "active_sessions": 5,
    "failed_logins_24h": 12,
    "locked_accounts": 1,
    "security_events_24h": 45
  }
}
```

**Get Active Sessions:**
```
GET /admin/api/security_dashboard_api.php?type=active_sessions
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "session_id": "abc123...",
      "user_id": 1,
      "username": "admin",
      "full_name": "System Administrator",
      "role": "admin",
      "ip_address": "192.168.1.100",
      "user_agent": "Mozilla/5.0...",
      "created_at": "2025-10-14 08:00:00",
      "last_activity": "2025-10-14 10:30:00",
      "expires_at": "2025-10-14 12:00:00"
    }
  ],
  "total": 1
}
```

**Get Failed Login Attempts:**
```
GET /admin/api/security_dashboard_api.php?type=failed_logins&limit=50
```

**Get Audit Log:**
```
GET /admin/api/security_dashboard_api.php?type=audit_log&limit=100&action_type=LOGIN
```

**Method:** POST  
**Access:** Admin only

**Terminate Session:**
```json
{
  "action": "terminate_session",
  "session_id": "abc123..."
}
```

**Unlock Account:**
```json
{
  "action": "unlock_account",
  "user_id": 5
}
```

**Clear Failed Logins:**
```json
{
  "action": "clear_failed_logins",
  "hours": 24
}
```

---

### 6. Management KPI Dashboard API
**Endpoint:** `/admin/api/management_kpi_api.php`

**Method:** GET  
**Access:** Admin, Management, AGM Ops, AGM Operations

**Parameters:**
- `date_from` (optional) - Format: YYYY-MM-DD (default: first day of current month)
- `date_to` (optional) - Format: YYYY-MM-DD (default: today)

**Response:**
```json
{
  "success": true,
  "date_from": "2025-10-01",
  "date_to": "2025-10-14",
  "data": {
    "roll_production": {
      "total_rolls": 150,
      "total_weight": 12500.50,
      "avg_weight": 83.34
    },
    "fiber_to_roll": {
      "total_fiber_used": 13000.00,
      "total_roll_produced": 12500.50
    },
    "conversion_efficiency": 96.15,
    "cnc_production": {
      "total_cnc": 200,
      "total_cnc_qty": 15000.00
    },
    "fg_production": {
      "total_fg": 50,
      "total_fg_weight": 5000.00
    },
    "qc_stats": {
      "total_inspections": 300,
      "passed": 285,
      "failed": 15
    },
    "qc_pass_rate": 95.00,
    "scrap_stats": {
      "total_scrap_qty": 500.00,
      "by_type": [
        {
          "scrap_type": "Cutting Waste",
          "qty_by_type": 300.00
        },
        {
          "scrap_type": "Defective Material",
          "qty_by_type": 200.00
        }
      ]
    },
    "recycle_stats": {
      "total_recycle_records": 10,
      "total_recycled_qty": 250.00
    },
    "recycle_rate": 50.00,
    "fg_stock": {
      "total_items": 100,
      "total_stock_weight": 8500.00
    },
    "fg_delivery": {
      "total_deliveries": 30,
      "total_delivered_weight": 3500.00
    },
    "target_stats": {
      "total_target": 10000.00,
      "total_actual": 9500.00
    },
    "target_achievement": 95.00,
    "active_projects": {
      "total_projects": 5
    },
    "active_users": {
      "active_users": 25
    },
    "opv_score": 93.25
  }
}
```

---

## 📞 Support

For API issues or questions:
- Check error responses first
- Verify authentication/session
- Ensure proper permissions for user role
- Check date formats and parameter names

---

**Last Updated:** 2025-10-14  
**API Version:** 1.0  
**Documentation Version:** 1.4

