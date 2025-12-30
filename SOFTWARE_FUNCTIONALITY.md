# GEOCIL Automation - Complete Software Functionality Documentation

## 1. USER MANAGEMENT & AUTHENTICATION

### 1.1 User Roles
- **Admin**: Full system access
- **Management**: Strategic oversight and reports
- **AGM Operations**: Operational management and approvals
- **Production**: Production floor operations
- **QC (Quality Control)**: Quality inspection
- **QC Inspector**: Quality checks requiring approval
- **Tester/Lab Tester**: Laboratory testing
- **Store/Inventory**: Material management
- **Accounts**: Financial operations

### 1.2 Authentication Features
- Secure login with password hashing (bcrypt)
- Session management with timeout (30 minutes)
- Failed login attempt tracking
- Account lockout after multiple failed attempts
- Role-based access control (RBAC)
- Password change functionality
- Session activity tracking

### 1.3 User Management (Admin Only)
- Create new users
- Edit user details
- Activate/deactivate accounts
- Assign roles and permissions
- View user activity logs
- Reset passwords

---

## 2. PRODUCTION MODULES

### 2.1 Fiber Management
**Fiber Entry**
- Record fiber reception
- Track fiber type, grade, and specifications
- Record supplier information
- Log weights and quantities
- Generate fiber IDs

**Fiber Test Report**
- Sample testing details
- Test date and performed by
- Test results and parameters
- Status tracking (pending, approved, rejected)
- Approval workflow

### 2.2 Roll Production
**Roll Entry**
- Record roll production
- Link to fiber source
- Track GSM (grams per square meter)
- Record dimensions and weight
- Generate roll numbers
- Batch tracking

**Fiber to Roll Entry**
- Track fiber consumption in roll production
- Link fiber batches to rolls
- Record conversion ratios
- Production date and shift

**Roll Transfer Entry**
- Transfer rolls between locations/stages
- Track movement history
- Update inventory locations

**Roll Received Entry**
- Receive transferred rolls
- Verify quantities
- Update stock levels

### 2.3 Production Entry
**Production Records**
- Daily production tracking
- Shift-wise production
- Machine/line assignment
- Operator tracking
- Production targets vs actuals

**Swing Machine Entry**
- Swing machine operations
- Production quantities
- Quality parameters
- Operator details

### 2.4 CNC Operations
**CNC Entry**
- CNC cutting operations
- Material consumption tracking
- Cutting roll quantity (number of rolls)
- Output quantities
- Waste tracking
- Operator and shift details
- Project and bag size selection
- Reference number and cutting batch tracking
- Clean database structure (removed unused actual_weight and recommended_weight columns)

---

## 3. FINISHED GOODS (FG) MANAGEMENT

### 3.1 FG Entry
- Record finished goods production
- Product specifications
- Batch numbers
- Quantities produced
- Packaging details

### 3.2 FG Received Entry
- Receive finished goods
- Quality verification
- Stock updates
- Location assignment

### 3.3 FG Delivery Entry
- Delivery order management
- Customer details
- Delivery quantities
- Dispatch tracking
- Invoice generation

### 3.4 Branding Entry
- Branding operations
- Logo/label application
- Quality checks
- Batch tracking

---

## 4. QUALITY CONTROL (QC) SYSTEM

### 4.1 QC Entry (NEW APPROVAL WORKFLOW)
**For QC Inspectors:**
- Submit QC inspections
- Stage-wise QC (Roll, CNC, Production, FG)
- Test parameters and results
- Pass/Fail determination
- Status: Pending approval

**For Admin/AGM Ops:**
- Auto-approved submissions
- No inspector name fields shown
- Direct entry capability

### 4.2 QC Approval Dashboard (Admin/AGM Ops Only)
- View pending QC submissions from inspectors
- Approve or reject QC entries
- Add rejection reasons
- Track approved entries
- Statistics: Pending, Approved, Rejected counts
- Recently approved entries list

### 4.3 QC Test Order
- Order laboratory tests
- Specify test requirements
- Track test requests
- Link to samples

### 4.4 Laboratory Testing
**Water Permeability Test**
- ISO 11058 standard testing
- Permeability measurements
- Flow rate calculations
- Approval workflow

**Characteristics Test (ISO 12956)**
- Fabric characteristics analysis
- Multiple test parameters
- Test standards compliance
- Checker and approver workflow

**Sewing Thread Report**
- Thread quality testing
- Strength measurements
- Test standards
- Approval process

**UV/Weathering Exposure Test**
- UV resistance testing
- Exposure duration tracking
- Degradation measurements
- Test results and approval

**Fiber Test Report**
- Fiber quality analysis
- Sample testing
- Test parameters
- Approval workflow

**Fabric Pre-Production Test**
- Pre-production quality checks
- GSM measurements
- Thickness testing
- Strip tensile test (MD:CD ratio)
- Grab tensile test
- CBR puncture test
- Dynamic perforation test
- Summary statistics (max, min, average, CV%)
- Multi-level approval (checker, approver)

**Fabric After Production Test**
- Post-production quality verification
- Similar parameters to pre-production
- Final quality confirmation
- Approval workflow

**Sun Test Report**
- Sun exposure testing
- Duration tracking
- Test results
- Approval process

### 4.5 Rejected Reports Dashboard (For Testers/Inspectors)
- View all rejected reports
- Filter by test type
- See rejection reasons
- Direct link to edit and resubmit
- Track resubmission status

### 4.6 QC Inspection Report
- Historical QC data
- Pass/fail trends
- Inspector performance
- Date range filtering

---

## 5. MATERIAL MANAGEMENT

### 5.1 BOM (Bill of Materials) Entry
- Project-wise BOM
- Material requirements from materials master table
- Dynamic material type dropdown (fetched from materials table)
- Quantity specifications
- Total material cost calculation (Unit Price field removed)
- Bag size selection from bag_size_master
- Quick Add form for new bag configurations
- Auto-refresh bag size buttons when new bags added
- Two-way sync with Product Pricing page
- Direct link to Product Pricing (Manage All)
- Cost estimation for each bag configuration

### 5.2 Material Consumption Entry
- Record material usage
- Material name saved directly (no foreign key dependency)
- Track consumption per project
- Process type tracking (Fiber to Roll, CNC, Finishing, etc.)
- Quantity and unit tracking
- Shift and operator details
- Update inventory
- Cost tracking via Materials Management prices
- Individual entry display in reports (not grouped)

### 5.3 Scrap Management
**Scrap Entry**
- Record scrap generation
- Categorize by type
- Track quantities
- Source identification

**Scrap Recycle Entry**
- Scrap recycling operations
- Recovery quantities
- Recycled material tracking
- Value recovery

---

## 6. PROJECT MANAGEMENT

### 6.1 Project Entry
- Create new projects
- Project specifications
- Customer details
- Target quantities
- Timeline tracking

### 6.2 Target Entry
- Set production targets
- Daily/weekly/monthly targets
- Department-wise targets
- Target vs actual tracking

---

## 7. REPORTING & ANALYTICS

### 7.1 Production Reports
**Roll Production Summary**
- Production quantities by date
- Machine/line wise production
- Shift-wise analysis
- Production efficiency

**Target vs Actual Report**
- Compare targets with achievements
- Variance analysis
- Performance metrics
- Graphical representation

**Fiber to Roll Production Report**
- Conversion tracking
- Fiber consumption analysis
- Production efficiency
- Material utilization

### 7.2 Quality Reports
**QC Inspection Report**
- QC history by date range
- Pass/fail statistics
- Inspector performance
- Stage-wise quality metrics

**QC Pass/Fail Trend**
- Trend analysis over time
- Quality improvement tracking
- Defect patterns
- Statistical analysis

**Defect Category Analysis**
- Categorize defects
- Frequency analysis
- Root cause identification
- Improvement areas

### 7.3 Inventory Reports
**FG Stock Summary**
- Current stock levels
- Product-wise inventory
- Batch tracking
- Aging analysis

**Scrap Summary by Type**
- Scrap categories
- Quantities by type
- Value analysis
- Disposal tracking

### 7.4 Financial Reports & Settings

**Materials Management** (NEW)
- Master table for raw material prices
- Set price per kg for all materials
- Track material costs centrally
- Add/edit/delete materials
- Price history tracking
- Used for: Material Consumption Cost calculations

**Product Pricing Settings**
- Set selling prices for finished goods
- View BOM cost per product
- Calculate profit margin (Unit Price - BOM Cost)
- Auto-sync with bag configurations
- Detailed specifications (Size, GSM, Thickness, Capacity)
- Smart pricing controls (requires BOM cost to set price)
- Direct link to BOM Entry
- Two-way auto-refresh with BOM Entry page

**Production Cost Settings**
- Configure labor cost per unit
- Set utility cost per unit
- Define overhead cost per unit
- Total production cost calculation
- Summary statistics display
- Modern card-based interface

**Scrap Cost Settings**
- Set cost per kg for each scrap type
- Track scrap product categories
- Display total quantities
- Link to Scrap Loss Report

**Material Consumption Cost Report**
- Individual consumption entry tracking
- Material name and quantity consumed
- Unit price from Materials Management
- Total cost calculation (Quantity × Unit Price)
- Process type breakdown (Fiber to Roll, CNC, Finishing, etc.)
- Project-wise costs
- Shift and operator tracking
- Auto-create missing materials feature
- Date range filtering
- Export to CSV

**Production Cost Report**
- Labor, utility, and overhead cost tracking
- Production type breakdown (CNC, Sewing, Branding)
- Project-wise production costs
- Shift-wise cost analysis
- Cost per unit calculations
- Date range filtering
- Gradient stat cards for visual impact
- Link to Production Cost Settings
- Export and print functionality

**Scrap Loss Report**
- Financial impact of scrap
- Loss quantification
- Category-wise losses
- Recovery opportunities
- Date range analysis

**Profitability Report**
- Revenue from FG deliveries
- Comprehensive cost analysis (BOM + Material + Production + Scrap)
- Net profit calculation
- Profit margin percentage
- Project and bag size breakdown
- Color-coded profit indicators (green=positive, red=negative)
- Date range filtering
- Modern gradient stat cards
- Export to CSV

**Material Requirement Report**
- Future material needs
- Ordering recommendations
- Stock sufficiency
- Lead time analysis

---

## 8. ADMIN PANEL

### 8.1 Dashboard Overview
**All Dashboard**
- Key performance indicators
- Production summary
- Quality metrics
- Inventory status
- Recent activities

**Management KPI Dashboard**
- Executive-level KPIs
- Strategic metrics
- Trend analysis
- Performance scorecards

### 8.2 System Administration
**User Management**
- Create/edit/delete users
- Role assignments
- Permission management
- User activity monitoring

**Security Dashboard**
- Login attempts monitoring
- Failed login tracking
- Account lockout management
- Security audit logs
- IP address tracking
- Session management

**System Monitoring Dashboard**
- Server uptime
- Database performance
- Total queries executed
- Slow query detection
- Database size tracking
- Connection monitoring
- Table sizes
- Recent database changes

**Role Permissions Report**
- View role-based permissions
- Access control matrix
- Module access by role
- Permission audit

### 8.3 Email Management
- Configure email notifications
- Recipient management
- Email templates
- Automated reports via email
- Schedule email reports

### 8.4 System Data Management
**System Data View**
- View all database tables
- Record counts
- Data integrity checks
- System statistics

**Export All Data**
- Backup all data
- CSV/Excel export
- Scheduled backups
- Data archival

**Quick Access**
- Frequently used functions
- Shortcuts to common tasks
- Quick data entry
- Fast navigation

---

## 9. SECURITY FEATURES

### 9.1 Authentication Security
- Password hashing (bcrypt)
- Session timeout (30 minutes)
- Failed login tracking (max 5 attempts)
- Account lockout mechanism
- IP address logging
- Real-time security monitoring

### 9.2 Access Control
- Role-based permissions (RBAC)
- Module-level access control
- Feature-level permissions
- Data access restrictions
- Audit trails for sensitive operations

### 9.3 Audit & Compliance
**Security Audit Report**
- Login history
- Failed login attempts
- Account lockouts
- User activity logs
- IP address tracking

**Audit Log**
- All system changes
- Who did what and when
- Data modification tracking
- Delete operation logging
- Change history

### 9.4 Session Management
- Automatic session timeout
- Session activity tracking
- Multiple session prevention
- Secure session handling
- Session cleanup

---

## 10. WORKFLOW AUTOMATIONS

### 10.1 QC Approval Workflow
1. **Inspector submits** → Status: Pending
2. **Admin/AGM Ops reviews** → Approve/Reject
3. **If Approved** → Status: Approved
4. **If Rejected** → Appears in Inspector's Rejected Reports
5. **Inspector resubmits** → Back to Pending

### 10.2 Multi-Level Test Approval
1. **Tester submits** → Pending
2. **Checker reviews** → Approved/Rejected by Checker
3. **Approver final review** → Approved/Rejected by Approver
4. **If Rejected** → Back to tester for resubmission

### 10.3 Material Tracking
- Fiber → Roll → CNC → Production → FG → Delivery
- Complete traceability
- Batch tracking
- Quality checkpoints at each stage

### 10.4 Inventory Updates
- Automatic stock updates on production
- Material consumption tracking
- Transfer operations
- Delivery deductions

---

## 11. KEY FEATURES

### 11.1 Real-Time Monitoring
- Live production tracking
- Quality metrics dashboard
- Inventory levels
- System performance

### 11.2 Traceability
- Complete batch tracking
- Material genealogy
- Quality history
- Process tracking

### 11.3 Approval Workflows
- Multi-level approvals
- Role-based authorization
- Rejection with reasons
- Resubmission tracking

### 11.4 Responsive Design
- Modern UI/UX
- Bootstrap-based design
- Mobile-friendly interface
- Consistent styling

### 11.5 Data Integrity
- Database constraints
- Validation rules
- Referential integrity
- Audit trails

---

## 12. TECHNICAL SPECIFICATIONS

### 12.1 Technology Stack
- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap
- **Backend**: PHP 8.x
- **Database**: MySQL/MariaDB
- **Server**: Apache (XAMPP)
- **Security**: bcrypt password hashing, prepared statements

### 12.2 Database Design
- Normalized relational database
- Foreign key constraints
- Indexed columns for performance
- Audit logging tables
- Session management tables

### 12.3 Security Standards
- SQL injection prevention (prepared statements)
- XSS protection (htmlspecialchars)
- CSRF protection
- Secure session handling
- Password strength requirements

---

## 13. USER INTERFACE FEATURES

### 13.1 Navigation
- Role-based menu system
- Collapsible sidebar
- Breadcrumb navigation
- Quick access shortcuts

### 13.2 Forms
- Input validation
- Auto-calculation fields
- Dynamic form fields
- Date pickers
- Dropdown selections
- Read-only fields for auto-filled data

### 13.3 Tables & Lists
- Sortable columns
- Search/filter functionality
- Pagination
- Export options
- Color-coded status

### 13.4 Dashboards
- Statistics cards
- Charts and graphs
- Recent activity feeds
- Alert notifications
- Performance metrics

---

## 14. INTEGRATION POINTS

### 14.1 Email Integration
- PHPMailer library
- SMTP configuration
- Automated notifications
- Report delivery
- Alert emails

### 14.2 Export Capabilities
- CSV export
- Excel export
- PDF generation (future)
- Backup/restore

---

## 15. FUTURE ENHANCEMENTS (Planned)

- Mobile app integration
- Barcode/QR code scanning
- Advanced analytics and AI-based insights
- Real-time notifications
- Cloud backup
- API for third-party integrations
- Multi-language support
- Advanced reporting with charts

---

## 16. SYSTEM REQUIREMENTS

### 16.1 Server Requirements
- PHP 8.0 or higher
- MySQL 5.7 or MariaDB 10.2+
- Apache 2.4+
- 2GB RAM minimum
- 10GB disk space

### 16.2 Client Requirements
- Modern web browser (Chrome, Firefox, Edge, Safari)
- JavaScript enabled
- Minimum 1024x768 resolution
- Internet connection

---

## 17. SUPPORT & MAINTENANCE

### 17.1 Backup Strategy
- Daily automated backups
- Manual backup option
- Point-in-time recovery
- Data export capabilities

### 17.2 Monitoring
- System health checks
- Database performance monitoring
- Error logging
- Activity tracking

### 17.3 Updates
- Security patches
- Feature enhancements
- Bug fixes
- Performance optimizations

---

**Document Version**: 1.0  
**Last Updated**: October 19, 2025  
**Software Name**: GEOCIL Automation  
**Purpose**: Geotextile Manufacturing ERP System

