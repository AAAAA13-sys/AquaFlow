@echo off
REM ===================================================================
REM  AquaFlow - LEGACY launcher (kept for reference only)
REM
REM  NOT SUPPORTED. The Docker launcher START-AQUAFLOW.bat is the
REM  supported path.
REM
REM  This script predates the .env format: it reads DB_HOST, DB_PORT,
REM  DB_USERNAME and DB_PASSWORD out of .env, but the shipped
REM  .env.example does not define them (it is Docker-oriented and uses
REM  MYSQL_ROOT_PASSWORD / AQUAFLOW_DB_PORT). On a fresh clone this
REM  script therefore falls back to its defaults and will NOT pick up
REM  your database settings.
REM
REM  Use START-AQUAFLOW.bat instead.
REM
REM  Starts MySQL, the Python ARIMA service and the Laravel web app,
REM  then opens the cashier login. Re-running is safe: anything already
REM  running is skipped.
REM
REM  DB credentials are read from the gitignored .env - no secrets here.
REM ===================================================================

title AquaFlow
cd /d "%~dp0"

echo.
echo   ============================================
echo      AquaFlow  -  starting up
echo   ============================================
echo.

REM --- locate PHP (XAMPP) ----------------------------------------
where php >nul 2>nul
if errorlevel 1 (
    if exist "C:\xampp\php\php.exe" (
        set "PATH=C:\xampp\php;%PATH%"
    ) else (
        echo   [!!] PHP 8.2+ not found. Install XAMPP or add php to PATH.
        pause
        exit /b 1
    )
)

REM --- preflight ---------------------------------------------------
if not exist "vendor\autoload.php" (
    echo   [..] Installing PHP dependencies ^(first run^)...
    composer install --no-interaction --no-progress
    if errorlevel 1 ( echo   [!!] composer install failed & pause & exit /b 1 )
)

if not exist ".env" (
    echo   [!!] .env missing - copy .env.example to .env and set DB credentials.
    pause
    exit /b 1
)

if not exist ".venv\Scripts\python.exe" (
    echo   [..] Creating Python environment ^(first run^)...
    python -m venv .venv
    .venv\Scripts\python.exe -m pip install -r analytics\requirements.txt --quiet
)

REM --- read DB settings from .env ---------------------------------
for /f "usebackq tokens=1,* delims==" %%A in (`findstr /B /C:"DB_HOST=" /C:"DB_PORT=" /C:"DB_DATABASE=" /C:"DB_USERNAME=" /C:"DB_PASSWORD=" .env`) do (
    set "AQ_%%A=%%B"
)
if not defined AQ_DB_HOST     set "AQ_DB_HOST=127.0.0.1"
if not defined AQ_DB_PORT     set "AQ_DB_PORT=3306"
if not defined AQ_DB_DATABASE set "AQ_DB_DATABASE=aquaflow_db"
if not defined AQ_DB_USERNAME set "AQ_DB_USERNAME=root"
if not defined AQ_DB_PASSWORD set "AQ_DB_PASSWORD="

set "AQUAFLOW_DB_HOST=%AQ_DB_HOST%"
set "AQUAFLOW_DB_PORT=%AQ_DB_PORT%"
set "AQUAFLOW_DB_NAME=%AQ_DB_DATABASE%"
set "AQUAFLOW_DB_USER=%AQ_DB_USERNAME%"
set "AQUAFLOW_DB_PASSWORD=%AQ_DB_PASSWORD%"

REM --- locate XAMPP (supports C: or D:) ---------------------------
if not defined XAMPP_DIR (
    if exist "C:\xampp\mysql\bin\mysqld.exe" set "XAMPP_DIR=C:\xampp"
    if exist "D:\Xampp\mysql\bin\mysqld.exe" set "XAMPP_DIR=D:\Xampp"
)
REM --- helper: is a port listening? -------------------------------
call :port 3306 && (
    echo   [ok]  MySQL already running
    goto :mysql_done
)
echo   [..] Starting MySQL
if defined XAMPP_DIR (
    start "" /min "%XAMPP_DIR%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP_DIR%\mysql\bin\my.ini"
) else (
    echo   [!!] mysqld.exe not found under C:\xampp or D:\Xampp - start MySQL from the XAMPP control panel
)
call :wait 3306 40
call :port 3306 && echo   [ok]  MySQL up || echo   [!!] MySQL failed - start it from the XAMPP control panel
:mysql_done

REM --- tier 3: Python ARIMA service -------------------------------
call :port 5000 && (
    echo   [ok]  analytics already running
    goto :analytics_done
)
echo   [..] Starting analytics service
start "" /min ".venv\Scripts\python.exe" analytics\service.py
call :wait 5000 40
call :port 5000 && echo   [ok]  analytics up || echo   [!!] analytics failed - forecasts unavailable
:analytics_done

REM --- tier 2: Laravel web app ------------------------------------
call :port 8000 && (
    echo   [ok]  web app already running
    goto :web_done
)
echo   [..] Starting web app
start "" /min php artisan serve --port=8000
call :wait 8000 30
call :port 8000 && echo   [ok]  web app up || echo   [!!] web app failed to start
:web_done

echo.
echo   Cashier POS  http://127.0.0.1:8000              cashier / 1234
echo   Owner portal http://127.0.0.1:8000/owner/login  admin / 1234  PIN 2468
echo.
start "" "http://127.0.0.1:8000"
echo   Opened in your browser.
echo   To stop: run  php artisan serve  ^ Ctrl+C  in that window,
echo   or close the analytics window. MySQL is left alone.
echo.
exit /b 0

REM --- subroutines -------------------------------------------------
:port
netstat -ano | findstr ":%~1 " | findstr LISTENING >nul 2>nul
exit /b %errorlevel%

:wait
set /a "_n=0"
:wait_loop
call :port %1 || (
    set /a "_n+=1"
    if %_n% lss %2 ( ping -n 2 127.0.0.1 >nul & goto wait_loop )
)
goto :eof