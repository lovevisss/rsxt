<?php

return [
    'write_target' => env('LEAVE_WRITE_TARGET', 'disabled'), // disabled, stage, production
    'production_writes_enabled' => env('LEAVE_PRODUCTION_WRITES_ENABLED', false),
    'legacy_attachment_host' => env('LEAVE_LEGACY_ATTACHMENT_HOST', 'rsgl.zufedfc.edu.cn'),
    'public_attachment_url' => env('LEAVE_PUBLIC_ATTACHMENT_URL', ''),
];
