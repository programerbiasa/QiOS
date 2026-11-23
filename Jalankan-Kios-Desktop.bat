@echo off
title QiOS Cendekia
cd /d "%~dp0\kiosk_flutter\build\windows\x64\runner\Release"
if exist "kiosk_cendekia.exe" (
    start "" "kiosk_cendekia.exe"
) else (
    start "" "msedge.exe" --app="http://172.16.5.11/" --window-size=1280,800
)
exit
