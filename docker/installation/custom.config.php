<?php
/**
 * AIquila Installation Test Environment - Nextcloud Configuration
 */

$CONFIG = array(
  /**
   * Redis Configuration (Caching & Session)
   */
  'memcache.local' => '\OC\Memcache\Redis',
  'memcache.locking' => '\OC\Memcache\Redis',
  'redis' => array(
    'host' => 'redis',
    'port' => 6379,
    'timeout' => 0.0,
  ),

  /**
   * Debug Settings (for installation testing)
   */
  'debug' => true,
  'loglevel' => 0,

  /**
   * General Settings
   */
  'default_phone_region' => 'US',
  'trashbin_retention_obligation' => 'auto, 7',

  /**
   * Network / Proxy
   */
  'trusted_proxies' => array('172.16.0.0/12', '10.0.0.0/8'),
  'overwriteprotocol' => 'http',

  /**
   * App paths - allows installation into custom_apps
   */
  'apps_paths' => array(
    0 => array(
      'path' => '/var/www/html/apps',
      'url' => '/apps',
      'writable' => false,
    ),
    1 => array(
      'path' => '/var/www/html/custom_apps',
      'url' => '/custom_apps',
      'writable' => true,
    ),
  ),

  /**
   * File Upload Settings
   */
  'max_chunk_size' => 10 * 1024 * 1024,

  /**
   * Maintenance Window
   */
  'maintenance_window_start' => 4,
);
