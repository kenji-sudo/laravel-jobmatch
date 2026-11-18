@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\Apply-AI-Update.ps1" -SetupAssistant
pause
