@echo off
REM ============================================
REM Network Access Setup Script for GEOCIL System
REM ============================================
echo.
echo ============================================
echo   GEOCIL Automation System - Network Setup
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

echo [1/4] Finding Server IP Address...
echo.
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    set IP=%%a
    set IP=!IP:~1!
    echo Found IP Address: !IP!
    goto :found_ip
)
:found_ip

echo.
echo [2/4] Configuring Windows Firewall...
echo.

REM Allow Apache HTTP Server (Port 80)
netsh advfirewall firewall delete rule name="Apache HTTP Server" >nul 2>&1
netsh advfirewall firewall add rule name="Apache HTTP Server" dir=in action=allow protocol=TCP localport=80
if %errorLevel% equ 0 (
    echo [OK] Firewall rule added for Apache (Port 80)
) else (
    echo [WARNING] Could not add firewall rule. Please add manually.
)

REM Allow MySQL Database (Port 3306)
netsh advfirewall firewall delete rule name="MySQL Database" >nul 2>&1
netsh advfirewall firewall add rule name="MySQL Database" dir=in action=allow protocol=TCP localport=3306
if %errorLevel% equ 0 (
    echo [OK] Firewall rule added for MySQL (Port 3306)
) else (
    echo [WARNING] Could not add firewall rule. Please add manually.
)

echo.
echo [3/4] Checking Apache Configuration...
echo.

set XAMPP_PATH=C:\xampp
if not exist "%XAMPP_PATH%\apache\conf\httpd.conf" (
    echo [ERROR] XAMPP not found at %XAMPP_PATH%
    echo Please edit the XAMPP_PATH variable in this script.
    pause
    exit /b 1
)

echo [INFO] Apache config found at: %XAMPP_PATH%\apache\conf\httpd.conf
echo [INFO] You need to manually edit httpd.conf:
echo.
echo   1. Find: Listen 80
echo      Change to: Listen 0.0.0.0:80
echo.
echo   2. Find: Require local
echo      Change to: Require all granted
echo.
echo   3. Save and restart Apache
echo.

echo [4/4] Summary...
echo.
echo ============================================
echo   SETUP COMPLETE
echo ============================================
echo.
echo Server IP Address: %IP%
echo.
echo Access URL for users:
echo   http://%IP%/geotexx
echo.
echo Next Steps:
echo   1. Edit Apache httpd.conf (see instructions above)
echo   2. Restart Apache in XAMPP Control Panel
echo   3. Test access from server: http://%IP%/geotexx
echo   4. Share the access URL with users
echo.
echo ============================================
echo.
pause

