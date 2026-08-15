@echo off
REM ml/run_server.bat
REM Windows batch file to start the ML API Server

set "ML_DIR=%~dp0"
set "PYTHON_EXE=%ML_DIR%..\.venv\Scripts\python.exe"

echo.
echo ========================================
echo ML Recommendation API Server Startup
echo ========================================
echo.

REM Use the project virtual environment so startup does not depend on PATH.
"%PYTHON_EXE%" --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Project Python environment was not found or is broken:
    echo %PYTHON_EXE%
    pause
    exit /b 1
)

REM Make startup independent of the caller's current directory.
cd /d "%ML_DIR%"

if not exist "api_server.py" (
    echo ERROR: api_server.py not found in current directory
    echo Please run this script from the ml directory
    pause
    exit /b 1
)

echo.
echo Installing dependencies from requirements.txt...
"%PYTHON_EXE%" -m pip install -r requirements.txt
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

"%PYTHON_EXE%" api_server.py

pause
