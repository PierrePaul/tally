<?php

/**
 * @file
 * Drupal settings for the Dokku deployment, copied over settings.php by the
 * Dockerfile. Everything that differs per environment comes from the app's
 * environment variables (dokku config:set), so no secret is in git.
 *
 * Variables:
 * - DATABASE_URL: set by dokku when a mysql service is linked.
 * - HASH_SALT: required. Also keys the encryption of users' saved Stripe keys,
 *   so it must never change once users have saved keys.
 * - TALLY_ENCRYPTION_KEY: optional, a dedicated key for those Stripe keys
 *   instead of the hash salt.
 * - TRUSTED_HOSTS: comma-separated host names the site answers to.
 */

$databases = [];
$settings['config_sync_directory'] = '/var/www/html/config/sync';
$settings['file_private_path'] = '/private';
$settings['update_free_access'] = FALSE;
$settings['entity_update_batch_size'] = 50;
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components', '.git'];

$salt = getenv('HASH_SALT');
if (!$salt) {
  throw new \RuntimeException('HASH_SALT is not set. Run: dokku config:set <app> HASH_SALT=$(openssl rand -hex 32)');
}
$settings['hash_salt'] = $salt;

if ($key = getenv('TALLY_ENCRYPTION_KEY')) {
  $settings['tally_encryption_key'] = $key;
}

if ($hosts = getenv('TRUSTED_HOSTS')) {
  $settings['trusted_host_patterns'] = array_map(
    fn (string $host) => '^' . preg_quote(trim($host), '/') . '$',
    explode(',', $hosts),
  );
}

// Behind dokku's nginx: it terminates HTTPS and forwards X-Forwarded-*. Drupal
// builds absolute URLs (the share link, Stripe's return URL) from them, so they
// must be trusted. Only nginx can reach the container, so its address is the
// only one that may speak for the client.
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'];

if ($database_url = getenv('DATABASE_URL')) {
  $url = parse_url($database_url);
  $databases['default']['default'] = [
    'database' => substr(urldecode($url['path']), 1),
    'username' => urldecode($url['user']),
    'password' => urldecode($url['pass']),
    'prefix' => '',
    'host' => urldecode($url['host']),
    'port' => urldecode($url['port'] ?? '3306'),
    'driver' => 'mysql',
  ];
}
