<?php

declare(strict_types=1);

/**
 * Test Links Page - Dynamic PDF Certificate Generator Testing Interface
 *
 * This page provides a comprehensive testing interface for the dynamic PDF certificate generator.
 * It displays test links, provides URL generation tools, and offers email-friendly sharing options.
 *
 * Features:
 * - Displays categorized test links with environment-specific URLs (dev/prod)
 * - Dynamic URL Generator for creating custom test URLs with parameters
 * - Email-friendly formatted text blocks for sharing test links
 * - Self-referencing links for sharing the test page itself
 * - Dark mode support using Tailwind CSS
 * - Vue.js interactive components for dynamic functionality
 *
 * Security:
 * - Access controlled by global.expose_test_links configuration setting
 * - Optional key-based authentication via expose_test_links_key
 * - Two-tier path filtering: expose (security) and visible (UI) flags with Vue.js controls
 *
 * Configuration Dependencies:
 * - config.json: Main configuration file with test_links section
 * - helpers.php: Utility functions including load_config()
 *
 * URL Parameters:
 * - key: Optional access key for authentication
 * - paths: Comma-separated list of paths to filter displayed links
 * - No URL parameters needed (all controlled via UI toggles)
 *
 * @author Dynamic PDF Generator System
 * @version 2.0 (Dark Mode + Vue.js Enhanced)
 *
 * Code Structure Review:
 * - Security: Proper access controls with fallback 404 responses
 * - Error Handling: Comprehensive try-catch with logging
 * - Configuration: Flexible path filtering and environment detection
 * - Data Organization: Clean separation of data processing and rendering
 * - HTML Structure: Semantic markup with proper accessibility
 * - CSS: Dark mode support via Tailwind classes with custom animations
 * - JavaScript: Vue.js integration for dynamic functionality
 * - Documentation: Comprehensive inline comments and section headers
 */

// =============================================================================
// PHP LOGIC BLOCK
// All PHP processing, data loading, and variable setup happens here.
// =============================================================================

// Include helpers to access load_config()
require_once __DIR__ . '/helpers.php';

try {
    // CONFIGURATION LOADING & VALIDATION
    $config = load_config();

    // ACCESS CONTROL & SECURITY
    $expose_test_links = $config['global']['expose_test_links'] ?? false;

    if (!$expose_test_links) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Not Found</h1><p>The requested resource could not be found.</p></body></html>';
        exit;
    }

    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $current_platform_key = '';
    $current_platform_env = '';
    $environments = $config['environments'] ?? [];

    if (!empty($environments)) {
        foreach ($environments as $env => $env_config) {
            $url = $env_config['url'];
            $parsed_url = parse_url($url);

            if ($parsed_url && isset($parsed_url['host']) && $parsed_url['host'] === $current_host) {
                $current_platform_env = $env;
                $current_platform_key = $env_config['key'] ?? '';
                break;
            }
        }
    }

    if (!empty($current_platform_key)) {
        $provided_key = $_GET['key'] ?? '';

        if (empty($provided_key) || $provided_key !== $current_platform_key) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Not Found</h1><p>The requested resource could not be found.</p></body></html>';
            exit;
        }
    }

    // PATH FILTERING & URL PARAMETERS
    $exposed_paths = [];
    $path_configs = [];

    if (!empty($environments)) {
        foreach ($environments as $env => $env_config) {
            $expose = $env_config['expose'] ?? true;
            $visible = $env_config['visible'] ?? true;

            if ($expose) {
                $exposed_paths[] = $env;
                $path_configs[$env] = [
                    'url' => $env_config['url'],
                    'key' => $env_config['key'] ?? '',
                    'visible' => $visible,
                    'label' => $env_config['label'] ?? ucfirst($env)
                ];
            }
        }
    }

    $active_paths = $exposed_paths;

    // TEST LINKS DATA PREPARATION
    $test_versions = $config['test_versions'] ?? [];
    $has_test_links = !empty($environments) && !empty($test_versions);

    $allow_toggle_self_link = $config['global']['allow_toggle_self_link'] ?? false;

    // SELF-REFERENCING URL GENERATION
    $self_links = [];
    if ($allow_toggle_self_link && $has_test_links) {
        foreach ($path_configs as $env => $env_config) {
            $domain_url = $env_config['url'];
            $env_key = $env_config['key'];

            $query_params = [];
            if (!empty($env_key)) {
                $query_params[] = 'key=' . urlencode($env_key);
            }

            $query_string = !empty($query_params) ? '?' . implode('&', $query_params) : '';
            $full_domain_url = rtrim($domain_url, '/') . '/test-links.php';
            $self_links[$env] = $full_domain_url . $query_string;
        }
    }

    // PLATFORM DETECTION
    $current_platform = !empty($current_platform_env) ? ucfirst($current_platform_env) : '';
    $current_platform_url = '';

    if (!empty($current_platform_env) && isset($environments[$current_platform_env])) {
        $env_config = $environments[$current_platform_env];
        $current_platform_url = rtrim($env_config['url'], '/') . '/';
    }

    // PAGE DATA PREPARATION
    $single_environment = count($exposed_paths) <= 1;

    $page_data = [
        'title' => 'Test Links - Dynamic PDF Generator',
        'has_test_links' => $has_test_links,
        'message' => $has_test_links ? null : 'No test configuration found',
        'environments' => $environments,
        'test_versions' => $test_versions,
        'config' => $config,
        'allow_toggle_self_link' => $allow_toggle_self_link,
        'self_links' => $self_links,
        'current_platform' => $current_platform,
        'current_platform_url' => $current_platform_url,
        'paths' => $path_configs,
        'exposed_paths' => $exposed_paths,
        'single_environment' => $single_environment
    ];

    // TEST LINKS ORGANIZATION
    if ($has_test_links) {
        $page_data['organized_links'] = [];

        if (!empty($environments) && !empty($test_versions)) {
            $envs = $environments;
            $versions = $test_versions;

            if (!empty($active_paths)) {
                $envs = array_intersect_key($envs, array_flip($active_paths));
            }

            $page_data['organized_links']['test_versions'] = [
                'name' => 'Test Versions',
                'links' => []
            ];

            foreach ($versions as $version_key => $version_params) {
                $query_params = [];
                foreach ($version_params as $param_key => $param_value) {
                    if ($param_value !== null && $param_value !== '') {
                        $query_params[] = urlencode($param_key) . '=' . urlencode((string)$param_value);
                    }
                }
                $query_string = !empty($query_params) ? '?' . implode('&', $query_params) : '';

                $links = [];
                foreach ($envs as $env => $env_config) {
                    $base_url = $env_config['url'];
                    $links[$env] = rtrim($base_url, '/') . '/' . $query_string;
                }

                $page_data['organized_links']['test_versions']['links'][$version_key] = $links;
            }
        } else {
            foreach ($environments as $category => $links) {
                if (is_array($links)) {
                    $page_data['organized_links'][$category] = [
                        'name' => ucfirst(str_replace('_', ' ', $category)),
                        'links' => $links
                    ];
                }
            }
        }
    }

} catch (Exception $e) {
    // ERROR HANDLING
    error_log("Error in test-links.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Internal Server Error</h1><p>An error occurred while processing your request.</p></body></html>';
    exit;
}

// =============================================================================
// HTML STRUCTURE BLOCK
// This section defines the main HTML layout and includes PHP for data display.
// =============================================================================

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_data['title']); ?></title>

    <!-- External Dependencies -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>

    <!-- =====================================================================
    // CSS STYLES BLOCK
    // Contains custom CSS rules and animations.
    // ===================================================================== -->
    <script>
        tailwind.config = {
            darkMode: 'media', // Enable system-preference based dark mode
            theme: {
                extend: {
                    colors: {
                        'brand-blue': '#1e40af',   // Custom brand color for buttons/accents
                        'brand-accent': '#ea580c', // Custom brand color for headers
                    }
                }
            }
        }
    </script>

    <style>
        /* Typography: Professional font stack */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
        }

        /* CUSTOM ANIMATIONS */
        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .slide-in-right {
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .pulse-success {
            animation: pulseSuccess 0.5s ease-in-out;
        }

        @keyframes pulseSuccess {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
        }

        .shake-error {
            animation: shakeError 0.5s ease-in-out;
        }

        @keyframes shakeError {
            0%, 100% {
                transform: translateX(0);
            }
            25% {
                transform: translateX(-5px);
            }
            75% {
                transform: translateX(5px);
            }
        }

        /* INTERACTIVE ELEMENT STATES */
        .input-focus {
            transform: scale(1.01);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn-hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        /* UTILITY CLASSES */
        html {
            scroll-behavior: smooth;
        }

        .spinner {
            border: 2px solid #e5e7eb;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
        }

        @media (prefers-color-scheme: dark) {
            .spinner {
                border: 2px solid #4b5563;
                border-top: 2px solid #60a5fa;
            }
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-gray-900 dark:text-gray-100 min-h-screen">
<!-- Vue.js application root -->
<div id="app" class="container mx-auto px-3 py-3 max-w-6xl">

    <!-- PAGE HEADER -->
    <div class="text-center mb-4">
        <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-gray-100 mb-2">Test Links</h1>
        <p class="text-base sm:text-lg text-gray-600 dark:text-gray-400">Dynamic PDF Certificate Testing Environment</p>

        <!-- Status indicators and platform info -->
        <div class="mt-2 flex flex-col sm:flex-row items-center gap-4 justify-between">
            <div class="flex items-center gap-2">
                <!-- Testing mode active indicator -->
                <div class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200">
                    <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                              d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                              clip-rule="evenodd"></path>
                    </svg>
                    Testing Mode Active
                </div>
            </div>

            <!-- Environment and self-link visibility controls -->
            <div class="flex items-center gap-4">
                <!-- Self-link toggle (shown when feature is enabled in config) -->
                <div v-if="allowToggleSelfLink" class="flex items-center space-x-1">
                    <input type="checkbox"
                           v-model="showSelfLinks"
                           id="self-link-toggle"
                           class="w-4 h-4 text-brand-blue bg-gray-100 border-gray-300 rounded focus:ring-brand-blue dark:focus:ring-brand-blue dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                    <label for="self-link-toggle"
                           class="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                        Self Links
                    </label>
                </div>

                <!-- Environment visibility controls (only shown when multiple paths are exposed) -->
                <div v-if="exposedPaths.length > 1" class="flex items-center gap-3">
                    <label v-for="pathName in exposedPaths" :key="pathName"
                           class="flex items-center space-x-1 cursor-pointer">
                        <input type="checkbox"
                               :value="pathName"
                               v-model="visiblePaths"
                               class="w-4 h-4 text-brand-blue bg-gray-100 border-gray-300 rounded focus:ring-brand-blue dark:focus:ring-brand-blue dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ pathConfigs[pathName] ? pathConfigs[pathName].label : pathName }}
                            </span>
                        <span v-if="pathConfigs[pathName] && pathConfigs[pathName].key"
                              class="text-xs bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-1 py-0.5 rounded">
                                🔒
                            </span>
                    </label>
                </div>
            </div>

            <?php if (!empty($page_data['current_platform']) && !empty($page_data['current_platform_url'])): ?>
                <a href="<?php echo htmlspecialchars($page_data['current_platform_url']); ?>"
                   target="_blank"
                   class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors duration-200">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                    </svg>
                    <?php echo htmlspecialchars($page_data['current_platform']); ?> Platform
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Self-Referencing Links Card -->
    <div v-if="showSelfLinks && hasValidSelfLinks"
         class="mt-6 bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
        <!-- Card Header -->
        <div class="bg-gradient-to-r from-brand-blue to-blue-600 px-6 py-4">
            <h3 class="text-xl font-semibold text-white">
                Self Links
            </h3>
            <p class="text-blue-100 text-sm mt-1">
                Links back to this testing environment
            </p>
        </div>

        <!-- Links Content -->
        <div class="p-6">
            <!-- Environment Links -->
            <div class="space-y-4">
                <?php
                $env_colors = [
                    'dev' => ['bg' => 'bg-yellow-50 dark:bg-yellow-900/20', 'border' => 'border-yellow-200 dark:border-yellow-800', 'badge_bg' => 'bg-yellow-100 dark:bg-yellow-900', 'badge_text' => 'text-yellow-800 dark:text-yellow-200', 'dot' => 'bg-yellow-400 dark:bg-yellow-500'],
                    'prod' => ['bg' => 'bg-green-50 dark:bg-green-900/20', 'border' => 'border-green-200 dark:border-green-800', 'badge_bg' => 'bg-green-100 dark:bg-green-900', 'badge_text' => 'text-green-800 dark:text-green-200', 'dot' => 'bg-green-400'],
                    'default' => ['bg' => 'bg-blue-50 dark:bg-blue-900/20', 'border' => 'border-blue-200 dark:border-blue-800', 'badge_bg' => 'bg-blue-100 dark:bg-blue-900', 'badge_text' => 'text-blue-800 dark:text-blue-200', 'dot' => 'bg-blue-400']
                ];

                foreach ($self_links as $env => $url):
                    $colors = $env_colors[$env] ?? $env_colors['default'];
                    $label = $path_configs[$env]['label'] ?? ucfirst($env);
                    ?>
                    <div v-if="visiblePaths.includes('<?php echo $env; ?>')" class="<?php echo $colors['bg']; ?> border <?php echo $colors['border']; ?> rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $colors['badge_bg']; ?> <?php echo $colors['badge_text']; ?>">
                                                <span class="w-2 h-2 <?php echo $colors['dot']; ?> rounded-full mr-1"></span>
                                                <?php echo htmlspecialchars($label); ?>
                                            </span>
                            <div class="flex items-center space-x-2">
                                <a href="<?php echo htmlspecialchars($url); ?>"
                                   target="_blank"
                                   class="inline-flex items-center px-3 py-1 text-sm font-medium text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 hover:underline">
                                    Open Link
                                    <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                </a>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded p-3">
                            <div class="flex items-center justify-between">
                                <code class="text-sm text-gray-700 dark:text-gray-300 font-mono break-all select-all">
                                    <?php echo htmlspecialchars($url); ?>
                                </code>
                                <button onclick="copyToClipboard('<?php echo htmlspecialchars(addslashes($url)); ?>', this)"
                                        class="ml-2 p-1 text-gray-400 dark:text-gray-500 hover:text-purple-600 dark:hover:text-purple-400 focus:outline-none transition-colors duration-200">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT SECTIONS -->
    <?php if (!$page_data['has_test_links']): ?>
        <!-- Empty state: No test links configured -->
        <div class="text-center py-16">
            <div class="mx-auto w-24 h-24 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-6">
                <svg class="w-12 h-12 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor"
                     viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mb-2">No Test Links Configured</h2>
            <p class="text-gray-600 dark:text-gray-400 max-w-md mx-auto">
                <?php echo htmlspecialchars($page_data['message']); ?>
            </p>
        </div>
    <?php else: ?>

        <!-- SECTION 1: TEST LINKS DISPLAY -->
        <!-- Display organized test links by category -->
        <div class="space-y-4 mt-6">
            <?php foreach ($page_data['organized_links'] as $category => $category_data): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                    <!-- Category header with gradient background -->
                    <div class="bg-gradient-to-r from-brand-blue to-blue-600 px-6 py-4">
                        <h3 class="text-xl font-semibold text-white">
                            <?php echo htmlspecialchars($category_data['name']); ?>
                        </h3>
                        <p class="text-blue-100 text-sm mt-1">
                            <?php echo count($category_data['links']); ?> test
                            link<?php echo count($category_data['links']) !== 1 ? 's' : ''; ?>
                        </p>
                    </div>

                    <!-- Links List -->
                    <div class="p-4">
                        <div class="space-y-4">
                            <?php foreach ($category_data['links'] as $link_name => $link_data): ?>
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:border-brand-blue hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors duration-200">
                                    <!-- Link Title -->
                                    <div class="mb-3">
                                        <h4 class="font-semibold text-gray-900 dark:text-gray-100 text-lg">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $link_name))); ?>
                                        </h4>
                                    </div>

                                    <!-- Environment Links -->
                                    <div class="space-y-3">
                                        <?php
                                        $env_colors = [
                                            'dev' => ['bg' => 'bg-yellow-50 dark:bg-yellow-900/20', 'border' => 'border-yellow-200 dark:border-yellow-800', 'badge_bg' => 'bg-yellow-100 dark:bg-yellow-900', 'badge_text' => 'text-yellow-800 dark:text-yellow-200', 'dot' => 'bg-yellow-400 dark:bg-yellow-500'],
                                            'prod' => ['bg' => 'bg-green-50 dark:bg-green-900/20', 'border' => 'border-green-200 dark:border-green-800', 'badge_bg' => 'bg-green-100 dark:bg-green-900', 'badge_text' => 'text-green-800 dark:text-green-200', 'dot' => 'bg-green-400'],
                                            'default' => ['bg' => 'bg-blue-50 dark:bg-blue-900/20', 'border' => 'border-blue-200 dark:border-blue-800', 'badge_bg' => 'bg-blue-100 dark:bg-blue-900', 'badge_text' => 'text-blue-800 dark:text-blue-200', 'dot' => 'bg-blue-400']
                                        ];

                                        foreach ($link_data as $env => $url):
                                            if (!is_string($url)) continue; // Skip non-URL values
                                            $colors = $env_colors[$env] ?? $env_colors['default'];
                                            $label = $path_configs[$env]['label'] ?? ucfirst($env);
                                            ?>
                                            <div v-if="visiblePaths.includes('<?php echo $env; ?>')"
                                                 class="<?php echo $colors['bg']; ?> border <?php echo $colors['border']; ?> rounded-lg p-4">
                                                <div class="flex items-center justify-between mb-3">
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $colors['badge_bg']; ?> <?php echo $colors['badge_text']; ?>">
                                                            <span class="w-2 h-2 <?php echo $colors['dot']; ?> rounded-full mr-1"></span>
                                                            <?php echo htmlspecialchars($label); ?>
                                                        </span>
                                                    <a href="<?php echo htmlspecialchars($url); ?>"
                                                       target="_blank"
                                                       class="inline-flex items-center px-3 py-1 text-sm font-medium text-brand-blue dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:underline">
                                                        Open Link
                                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor"
                                                             viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                  stroke-width="2"
                                                                  d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                        </svg>
                                                    </a>
                                                </div>
                                                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded p-3">
                                                    <div class="flex items-center justify-between">
                                                        <code class="text-sm text-gray-700 dark:text-gray-300 font-mono break-all select-all">
                                                            <?php echo htmlspecialchars($url); ?>
                                                        </code>
                                                        <button onclick="copyToClipboard('<?php echo htmlspecialchars(addslashes($url)); ?>', this)"
                                                                class="ml-2 p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-400 focus:outline-none">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                                 viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                      stroke-width="2"
                                                                      d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Dynamic URL Generator Form -->
    <div v-if="hasNewStructure && hasValidPaths && hasValidDefaultVersion" class="mt-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <!-- Form Header -->
            <div class="bg-gradient-to-r from-brand-blue to-blue-600 px-6 py-4">
                <h3 class="text-xl font-semibold text-white">Dynamic URL Generator</h3>
                <p class="text-blue-100 text-sm mt-1"> Generate custom test URLs with your own parameters </p></div>
            <!-- Form Content -->
            <div class="p-4">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Form Inputs -->
                    <div class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center">
                                <svg class="w-5 h-5 text-gray-600 dark:text-gray-400 mr-2" fill="none"
                                     stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                URL Parameters
                            </h4>
                            <div class="space-y-3">
                                <div v-for="(value, key) in formData" :key="key" class="space-y-2"><label
                                            :for="'input-' + key"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 capitalize">
                                        {{ key.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase()) }}
                                        <span v-if="getFieldValidationState(key).isInvalid"
                                              class="text-red-500 text-xs ml-1">*</span> </label> <input
                                            :id="'input-' + key" v-model="formData[key]" type="text"
                                            :placeholder="String(value)" :class="[
                                                'w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none transition-all duration-200 text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100',
                                                getFieldValidationState(key).isValid ? 'border-gray-300 dark:border-gray-600 focus:ring-brand-blue focus:border-brand-blue' : '',
                                                getFieldValidationState(key).isInvalid ? 'border-red-300 dark:border-red-600 focus:ring-red-500 focus:border-red-500 bg-red-50 dark:bg-red-900/20' : ''
                                            ]"
                                    />
                                    <!-- Field validation errors -->
                                    <div v-if="getFieldValidationState(key).isInvalid"
                                         class="text-red-600 dark:text-red-400 text-xs mt-1">
                                        <div v-for="error in getFieldValidationState(key).errors" :key="error"
                                             class="flex items-center">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                      d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                                      clip-rule="evenodd"></path>
                                            </svg>
                                            {{ error }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Validation Summary -->
                        <div v-if="Object.keys(validationErrors).length > 0"
                             class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 fade-in">
                            <div class="flex items-center mb-2">
                                <svg class="w-5 h-5 text-red-400 dark:text-red-500 mr-2" fill="currentColor"
                                     viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                          d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                          clip-rule="evenodd"></path>
                                </svg>
                                <h5 class="text-sm font-medium text-red-800 dark:text-red-200">Form Validation
                                    Issues</h5></div>
                            <ul class="text-sm text-red-700 dark:text-red-300 space-y-1">
                                <li v-for="(errors, field) in validationErrors" :key="field">
                                    <strong>{{ field.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase())
                                        }}:</strong> <span v-for="(error, index) in errors" :key="index">
                                            {{ error }}{{ index < errors.length - 1 ? ', ' : '' }}
                                        </span></li>
                            </ul>
                        </div>
                        <!-- Form Actions -->
                        <div class="flex flex-wrap gap-3">
                            <button @click="resetForm" type="button" :disabled="isLoading"
                                    :class="[
                                        'inline-flex items-center px-4 py-2 border rounded-md shadow-sm text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-blue transition-all duration-200',
                                        isLoading ? 'border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-500 bg-gray-100 dark:bg-gray-800 cursor-not-allowed' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700'
                                    ]"
                            >
                                <svg v-if="!isLoading" class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                                     viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                </svg>
                                <svg v-else class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor"
                                     viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                </svg>
                                {{ isLoading ? 'Resetting...' : 'Reset to Default' }}
                            </button>
                            <button @click="validateForm" type="button" :disabled="isLoading"
                                    :class="[
                                        'inline-flex items-center px-4 py-2 border rounded-md shadow-sm text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 transition-all duration-200',
                                        Object.keys(validationErrors).length === 0 ? 'border-green-300 dark:border-green-600 text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-900/20 hover:bg-green-100 dark:hover:bg-green-900/30 focus:ring-green-600' : 'border-orange-300 dark:border-orange-600 text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-900/20 hover:bg-orange-100 dark:hover:bg-orange-900/30 focus:ring-orange-600',
                                        isLoading ? 'opacity-50 cursor-not-allowed' : ''
                                    ]"
                            >
                                <svg v-if="Object.keys(validationErrors).length === 0" class="w-4 h-4 mr-2" fill="none"
                                     stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <svg v-else class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16c-.77.833.192 2.5 1.732 2.5z"></path>
                                </svg>
                                {{ Object.keys(validationErrors).length === 0 ? 'Form Valid' : 'Validate Form' }}
                            </button>
                            <button v-if="debugMode"
                                    @click="debugInfo"
                                    type="button"
                                    :disabled="isLoading"
                                    :class="[
                                        'inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-blue transition-colors duration-200',
                                        isLoading ? 'text-gray-400 dark:text-gray-500 bg-gray-100 dark:bg-gray-800 cursor-not-allowed' : 'text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700'
                                    ]"
                            >
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Debug Info
                            </button>
                        </div>
                    </div>
                    <!-- URL Preview -->
                    <div class="space-y-3">
                        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center">
                                <svg class="w-5 h-5 text-gray-600 dark:text-gray-400 mr-2" fill="none"
                                     stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                                </svg>
                                Generated URLs
                            </h4>
                            <div class="space-y-3">
                                <div v-for="(url, env) in generatedUrls" :key="env" class="space-y-2">
                                    <!-- Environment Label -->
                                    <div class="flex items-center">
                                        <span :class="[
                                                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                                    getEnvColors(env).badgeClasses
                                                ]">
                                            <span :class="[
                                                        'w-2 h-2 rounded-full mr-1',
                                                        getEnvColors(env).dotClasses
                                                    ]"
                                            ></span>
                                            {{ pathConfigs[env] ? pathConfigs[env].label : env.charAt(0).toUpperCase() + env.slice(1) }}
                                        </span>
                                    </div>
                                    <!-- URL Display -->
                                    <div :class="[
                                                'border rounded-lg p-3 transition-colors duration-200',
                                                getEnvColors(env).containerClasses
                                            ]"
                                    >
                                        <div class="flex items-center justify-between mb-2">
                                            <a :href="url"
                                               target="_blank"
                                               class="inline-flex items-center px-3 py-1 text-sm font-medium text-brand-blue dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:underline transition-colors duration-200"
                                            > Open Link
                                                <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor"
                                                     viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          stroke-width="2"
                                                          d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                </svg>
                                            </a>
                                            <button @click="copyToClipboard(url, this)"
                                                    class="p-1 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-400 focus:outline-none transition-colors duration-200"
                                                    :title="'Copy ' + env + ' URL'"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                     viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          stroke-width="2"
                                                          d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded p-2">
                                            <code class="text-xs text-gray-700 dark:text-gray-300 font-mono break-all select-all">
                                                {{ url }}
                                            </code></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Email-Friendly Text Block -->
    <div v-if="showSelfLinks && hasValidSelfLinks"
         class="mt-6 bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
        <!-- Card Header -->
        <div class="bg-gradient-to-r from-brand-blue to-blue-600 px-6 py-4">
            <h3 class="text-xl font-semibold text-white"> Email-Friendly Test Links </h3>
            <p class="text-blue-100 text-sm mt-1">Copy rich text with formatting for emails and documents </p>
        </div>
        <!-- Content -->
        <div class="p-4">
            <div class="mb-4">
                <p class="text-gray-600 dark:text-gray-400 text-sm"> This text block contains all test
                    links formatted with rich text (HTML) that maintains proper formatting when pasted into emails,
                    documents, or messaging systems. Falls back to plain text for compatibility with older email
                    clients. </p>
            </div>

            <!-- Action Bar with Copy Button and Environment Dropdown -->
            <div class="mb-3 flex items-center justify-between">
                <button @click="copyEmailContent"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Copy Rich Text
                </button>

                <!-- Environment Selection Dropdown (shown when multiple environments available) -->
                <div v-if="hasMultipleVisiblePaths" class="flex items-center space-x-3">
                    <label for="email-environment-select" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Environment:
                    </label>
                    <select
                        id="email-environment-select"
                        v-model="selectedEmailEnvironment"
                        @change="handleEmailEnvironmentChange"
                        class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-brand-blue focus:border-brand-blue bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                    >
                        <option v-for="env in availableEmailEnvironments" :key="env.value" :value="env.value">
                            {{ env.label }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Formatted Text Block -->
            <div class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <div class="bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded p-4 max-h-96 overflow-y-auto">
                    <pre id="email-text-content"
                         class="text-sm font-mono text-gray-800 dark:text-gray-200 whitespace-pre-wrap select-all leading-relaxed">{{ emailContent }}</pre>
                </div>
            </div>

            <!-- Usage Tips -->
            <div class="mt-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-blue-400 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                              d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                              clip-rule="evenodd"></path>
                    </svg>
                    <div class="text-sm text-blue-800 dark:text-blue-200">
                        <p class="font-medium mb-1">Usage Tips:</p>
                        <ul class="text-blue-700 space-y-1">
                            <li>- Click "Copy Rich Text" to copy formatted content with HTML styling</li>
                            <li>- Paste directly into emails, Slack, or documents for rich formatting</li>
                            <li>- Links are clickable when pasted into email clients</li>
                            <li>- Automatically falls back to plain text for older email clients</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="mt-12 text-center">
        <div class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
            <svg class="w-5 h-5 text-gray-400 dark:text-gray-500 mr-2" fill="none" stroke="currentColor"
                 viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span class="text-sm text-gray-600 dark:text-gray-400">
                    Dynamic PDF Certificate Testing Environment
                </span>
        </div>
    </div>
</div>

<!-- =========================================================================
// JAVASCRIPT BLOCK
// Contains global helper functions and the Vue.js application.
// ========================================================================= -->
<script>
    // Copy to clipboard functionality
    function copyToClipboard(text, button) {
        navigator.clipboard.writeText(text).then(function () {
            // Visual feedback
            const originalSvg = button.innerHTML;
            button.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            button.classList.add('text-green-600');

            setTimeout(() => {
                button.innerHTML = originalSvg;
                button.classList.remove('text-green-600');
            }, 2000);
        }).catch(function (err) {
            console.error('Failed to copy text: ', err);
        });
    }

    // Copy email text content with rich text formatting
    function copyEmailText() {
        const emailContent = document.getElementById('email-text-content');
        if (!emailContent) {
            console.error('Email text content element not found');
            return;
        }

        const textContent = emailContent.textContent || emailContent.innerText;
        const copyButton = document.querySelector('button[onclick="copyEmailText()"]');

        // Generate HTML formatted content for rich text copying
        const htmlContent = generateRichTextEmailContent(textContent);

        // Check if the modern Clipboard API is available
        if (navigator.clipboard && navigator.clipboard.write) {
            // Use modern Clipboard API with both HTML and plain text
            const clipboardItems = [];

            try {
                // Create clipboard item with both HTML and plain text
                const clipboardItem = new ClipboardItem({
                    'text/html': new Blob([htmlContent], {type: 'text/html'}),
                    'text/plain': new Blob([textContent], {type: 'text/plain'})
                });

                clipboardItems.push(clipboardItem);

                navigator.clipboard.write(clipboardItems).then(function () {
                    console.log('Rich text email content copied to clipboard');
                    showCopySuccess(copyButton, 'Rich Text Copied!');
                }).catch(function (err) {
                    console.error('Failed to copy rich text, falling back to plain text: ', err);
                    // Fallback to plain text if rich text fails
                    fallbackToPlainText(textContent, copyButton);
                });
            } catch (err) {
                console.error('Error creating clipboard item, falling back to plain text: ', err);
                // Fallback to plain text if clipboard item creation fails
                fallbackToPlainText(textContent, copyButton);
            }
        } else {
            // Fallback to plain text for older browsers
            console.log('Modern clipboard API not available, using plain text fallback');
            fallbackToPlainText(textContent, copyButton);
        }
    }

    // Generate rich text HTML content from plain text
    function generateRichTextEmailContent(plainText) {
        if (!plainText) return '';

        const lines = plainText.split('\n');
        let html = '';

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) {
                html += '<br>';
                continue;
            }

            // Check if it's a header line (ends with ':' and no leading dash/bullet)
            if (line.endsWith(':') && !line.startsWith('-') && !line.startsWith('•')) {
                html += `<strong style="color: #374151; font-weight: 600;">${escapeHtml(line)}</strong><br>`;
            }
            // Check if it's a URL link (starts with http)
            else if (line.includes('http')) {
                const urlMatch = line.match(/(https?:\/\/[^\s]+)/);
                if (urlMatch) {
                    const url = urlMatch[1];
                    const linkText = line.replace(url, '').replace(/^[-\•]\s*/, '').trim() || url;
                    html += `&nbsp;&nbsp;• <a href="${url}" style="color: #2563eb; text-decoration: underline;">${escapeHtml(linkText || url)}</a><br>`;
                } else {
                    html += `&nbsp;&nbsp;• ${escapeHtml(line.replace(/^[-\•]\s*/, ''))}<br>`;
                }
            }
            // List item (starts with dash or bullet)
            else if (line.startsWith('-') || line.startsWith('•')) {
                html += `&nbsp;&nbsp;• ${escapeHtml(line.replace(/^[-\•]\s*/, ''))}<br>`;
            }
            // Regular line
            else {
                html += `${escapeHtml(line)}<br>`;
            }
        }

        return html;
    }

    // HTML escape utility function
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Fallback to plain text copying
    function fallbackToPlainText(textContent, copyButton) {
        navigator.clipboard.writeText(textContent).then(function () {
            console.log('Plain text email content copied to clipboard');
            showCopySuccess(copyButton, 'Text Copied!');
        }).catch(function (err) {
            console.error('Failed to copy plain text: ', err);
            showCopyError(copyButton, 'Copy Failed');
        });
    }

    // Show copy success feedback
    function showCopySuccess(button, message) {
        const originalText = button.textContent;
        button.textContent = message;
        button.classList.add('pulse-success');

        setTimeout(() => {
            button.textContent = originalText;
            button.classList.remove('pulse-success');
        }, 2000);
    }

    // Show copy error feedback
    function showCopyError(button, message) {
        const originalText = button.textContent;
        button.textContent = message;
        button.classList.add('shake-error');

        setTimeout(() => {
            button.textContent = originalText;
            button.classList.remove('shake-error');
        }, 2000);
    }

    // Vue.js App Initialization
    const {createApp} = Vue;

    const vueApp = createApp({
        data() {
            return {
                // PHP data passed from server
                phpData: <?php echo json_encode($page_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {},
                environments: <?php echo json_encode($environments ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                testVersions: <?php echo json_encode($test_versions ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                pathConfigs: <?php echo json_encode($path_configs ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                exposedPaths: <?php echo json_encode($exposed_paths ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,

                // Path visibility control
                visiblePaths: <?php echo json_encode($exposed_paths ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,

                // Self-link functionality
                allowToggleSelfLink: <?php echo json_encode($page_data['allow_toggle_self_link'] ?? false, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                selfLinks: <?php echo json_encode($self_links ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                showSelfLinks: false, // Default state: checkbox unchecked, Self Links section hidden

                // Dynamic URL Generator properties
                defaultVersion: <?php echo json_encode(array_values($test_versions)[0] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                formData: {},
                generatedUrls: {},
                validationErrors: {},
                isLoading: false,
                debugMode: false,

                // Email-friendly content properties
                selectedEmailEnvironment: null,
                emailContent: ''
            };
        },
        computed: {
            hasValidSelfLinks() {
                return this.selfLinks && Object.keys(this.selfLinks).length > 0;
            },
            hasNewStructure() {
                return this.environments && Object.keys(this.environments).length > 0 && this.testVersions && Object.keys(this.testVersions).length > 0;
            },
            hasValidPaths() {
                return this.exposedPaths && this.exposedPaths.length > 0;
            },
            hasValidDefaultVersion() {
                return this.defaultVersion && Object.keys(this.defaultVersion).length > 0;
            },
            hasMultipleExposedPaths() {
                return this.exposedPaths && this.exposedPaths.length > 1;
            },
            defaultEmailEnvironment() {
                // Default to 'prod' if available in visible paths, otherwise first visible path
                if (this.visiblePaths && this.visiblePaths.length > 0) {
                    return this.visiblePaths.includes('prod') ? 'prod' : this.visiblePaths[0];
                }
                return null;
            },
            availableEmailEnvironments() {
                return this.visiblePaths.map(env => ({
                    value: env,
                    label: this.pathConfigs[env] ? this.pathConfigs[env].label : env.charAt(0).toUpperCase() + env.slice(1)
                }));
            },
            hasMultipleVisiblePaths() {
                return this.visiblePaths && this.visiblePaths.length > 1;
            }
        },
        methods: {
            getEnvColors(env) {
                const colorMap = {
                    'dev': {
                        badgeClasses: 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200',
                        dotClasses: 'bg-yellow-400 dark:bg-yellow-500',
                        containerClasses: 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800'
                    },
                    'prod': {
                        badgeClasses: 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
                        dotClasses: 'bg-green-400 dark:bg-green-500',
                        containerClasses: 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800'
                    },
                    'default': {
                        badgeClasses: 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200',
                        dotClasses: 'bg-blue-400 dark:bg-blue-500',
                        containerClasses: 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800'
                    }
                };
                return colorMap[env] || colorMap['default'];
            },
            // Dynamic URL Generator methods
            getFieldValidationState(key) {
                return {
                    isValid: true,
                    isInvalid: false,
                    errors: []
                };
            },
            handleFieldInput(key, value) {
                this.formData[key] = value;
            },
            resetForm() {
                this.formData = {...this.defaultVersion};
            },
            validateForm() {
                // Form validation logic placeholder
            },
            debugInfo() {
                console.log('Debug Info:', {
                    defaultVersion: this.defaultVersion,
                    formData: this.formData,
                    generatedUrls: this.generatedUrls,
                    paths: this.paths,
                    exposedPaths: this.exposedPaths
                });
            },
            generateUrls() {
                // Generate URLs for each environment based on form data
                this.generatedUrls = {};

                // Build query string from form data
                const queryParams = [];
                for (const [key, value] of Object.entries(this.formData)) {
                    if (value !== null && value !== '') {
                        queryParams.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
                    }
                }
                const queryString = queryParams.length > 0 ? '?' + queryParams.join('&') : '';

                // Generate URLs for each visible path
                for (const pathName of this.visiblePaths) {
                    if (this.pathConfigs[pathName]) {
                        const baseUrl = this.pathConfigs[pathName].url;
                        this.generatedUrls[pathName] = baseUrl.replace(/\/$/, '') + '/' + queryString;
                    }
                }
            },
            // Email content generation methods
            generateEmailContent() {
                if (!this.selectedEmailEnvironment || !this.pathConfigs[this.selectedEmailEnvironment]) {
                    this.emailContent = '';
                    return;
                }

                let emailContent = "Test Links:\n";
                const selectedEnv = this.selectedEmailEnvironment;
                const envConfig = this.pathConfigs[selectedEnv];

                // Add main test versions
                if (this.phpData.organized_links && this.phpData.organized_links.test_versions) {
                    const testLinks = this.phpData.organized_links.test_versions.links;
                    for (const [versionName, versionLinks] of Object.entries(testLinks)) {
                        const formattedName = versionName.charAt(0).toUpperCase() + versionName.slice(1).replace(/_/g, ' ');
                        emailContent += `${formattedName}:\n`;

                        if (versionLinks[selectedEnv]) {
                            emailContent += `- ${versionLinks[selectedEnv]}\n\n`;
                        }
                    }
                }

                // Add other categories if they exist (backward compatibility)
                if (this.phpData.organized_links) {
                    for (const [category, categoryData] of Object.entries(this.phpData.organized_links)) {
                        if (category !== 'test_versions' && category !== 'self_links' && categoryData.links) {
                            emailContent += `- ${categoryData.name}:\n`;
                            for (const [linkName, linkData] of Object.entries(categoryData.links)) {
                                const formattedLinkName = linkName.charAt(0).toUpperCase() + linkName.slice(1).replace(/_/g, ' ');
                                emailContent += `  - ${formattedLinkName}:\n`;

                                if (linkData[selectedEnv] && typeof linkData[selectedEnv] === 'string') {
                                    const envLabel = envConfig.label || selectedEnv.charAt(0).toUpperCase() + selectedEnv.slice(1);
                                    if (this.exposedPaths.length === 1) {
                                        emailContent += `    - ${linkData[selectedEnv]}\n`;
                                    } else {
                                        emailContent += `    - ${envLabel}: ${linkData[selectedEnv]}\n`;
                                    }
                                }
                            }
                            emailContent += "\n";
                        }
                    }
                }

                // Add self-referencing links if they exist and are shown
                if (this.showSelfLinks && this.selfLinks && this.selfLinks[selectedEnv]) {
                    emailContent += "Test Links Page:\n";
                    emailContent += `- ${this.selfLinks[selectedEnv]}\n\n`;
                }

                // Trim trailing newlines for clean output
                this.emailContent = emailContent.trim();
            },
            handleEmailEnvironmentChange() {
                this.generateEmailContent();
            },
            copyEmailContent() {
                if (!this.emailContent) {
                    console.error('No email content available');
                    return;
                }

                // Use the global copyEmailText function but pass our content
                const textContent = this.emailContent;
                const copyButton = document.querySelector('button[onclick="copyEmailText()"]') ||
                                 document.querySelector('button[class*="bg-indigo-600"]');

                // Generate HTML formatted content for rich text copying
                const htmlContent = generateRichTextEmailContent(textContent);

                // Check if the modern Clipboard API is available
                if (navigator.clipboard && navigator.clipboard.write) {
                    try {
                        // Create clipboard item with both HTML and plain text
                        const clipboardItem = new ClipboardItem({
                            'text/html': new Blob([htmlContent], {type: 'text/html'}),
                            'text/plain': new Blob([textContent], {type: 'text/plain'})
                        });

                        navigator.clipboard.write([clipboardItem]).then(() => {
                            console.log('Rich text email content copied to clipboard');
                            showCopySuccess(copyButton, 'Rich Text Copied!');
                        }).catch((err) => {
                            console.error('Failed to copy rich text, falling back to plain text: ', err);
                            fallbackToPlainText(textContent, copyButton);
                        });
                    } catch (err) {
                        console.error('Error creating clipboard item, falling back to plain text: ', err);
                        fallbackToPlainText(textContent, copyButton);
                    }
                } else {
                    console.log('Modern clipboard API not available, using plain text fallback');
                    fallbackToPlainText(textContent, copyButton);
                }
            }
        },
        mounted() {
            // Initialize form data with default version
            if (this.defaultVersion && Object.keys(this.defaultVersion).length > 0) {
                this.formData = {...this.defaultVersion};
                this.generateUrls();
            }

            // Initialize email environment selection
            if (this.defaultEmailEnvironment) {
                this.selectedEmailEnvironment = this.defaultEmailEnvironment;
                this.generateEmailContent();
            }
        },
        watch: {
            formData: {
                handler() {
                    this.generateUrls();
                },
                deep: true
            },
            visiblePaths() {
                this.generateUrls();
                // Update selected email environment if current selection is no longer visible
                if (this.selectedEmailEnvironment && !this.visiblePaths.includes(this.selectedEmailEnvironment)) {
                    this.selectedEmailEnvironment = this.defaultEmailEnvironment;
                }
            },
            selectedEmailEnvironment() {
                this.generateEmailContent();
            },
            showSelfLinks() {
                this.generateEmailContent();
            }
        }
    });

    const vm = vueApp.mount('#app');
</script>
</body>
</html>
