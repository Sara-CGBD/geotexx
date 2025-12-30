@echo off
REM =====================================================
REM GEOTEX AUTOMATED DATABASE BACKUP SCRIPT
REM Runs daily backup with rotation
REM =====================================================

SETLOCAL EnableDelayedExpansion

REM Configuration
SET MYSQL_BIN=C:\xampp\mysql\bin\mysqldump.exe
SET MYSQL_USER=root
SET MYSQL_PASS=
SET DB_NAME=geobagg
SET BACKUP_BASE_DIR=C:\geotex_backups

REM Create date-based folder structure
SET YEAR=%date:~-4%
SET MONTH=%date:~-10,2%
SET DAY=%date:~-7,2%
SET HOUR=%time:~0,2%
SET MINUTE=%time:~3,2%

REM Remove leading space from hour if present
IF "%HOUR:~0,1%"==" " SET HOUR=0%HOUR:~1,1%

SET BACKUP_DATE=%YEAR%%MONTH%%DAY%
SET BACKUP_TIME=%HOUR%%MINUTE%
SET BACKUP_DIR=%BACKUP_BASE_DIR%\%YEAR%\%MONTH%

REM Create backup directory if not exists
IF NOT EXIST "%BACKUP_DIR%" (
    mkdir "%BACKUP_DIR%"
)

REM Backup filename
SET BACKUP_FILE=%BACKUP_DIR%\geobagg_%BACKUP_DATE%_%BACKUP_TIME%.sql

REM Perform backup
ECHO =====================================================
ECHO GEOTEX Database Backup Started
ECHO Date: %BACKUP_DATE%
ECHO Time: %BACKUP_TIME%
ECHO =====================================================

"%MYSQL_BIN%" -u %MYSQL_USER% %DB_NAME% > "%BACKUP_FILE%"

IF %ERRORLEVEL% EQU 0 (
    ECHO [SUCCESS] Backup created: %BACKUP_FILE%
    
    REM Compress the backup
    ECHO Compressing backup...
    powershell -Command "Compress-Archive -Path '%BACKUP_FILE%' -DestinationPath '%BACKUP_FILE%.zip' -Force"
    
    IF %ERRORLEVEL% EQU 0 (
        ECHO [SUCCESS] Backup compressed
        DEL "%BACKUP_FILE%"
        ECHO [INFO] Original SQL file deleted, keeping compressed version
    ) ELSE (
        ECHO [WARNING] Compression failed, keeping original SQL file
    )
    
    REM Get file size
    FOR %%A IN ("%BACKUP_FILE%.zip") DO SET FILESIZE=%%~zA
    ECHO Backup size: !FILESIZE! bytes
    
) ELSE (
    ECHO [ERROR] Backup failed!
    ECHO Check MySQL connection and credentials
)

REM =====================================================
REM Delete backups older than 30 days
REM =====================================================

ECHO.
ECHO Cleaning up old backups (older than 30 days)...

FORFILES /P "%BACKUP_BASE_DIR%" /S /M *.zip /D -30 /C "cmd /c del @path" 2>NUL

IF %ERRORLEVEL% EQU 0 (
    ECHO [SUCCESS] Old backups cleaned up
) ELSE (
    ECHO [INFO] No old backups to delete
)

REM =====================================================
REM Log backup completion
REM =====================================================

SET LOG_FILE=%BACKUP_BASE_DIR%\backup_log.txt
ECHO %date% %time% - Backup completed: %BACKUP_FILE%.zip >> "%LOG_FILE%"

ECHO.
ECHO =====================================================
ECHO Backup process completed!
ECHO =====================================================
ECHO.

ENDLOCAL

REM Exit with success code
EXIT /B 0


