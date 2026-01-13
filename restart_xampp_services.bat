@echo off
REM ============================================
REM Restart XAMPP Services
REM ============================================
echo.
echo ============================================
echo   Restarting XAMPP Services
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

echo [1/4] Stopping Apache...
net stop Apache2.4 >nul 2>&1
if %errorLevel% equ 0 (
    echo [OK] Apache stopped
) else (
    echo [INFO] Apache was not running or service name is different
)

echo.
echo [2/4] Stopping MySQL...
net stop mysql80 >nul 2>&1
if %errorLevel% equ 0 (
    echo [OK] MySQL stopped
) else (
    net stop mysql >nul 2>&1
    if %errorLevel% equ 0 (
        echo [OK] MySQL stopped
    ) else (
        echo [INFO] MySQL was not running or service name is different
    )
)

echo.
echo [3/4] Waiting 3 seconds...
timeout /t 3 /nobreak >nul

echo.
echo [4/4] Starting Services...
net start Apache2.4
if %errorLevel% equ 0 (
    echo [OK] Apache started successfully
) else (
    echo [ERROR] Failed to start Apache
)

net start mysql80
if %errorLevel% equ 0 (
    echo [OK] MySQL started successfully
) else (
    net start mysql
    if %errorLevel% equ 0 (
        echo [OK] MySQL started successfully
    ) else (
        echo [ERROR] Failed to start MySQL
    )
)

echo.
echo ============================================
echo   RESTART COMPLETE
echo ============================================
echo.
echo Test your application: http://localhost/geotexx
echo.
pause

