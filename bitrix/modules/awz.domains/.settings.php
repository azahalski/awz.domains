<?php
return [
    'ui.entity-selector' => [
        'value' => [
            'entities' => [
                [
                    'entityId' => 'awzdomains-user',
                    'provider' => [
                        'moduleId' => 'awz.domains',
                        'className' => '\\Awz\\Domains\\Access\\EntitySelectors\\User'
                    ],
                ],
                [
                    'entityId' => 'awzdomains-group',
                    'provider' => [
                        'moduleId' => 'awz.domains',
                        'className' => '\\Awz\\Domains\\Access\\EntitySelectors\\Group'
                    ],
                ],
            ]
        ],
        'readonly' => true,
    ]
];