@echo off
chcp 65001 >nul
cd /d "%~dp0"
REM One-click start on Windows: creates .venv on first run, then opens the dashboard.
REM   run.bat          -> dashboard on http://127.0.0.1:8000
REM   run.bat doctor   -> connectivity / schema check
if not exist .venv\Scripts\python.exe (
    echo Creating virtual environment...
    python -m venv .venv || goto :nopython
    .venv\Scripts\python -m pip install --upgrade pip
    .venv\Scripts\python -m pip install -e . || goto :pipfail
)
if /I "%~1"=="doctor" (
    .venv\Scripts\tse-analyzer doctor
    pause
    exit /b
)
start "" http://127.0.0.1:8000
.venv\Scripts\tse-analyzer serve
exit /b

:nopython
echo Python 3.10+ is required: https://www.python.org/downloads/  (tick "Add python.exe to PATH")
pause
exit /b 1

:pipfail
rmdir /s /q .venv
echo Package install failed. Turn on a VPN for installation only, then run again.
pause
exit /b 1
