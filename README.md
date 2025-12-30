# GEOCIL Automation System

A comprehensive manufacturing automation system for GEOCIL with role-based access control, data entry forms, and reporting capabilities.

##  Project Structure

```
test/
 Core Files
 index.php              # Main dashboard 
login.php              # Login handler
 login.html             # Login form
 logout.php             # Logout handler
all_dashboard.php      # Dashboard content

 config/                 # Configuration Files
 config.php             # Database configuration
 security_config.php    # Security settings

 includes/               # Template Files
 form_header.php        # Full form header template
 form_footer.php        # Full form footer template
 iframe_form_header.php # Iframe form header template
iframe_form_footer.php # Iframe form footer template

forms/                  # Data Entry Forms
 fiber_entry.php        # Fiber entry (full layout)
fiber_entry_iframe.php # Fiber entry (iframe)
roll_entry.php         # Roll entry (full layout)
 roll_entry_iframe.php  # Roll entry (iframe)
 cnc_entry.php          # CNC machine entry
scrap_entry.php        # Scrap entry
 fg_entry.php           # Finished goods entry
branding_entry.php     # Branding entry
 project_entry.php      # Project entry
 BOM_entry.php          # Bill of Materials entry
[other entry forms]

handlers/               # Form Submission Handlers
 submit_fiber_entry.php # Fiber entry handler
 submit_roll_entry.php  # Roll entry handler
 submit_scrap_entry.php # Scrap entry handler
 submit_bom_entry.php   # BOM entry handler

 admin/                  # User Management
 user_create_new_user.php      # Create new user
 user_management_new_user.php # User management interface
create_new_user.php          # Create user (legacy)
 [other admin files]

 assets/                 # Static Assets
 css/                   # Stylesheets
 js/                    # JavaScript files
images/                 # Images and icons
â”‚
 Database Files
  create_tables.sql       # Database schema
    setup_database.php     # Database setup script
 get_real_ip.php        # IP detection utility
```

 Features

### Core Functionality
- **Role-based Access Control**: Admin, Production, QC, Finance, Management roles
- **Responsive Dashboard**: Hamburger menu with collapsible sidebar
- **Data Entry Forms**: Comprehensive forms for all manufacturing processes
- **Session Management**: Secure session handling with timeout protection
- **Database Integration**: MySQL database with prepared statements

### User Roles & Permissions
- **Admin**: Full system access, user management, security dashboard
- **Production**: Roll production, CNC operations, manufacturing entries
- **QC**: Quality control entries and reports
- **Finance**: Financial reports and analysis
- **Management**: KPI dashboards, production summaries, cost analysis

### Form Types
- **Full Layout Forms**: Complete pages with hamburger menu
- **Iframe Forms**: Optimized for Quick Actions within dashboard
- **Entry Forms**: Fiber, Roll, CNC, Scrap, FG, Branding, Project, BOM
- **Management Forms**: User creation, password changes, settings

## Installation & Setup

### Prerequisites
- XAMPP (Apache + MySQL + PHP)
- Modern web browser
- Font Awesome 6.5.0
- Inter font family

### Setup Steps
1. **Clone/Download** the project to `C:\xampp\htdocs\test\`
2. **Start XAMPP** services (Apache + MySQL)
3. **Create Database**:
   ```sql
   CREATE DATABASE geobagg;
   ```
4. **Run Setup Script**:
   ```bash
   php setup_database.php
   ```
5. **Access System**:
   ```
   http://localhost/test/
   ```

### Default Login Credentials
- **Username**: `admin`
- **Password**: `admin123`
- **Role**: `admin`

## ðŸ“‹ Usage

### Dashboard Navigation
1. **Login** with your credentials
2. **Use Hamburger Menu** (â˜°) to navigate
3. **Click Group Headers** to expand submenus
4. **Quick Actions** open forms within the dashboard iframe

### Data Entry
1. **Navigate** to desired form via menu or Quick Actions
2. **Fill Required Fields** (marked with *)
3. **Submit Form** using the submit button
4. **View Confirmation** message

### User Management (Admin Only)
1. **Go to** User & Security â†’ Add User
2. **Fill User Details** and assign role
3. **Create User** account
4. **Manage Users** via User Management

## ðŸ”§ Configuration

### Database Settings
Edit `config/config.php`:
```php
$host = "localhost";
$username = "root";
$password = "";
$dbname = "geobagg";
```

### Security Settings
Edit `config/security_config.php` for:
- Session timeout settings
- Password policies
- Account lockout rules
- Security headers

## ðŸŽ¨ Customization

### Styling
- **Main Styles**: Inline CSS in template files
- **Color Scheme**: Modify CSS variables in templates
- **Layout**: Adjust sidebar width, padding, margins
- **Responsive**: Mobile-first design with breakpoints

### Menu Structure
Edit menu arrays in:
- `index.php` (main dashboard)
- `includes/form_header.php` (full forms)
- `includes/iframe_form_header.php` (iframe forms)

### Adding New Forms
1. **Create Form File** in `forms/` directory
2. **Include Template**: Use `iframe_form_header.php` for Quick Actions
3. **Add Menu Item**: Update menu arrays
4. **Create Handler**: Add submission handler in `handlers/`

## Security Features

- **SQL Injection Protection**: Prepared statements
- **XSS Protection**: Input sanitization and output escaping
- **Session Security**: Secure session handling
- **CSRF Protection**: Token-based protection
- **Account Lockout**: Failed login attempt limits
- **Password Hashing**: Secure password storage
- **IP Tracking**: Real IP address detection

##  Database Schema

### Core Tables
- `users`: User accounts and roles
- `new_user`: Enhanced user management
- `active_sessions`: Session tracking
- `projects`: Project management
- `[entry_tables]`: Data entry storage

### Key Relationships
- Users Roles (one-to-many)
- Projects  Entries (one-to-many)
- Sessions  Users (one-to-many)

##  Troubleshooting

### Common Issues
1. **Login Fails**: Check database connection and user credentials
2. **Menu Not Showing**: Verify user role in database
3. **Forms Not Loading**: Check file paths and includes
4. **Session Issues**: Clear browser cache and cookies

### Debug Mode
Enable debug mode by setting:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

##  Development Notes

### Code Standards
- **PHP**: PSR-4 autoloading, prepared statements
- **HTML**: Semantic markup, accessibility
- **CSS**: Mobile-first, BEM methodology
- **JavaScript**: ES6+, modular approach

### File Naming
- **Forms**: `[type]_entry.php` or `[type]_entry_iframe.php`
- **Handlers**: `submit_[type]_entry.php`
- **Templates**: `[type]_form_[header/footer].php`
- **Config**: `[purpose]_config.php`

## Support

For technical support or feature requests, contact the development team.

---

**Version**: 1.0.0  
**Last Updated**: September 2025  
**License**: Proprietary

