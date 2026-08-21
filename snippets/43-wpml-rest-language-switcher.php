
/**
 * WPML REST API Language Switcher
 * Description: Custom endpoints (v1/posts, v1/pages, wph/v2/*) থেকে locale অনুযায়ী translated content return করে
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {
    if (!defined('REST_REQUEST') || !REST_REQUEST) return;
    
    // Next.js already sends ?locale=en or ?locale=ca - read it and switch WPML
    $locale = isset($_GET['locale']) ? sanitize_key($_GET['locale']) : '';
    
    if ($locale && function_exists('wpml_switch_language')) {
        wpml_switch_language($locale);
    }
});

// Extra safety: also hook on rest_pre_serve_request
add_action('rest_pre_serve_request', function ($result) {
    $locale = isset($_GET['locale']) ? sanitize_key($_GET['locale']) : '';
    if ($locale && function_exists('wpml_switch_language')) {
        wpml_switch_language($locale);
    }
    return $result;
});