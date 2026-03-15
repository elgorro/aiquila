<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
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
