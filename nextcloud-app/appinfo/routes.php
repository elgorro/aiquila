<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        // Conversation API
        ['name' => 'conversation#index',   'url' => '/api/conversations',              'verb' => 'GET'],
        ['name' => 'conversation#create',  'url' => '/api/conversations',              'verb' => 'POST'],
        ['name' => 'conversation#show',    'url' => '/api/conversations/{id}',          'verb' => 'GET'],
        ['name' => 'conversation#update',  'url' => '/api/conversations/{id}',          'verb' => 'PUT'],
        ['name' => 'conversation#destroy', 'url' => '/api/conversations/{id}',          'verb' => 'DELETE'],
        ['name' => 'conversation#message',   'url' => '/api/conversations/{id}/messages',  'verb' => 'POST'],
        ['name' => 'conversation#messageStream', 'url' => '/api/conversations/{id}/messages/stream', 'verb' => 'POST'],
        ['name' => 'conversation#duplicate', 'url' => '/api/conversations/{id}/duplicate', 'verb' => 'POST'],
        ['name' => 'conversation#search',    'url' => '/api/conversations/search',         'verb' => 'GET'],

        // Project API
        ['name' => 'project#index',      'url' => '/api/projects',                        'verb' => 'GET'],
        ['name' => 'project#create',     'url' => '/api/projects',                        'verb' => 'POST'],
        ['name' => 'project#show',       'url' => '/api/projects/{id}',                   'verb' => 'GET'],
        ['name' => 'project#update',     'url' => '/api/projects/{id}',                   'verb' => 'PUT'],
        ['name' => 'project#destroy',    'url' => '/api/projects/{id}',                   'verb' => 'DELETE'],
        ['name' => 'project#addPath',    'url' => '/api/projects/{id}/paths',             'verb' => 'POST'],
        ['name' => 'project#removePath', 'url' => '/api/projects/{id}/paths/{pathId}',    'verb' => 'DELETE'],

        ['name' => 'chat#ask', 'url' => '/api/ask', 'verb' => 'POST'],
        ['name' => 'chat#chat', 'url' => '/api/chat', 'verb' => 'POST'],
        ['name' => 'chat#summarize', 'url' => '/api/summarize', 'verb' => 'POST'],
        ['name' => 'chat#analyzeFile', 'url' => '/api/analyze-file', 'verb' => 'POST'],
        ['name' => 'settings#save', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#saveAdmin', 'url' => '/api/admin/settings', 'verb' => 'POST'],
        ['name' => 'settings#testConfig', 'url' => '/api/admin/test', 'verb' => 'POST'],
        ['name' => 'occ#execute', 'url' => '/api/occ', 'verb' => 'POST'],

        // MCP Server Admin API
        ['name' => 'mcp_server#index',   'url' => '/api/admin/mcp-servers',           'verb' => 'GET'],
        ['name' => 'mcp_server#create',  'url' => '/api/admin/mcp-servers',           'verb' => 'POST'],
        ['name' => 'mcp_server#update',  'url' => '/api/admin/mcp-servers/{id}',      'verb' => 'PUT'],
        ['name' => 'mcp_server#destroy', 'url' => '/api/admin/mcp-servers/{id}',      'verb' => 'DELETE'],
        ['name' => 'mcp_server#test',    'url' => '/api/admin/mcp-servers/{id}/test', 'verb' => 'POST'],
        ['name' => 'mcp_server#tools',         'url' => '/api/admin/mcp-servers/{id}/tools',          'verb' => 'GET'],
        ['name' => 'mcp_server#authorize',     'url' => '/api/admin/mcp-servers/{id}/oauth/authorize', 'verb' => 'POST'],
        ['name' => 'mcp_server#oauthCallback', 'url' => '/api/admin/mcp-servers/{id}/oauth/callback',  'verb' => 'GET'],

        // File API
        ['name' => 'file#info',     'url' => '/api/files/info',     'verb' => 'GET'],
        ['name' => 'file#listDir',  'url' => '/api/files/list',     'verb' => 'GET'],
        ['name' => 'file#content',  'url' => '/api/files/content',  'verb' => 'GET'],
        ['name' => 'file#download', 'url' => '/api/files/download', 'verb' => 'GET'],
        ['name' => 'file#search',   'url' => '/api/files/search',   'verb' => 'GET'],
        ['name' => 'file#preview',  'url' => '/api/files/preview',  'verb' => 'GET'],
    ],
];
