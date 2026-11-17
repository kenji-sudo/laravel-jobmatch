@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\Apply-AI-Update.ps1"
if errorlevel 1 echo Update stopped. Read the message above before trying again.
pause
