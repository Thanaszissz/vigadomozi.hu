@echo off
REM Download Composer installer
echo Downloading Composer installer...
powershell -Command "(New-Object System.Net.ServicePointManager).SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor [System.Net.SecurityProtocolType]::Tls12; (New-Object System.Net.WebClient).DownloadFile('https://getcomposer.org/installer', 'composer-setup.php')"

REM Install Composer
echo Installing Composer...
php composer-setup.php --install-dir=. --filename=composer.phar

REM Update dependencies
echo Updating dependencies...
php composer.phar update --no-interaction

REM Clean up
del composer-setup.php

echo Done!
pause
