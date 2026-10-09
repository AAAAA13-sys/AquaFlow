@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0AquaFlow.ps1" -Action remote
if errorlevel 1 echo AquaFlow could not finish. Read the error above.
pause
