<?php

return [
    'routes' => [
        ['name' => 'chat#ask', 'url' => '/api/ask', 'verb' => 'POST'],
        ['name' => 'chat#summarize', 'url' => '/api/summarize', 'verb' => 'POST'],
        ['name' => 'settings#save', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#saveAdmin', 'url' => '/api/admin/settings', 'verb' => 'POST'],
    ],
];
