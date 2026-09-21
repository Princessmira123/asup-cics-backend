<?php
// config/services.php
//
// This project's Laravel install already has a config/services.php with the
// standard mailgun/postmark/ses/slack entries. Merge the two new entries
// below into that existing array — don't overwrite the file wholesale.

return [

    // ... your existing entries (mailgun, postmark, ses, slack, etc.) stay here ...

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
    ],

    'termii' => [
        'api_key'   => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'ASUPCICS'),
    ],

];
