<?php
declare(strict_types=1);

return [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'maludb-edge API',
        'version' => '0.1.0',
    ],
    'paths' => [
        '/v1/health' => [
            'get' => [
                'summary' => 'Health check',
                'responses' => [
                    '200' => ['description' => 'API is healthy'],
                ],
            ],
        ],
        '/v1/version' => [
            'get' => [
                'summary' => 'Version metadata',
                'responses' => [
                    '200' => ['description' => 'Version metadata'],
                ],
            ],
        ],
        '/v1/openapi.json' => [
            'get' => [
                'summary' => 'OpenAPI document',
                'responses' => [
                    '200' => ['description' => 'OpenAPI JSON document'],
                ],
            ],
        ],
        '/v1/docs' => [
            'get' => [
                'summary' => 'HTML API documentation shell',
                'responses' => [
                    '200' => ['description' => 'HTML documentation shell'],
                ],
            ],
        ],
        '/v1/me' => [
            'get' => [
                'summary' => 'Authenticated API key context',
                'security' => [
                    ['ApiKeyAuth' => []],
                ],
                'responses' => [
                    '200' => ['description' => 'Authenticated context'],
                    '401' => ['description' => 'Missing or invalid API key'],
                ],
            ],
        ],
    ],
    'components' => [
        'securitySchemes' => [
            'ApiKeyAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'MaluDB Edge API key',
            ],
        ],
    ],
];
