<?php
/**
 * Plugin Name: Auto Reviews
 * Description: Автоматическое управление отзывами: очередь, импорт из Google Таблиц, генерация через GPT и динамическая микроразметка.
 * Author: Vlad
 * Version: 1.0.1
 * Text Domain: auto-reviews
 *
 * Bootstrap-файл. Вся логика лежит в `includes/` в неймспейсе `AutoReviews`.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AUTO_REVIEWS_VERSION', '1.0.0' );
define( 'AUTO_REVIEWS_PLUGIN_FILE', __FILE__ );
define( 'AUTO_REVIEWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Auto-updates via Plugin Update Checker (GitHub).
if ( file_exists( AUTO_REVIEWS_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
    require AUTO_REVIEWS_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

    if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        $auto_reviews_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/CruentoVulpes/auto-reviews/',
            __FILE__,
            'auto-reviews'
        );

        $auto_reviews_update_checker->setBranch( 'main' );
    }
}

// Подключаем классы.
require_once AUTO_REVIEWS_PLUGIN_DIR . 'includes/class-env.php';
require_once AUTO_REVIEWS_PLUGIN_DIR . 'includes/class-plugin.php';

// Bootstrap.
add_action(
    'plugins_loaded',
    function () {
        \AutoReviews\Plugin::instance();
    }
);

register_activation_hook( __FILE__, [ '\AutoReviews\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\AutoReviews\Plugin', 'deactivate' ] );

