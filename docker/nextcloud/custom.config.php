<?php
/**
 * AIquila Development Environment - Custom Nextcloud Configuration
 *
 * This file is automatically loaded by Nextcloud and provides
 * optimized settings for development with Docker.
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
   * Development Settings
   */
  'debug' => true,
  'loglevel' => 0, // Debug level logging

  /**
   * Performance Settings
   */
  'default_phone_region' => 'US',
  'trashbin_retention_obligation' => 'auto, 7',

  /**
   * Security - Development Only!
   */
  'trusted_proxies' => array('172.16.0.0/12', '10.0.0.0/8'),
  'overwriteprotocol' => 'https', // Caddy reverse proxy handles TLS

  /**
   * Allow app installation from custom_apps
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
  'max_chunk_size' => 10 * 1024 * 1024, // 10 MB

  /**
   * Maintenance Window (avoid interruptions during dev)
   */
  'maintenance_window_start' => 4, // 4 AM
);
