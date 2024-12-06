<?php
/**
 * Plugin Name: PK Elementor API
 * Description: API endpoints for PK Elementor extensions management.
 * Version: 1.0.0
 * Author: Pieter Keuzenkamp
 * Author URI: https://www.pieterkeuzenkamp.nl
 */

if (!defined('ABSPATH')) {
    exit;
}

class PK_Elementor_API {
    /**
     * Class instance.
     *
     * @var PK_Elementor_API
     */
    private static $_instance = null;

    private $rate_limit = 60; // requests per minute
    private $cache_expiry = 3600; // 1 hour

    /**
     * Get class instance.
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('rest_pre_dispatch', [$this, 'check_rate_limit'], 10, 3);
        add_filter('rest_pre_serve_request', [$this, 'add_cors_headers']);
        add_filter('rest_post_dispatch', [$this, 'add_version_headers']);
    }

    /**
     * Register API routes.
     */
    public function register_routes() {
        register_rest_route('pk-elementor/v1', '/updates/check', [
            'methods' => 'POST',
            'callback' => [$this, 'check_updates'],
            'permission_callback' => [$this, 'verify_request'],
        ]);

        register_rest_route('pk-elementor/v1', '/updates/info', [
            'methods' => 'POST',
            'callback' => [$this, 'get_plugin_info'],
            'permission_callback' => [$this, 'verify_request'],
        ]);

        register_rest_route('pk-elementor/v1', '/license/activate', [
            'methods' => 'POST',
            'callback' => [$this, 'activate_license'],
            'permission_callback' => [$this, 'verify_request'],
        ]);

        register_rest_route('pk-elementor/v1', '/license/deactivate', [
            'methods' => 'POST',
            'callback' => [$this, 'deactivate_license'],
            'permission_callback' => [$this, 'verify_request'],
        ]);

        register_rest_route('pk-elementor/v1', '/license/check', [
            'methods' => 'POST',
            'callback' => [$this, 'check_license'],
            'permission_callback' => [$this, 'verify_request'],
        ]);

        register_rest_route('pk-elementor/v1', '/download', [
            'methods' => 'POST',
            'callback' => [$this, 'get_download_url'],
            'permission_callback' => [$this, 'verify_request'],
        ]);
    }

    /**
     * Verify API request.
     */
    public function verify_request($request) {
        // Add your authentication logic here
        return true;
    }

    /**
     * Check rate limit.
     */
    public function check_rate_limit($response, $handler, $request) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $key = 'pk_elementor_api_rate_' . md5($ip);
        $count = (int)get_transient($key);

        if ($count >= $this->rate_limit) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Rate limit exceeded. Please try again later.', 'pk-elementor-api'),
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, 60);
        return $response;
    }

    /**
     * Add CORS headers.
     */
    public function add_cors_headers() {
        header('Access-Control-Allow-Origin: ' . esc_url_raw(site_url()));
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Credentials: true');
        return true;
    }

    /**
     * Add version headers.
     */
    public function add_version_headers($response) {
        $response->header('X-PK-API-Version', '1.0.0');
        return $response;
    }

    /**
     * Get cached response.
     */
    private function get_cached_response($cache_key) {
        return get_transient($cache_key);
    }

    /**
     * Set cached response.
     */
    private function set_cached_response($cache_key, $data) {
        set_transient($cache_key, $data, $this->cache_expiry);
    }

    /**
     * Check for updates.
     */
    public function check_updates($request) {
        $params = $request->get_params();
        $slug = sanitize_text_field($params['slug']);
        $version = sanitize_text_field($params['version']);
        $license_key = sanitize_text_field($params['license_key'] ?? '');

        // Get extension data from the database
        $extension = $this->get_extension_data($slug);

        if (!$extension) {
            return new WP_Error('extension_not_found', __('Extension not found.', 'pk-elementor-api'));
        }

        // Check if update is available
        if (version_compare($version, $extension['latest_version'], '<')) {
            return [
                'success' => true,
                'new_version' => $extension['latest_version'],
                'package' => $this->get_package_url($slug, $license_key),
                'tested' => $extension['tested'],
                'requires' => $extension['requires'],
                'requires_php' => $extension['requires_php'],
            ];
        }

        return ['success' => false];
    }

    /**
     * Get plugin information.
     */
    public function get_plugin_info($request) {
        $params = $request->get_params();
        $slug = sanitize_text_field($params['slug']);
        $license_key = sanitize_text_field($params['license_key'] ?? '');

        $cache_key = 'pk_elementor_api_info_' . md5($slug . $license_key);
        $cached_response = $this->get_cached_response($cache_key);

        if ($cached_response !== false) {
            return $cached_response;
        }

        // Get extension data from the database
        $extension = $this->get_extension_data($slug);

        if (!$extension) {
            return new WP_Error(
                'extension_not_found',
                __('Extension not found.', 'pk-elementor-api'),
                ['status' => 404]
            );
        }

        $response = [
            'name' => $extension['name'],
            'version' => $extension['latest_version'],
            'author' => 'Pieter Keuzenkamp',
            'author_profile' => 'https://www.pieterkeuzenkamp.nl',
            'requires' => $extension['requires'],
            'tested' => $extension['tested'],
            'requires_php' => $extension['requires_php'],
            'sections' => [
                'description' => $extension['description'],
                'changelog' => $extension['changelog'],
            ],
            'banners' => $extension['banners'],
            'download_link' => $this->get_package_url($slug, $license_key),
        ];

        $this->set_cached_response($cache_key, $response);
        return $response;
    }

    /**
     * Activate license.
     */
    public function activate_license($request) {
        $params = $request->get_params();
        $extension = sanitize_text_field($params['extension']);
        $license_key = sanitize_text_field($params['license_key']);
        $site_url = esc_url_raw($params['site_url']);

        // Verify license key
        $license = $this->verify_license_key($extension, $license_key);

        if (!$license) {
            return new WP_Error('invalid_license', __('Invalid license key.', 'pk-elementor-api'));
        }

        // Register site URL with license
        $this->register_site_url($license_key, $site_url);

        return [
            'success' => true,
            'message' => __('License activated successfully.', 'pk-elementor-api'),
            'expiry' => $license['expiry'],
        ];
    }

    /**
     * Deactivate license.
     */
    public function deactivate_license($request) {
        $params = $request->get_params();
        $extension = sanitize_text_field($params['extension']);
        $license_key = sanitize_text_field($params['license_key']);
        $site_url = esc_url_raw($params['site_url']);

        // Unregister site URL from license
        $this->unregister_site_url($license_key, $site_url);

        return [
            'success' => true,
            'message' => __('License deactivated successfully.', 'pk-elementor-api'),
        ];
    }

    /**
     * Check license status.
     */
    public function check_license($request) {
        $params = $request->get_params();
        $extension = sanitize_text_field($params['extension']);
        $license_key = sanitize_text_field($params['license_key']);
        $site_url = esc_url_raw($params['site_url']);

        // Verify license key and site URL
        $license = $this->verify_license_key($extension, $license_key);

        if (!$license) {
            return [
                'status' => 'invalid',
                'message' => __('Invalid license key.', 'pk-elementor-api'),
            ];
        }

        if (!$this->verify_site_url($license_key, $site_url)) {
            return [
                'status' => 'inactive',
                'message' => __('License not activated for this site.', 'pk-elementor-api'),
            ];
        }

        return [
            'status' => 'valid',
            'message' => __('License is valid and active.', 'pk-elementor-api'),
            'expiry' => $license['expiry'],
        ];
    }

    /**
     * Get download URL.
     */
    public function get_download_url($request) {
        $params = $request->get_params();
        $extension = sanitize_text_field($params['extension']);
        $license_key = sanitize_text_field($params['license_key'] ?? '');
        $site_url = esc_url_raw($params['site_url']);

        // Verify license for PRO versions
        if ($this->is_pro_extension($extension)) {
            $license = $this->verify_license_key($extension, $license_key);
            if (!$license || !$this->verify_site_url($license_key, $site_url)) {
                return new WP_Error('invalid_license', __('Valid license required for PRO version.', 'pk-elementor-api'));
            }
        }

        $url = $this->get_package_url($extension, $license_key);

        if (!$url) {
            return new WP_Error('download_failed', __('Failed to generate download URL.', 'pk-elementor-api'));
        }

        return [
            'success' => true,
            'download_url' => $url,
        ];
    }

    /**
     * Get extension data from database.
     */
    private function get_extension_data($slug) {
        // Implement your database query here
        // This is just an example structure
        $extensions = [
            'pk-elementor-service-box' => [
                'name' => 'PK Elementor Service Box',
                'latest_version' => '2.0.0',
                'requires' => '5.0',
                'tested' => '6.4',
                'requires_php' => '7.0',
                'description' => 'Create beautiful service boxes with icons and descriptions.',
                'changelog' => '= 2.0.0 =\n* Added Hub integration\n* Improved UI',
                'banners' => [
                    'high' => 'https://www.pieterkeuzenkamp.nl/wp-content/banners/service-box-banner-1544x500.jpg',
                    'low' => 'https://www.pieterkeuzenkamp.nl/wp-content/banners/service-box-banner-772x250.jpg',
                ],
            ],
        ];

        return $extensions[$slug] ?? null;
    }

    /**
     * Get package download URL.
     */
    private function get_package_url($slug, $license_key = '') {
        // Implement your download URL generation logic here
        return 'https://www.pieterkeuzenkamp.nl/wp-content/downloads/' . $slug . '.zip';
    }

    /**
     * Verify license key.
     */
    private function verify_license_key($extension, $license_key) {
        // Implement your license verification logic here
        return [
            'valid' => true,
            'expiry' => '2025-12-31',
        ];
    }

    /**
     * Register site URL with license.
     */
    private function register_site_url($license_key, $site_url) {
        // Implement your site registration logic here
    }

    /**
     * Unregister site URL from license.
     */
    private function unregister_site_url($license_key, $site_url) {
        // Implement your site unregistration logic here
    }

    /**
     * Verify site URL for license.
     */
    private function verify_site_url($license_key, $site_url) {
        // Implement your site verification logic here
        return true;
    }

    /**
     * Check if extension is PRO version.
     */
    private function is_pro_extension($extension) {
        // Implement your PRO version check logic here
        return false;
    }
}

// Initialize the plugin
PK_Elementor_API::instance();
