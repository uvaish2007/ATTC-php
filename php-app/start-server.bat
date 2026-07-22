@echo off
REM ===========================================================================
REM  ATTS IQAC Portal - start the local web server
REM
REM  Double-click this file, then open:
REM      http://localhost:8000/php-app/login.php
REM
REM  Press Ctrl+C in this window (or just close it) to stop the server.
REM  PHP lives in .php-runtime\ - it is portable, nothing was installed on
REM  Windows. Deleting that folder removes PHP completely.
REM ===========================================================================

cd /d "%~dp0"

if not exist ".php-runtime\php.exe" (
    echo.
    echo   ERROR: .php-runtime\php.exe is missing.
    echo   Download the PHP 8.4 NTS x64 zip from https://windows.php.net/download
    echo   and unzip it into a folder named .php-runtime next to this file.
    echo.
    pause
    exit /b 1
)

echo.
echo   ATTS IQAC Portal
echo   ----------------------------------------------------------------
echo   Open this address in your browser:
echo.
echo       http://localhost:8000/php-app/login.php
echo.
echo   Sign in with any of these:
echo       Admin        mohameduvaish132@gmail.com   uvaish123
echo       Director     director@atts.edu            director123
echo       HoD          hod@atts.edu                 hod12345
echo       Coordinator  coordinator@atts.edu         coord1234
echo       Faculty      faculty@atts.edu             faculty123
echo.
echo   Keep this window open. Close it to stop the server.
echo   ----------------------------------------------------------------
echo.

start "" "http://localhost:8000/php-app/login.php"

".php-runtime\php.exe" -c ".php-runtime\php.ini" -S localhost:8000 -t "%~dp0."
