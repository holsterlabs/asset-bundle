<?php

declare(strict_types=1);

// Installed independently: composer install in this package. In the skeleton: use its vendor dir.
$autoload = getenv('HL_ASSET_TEST_AUTOLOAD') ?: (is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../vendor/autoload.php');
$loader = require $autoload;
$loader->addPsr4('Hl\\AssetBundle\\', __DIR__.'/../src', true);
// Composer's optimized class map takes precedence over PSR-4, including mirrored installs.
$sourceDirectory = realpath(__DIR__.'/../src');
$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $relative = substr($file->getPathname(), strlen($sourceDirectory) + 1, -4);
        $classes['Hl\\AssetBundle\\'.str_replace(['/', '\\'], '\\', $relative)] = $file->getPathname();
    }
}
$loader->addClassMap($classes);
