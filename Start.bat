@echo off
title Parcel Tracking System

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
start http://%ip%/parcel

:: open the HTA close window
start close.hta
