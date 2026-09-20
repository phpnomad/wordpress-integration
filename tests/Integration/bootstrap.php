<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$wordpressRoot = getenv('WORDPRESS_ROOT');

if (!is_string($wordpressRoot) || $wordpressRoot === '') {
    throw new RuntimeException('WORDPRESS_ROOT must point to an official WordPress source tree.');
}

$wordpressRoot = rtrim($wordpressRoot, '/');
$runtimeMetadataFile = __DIR__ . '/wordpress-runtime.json';
$runtimeMetadata = json_decode((string) file_get_contents($runtimeMetadataFile), true);

if (!is_array($runtimeMetadata) || !isset($runtimeMetadata['version'], $runtimeMetadata['source'], $runtimeMetadata['commit'])) {
    throw new RuntimeException('WordPress runtime metadata is missing or invalid.');
}

$requiredFiles = [
    'load' => $wordpressRoot . '/wp-includes/load.php',
    'plugin' => $wordpressRoot . '/wp-includes/plugin.php',
    'functions' => $wordpressRoot . '/wp-includes/functions.php',
    'version' => $wordpressRoot . '/wp-includes/version.php',
    'wpdb' => $wordpressRoot . '/wp-includes/class-wpdb.php',
];

foreach ($requiredFiles as $requiredFile) {
    if (!is_file($requiredFile)) {
        throw new RuntimeException("Required WordPress runtime file is missing: {$requiredFile}");
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', $wordpressRoot . '/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}

require_once $requiredFiles['version'];

if (!isset($wp_version) || $wp_version !== $runtimeMetadata['version']) {
    throw new RuntimeException(sprintf(
        'WORDPRESS_ROOT must contain WordPress %s from %s at %s.',
        $runtimeMetadata['version'],
        $runtimeMetadata['source'],
        $runtimeMetadata['commit']
    ));
}

require_once $requiredFiles['load'];
require_once $requiredFiles['plugin'];
require_once $requiredFiles['functions'];
wp_load_translations_early();
require_once $requiredFiles['wpdb'];
