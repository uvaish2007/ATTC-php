@echo off
setlocal
REM ===========================================================================
REM  ATTS IQAC Portal - start the local web server
REM
REM  Double-click this file. Your browser opens automatically at:
REM      http://127.0.0.1:8000/php-app/login.php
REM
REM  Keep this window OPEN - it is the server. Press Ctrl+C or close it to stop.
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
echo   Starting the server. Your browser opens automatically once it is
echo   ready (the FIRST start can take ~10s while Windows scans PHP).
echo.
echo       http://127.0.0.1:8000/php-app/login.php
echo.
echo   Sign in with any of these (pick the matching role in the form):
echo       Admin        mohameduvaish132@gmail.com   uvaish123
echo       Director     director@atts.edu            director123
echo       HoD          hod@atts.edu                 hod12345
echo       Coordinator  coordinator@atts.edu         coord1234
echo       Faculty      faculty@atts.edu             faculty123
echo.
echo   Keep THIS window open - it is the server. Close it (or Ctrl+C) to stop.
echo   ----------------------------------------------------------------
echo.

REM A background helper POLLS the port and opens the browser only once the
REM server is actually accepting connections - so the page never loads before
REM the server is ready (which caused "ERR_CONNECTION_REFUSED" with a fixed wait).
start "" /min powershell -NoProfile -WindowStyle Hidden -Command "for($i=0;$i -lt 60;$i++){try{$c=New-Object Net.Sockets.TcpClient;$c.Connect('127.0.0.1',8000);$c.Close();Start-Process 'http://127.0.0.1:8000/php-app/login.php';break}catch{Start-Sleep -Milliseconds 500}}"

REM Run the server in THIS window. It stays running and prints each request
REM until you press Ctrl+C or close the window.
".php-runtime\php.exe" -c ".php-runtime\php.ini" -S 127.0.0.1:8000 -t "%~dp0."

echo.
echo   ----------------------------------------------------------------
echo   The server has stopped.
echo   If that was unexpected, read the message just above - the most
echo   common cause is "Failed to listen ... Address already in use",
echo   which means a server is already running on port 8000.
echo   ----------------------------------------------------------------
echo.
pause
