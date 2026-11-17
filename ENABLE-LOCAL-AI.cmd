@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\Enable-LocalAI.ps1"
if errorlevel 1 echo Setup did not complete. Read the message above.
pause
