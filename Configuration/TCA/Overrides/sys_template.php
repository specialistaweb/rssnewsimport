<?php

defined('TYPO3') or die();

// For installations still using classic sys_template records.
// Installations with site sets use the set "specialistaweb/rss-news-import" instead.
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'rssnewsimport',
    'Configuration/TypoScript/',
    'RSS News Import (Fluid templates for EXT:news)'
);
