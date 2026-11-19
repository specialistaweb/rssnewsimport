<?php

defined('TYPO3') or die();

// Per installazioni che usano ancora i record sys_template classici.
// Nelle installazioni con Site Sets usare invece il set "specialistaweb/rss-news-import".
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'rss_news_import',
    'Configuration/TypoScript/',
    'RSS News Import (template Fluid per EXT:news)'
);
