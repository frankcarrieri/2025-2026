@echo off
:: Controlla se PHP è installato
php -v >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo PHP non è installato o non è nel PATH. Assicurati di avere PHP configurato correttamente.
    pause
    exit /b
)

:: Controlla se il file extractor.php esiste
if not exist "estrazione_dati.php" (
    echo Il file estrazione_dati.php non è stato trovato.
    pause
    exit /b
)

:: Esegui lo script PHP
echo Esecuzione di estrazione_dati.php...
php estrazione_dati.php

:: Attendi input da parte dell'utente prima di chiudere
pause
