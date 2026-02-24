@echo off
title DHSUD - New Device Setup
setlocal

cd /d "%~dp0"

powershell -NoProfile -ExecutionPolicy Bypass -File ".\scripts\setup-new-device.ps1" %*

if errorlevel 1 (
  echo.
  echo Setup failed. Re-run Command Prompt or PowerShell as Administrator.
  pause
)
