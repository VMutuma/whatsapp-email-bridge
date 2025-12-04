@echo off
cd /d "C:\xampp\htdocs\project\whatsapp-email-bridge"
echo Starting Drip Processor Cron (every 60 seconds)
echo Press Ctrl+C to stop
echo.

:loop
php process_drip.php
echo Ran at %time%
timeout /t 60 /nobreak > nul
goto loop