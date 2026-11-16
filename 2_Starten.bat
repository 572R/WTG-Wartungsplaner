@echo off
chcp 65001 >nul
cd /d "%~dp0"
rem php.exe suchen: zuerst C:\php, sonst aus dem PATH
set "PHP=C:\php\php.exe"
if not exist "%PHP%" for /f "delims=" %%p in ('where php 2^>nul') do if not defined PHPGEF set "PHPGEF=%%p"
if not exist "%PHP%" if defined PHPGEF set "PHP=%PHPGEF%"
if not exist "%PHP%" (
  echo PHP nicht gefunden. Bitte PHP nach C:\php entpacken - siehe README.
  pause
  exit /b 1
)
if not exist config.lokal.php copy config.lokal.beispiel.php config.lokal.php >nul
if not exist daten\wtg.db (
  echo Noch keine Daten. Bitte zuerst 1_Einrichten doppelklicken.
  pause
  exit /b 1
)
echo.
echo  WTG Wartungsplaner laeuft: http://localhost:8000  (Anmelden z. B. mit buero / test)
echo  Fenster offen lassen, beenden mit Strg+C.
start "" http://localhost:8000
"%PHP%" -d upload_max_filesize=32M -d post_max_size=40M -S localhost:8000 router.php
pause
