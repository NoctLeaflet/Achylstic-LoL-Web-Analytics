@echo off
rem Starts everything Achylstic needs on this PC, each in its own window:
rem   1. the website            (http://127.0.0.1:8000)
rem   2. the scheduler          (rank, match and live syncs, Discord messages, backups)
rem Keep both windows open. Double-click this file, or put a shortcut to it in the Startup folder.

set PHP=C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe
cd /d "%~dp0"

start "Achylstic - website" "%PHP%" artisan serve --host=127.0.0.1 --port=8000
start "Achylstic - scheduler" "%PHP%" artisan schedule:work

echo Website and scheduler started in their own windows. You can close this one.
timeout /t 5 >nul
