<?php

declare(strict_types=1);

$candidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        break;
    }
}

if (!isset($loader) || !$loader instanceof Composer\Autoload\ClassLoader) {
    fwrite(STDERR, "No Composer autoloader found. Run composer install first.\n");
    exit(1);
}

$loader->addPsr4('Itw\\Gatecha\\', __DIR__ . '/../src/');
$loader->addPsr4('Itw\\Gatecha\\Tests\\', __DIR__ . '/');
