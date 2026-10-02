<?php
declare(strict_types=1);

namespace Audiomack;

final class OpenApiSpec
{
    public static function document(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Audiomack API',
                'version' => '1.0.0',
                'description' => 'Extract song and album metadata from Audiomack URLs.',
            ],
            'servers' => [['url' => '/']],
            'paths' => [
                '/' => [
                    'get' => [
                        'summary' => 'Check API status',
                        'operationId' => 'getStatus',
                        'responses' => [
                            '200' => [
                                'description' => 'API is running.',
                                'content' => ['application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/StatusResponse'],
                                    'example' => ['success' => true, 'message' => 'Audiomack API is running'],
                                ]],
                            ],
                        ],
                    ],
                ],
                '/song' => [
                    'get' => [
                        'summary' => 'Extract song metadata',
                        'operationId' => 'getSong',
                        'parameters' => [
                            [
                                'name' => 'url',
                                'in' => 'query',
                                'required' => true,
                                'description' => 'Audiomack song URL; the URL path must contain /song/. For album tracks, use /album with the track parameter.',
                                'schema' => ['type' => 'string', 'format' => 'uri'],
                                'example' => 'https://audiomack.com/artist/song/example',
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Song metadata was extracted.',
                                'headers' => [
                                    'X-Process-Time' => [
                                        'description' => 'Request processing time.',
                                        'schema' => ['type' => 'string', 'example' => '2.3412s'],
                                    ],
                                ],
                                'content' => ['application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/SongResponse'],
                                ]],
                            ],
                            '400' => ['$ref' => '#/components/responses/BadRequest'],
                            '422' => ['$ref' => '#/components/responses/ValidationError'],
                            '500' => ['$ref' => '#/components/responses/ServerError'],
                        ],
                    ],
                ],
                '/album' => [
                    'get' => [
                        'summary' => 'Extract album metadata',
                        'operationId' => 'getAlbum',
                        'parameters' => [
                            [
                                'name' => 'url',
                                'in' => 'query',
                                'required' => true,
                                'description' => 'Audiomack album URL.',
                                'schema' => ['type' => 'string', 'format' => 'uri'],
                                'example' => 'https://audiomack.com/artist/album/example',
                            ],
                            [
                                'name' => 'track',
                                'in' => 'query',
                                'required' => false,
                                'description' => 'Optional 1-based album track number. Includes that track in the response.',
                                'schema' => ['type' => 'integer', 'minimum' => 1],
                                'example' => 1,
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Album metadata was extracted.',
                                'headers' => [
                                    'X-Process-Time' => [
                                        'description' => 'Request processing time.',
                                        'schema' => ['type' => 'string', 'example' => '2.3412s'],
                                    ],
                                ],
                                'content' => ['application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/AlbumResponse'],
                                ]],
                            ],
                            '400' => ['$ref' => '#/components/responses/BadRequest'],
                            '404' => ['$ref' => '#/components/responses/NotFound'],
                            '422' => ['$ref' => '#/components/responses/ValidationError'],
                            '500' => ['$ref' => '#/components/responses/ServerError'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'responses' => [
                    'BadRequest' => [
                        'description' => 'The request URL or parameter is invalid.',
                        'content' => ['application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ]],
                    ],
                    'NotFound' => [
                        'description' => 'The requested album track was not found.',
                        'content' => ['application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ]],
                    ],
                    'ValidationError' => [
                        'description' => 'A required or typed query parameter is missing or invalid.',
                        'content' => ['application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ]],
                    ],
                    'ServerError' => [
                        'description' => 'The page could not be scraped or the browser automation failed.',
                        'content' => ['application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ]],
                    ],
                ],
                'schemas' => [
                    'StatusResponse' => [
                        'type' => 'object',
                        'required' => ['success', 'message'],
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'message' => ['type' => 'string', 'example' => 'Audiomack API is running'],
                        ],
                    ],
                    'ErrorResponse' => [
                        'type' => 'object',
                        'required' => ['detail'],
                        'properties' => ['detail' => ['type' => 'string']],
                    ],
                    'SongMetadata' => [
                        'type' => 'object',
                        'required' => [
                            'title', 'artist', 'featuringArtists', 'duration', 'genre', 'producer',
                            'releaseDate', 'year', 'trackNumber', 'streamingUrl', 'trackImageUrl',
                        ],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'artist' => ['type' => 'string'],
                            'featuringArtists' => ['type' => 'string'],
                            'duration' => ['type' => 'string', 'example' => '3:30'],
                            'genre' => ['type' => 'string'],
                            'producer' => ['type' => 'string'],
                            'releaseDate' => ['type' => 'string', 'example' => '2026-10-02'],
                            'year' => ['type' => 'string', 'example' => '2026'],
                            'trackNumber' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
                            'streamingUrl' => ['type' => 'string', 'format' => 'uri'],
                            'trackImageUrl' => ['type' => 'string', 'format' => 'uri'],
                        ],
                    ],
                    'AlbumTrackMetadata' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/SongMetadata'],
                            [
                                'type' => 'object',
                                'required' => ['songId'],
                                'properties' => ['songId' => ['type' => 'string']],
                            ],
                        ],
                    ],
                    'SongResponse' => [
                        'type' => 'object',
                        'required' => ['success', 'execution_time', 'data'],
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'execution_time' => ['type' => 'string', 'example' => '2.34s'],
                            'data' => ['$ref' => '#/components/schemas/SongMetadata'],
                        ],
                    ],
                    'AlbumMetadata' => [
                        'type' => 'object',
                        'required' => [
                            'albumId', 'albumTitle', 'albumArtist', 'albumFeaturingArtists',
                            'albumReleaseDate', 'albumTotalDuration', 'albumGenre', 'albumYear',
                            'albumImageUrl', 'albumTotalTracks', 'producer', 'description',
                        ],
                        'properties' => [
                            'albumId' => ['type' => 'string'],
                            'albumTitle' => ['type' => 'string'],
                            'albumArtist' => ['type' => 'string'],
                            'albumFeaturingArtists' => ['type' => 'string'],
                            'albumReleaseDate' => ['type' => 'string', 'example' => 'August 3rd'],
                            'albumTotalDuration' => ['type' => 'string', 'example' => '1:19:10'],
                            'albumGenre' => ['type' => 'string'],
                            'albumYear' => ['type' => 'string', 'example' => '2018'],
                            'albumImageUrl' => ['type' => 'string', 'format' => 'uri'],
                            'albumTotalTracks' => ['type' => 'integer', 'minimum' => 0],
                            'producer' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'track' => ['$ref' => '#/components/schemas/AlbumTrackMetadata'],
                        ],
                    ],
                    'AlbumResponse' => [
                        'type' => 'object',
                        'required' => ['success', 'execution_time', 'data'],
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'execution_time' => ['type' => 'string', 'example' => '2.34s'],
                            'data' => ['$ref' => '#/components/schemas/AlbumMetadata'],
                        ],
                    ],
                ],
            ],
        ];
    }
}