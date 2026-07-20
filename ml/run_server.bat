@echo off
REM ml/run_server.bat
REM Windows batch file to start the ML API Server

echo.
echo ========================================
echo ML Recommendation API Server Startup
echo ========================================
echo.

REM Check if Python is installed
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python is not installed or not in PATH
    echo Please install Python 3.8+ and add it to your system PATH
    pause
    exit /b 1
)

REM Check if we're in the right directory
if not exist "api_server.py" (
    echo ERROR: api_server.py not found in current directory
    echo Please run this script from the ml directory
    pause
    exit /b 1
)

echo.
echo Installing dependencies from requirements.txt...
pip install -r requirements.txt
if errorlevel 1 (
    echo ERROR: Failed to install dependencies
    pause
    exit /b 1
)

echo.
echo ========================================
echo Starting ML API Server on http://127.0.0.1:5000
echo ========================================
echo.
echo Press Ctrl+C to stop the server
echo.

python api_server.py

pause
