@echo off
title Mail Tracking System - Start
setlocal

:: Remember this script directory (DHSUD folder)
set "APP_DIR=%~dp0"

:: Go to XAMPP folder
cd /d C:\xampp

echo Starting Apache & MySQL...
start /min xampp_start.exe

:: wait 5 seconds for Apache to boot
timeout /t 5 >nul

:: get LAN IP address
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| find "IPv4 Address"') do (
 set ip=%%a
 goto :done
)
:done
set ip=%ip: =%

:: save IP for later use
echo %ip% > ip.txt

:: open browser automatically
start http://localhost/DHSUD

