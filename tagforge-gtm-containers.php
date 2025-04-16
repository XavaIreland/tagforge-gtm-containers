<?php
/*
Plugin Name: TagForge GTM Containers
Description: Custom GTM container generation and secure download for WooCommerce, using ACF for container type.
Version: 1.3
Author: Amit Wadhwa
*/

defined('ABSPATH') || exit;

class TagForge_Container_Factory {
    // Supported container types and their template files/placeholders
    public static $container_templates = [
        'ga4' => [
            'file' => 'templates/ga4-container.json',
            'fields' => ['GA4 ID']
        ],
        'fbp' => [
            'file' => 'templates/facebook-pixel.json',
            'fields' => ['pixel_id', 'events']
        ],
        'lii' => [
            'file' => 'templates/linkedin-insight.json',
            'fields' => ['partner_id']
        ],
    ];

    public static function generate_container($type, $data, $order_id) {
        if (!isset(self::$container_templates[$type])) {
            throw new Exception("Invalid container type: $type");
        }

        // Validate required fields
        foreach (self::$container_templates[$type]['fields'] as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        // Load template
        $template_path = plugin_dir_path(__FILE__) . self::$container_templates[$type]['file'];
        if (!file_exists($template_path)) {
            throw new Exception("Template not found: $template_path");
        }

        $json = file_get_contents($template_path);

        // Customize template
        $json = self::customize_container($type, $json, $data);

        // Save file
        $upload_dir = wp_upload_dir();
        $save_path = $upload_dir['basedir'] . '/tagforge-containers/';
        if (!file_exists($save_path)) {
            wp_mkdir_p($save_path);
        }

        $filename = sanitize_file_name("{$type}-{$order_id}-" . time() . ".json");
        $full_path = $save_path . $filename;

        if (!file_put_contents($full_path, $json)) {
            throw new Exception("Failed to save container file: $full_path");
        }

        if (!file_exists($full_path)) {
            throw new Exception("File creation failed: $full_path");
        }

        return [
            'path' => $full_path,
            'url' => $upload_dir['baseurl'] . '/tagforge-containers/' . $filename,
            'expires' => time() + (7 * DAY_IN_SECONDS)
        ];
    }

    private static function customize_container($type, $json, $data) {
        switch ($type) {
            case 'ga4':
                return str_replace(
                    ['MEASUREMENT_ID_PLACEHOLDER'],
                    [$data['GA4 ID']],
                    $json
                );
            case 'fbp':
                return str_replace(
                    ['PIXEL_ID_PLACEHOLDER', 'EVENTS_PLACEHOLDER'],
                    [$data['pixel_id'], json_encode($data['events'])],
                    $json
                );
            case 'lii':
                return str_replace(
                    ['PARTNER_ID_PLACEHOLDER'],
                    [$data['partner_id']],
                    $json
                );
            default:
                return $json;
        }
    }
}

// ======================
// WooCommerce Integration
// ======================

add_action('woocommerce_order_status_completed', 'tagforge_process_order');
function tagforge_process_order($order_id) {
    error_log("[TagForge] Processing order $order_id");
    
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log("[TagForge] Order $order_id not found");
        return;
    }

    foreach ($order->get_items() as $item_id => $item) {
        error_log("[TagForge] Processing item $item_id");
        
        $product = $item->get_product();
        if (!$product) {
            error_log("[TagForge] Product not found for item $item_id");
            continue;
        }

        // Get ACF field
        $container_type = get_field('gtm_container_type', $product->get_id());
        error_log("[TagForge] Container type for product {$product->get_id()}: " . ($container_type ?: 'NOT SET'));
        
        if (!$container_type) {
            error_log("[TagForge] Skipping item - no container type");
            continue;
        }

        // Collect custom data
        $custom_data = [];
        foreach (TagForge_Container_Factory::$container_templates[$container_type]['fields'] as $field) {
            $value = $item->get_meta($field);
            $custom_data[$field] = $value;
            error_log("[TagForge] Field $field value: " . ($value ?: 'EMPTY'));
        }

        try {
            error_log("[TagForge] Generating container for $container_type");
            $container_info = TagForge_Container_Factory::generate_container(
                $container_type,
                $custom_data,
                $order_id
            );
            
            $order->update_meta_data("tagforge_container_{$item_id}", $container_info);
            error_log("[TagForge] Container generated: " . $container_info['path']);

        } catch (Exception $e) {
            error_log("[TagForge] Error: " . $e->getMessage());
            continue;
        }
    }
    
    $order->save();
    error_log("[TagForge] Order $order_id processed");
}


// ==================
// Secure File Delivery
// ==================

add_action('init', 'tagforge_handle_download');
/*
function tagforge_handle_download() {
    // Check if the request has the necessary parameters
    if (!isset($_GET['tagforge_download'], $_GET['order_id'])) {
        // Silently exit for all other requests
        return;
    }

    // Sanitize input parameters
    $token = sanitize_text_field($_GET['tagforge_download']);
    $order_id = absint($_GET['order_id']);

    try {
        // Validate the download token and retrieve container data
        $container_data = tagforge_validate_download_token($token, $order_id);
        
        // Deliver the file if validation succeeds
        tagforge_deliver_file($container_data['path']);
    } catch (Exception $e) {
        // Log the error for debugging purposes
        error_log("[TagForge] Download failed: " . $e->getMessage());
        
        // Show a user-friendly error message only for relevant requests
        wp_die($e->getMessage(), 'Download Error', ['response' => 403]);
    }
}
*/
function tagforge_handle_download() {
    if (!isset($_GET['tagforge_download'], $_GET['order_id'])) {
        return; // Silent exit for non-download requests
    }

    $token = sanitize_text_field($_GET['tagforge_download']);
    $order_id = absint($_GET['order_id']);

    try {
        $container_data = tagforge_validate_download_token($token, $order_id);
        error_log ("[Tagforge Handle Download]: Container data: ". print_r($container_data, true));

        if (empty($container_data['path'])) {
            throw new Exception("File path not found in token data");
        }

        tagforge_deliver_file($container_data['path']);
    } catch (Exception $e) {
        error_log("[TagForge] Download failed: " . $e->getMessage());
        wp_die($e->getMessage(), 'Download Error', ['response' => 403]);
    }
}




function tagforge_generate_download_url($container_data, $order_id) {
    $token = tagforge_create_download_token($container_data, $order_id);
    
    error_log("[TagForge Generate DL URL] Generated download URL: " . home_url('/?tagforge_download=' . urlencode($token) . '&order_id=' . $order_id));

    return add_query_arg([
        'tagforge_download' => $token,
        'order_id' => $order_id
    ], home_url('/'));
}


function tagforge_create_download_token($container_data, $order_id) {
    $data = [
        'order_id' => $order_id,
        'path' => $container_data['path'], // Changed key from 'file' to 'path'
        'expires' => $container_data['expires']
    ];
    return urlencode(base64_encode(json_encode($data)));
}


function tagforge_validate_download_token($token, $order_id) {
    error_log("[TagForge] Validating token for order ID: $order_id");
    error_log("[TagForge] Raw token: $token");

    // Decode and parse the token
    $decoded = json_decode(base64_decode(urldecode($token)), true);
    
    if (!$decoded) {
        error_log("[TagForge] Token decoding failed");
        throw new Exception("Invalid download token");
    }

    // Validate required fields
    if (!isset($decoded['path'], $decoded['order_id'], $decoded['expires'])) {
        error_log("[TagForge] Missing required fields in token");
        throw new Exception("Invalid token data");
    }

    // Verify order match
    if ($decoded['order_id'] != $order_id) {
        error_log("[TagForge] Order ID mismatch");
        throw new Exception("Invalid order reference");
    }

    // Check expiration
    if (time() > $decoded['expires']) {
        error_log("[TagForge] Token expired");
        throw new Exception("Download link expired");
    }

    // Verify file exists
    if (!file_exists($decoded['path'])) {
        error_log("[TagForge] File not found: " . $decoded['path']);
        throw new Exception("Requested file not found");
    }

    return $decoded;
}


function tagforge_deliver_file($file_path) {
    // Validate file path
    if (empty($file_path)) {
        throw new Exception("File path is empty");
    }

    if (!file_exists($file_path)) {
        throw new Exception("File not found: $file_path");
    }

    // Set headers
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));

    // Output the file
    readfile($file_path);
    exit;
}


// ==================
// Email Customization
// ==================

add_action('woocommerce_email_before_order_table', 'tagforge_email_download_links', 20, 4);
function tagforge_email_download_links($order, $sent_to_admin, $plain_text, $email) {
    if ($sent_to_admin || $email->id !== 'customer_completed_order') return;

    echo '<h2>Your GTM Containers</h2>';
    
    foreach ($order->get_items() as $item_id => $item) {
        $container_data = $order->get_meta("tagforge_container_{$item_id}", true);
        error_log("[TagForge EMail] Container data: " . print_r($container_data, true));

        if (empty($container_data)) {
            error_log("[TagForge] No container data found for item $item_id in order {$order->get_id()}");
            continue;
        }

        $download_url = tagforge_generate_download_url($container_data, $order->get_id());
        
        echo '<div style="margin-bottom: 20px;">';
        echo '<h3>' . esc_html($item->get_name()) . '</h3>';
        echo '<p><a href="' . esc_url($download_url) . '">Download Container File</a></p>';
        echo '<p><em>Link expires: ' . date('F j, Y H:i', $container_data['expires']) . '</em></p>';
        echo '</div>';
    }
}
