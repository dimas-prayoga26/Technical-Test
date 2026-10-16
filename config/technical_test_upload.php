<?php

return [
    'connection' => env('TECHNICAL_TEST_UPLOAD_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    'default_brand' => 'rnb',

    'document_type' => 'technical_test',

    'storage_disk' => env('TECHNICAL_TEST_UPLOAD_STORAGE_DISK', 'public'),

    'storage_directory' => 'uploaded-technical-tests',

    'host_brands' => [
        'technical-test.rnb.co.id' => 'rnb',
        'technical-test.trah.co.id' => 'trah',
        'technical-test.karpetmerah.id' => 'kma',
        'technical-test.rne.co.id' => 'rne',
        'technical-test.coffeeniskala.com' => 'niskala',
        'technical-test.tims.co.id' => 'tms',
    ],
];
