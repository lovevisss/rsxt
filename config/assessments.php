<?php

return [
    'write_target' => env('ASSESSMENT_WRITE_TARGET', 'disabled'), // disabled, stage, production
    'production_writes_enabled' => env('ASSESSMENT_PRODUCTION_WRITES_ENABLED', false),
];
