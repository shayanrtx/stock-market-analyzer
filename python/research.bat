@echo off
chcp 65001 >nul
cd /d "%~dp0"
REM Research run on Windows: price data of the most traded shares, honest accuracy tests, report.
REM   research.bat                  -> full run (first time 20-40 minutes; later runs only fetch what is new)
REM   research.bat --top 100        -> fewer symbols
REM   research.bat --skip-update    -> re-run the analysis on the data already downloaded
REM Must run on an Iranian internet connection WITHOUT a VPN (TSETMC answers Iranian IPs only).
if not exist .venv\Scripts\python.exe (
    echo Creating virtual environment...
    python -m venv .venv || goto :nopython
    .venv\Scripts\python -m pip install --upgrade pip
    .venv\Scripts\python -m pip install -e . || goto :pipfail
)
.venv\Scripts\tse-analyzer research run %*
echo.
echo Results: research-output folder (send the newest folder, or at least its research.log and report.txt)
start "" research-output
pause
exit /b

:nopython
echo Python 3.10+ is required: https://www.python.org/downloads/  (tick "Add python.exe to PATH")
pause
exit /b 1

:pipfail
rmdir /s /q .venv
echo Package install failed. Turn on a VPN for installation only, then turn it off and run again.
pause
exit /b 1
