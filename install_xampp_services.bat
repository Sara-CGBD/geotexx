@echo off
REM ============================================
REM Install XAMPP Services for Auto-Start
REM ============================================
echo.
echo ============================================
echo   XAMPP Services Auto-Start Installation
echo ============================================
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [ERROR] This script must be run as Administrator!
    echo Right-click and select "Run as administrator"
    pause
    exit /b 1
)

set XAMPP_PATH=C:\xampp
if not exist "%XAMPP_PATH%\xampp-control.exe" (
    echo [ERROR] XAMPP not found at %XAMPP_PATH%
    echo Please edit the XAMPP_PATH variable in this script.
    pause
    exit /b 1
)

echo [INFO] XAMPP found at: %XAMPP_PATH%
echo.
echo This script will:
echo   1. Install Apache as Windows Service
echo   2. Install MySQL as Windows Service
echo   3. Set services to start automatically
echo.
pause

echo.
echo [1/3] Installing Apache Service...
cd /d "%XAMPP_PATH%\apache\bin"
httpd.exe -k install
if %errorLevel% equ 0 (
    echo [OK] Apache service installed successfully
) else (
    echo [WARNING] Apache service installation had issues
    echo You may need to install it manually from XAMPP Control Panel
)

echo.
echo [2/3] Installing MySQL Service...
cd /d "%XAMPP_PATH%\mysql\bin"
mysqld.exe --install
if %errorLevel% equ 0 (
    echo [OK] MySQL service installed successfully
) else (
    echo [WARNING] MySQL service installation had issues
    echo You may need to install it manually from XAMPP Control Panel
)

echo.
echo [3/3] Configuring Services to Start Automatically...
sc config Apache2.4 start= auto
if %errorLevel% equ 0 (
    echo [OK] Apache set to start automatically
) else (
    echo [WARNING] Could not set Apache to auto-start
    echo Service name might be different. Check with: sc query Apache2.4
)

REM Try common MySQL service names
sc config mysql start= auto >nul 2>&1
sc config mysql80 start= auto >nul 2>&1
sc config MySQL start= auto >nul 2>&1
echo [OK] MySQL set to start automatically (if service exists)

echo.
echo ============================================
echo   INSTALLATION COMPLETE
echo ============================================
echo.
echo Next Steps:
echo   1. Verify services in Services.msc
echo   2. Restart the computer to test auto-start
echo   3. After reboot, test: http://localhost/geotexx
echo.
echo To check services:
echo   services.msc
echo.
echo To manually start services:
echo   net start Apache2.4
echo   net start mysql80
echo.
pause

