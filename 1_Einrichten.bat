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
set ACCESS=
for %%f in (Import\*.accdb Import\*.mdb) do if not defined ACCESS set "ACCESS=%%f"
set EXCEL=
for %%f in (Import\*.xlsx) do if not defined EXCEL set "EXCEL=%%f"
if not defined ACCESS (
  echo Keine Access-Datei im Ordner Import gefunden.
  pause
  exit /b 1
)
set NEU=
if not exist daten\wtg.db goto import
set /p ANTWORT=Es gibt schon Daten. Alles neu importieren? Geplante Termine gehen verloren. [j/n] 
if /i not "%ANTWORT%"=="j" exit /b 0
set NEU=--neu
:import
if defined EXCEL goto mitexcel
"%PHP%" werkzeuge\import.php --access="%ACCESS%" %NEU% --testzugaenge
goto weiter
:mitexcel
"%PHP%" werkzeuge\import.php --access="%ACCESS%" --excel="%EXCEL%" %NEU% --testzugaenge
:weiter
if errorlevel 1 (
  pause
  exit /b 1
)
echo.
echo Adressen werden auf der Karte gesucht (OpenStreetMap, ca. 10 Minuten)...
"%PHP%" werkzeuge\geokodieren.php
echo.
echo Fertig. Jetzt 2_Starten doppelklicken.
pause
