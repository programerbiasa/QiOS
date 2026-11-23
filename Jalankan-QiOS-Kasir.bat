@echo off
title QiOS Cendekia
cd /d "%~dp0\kiosk_flutter\build\windows\x64\runner\Release"
start "" "kiosk_cendekia.exe"
exit
