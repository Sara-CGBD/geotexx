@echo off
REM =====================================================
REM SCHEDULE AUTOMATIC DATABASE BACKUPS
REM Run this script ONCE to set up automatic daily backups
REM =====================================================

ECHO =====================================================
ECHO Setting up automated database backups
ECHO =====================================================
ECHO.

REM Get the current directory
SET SCRIPT_DIR=%~dp0

REM Create scheduled task for daily backup at 2 AM
SCHTASKS /CREATE /TN "GEOTEX_Daily_Backup" /TR "%SCRIPT_DIR%automated_backup.bat" /SC DAILY /ST 02:00 /F /RU SYSTEM

IF %ERRORLEVEL% EQU 0 (
    ECHO [SUCCESS] Daily backup scheduled for 2:00 AM
) ELSE (
    ECHO [ERROR] Failed to create scheduled task
    ECHO Run this script as Administrator
    PAUSE
    EXIT /B 1
)

REM Create scheduled task for hourly incremental backup (during business hours 8 AM - 6 PM)
SCHTASKS /CREATE /TN "GEOTEX_Hourly_Backup" /TR "%SCRIPT_DIR%automated_backup.bat" /SC HOURLY /ST 08:00 /ET 18:00 /F /RU SYSTEM

IF %ERRORLEVEL% EQU 0 (
    ECHO [SUCCESS] Hourly backup scheduled (8 AM - 6 PM)
) ELSE (
    ECHO [WARNING] Failed to create hourly backup task
)

ECHO.
ECHO =====================================================
ECHO Backup automation setup complete!
ECHO =====================================================
ECHO.
ECHO Daily full backup: 2:00 AM
ECHO Hourly backups: 8:00 AM - 6:00 PM (business hours)
ECHO Backup location: C:\geotex_backups
ECHO.
ECHO To view scheduled tasks, run: schtasks /query /tn GEOTEX*
ECHO To delete scheduled tasks, run: schtasks /delete /tn GEOTEX*
ECHO.

PAUSE

