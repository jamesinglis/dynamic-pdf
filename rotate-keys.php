<?php

declare(strict_types=1);

/**
 * Key Rotation Script - Dynamic PDF Certificate Generator
 *
 * This script rotates authentication keys for test links environments.
 * It updates any non-empty key values in test_links.paths.*.key with new
 * randomly generated 24-character alphanumeric strings.
 *
 * SECURITY NOTICE:
 * This script is CLI-only for security reasons. It will refuse to run
 * when accessed via web server to prevent unauthorized key rotation.
 *
 * Usage:
 *   php rotate-keys.php
 *
 * Features:
 * - CLI-only execution (blocks web server access)
 * - Rotates keys for all environments with existing non-empty keys
 * - Rotates the global cache expiry key (global.cache_expiry_key)
 * - Preserves empty keys (environments without authentication)
 * - Saves old keys as timestamped fields in the config (key_YYYYMMDDHHMMSS)
 * - Validates JSON structure before saving
 * - Comprehensive logging of changes made
 *
 * @author Dynamic PDF Generator System
 * @version 1.0
 */

// ================================
// SECURITY: CLI-ONLY ENFORCEMENT
// ================================

// Check if script is being run from command line
if (php_sapi_name() !== 'cli') {
    // Return 404 to hide script existence from web browsers
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Not Found</h1><p>The requested resource could not be found.</p></body></html>';
    exit(1);
}

// Additional security check - ensure no web server environment variables
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI']) || isset($_SERVER['HTTP_USER_AGENT'])) {
    echo "ERROR: This script can only be run from the command line.\n";
    exit(1);
}

// ================================
// DEPENDENCIES AND INITIALIZATION
// ================================

// Include helpers to access load_config()
require_once __DIR__ . '/helpers.php';

// Script configuration
const KEY_LENGTH = 24;

/**
 * Generate a random alphanumeric string
 *
 * @param int $length Length of the string to generate
 * @return string Random alphanumeric string
 */
function generateRandomKey(int $length = KEY_LENGTH): string
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }

    return $randomString;
}


/**
 * Rotate keys in the configuration array
 *
 * @param array $config Configuration array to modify
 * @return array Array with rotation statistics
 */
function rotateKeys(array &$config): array
{
    $stats = [
        'total_environments' => 0,
        'keys_rotated' => 0,
        'keys_skipped' => 0,
        'cache_expiry_rotated' => false,
        'rotated_environments' => [],
        'cache_expiry_info' => []
    ];

    $timestamp = date('YmdHis');

    // Rotate cache expiry key if it exists
    if (isset($config['global']['cache_expiry_key'])) {
        $currentCacheKey = $config['global']['cache_expiry_key'];
        $newCacheKey = generateRandomKey(24); // Standard 24-character key length

        // Save old cache key with timestamp
        $oldCacheKeyField = "cache_expiry_key_{$timestamp}";
        $config['global'][$oldCacheKeyField] = $currentCacheKey;
        $config['global']['cache_expiry_key'] = $newCacheKey;

        $stats['cache_expiry_rotated'] = true;
        $stats['cache_expiry_info'] = [
            'old_key' => $currentCacheKey,
            'new_key' => $newCacheKey,
            'old_key_field' => $oldCacheKeyField
        ];
        echo "✓ Rotated cache expiry key (old key saved as {$oldCacheKeyField})\n";
    }

    // Check environments
    if (!isset($config['environments']) || !is_array($config['environments'])) {
        echo "⚠ No environments configuration found.\n";
        return $stats;
    }

    foreach ($config['environments'] as $envName => &$envConfig) {
        $stats['total_environments']++;

        if (is_array($envConfig) && isset($envConfig['key'])) {
            $currentKey = $envConfig['key'];

            // Only rotate non-empty keys
            if (!empty($currentKey)) {
                // Save old key with timestamp
                $oldKeyField = "key_{$timestamp}";
                $envConfig[$oldKeyField] = $currentKey;

                // Generate and set new key
                $newKey = generateRandomKey();
                $envConfig['key'] = $newKey;

                $stats['keys_rotated']++;
                $stats['rotated_environments'][$envName] = [
                    'old_key' => $currentKey,
                    'new_key' => $newKey,
                    'old_key_field' => $oldKeyField
                ];
                echo "✓ Rotated key for environment: {$envName} (old key saved as {$oldKeyField})\n";
            } else {
                $stats['keys_skipped']++;
                echo "- Skipped environment (no key): {$envName}\n";
            }
        } else {
            $stats['keys_skipped']++;
            echo "- Skipped environment (invalid format): {$envName}\n";
        }
    }

    return $stats;
}

// ================================
// MAIN EXECUTION
// ================================

try {
    echo "=== Key Rotation Script ===\n";
    echo "Dynamic PDF Certificate Generator\n\n";

    // Load current configuration
    echo "Loading configuration...\n";
    $config = load_config();

    // Rotate keys
    echo "Rotating keys...\n";
    $stats = rotateKeys($config);

    // Save updated configuration if changes were made
    if ($stats['keys_rotated'] > 0 || $stats['cache_expiry_rotated']) {
        echo "\nSaving updated configuration...\n";

        // Encode with pretty printing for readability
        $jsonData = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($jsonData === false) {
            throw new Exception("Failed to encode configuration as JSON: " . json_last_error_msg());
        }

        // Write to file
        $configPath = __DIR__ . '/config.json';
        if (file_put_contents($configPath, $jsonData) === false) {
            throw new Exception("Failed to write configuration file: {$configPath}");
        }

        echo "✓ Configuration saved successfully.\n";
    } else {
        echo "\n⚠ No keys were rotated, configuration unchanged.\n";
    }

    // Display summary
    echo "\n=== Rotation Summary ===\n";
    echo "Total environments: {$stats['total_environments']}\n";
    echo "Keys rotated: {$stats['keys_rotated']}\n";
    echo "Keys skipped: {$stats['keys_skipped']}\n";
    echo "Cache expiry key rotated: " . ($stats['cache_expiry_rotated'] ? 'Yes' : 'No') . "\n";

    if ($stats['cache_expiry_rotated']) {
        echo "\n=== New Cache Expiry Key ===\n";
        echo "global.cache_expiry_key: {$stats['cache_expiry_info']['new_key']}\n";

        echo "\n=== Old Cache Expiry Key (preserved in config) ===\n";
        echo "global.{$stats['cache_expiry_info']['old_key_field']}: {$stats['cache_expiry_info']['old_key']}\n";
    }

    if ($stats['keys_rotated'] > 0) {
        echo "\n=== New Environment Keys ===\n";
        foreach ($stats['rotated_environments'] as $envName => $keyInfo) {
            echo "{$envName}: {$keyInfo['new_key']}\n";
        }

        echo "\n=== Old Environment Keys (preserved in config) ===\n";
        foreach ($stats['rotated_environments'] as $envName => $keyInfo) {
            echo "{$envName}.{$keyInfo['old_key_field']}: {$keyInfo['old_key']}\n";
        }
    }

    if ($stats['keys_rotated'] > 0 || $stats['cache_expiry_rotated']) {
        echo "\n⚠ IMPORTANT: Update any external systems or documentation that reference the old keys.\n";
        echo "Old keys have been preserved in the configuration file for reference.\n";
    }

    echo "\n✓ Key rotation completed successfully.\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
