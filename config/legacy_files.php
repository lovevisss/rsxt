<?php

return [
    'enabled' => env('LEGACY_PRIVATE_FILES_ENABLED', false),
    'remote_fallback' => env('LEGACY_FILES_REMOTE_FALLBACK', true),
    'source_url' => 'http://rsgl.zufedfc.edu.cn:8080',
    'download_base_url' => env('LEGACY_FILES_DOWNLOAD_BASE_URL', 'http://10.1.12.184:8080'),
    'download_timeout' => env('LEGACY_FILES_DOWNLOAD_TIMEOUT', 600),
    'aliases' => ['rsgl.zufedfc.edu.cn', '10.1.12.184', 'localhost', 'rsgl-8080.webvpn.zufedfc.edu.cn'],
];
