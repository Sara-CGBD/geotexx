@echo off
REM ============================================
REM Check XAMPP Services Status
REM ============================================
echo.
echo ============================================
echo   XAMPP Services Status Check
echo ============================================
echo.

echo [1/3] Checking Apache Service...
sc query Apache2.4 >nul 2>&1
if %errorLevel% equ 0 (
    sc query Apache2.4 | findstr "STATE"
    netstat -an | findstr :80 >nul
    if %errorLevel% equ 0 (
        echo [OK] Apache is running and listening on port 80
    ) else (
        echo [WARNING] Apache service exists but port 80 is not listening
    )
) else (
    echo [ERROR] Apache service is NOT installed
    echo Run install_xampp_services.bat to install it
)

echo.
echo [2/3] Checking MySQL Service...
sc query mysql80 >nul 2>&1
if %errorLevel% equ 0 (
    sc query mysql80 | findstr "STATE"
    netstat -an | findstr :3306 >nul
    if %errorLevel% equ 0 (
        echo [OK] MySQL is running and listening on port 3306
    ) else (
        echo [WARNING] MySQL service exists but port 3306 is not listening
    )
) else (
    sc query mysql >nul 2>&1
    if %errorLevel% equ 0 (
        sc query mysql | findstr "STATE"
        echo [OK] MySQL service found (different name)
    ) else (
        echo [ERROR] MySQL service is NOT installed
        echo Run install_xampp_services.bat to install it
    )
)

echo.
echo [3/3] Checking Service Startup Type...
for /f "tokens=3" %%a in ('sc qc Apache2.4 ^| findstr "START_TYPE"') do (
    echo Apache Startup Type: %%a
)
for /f "tokens=3" %%a in ('sc qc mysql80 ^| findstr "START_TYPE" 2^>nul') do (
    echo MySQL Startup Type: %%a
)

echo.
echo ============================================
echo   SUMMARY
echo ============================================
echo.
echo To view all services: services.msc
echo To start services: net start Apache2.4 ^&^& net start mysql80
echo To stop services: net stop Apache2.4 ^&^& net stop mysql80
echo.
pause

