@echo off
setlocal enabledelayedexpansion
REM ===================================================================
REM  AquaFlow - one-click launcher (Docker)
REM
REM  Brings up the whole four-tier stack: MySQL, Laravel, the Python
REM  ARIMA service, nginx and the scheduler. On the very first run it
REM  also builds the images and loads the schema + 90 days of demo data.
REM
REM  Re-running is safe: anything already up is left alone.
REM
REM  Secrets live in the gitignored .env - never in this file.
REM ===================================================================

title AquaFlow
cd /d "%~dp0"

echo.
echo   ============================================
echo      AquaFlow  -  starting up  (Docker)
echo   ============================================
echo.

REM --- locate the docker CLI (Docker Desktop does not always add it) ---
where docker >nul 2>nul
if errorlevel 1 (
    if exist "%LOCALAPPDATA%\Programs\DockerDesktop\resources\bin\docker.exe" (
        set "PATH=%LOCALAPPDATA%\Programs\DockerDesktop\resources\bin;%LOCALAPPDATA%\Programs\DockerDesktop\resources\cli-plugins;%PATH%"
    ) else if exist "%ProgramFiles%\Docker\Docker\resources\bin\docker.exe" (
        set "PATH=%ProgramFiles%\Docker\Docker\resources\bin;%PATH%"
    ) else (
        echo   [!!] Docker was not found.
        echo        Install Docker Desktop, then run this again.
        pause
        exit /b 1
    )
)

REM --- make sure the engine is actually up -----------------------------
docker info >nul 2>nul
if errorlevel 1 (
    echo   [..] Docker Desktop is not running - starting it...
    if exist "%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe" (
        start "" "%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe"
    ) else if exist "%ProgramFiles%\Docker\Docker\Docker Desktop.exe" (
        start "" "%ProgramFiles%\Docker\Docker\Docker Desktop.exe"
    ) else (
        echo   [!!] Could not find Docker Desktop. Start it, then run this again.
        pause
        exit /b 1
    )
    call :waitdocker 120
    docker info >nul 2>nul
    if errorlevel 1 (
        echo   [!!] The Docker engine did not come up in time.
        echo        Start Docker Desktop, wait for the whale icon, then run this again.
        pause
        exit /b 1
    )
)
echo   [ok]  Docker engine is running

REM --- .env (gitignored; holds the DB password and the app key) --------
if not exist ".env" (
    copy /y ".env.example" ".env" >nul
    echo   [..] Created .env from .env.example
)

REM --- build the images if they are not there yet ----------------------
set "IMGFOUND="
for /f "usebackq delims=" %%I in (`docker compose images -q app 2^>nul`) do set "IMGFOUND=%%I"
if not defined IMGFOUND (
    echo   [..] Building images - first run only, this takes a few minutes
    docker compose build
    if errorlevel 1 (
        echo   [!!] Image build failed. See the output above.
        pause
        exit /b 1
    )
)
echo   [ok]  Images ready

REM --- APP_KEY ----------------------------------------------------------
findstr /R /C:"^APP_KEY=base64:" ".env" >nul 2>nul
if errorlevel 1 (
    echo   [..] Generating an application key...
    set "NEWKEY="
    REM The placeholder only satisfies compose's required-variable check;
    REM --show prints a real key without touching any .env inside the image.
    set "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
    for /f "usebackq delims=" %%K in (`docker compose run --rm -T app php artisan key:generate --show 2^>nul`) do set "NEWKEY=%%K"
    if defined NEWKEY (
        findstr /V /B /C:"APP_KEY=" ".env" > ".env.tmp"
        >>".env.tmp" echo APP_KEY=!NEWKEY!
        move /y ".env.tmp" ".env" >nul
        echo   [ok]  APP_KEY written to .env
    ) else (
        echo   [!!] Could not generate an APP_KEY.
        pause
        exit /b 1
    )
)

REM --- start the stack --------------------------------------------------
echo   [..] Starting the stack
docker compose up -d
if errorlevel 1 (
    echo   [!!] docker compose up failed. See the output above.
    pause
    exit /b 1
)

REM --- wait for the app container to report healthy ---------------------
echo   [..] Waiting for the app to come up
set /a "_n=0"
:health_loop
docker compose ps app 2>nul | findstr /C:"healthy" >nul 2>nul
if not errorlevel 1 goto :health_ok
set /a "_n+=1"
if !_n! geq 40 goto :health_slow
ping -n 3 127.0.0.1 >nul
goto :health_loop

:health_slow
echo   [!!] The app is taking longer than usual. It may still be starting.
goto :after_health

:health_ok
echo   [ok]  App is healthy

:after_health
REM --- first run: schema + demo data ------------------------------------
docker compose exec -T app php artisan migrate:status >nul 2>nul
if errorlevel 1 (
    echo.
    echo   [..] First run detected - creating the schema and loading
    echo        90 days of demo data ^(about a minute^)...
    docker compose --profile setup run --rm migrate
    if errorlevel 1 (
        echo   [!!] Setup failed. See the output above.
        pause
        exit /b 1
    )
    echo   [ok]  Database ready
)

echo.
echo   ============================================
echo      AquaFlow is running
echo   ============================================
echo.
echo   Cashier POS     http://localhost:8080               cashier / 1234
echo   Owner portal    http://localhost:8080/owner/login   admin / 1234  PIN 2468
echo.
echo   Stop the app      docker compose down
echo   Wipe all data     docker compose down -v
echo   Run the tests     docker compose --profile test up -d test
echo                     docker compose exec test php artisan test
echo.
start "" "http://localhost:8080"
echo   Opened in your browser.
echo.
exit /b 0

REM --- subroutines -------------------------------------------------------
:waitdocker
set /a "_d=0"
:waitdocker_loop
docker info >nul 2>nul
if not errorlevel 1 exit /b 0
set /a "_d+=1"
if !_d! geq %1 exit /b 1
ping -n 3 127.0.0.1 >nul
goto :waitdocker_loop
