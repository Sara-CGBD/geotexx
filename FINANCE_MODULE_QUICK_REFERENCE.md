# Finance Module - Quick Reference Guide

## 📊 Finance Menu Overview

```
Finance ($ Finance in Navigation)
├── 📦 Product Pricing          → Set selling prices for bags
├── 📦 Materials Management     → Set raw material prices (NEW)
├── ⚙️  Production Cost Settings → Configure labor/utility/overhead
├── 🗑️  Scrap Cost Settings      → Set scrap costs per kg
├── 💰 Material Consumption Cost → Track material usage costs
├── 🏭 Production Cost Report   → Labor/utility/overhead costs
├── 📉 Scrap Loss Report        → Financial impact of scrap
└── 📈 Profitability Report     → Revenue vs costs analysis
```

---

## 🚀 Quick Start Guide

### Step 1: Set Material Prices
**Go to**: Materials Management
**Action**: Add or update material prices
**Example**: PP Stable Fiber = ৳80/kg

### Step 2: Create BOM
**Go to**: BOM Entry (Planning menu)
**Action**: Create material recipe for each bag type
**Result**: System calculates BOM Cost

### Step 3: Set Product Prices
**Go to**: Product Pricing
**Action**: Set Unit Price (selling price) for each bag
**View**: BOM Cost and Profit automatically calculated

### Step 4: Configure Production Costs
**Go to**: Production Cost Settings
**Action**: Set Labor, Utility, Overhead costs per unit
**Example**: Labor ৳15, Utility ৳5, Overhead ৳10 = ৳30 total

### Step 5: Track Operations
**Production Entries**: CNC, Sewing, Branding entries
**Material Consumption**: Record actual material usage
**FG Delivery**: Record sales/deliveries

### Step 6: View Reports
- **Material Consumption Cost**: See material usage costs
- **Production Cost**: See labor/utility/overhead costs
- **Profitability**: See net profit and margins

---

## 💡 Key Concepts

### Price vs Cost
| Term | Meaning | Set In | Used For |
|------|---------|--------|----------|
| **Material Price** | Cost to buy raw material | Materials Management | Material cost calculations |
| **BOM Cost** | Total material cost per bag | BOM Entry (auto-calculated) | Product costing |
| **Unit Price** | Selling price per bag | Product Pricing | Revenue calculation |
| **Production Cost** | Labor + Utility + Overhead | Production Cost Settings | Production cost calculation |

### Profit Calculation
```
Revenue = Delivery Qty × Unit Price
Total Cost = BOM Cost + Material Cost + Production Cost + Scrap Cost
Net Profit = Revenue - Total Cost
Profit Margin % = (Net Profit / Revenue) × 100
```

---

## 📝 Common Tasks

### Add a New Material
1. Finance → Materials Management
2. Fill form: Material Name, Price per kg
3. Click "Add Material"

### Set Price for a Bag
1. Finance → Product Pricing
2. Find the bag (e.g., 1200mmX950mm | 3mm | 400 GSM | 125kg)
3. Enter Unit Price
4. Click "Save All Prices"
5. View profit = Unit Price - BOM Cost

### Create BOM for New Bag
1. Planning → BOM Entry
2. Click "Quick Add" (green button)
3. Fill bag details
4. Submit
5. Add materials for this bag
6. System calculates total BOM cost

### Track Material Usage
1. Planning → Material Consumption Entry
2. Select date, shift, process type
3. Enter material name and quantity
4. Submit
5. View cost in Material Consumption Cost Report

### View Profitability
1. Finance → Profitability Report
2. Select date range
3. View revenue, costs, profit, and margin
4. Export CSV if needed

---

## ⚠️ Troubleshooting

### Material Consumption Cost shows ৳0
**Reason**: Materials don't have prices set
**Fix**: 
- Option 1: Click "Create Missing Materials" (sets all to ৳50)
- Option 2: Go to Materials Management and set proper prices

### Product Pricing shows "Create BOM first"
**Reason**: No BOM exists for that bag configuration
**Fix**: Go to BOM Entry and create BOM with materials

### Production Cost Report shows ৳0
**Reason**: Either no production data OR costs not configured
**Fix**:
- Check Production Cost Settings (set labor/utility/overhead)
- Verify production entries exist in date range

### Profitability Report shows ৳0
**Reason**: No FG delivery records
**Fix**: Create delivery entries in FG Delivery Entry form

### BOM Cost not showing in Product Pricing
**Reason**: Bag size format mismatch between BOM and bag_size_master
**Fix**: System auto-fixes on page load (silent)

---

## 🎨 Stat Card Colors Reference

### Material Consumption Cost Report
- Orange: Total Cost
- Green: Total Consumed (kg)
- Red: Avg Cost/kg

### Production Cost Report
- Orange: Total Cost
- Green: Labor Cost
- Pink/Orange: Utility Cost
- Dark Blue: Overhead Cost

### Profitability Report
- Green: Total Revenue
- Red: Total Cost
- Orange/Pink: Net Profit
- Purple: Profit Margin %

---

## 🔗 Page Interconnections

```
Materials Management
    ↓ (provides material prices)
BOM Entry
    ↓ (calculates BOM cost)
Product Pricing
    ↓ (sets selling prices)
FG Delivery Entry
    ↓ (records sales)
Profitability Report
    ↑
Material Consumption Cost Report
Production Cost Report
```

### Auto-Refresh Features
- **BOM Entry ↔ Product Pricing**: Adding bag in either refreshes the other
- When you add a bag in Product Pricing, BOM Entry button list auto-updates
- When you Quick Add a bag in BOM Entry, Product Pricing auto-refreshes

---

## 📊 Report Filters

### All Reports Support:
- **Date Range**: Start Date to End Date
- **Project Filter**: View data for specific project
- **Export CSV**: Download data
- **Print**: Print-friendly format

### Default Date Range:
- Most reports: Current month (from 1st to today)
- Material Consumption: Last 3 months
- Adjust as needed

---

## 🔐 Access Control

### Admin & Finance Roles:
✅ Full access to all finance pages
✅ Can modify prices and settings
✅ Can view all reports
✅ Can export data

### Other Roles:
❌ Restricted or no access
❌ Clear "Access Denied" messages shown

---

## 📱 Mobile Responsiveness

All finance pages are responsive:
- Tables scroll horizontally on small screens
- Filters stack vertically
- Stat cards adjust layout
- All features accessible on mobile

---

## 🎯 Best Practices

### 1. Material Prices
- Update regularly to reflect market changes
- Use realistic prices for accurate costing
- Review prices monthly

### 2. BOM Entry
- Create detailed BOMs for all bag types
- Keep BOM costs updated
- Review material quantities periodically

### 3. Product Pricing
- Ensure profit margin is positive
- Compare with market rates
- Adjust based on BOM cost changes

### 4. Production Costs
- Review labor/utility/overhead costs quarterly
- Adjust based on actual expenses
- Document changes

### 5. Reports
- Generate reports regularly (weekly/monthly)
- Compare trends over time
- Use filters to drill down into specifics
- Export data for presentations

---

## 📞 Support

For issues or questions:
1. Check this Quick Reference
2. Review FINANCE_MODULE_UPDATE_SUMMARY.md
3. Check SOFTWARE_FUNCTIONALITY.md
4. Contact system administrator

---

## ✅ Checklist: Setting Up Finance Module

- [ ] Add all raw materials in Materials Management
- [ ] Set prices for all materials
- [ ] Create BOMs for all bag types
- [ ] Set unit prices in Product Pricing
- [ ] Configure production cost settings
- [ ] Set scrap cost settings
- [ ] Verify all reports show data correctly
- [ ] Train staff on using the system
- [ ] Establish regular review schedule

---

**Last Updated**: November 3, 2025
**Version**: 2.0
**For**: GEOCIL Automation System

