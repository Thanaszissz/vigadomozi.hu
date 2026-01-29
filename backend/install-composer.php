<?php
// Download and install Composer
$installerUrl = 'https://getcomposer.org/installer';
$expectedSignature = file_get_contents('https://composer.github.io/installer.sig');
$actual = hash_file('sha384', 'composer-setup.php');

if ($actual !== $expectedSignature) {
    echo "Installer is corrupted\n";
    exit(1);
}

if (php_sapi_name() !== 'cli') {
    echo "Composer should be installed from command line\n";
    exit(1);
}

passthru('php composer-setup.php --install-dir=. --filename=composer');
