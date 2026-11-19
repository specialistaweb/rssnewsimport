<?php

defined('TYPO3') or die();

// SimplePie (https://simplepie.org, BSD-3-Clause).
// Composer installation: the library comes from vendor/ (simplepie/simplepie) and this block does nothing.
// Classic installation (Extension Manager): the copy bundled in Resources/Private/PHP/SimplePie is used.
if (!class_exists(\SimplePie\SimplePie::class)) {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'SimplePie\\')) {
            return;
        }
        $file = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('rssnewsimport')
            . 'Resources/Private/PHP/SimplePie/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
