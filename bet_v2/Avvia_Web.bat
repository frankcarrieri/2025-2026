@echo off
title Serie B Betting - Web Server
cd /d "%~dp0"

echo ================================================
echo   SERIE B DRAW BETTING - WEB SERVER
echo ================================================
echo.
echo   Server: http://localhost:8080
echo   Chiudi questa finestra per fermare il server.
echo.

start "" http://localhost:8080
php -S localhost:8080 server.php
