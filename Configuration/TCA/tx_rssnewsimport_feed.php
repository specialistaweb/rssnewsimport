<?php

defined('TYPO3') or die();

$ll = 'LLL:EXT:rssnewsimport/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_rssnewsimport_feed',
        'label' => 'title',
        'label_alt' => 'url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'default_sortby' => 'title',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'typeicon_classes' => [
            'default' => 'rss-news-import-feed',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;' . $ll . 'tab.general,
                    title, url, description,
                --div--;' . $ll . 'tab.import,
                    storage_pid, news_type, check_interval, --palette--;;flags, import_limit, max_age_days, default_author,
                --div--;' . $ll . 'tab.filter,
                    exclude_keywords,
                --div--;' . $ll . 'tab.categories,
                    categories, map_categories,
                --div--;' . $ll . 'tab.status,
                    last_import, error_count, last_status,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
            ',
        ],
    ],
    'palettes' => [
        'flags' => [
            'showitem' => 'import_images, add_related_link, --linebreak--, update_existing, import_hidden',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => $ll . 'tx_rssnewsimport_feed.title',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'url' => [
            'label' => $ll . 'tx_rssnewsimport_feed.url',
            'description' => $ll . 'tx_rssnewsimport_feed.url.description',
            'config' => [
                'type' => 'link',
                'required' => true,
                'allowedTypes' => ['url'],
                'size' => 60,
            ],
        ],
        'description' => [
            'label' => $ll . 'tx_rssnewsimport_feed.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 40,
            ],
        ],
        'storage_pid' => [
            'label' => $ll . 'tx_rssnewsimport_feed.storage_pid',
            'description' => $ll . 'tx_rssnewsimport_feed.storage_pid.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 1,
                'maxitems' => 1,
                'minitems' => 0,
            ],
        ],
        'news_type' => [
            'label' => $ll . 'tx_rssnewsimport_feed.news_type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => $ll . 'tx_rssnewsimport_feed.news_type.0', 'value' => '0'],
                    ['label' => $ll . 'tx_rssnewsimport_feed.news_type.2', 'value' => '2'],
                ],
                'default' => '0',
            ],
        ],
        'check_interval' => [
            'label' => $ll . 'tx_rssnewsimport_feed.check_interval',
            'description' => $ll . 'tx_rssnewsimport_feed.check_interval.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'range' => ['lower' => 5, 'upper' => 10080],
                'default' => 60,
            ],
        ],
        'import_images' => [
            'label' => $ll . 'tx_rssnewsimport_feed.import_images',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'add_related_link' => [
            'label' => $ll . 'tx_rssnewsimport_feed.add_related_link',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'update_existing' => [
            'label' => $ll . 'tx_rssnewsimport_feed.update_existing',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'import_hidden' => [
            'label' => $ll . 'tx_rssnewsimport_feed.import_hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'map_categories' => [
            'label' => $ll . 'tx_rssnewsimport_feed.map_categories',
            'description' => $ll . 'tx_rssnewsimport_feed.map_categories.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'import_limit' => [
            'label' => $ll . 'tx_rssnewsimport_feed.import_limit',
            'description' => $ll . 'tx_rssnewsimport_feed.zero_unlimited',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'range' => ['lower' => 0, 'upper' => 1000],
                'default' => 0,
            ],
        ],
        'max_age_days' => [
            'label' => $ll . 'tx_rssnewsimport_feed.max_age_days',
            'description' => $ll . 'tx_rssnewsimport_feed.zero_unlimited',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'range' => ['lower' => 0, 'upper' => 3650],
                'default' => 0,
            ],
        ],
        'default_author' => [
            'label' => $ll . 'tx_rssnewsimport_feed.default_author',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'exclude_keywords' => [
            'label' => $ll . 'tx_rssnewsimport_feed.exclude_keywords',
            'description' => $ll . 'tx_rssnewsimport_feed.exclude_keywords.description',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'cols' => 60,
                'max' => 10000,
                'placeholder' => $ll . 'tx_rssnewsimport_feed.exclude_keywords.placeholder',
            ],
        ],
                'categories' => [
            'label' => $ll . 'tx_rssnewsimport_feed.categories',
            'description' => $ll . 'tx_rssnewsimport_feed.categories.description',
            'config' => [
                'type' => 'category',
            ],
        ],
        'last_import' => [
            'label' => $ll . 'tx_rssnewsimport_feed.last_import',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'error_count' => [
            'label' => $ll . 'tx_rssnewsimport_feed.error_count',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'last_status' => [
            'label' => $ll . 'tx_rssnewsimport_feed.last_status',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'readOnly' => true,
            ],
        ],
    ],
];
