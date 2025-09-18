<?php
/*
Plugin Name: WooCommerce Payment Methods by Email
Description: Filter payment methods based on customer email domain
Version: 1.2
Author: Andreas Fält
Update URI: https://github.com/falt/payment-methods-by-email
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_Payment_Methods_By_Email {
    
    private $update_server = 'https://your-update-server.com'; // Replace with your actual update server
    private $plugin_slug;
    private $version;

public function __construct() {
    $this->plugin_slug = plugin_basename(__FILE__);
    $this->version = '1.2';
    
    // Existing hooks
    add_filter('woocommerce_available_payment_gateways', array($this, 'filter_payment_methods'), 10, 1);
    add_action('admin_menu', array($this, 'add_admin_menu'));
    add_action('admin_init', array($this, 'register_settings'));
    
    // Add new hooks for AJAX and checkout
    add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));
    add_action('wp_ajax_check_email_payment_methods', array($this, 'ajax_check_email_payment_methods'));
    add_action('wp_ajax_nopriv_check_email_payment_methods', array($this, 'ajax_check_email_payment_methods'));
    
    // Auto-update hooks
    add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
    add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
    add_filter('upgrader_process_complete', array($this, 'after_update'), 10, 2);
}

// Add this function to enqueue scripts
public function enqueue_checkout_scripts() {
    if (!is_checkout()) {
        return;
    }

    wp_enqueue_script(
        'wc-email-payment-methods',
        plugins_url('js/checkout.js', __FILE__),
        array('jquery'),
        '1.0',
        true
    );

    wp_localize_script('wc-email-payment-methods', 'wcEmailPayments', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('check_email_payment_methods')
    ));
}

// Add this function to handle AJAX requests
public function ajax_check_email_payment_methods() {
    check_ajax_referer('check_email_payment_methods', 'nonce');

    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $billing_invoice_email = isset($_POST['billing_invoice_email']) ? sanitize_email($_POST['billing_invoice_email']) : '';
    
    if (empty($email) && empty($billing_invoice_email)) {
        wp_send_json_error('No email provided');
        return;
    }

    // Get available gateways
    $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
    
    // Filter gateways based on email
    $filtered_gateways = $this->filter_payment_methods($available_gateways);
    
    // Get the gateway IDs that should be shown
    $allowed_gateway_ids = array_keys($filtered_gateways);

    wp_send_json_success(array(
        'allowed_gateways' => $allowed_gateway_ids
    ));
}

    public function filter_payment_methods($available_gateways) {
        // Get emails either from POST (AJAX) or checkout session
        $emails_to_check = array();
        
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
            $billing_invoice_email = isset($_POST['billing_invoice_email']) ? sanitize_email($_POST['billing_invoice_email']) : '';
            
            if (!empty($email)) {
                $emails_to_check[] = $email;
            }
            if (!empty($billing_invoice_email)) {
                $emails_to_check[] = $billing_invoice_email;
            }
        } else {
            // Check both billing_email and billing_invoice_email
            $billing_email = WC()->checkout->get_value('billing_email');
            $billing_invoice_email = WC()->checkout->get_value('billing_invoice_email');
            
            // Also try to get from $_POST in case it's not in checkout session yet
            if (empty($billing_email) && isset($_POST['billing_email'])) {
                $billing_email = sanitize_email($_POST['billing_email']);
            }
            if (empty($billing_invoice_email) && isset($_POST['billing_invoice_email'])) {
                $billing_invoice_email = sanitize_email($_POST['billing_invoice_email']);
            }
            
            if (!empty($billing_email)) {
                $emails_to_check[] = $billing_email;
            }
            if (!empty($billing_invoice_email)) {
                $emails_to_check[] = $billing_invoice_email;
            }
        }
        
        if (empty($emails_to_check)) {
            return $available_gateways;
        }
    
        $settings = get_option('wc_payment_email_settings', array());
    
        if (empty($settings)) {
            return $available_gateways;
        }
    
        // Check each email against the rules
        foreach ($emails_to_check as $customer_email) {
            $domain = strtolower(substr(strrchr($customer_email, "@"), 1));
            
            foreach ($settings as $rule) {
                if (empty($rule['domain']) || empty($rule['payment_methods'])) {
                    continue;
                }
        
                if ($domain === strtolower($rule['domain'])) {
                    $allowed_methods = is_array($rule['payment_methods']) 
                        ? $rule['payment_methods'] 
                        : explode(',', $rule['payment_methods']);
        
                    foreach ($available_gateways as $gateway_id => $gateway) {
                        if (!in_array($gateway_id, $allowed_methods)) {
                            unset($available_gateways[$gateway_id]);
                        }
                    }
                    // If we found a matching rule, apply it and stop checking other emails
                    return $available_gateways;
                }
            }
        }
    
        return $available_gateways;
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Payment Methods by Email',
            'Payment by Email',
            'manage_options',
            'wc-payment-email',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('wc_payment_email_settings', 'wc_payment_email_settings');
    }

    public function settings_page() {
        // Get available payment gateways
        $available_gateways = WC()->payment_gateways->payment_gateways();
        $settings = get_option('wc_payment_email_settings', array());
        ?>
        <div class="wrap">
            <h1>Payment Methods by Email Domain</h1>
            
            <!-- Enqueue Select2 -->
            <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
            <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
            
            <style>
                .select2-container {
                    min-width: 300px;
                }
                .rules-table {
                    margin-top: 20px;
                }
                .rules-table th {
                    padding: 10px;
                    background: #f8f9fa;
                }
                .rules-table td {
                    padding: 15px 10px;
                }
                .domain-input {
                    width: 100%;
                    max-width: 200px;
                }
                .selected-methods {
                    margin-top: 5px;
                    font-size: 0.9em;
                    color: #666;
                }
            </style>
    
            <form method="post" action="options.php">
                <?php settings_fields('wc_payment_email_settings'); ?>
                
                <table class="wp-list-table widefat fixed striped rules-table">
                    <thead>
                        <tr>
                            <th width="25%">Email Domain</th>
                            <th width="60%">Allowed Payment Methods</th>
                            <th width="15%">Action</th>
                        </tr>
                    </thead>
                    <tbody id="rules-container">
                        <?php
                        if (!empty($settings)) {
                            foreach ($settings as $index => $rule) {
                                $selected_methods = explode(',', $rule['payment_methods']);
                                ?>
                                <tr>
                                    <td>
                                        <input type="text" 
                                               class="domain-input"
                                               name="wc_payment_email_settings[<?php echo $index; ?>][domain]" 
                                               value="<?php echo esc_attr($rule['domain']); ?>" 
                                               placeholder="e.g., gmail.com" />
                                    </td>
                                    <td>
                                        <select class="payment-method-select" 
                                                name="wc_payment_email_settings[<?php echo $index; ?>][payment_methods]" 
                                                multiple="multiple">
                                            <?php foreach ($available_gateways as $gateway_id => $gateway) : ?>
                                                <option value="<?php echo esc_attr($gateway_id); ?>" 
                                                        <?php echo in_array($gateway_id, $selected_methods) ? 'selected' : ''; ?>>
                                                    <?php echo esc_html($gateway->title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                       
                                    </td>
                                    <td>
                                        <button type="button" class="button button-secondary remove-rule">Remove</button>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
                
                <p>
                    <button type="button" class="button button-secondary" id="add-rule">Add New Rule</button>
                </p>
    
                <?php submit_button('Save Changes', 'primary', 'submit', true); ?>
            </form>
        </div>
    
        <script>
        jQuery(document).ready(function($) {
            // Initialize Select2 for existing selects
            $('.payment-method-select').select2({
                placeholder: "Select payment methods",
                width: 'resolve',
                closeOnSelect: false
            });
    
            let ruleCount = <?php echo !empty($settings) ? count($settings) : 0; ?>;
    
            $('#add-rule').on('click', function() {
                const template = `
                    <tr>
                        <td>
                            <input type="text" 
                                   class="domain-input"
                                   name="wc_payment_email_settings[${ruleCount}][domain]" 
                                   placeholder="e.g., gmail.com" />
                        </td>
                        <td>
                            <select class="payment-method-select" 
                                    name="wc_payment_email_settings[${ruleCount}][payment_methods]" 
                                    multiple="multiple">
                                <?php foreach ($available_gateways as $gateway_id => $gateway) : ?>
                                    <option value="<?php echo esc_attr($gateway_id); ?>">
                                        <?php echo esc_html($gateway->title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="selected-methods"></div>
                        </td>
                        <td>
                            <button type="button" class="button button-secondary remove-rule">Remove</button>
                        </td>
                    </tr>
                `;
                
                const $newRow = $(template);
                $('#rules-container').append($newRow);
                
                // Initialize Select2 for the new select
                $newRow.find('.payment-method-select').select2({
                    placeholder: "Select payment methods",
                    width: 'resolve',
                    closeOnSelect: false
                });
                
                ruleCount++;
            });
    
            $(document).on('click', '.remove-rule', function() {
                $(this).closest('tr').remove();
            });
    
            // Update selected methods display when selection changes
            $(document).on('change', '.payment-method-select', function() {
                const selected = $(this).find('option:selected')
                    .map(function() { return $(this).text(); })
                    .get()
                    .join(', ');
                
                $(this).siblings('.selected-methods').text('Selected: ' + (selected || 'None'));
            });
        });
        </script>
        <?php
    }

    // Auto-update functionality
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get plugin version info from GitHub
        $remote_version = $this->get_remote_version();
        
        if ($remote_version && version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => 'https://github.com/falt/payment-methods-by-email',
                'package' => $this->get_download_url($remote_version)
            );
        }

        return $transient;
    }

    private function get_remote_version() {
        $request = wp_remote_get('https://api.github.com/repos/falt/payment-methods-by-email/releases/latest');
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            if (isset($data['tag_name'])) {
                return ltrim($data['tag_name'], 'v'); // Remove 'v' prefix if present
            }
        }
        
        return false;
    }

    private function get_download_url($version) {
        return "https://github.com/falt/payment-methods-by-email/archive/refs/tags/v{$version}.zip";
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }

        $request = wp_remote_get('https://api.github.com/repos/falt/payment-methods-by-email/releases/latest');
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            $result = (object) array(
                'name' => 'WooCommerce Payment Methods by Email',
                'slug' => dirname($this->plugin_slug),
                'version' => ltrim($data['tag_name'], 'v'),
                'author' => 'Andreas Fält',
                'homepage' => 'https://github.com/falt/payment-methods-by-email',
                'short_description' => 'Filter payment methods based on customer email domain',
                'sections' => array(
                    'description' => 'Filter WooCommerce payment methods based on customer email domains.',
                    'changelog' => isset($data['body']) ? $data['body'] : ''
                ),
                'download_link' => $this->get_download_url(ltrim($data['tag_name'], 'v'))
            );
        }

        return $result;
    }

    public function after_update($upgrader, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient('update_plugins');
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    new WC_Payment_Methods_By_Email();
});

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="admin.php?page=wc-payment-email">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});