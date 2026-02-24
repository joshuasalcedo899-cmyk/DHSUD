@echo off
title DHSUD - Install Client CA Trust
setlocal

cd /d "%~dp0"

powershell -NoProfile -ExecutionPolicy Bypass -File ".\scripts\install-client-ca.ps1" %*

if errorlevel 1 (
  echo.
  echo Failed. Re-run as Administrator and provide a valid rootCA.pem path if needed.
  pause
)
