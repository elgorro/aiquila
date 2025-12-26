<?php

/**
 * PHPUnit bootstrap for AIquila tests
 *
 * For standalone testing, mock the Nextcloud interfaces.
 * For integration testing, this should be run within Nextcloud's test framework.
 */

// Autoload app classes
spl_autoload_register(function ($class) {
    $prefix = 'OCA\\AIquila\\';
    $baseDir = __DIR__ . '/../lib/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Mock Nextcloud interfaces for standalone testing
if (!interface_exists('OCP\IConfig')) {
    interface OCP_IConfig {
        public function getAppValue(string $appName, string $key, string $default = ''): string;
        public function setAppValue(string $appName, string $key, string $value): void;
        public function getUserValue(string $userId, string $appName, string $key, string $default = ''): string;
        public function setUserValue(string $userId, string $appName, string $key, string $value): void;
    }
    class_alias('OCP_IConfig', 'OCP\IConfig');
}

if (!interface_exists('OCP\Http\Client\IClientService')) {
    interface OCP_Http_Client_IClientService {
        public function newClient();
    }
    class_alias('OCP_Http_Client_IClientService', 'OCP\Http\Client\IClientService');
}

if (!interface_exists('OCP\Http\Client\IClient')) {
    interface OCP_Http_Client_IClient {
        public function post(string $uri, array $options = []);
    }
    class_alias('OCP_Http_Client_IClient', 'OCP\Http\Client\IClient');
}

if (!interface_exists('OCP\Http\Client\IResponse')) {
    interface OCP_Http_Client_IResponse {
        public function getBody();
    }
    class_alias('OCP_Http_Client_IResponse', 'OCP\Http\Client\IResponse');
}
