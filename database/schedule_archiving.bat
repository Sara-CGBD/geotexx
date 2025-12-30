@echo off
REM =====================================================
REM SCHEDULE MONTHLY DATA ARCHIVING
REM Run this script ONCE to set up automatic archiving
REM =====================================================

ECHO =====================================================
ECHO Setting up automated data archiving
ECHO =====================================================
ECHO.

REM Get the current directory
SET SCRIPT_DIR=%~dp0

REM Create scheduled task for monthly archiving (1st of each month at 3 AM)
SCHTASKS /CREATE /TN "GEOTEX_Monthly_Archive" /TR "C:\xampp\php\php.exe %SCRIPT_DIR%data_archiving.php" /SC MONTHLY /D 1 /ST 03:00 /F /RU SYSTEM

IF %ERRORLEVEL% EQU 0 (
    ECHO [SUCCESS] Monthly archiving scheduled for 1st of each month at 3:00 AM
) ELSE (
    ECHO [ERROR] Failed to create scheduled task
    ECHO Run this script as Administrator
    PAUSE
    EXIT /B 1
)

ECHO.
ECHO =====================================================
ECHO Data archiving automation setup complete!
ECHO =====================================================
ECHO.
ECHO Monthly archiving: 1st of month at 3:00 AM
ECHO Archives data older than 2 years
ECHO.
ECHO To view scheduled task: schtasks /query /tn GEOTEX_Monthly_Archive
ECHO To delete scheduled task: schtasks /delete /tn GEOTEX_Monthly_Archive
ECHO.

PAUSE

