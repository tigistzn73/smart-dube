@echo off
echo ========================================================
echo   Smart Dube - Auto Git Sync & Push to GitHub
echo ========================================================
echo.

git add .
set /p commit_msg="Enter commit message (or press ENTER for default timestamp): "
if "%commit_msg%"=="" (
    set commit_msg=Update project interface and features [%date% %time%]
)

git commit -m "%commit_msg%"
echo.
echo Pushing to GitHub (origin main)...
git push origin main

echo.
echo ========================================================
echo   Sync Complete! Your project is up to date on GitHub.
echo ========================================================
pause
