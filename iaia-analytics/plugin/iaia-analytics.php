<?php
/**
 * Plugin Name:       IAIA Analytics
 * Plugin URI:        https://iaiapro.com
 * Description:       Analítica web propia, sin cookies y sin terceros: los datos nunca salen de tu servidor. Sin IP ni User-Agent almacenados; visitantes anónimos con hash diario irreversible.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            IAIA
 * License:           GPL-2.0-or-later
 * Text Domain:       iaia-analytics
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('IAIA_ANALYTICS_VERSION', '0.1.0');
define('IAIA_ANALYTICS_FILE', __FILE__);
define('IAIA_ANALYTICS_DIR', __DIR__);
define('IAIA_ANALYTICS_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/EventRecorder.php';
require_once __DIR__ . '/includes/RollupService.php';
require_once __DIR__ . '/includes/StatsService.php';
require_once __DIR__ . '/includes/Ga4Importer.php';
require_once __DIR__ . '/includes/RestController.php';
require_once __DIR__ . '/includes/Tracker.php';
require_once __DIR__ . '/includes/AdminPage.php';

register_activation_hook(__FILE__, ['IaiaAnalytics\\Schema', 'activate']);

add_action('rest_api_init', ['IaiaAnalytics\\RestController', 'registerRoutes']);
add_action('wp_enqueue_scripts', ['IaiaAnalytics\\Tracker', 'enqueue']);
add_action('admin_menu', ['IaiaAnalytics\\AdminPage', 'registerMenu']);
add_action('admin_enqueue_scripts', ['IaiaAnalytics\\AdminPage', 'enqueueAssets']);
