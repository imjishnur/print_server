@echo off
REM BIZPOZ Print Server - Auto-Update Runner (Windows Task Scheduler helper)
REM
REM Point Task Scheduler at this .bat file if you don't keep the dashboard
REM tab open. Set the schedule's repeat interval to match config.php's
REM auto_update.check_interval (this script itself also respects that
REM interval, so triggering it more often than that is harmless - it will
REM just report "not_due" and exit).
REM
REM Usage:
REM   check_update.bat            (respects the configured interval)
REM   check_update.bat --force    (checks immediately)

setlocal
cd /d "%~dp0"
php check_update.php %1
endlocal
