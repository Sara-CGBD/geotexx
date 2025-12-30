# Database Table Cleanup Guide

This directory contains scripts to identify and delete unused database tables.

## âš ï¸ IMPORTANT WARNING

**Always backup your database before running deletion scripts!**

## Step 1: List Unused Tables

Run the analysis script to see which tables are potentially unused:

```
http://localhost/geotex/database/list_unused_tables.php
```

This script will:

- List all tables in the database
- Scan the codebase for table references
- Identify tables that appear to be unused
- Show row counts for each unused table
- Highlight tables with data (review carefully!)

## Step 2: Review the Results

The script will show:

- âœ… **Used Tables**: Tables that are referenced in the codebase
- âŒ **Unused Tables**: Tables that don't appear to be used

**Important Notes:**

- Some tables might be used in ways not detected by the scan
- Review empty tables (0 rows) - these are safer to delete
- Review tables with data carefully - they might contain important information
- Check if tables are used by external scripts or scheduled tasks

## Step 3: Delete Unused Tables

After reviewing, you can delete unused tables:

### Option 1: Delete via Web Interface

1. Open `database/list_unused_tables.php` in your browser
2. Review the unused tables list
3. Manually delete tables using phpMyAdmin or run:

```
http://localhost/geotex/database/delete_unused_tables.php?tables=table1,table2,table3
```

### Option 2: Delete via SQL

Connect to MySQL and run:

```sql
DROP TABLE IF EXISTS `table_name`;
```

### Option 3: Delete via Command Line Script

```bash
php database/identify_unused_tables.php
```

## Safety Features

The deletion script includes:

- âœ… Admin-only access check
- âœ… Protection for critical system tables (new_user, users, etc.)
- âœ… Row count display before deletion
- âœ… Error handling and reporting

## Critical Tables (Never Delete)

These tables are protected and cannot be deleted:

- `new_user`
- `users`
- `active_sessions`
- `projects`
- `audit_log`

## Backup Before Deletion

Always create a backup first:

```bash
# Windows (XAMPP)
C:\xampp\mysql\bin\mysqldump.exe -u root geobagg > backup_before_cleanup.sql

# Or use phpMyAdmin Export feature
```

## Troubleshooting

If you accidentally delete a table:

1. Restore from backup
2. Check if the table is recreated automatically by the application
3. Some tables are created on-demand when needed

## Questions?

If unsure about a table:

1. Check when it was last modified: `SELECT MAX(updated_at) FROM table_name;`
2. Check if it has foreign key relationships
3. Search the codebase for the table name manually
4. Ask the development team

