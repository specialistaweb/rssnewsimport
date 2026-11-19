<?php

defined('TYPO3') or die();

// SimplePie (https://simplepie.org, BSD-3-Clause).
// Installazione con Composer: la libreria arriva da vendor/ (simplepie/simplepie) e questo blocco non fa nulla.
// Installazione classica (Extension Manager): si usa la copia inclusa in Resources/Private/PHP/SimplePie.
if (!class_exists(\SimplePie\SimplePie::class)) {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'SimplePie\\')) {
            return;
        }
        $file = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('rss_news_import')
            . 'Resources/Private/PHP/SimplePie/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
