<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'RSS News Import',
    'description' => 'Imports external RSS/Atom feeds into EXT:news via the Scheduler and SimplePie, with images, categories, keyword filters and Fluid templates.',
    'category' => 'be',
    'author' => 'Giulia Tuveri',
    'author_email' => 'gt@specialistaweb.it',
    'author_company' => 'Specialistaweb',
    'state' => 'stable',
    'version' => '3.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.4.99',
            'scheduler' => '14.0.0-14.4.99',
            'news' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
