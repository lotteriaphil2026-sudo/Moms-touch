<?php

return [
    'vapid' => [
        'subject' => env('WEB_PUSH_VAPID_SUBJECT'),
        'public_key' => env('WEB_PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY'),
    ],
    'endpoint_hosts' => [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'web.push.apple.com',
    ],
];
