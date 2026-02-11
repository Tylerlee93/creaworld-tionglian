<?php
/**
 * Plugin Name:     Creaworld TiongLian
 * Plugin URI:      https://www.creaworld.com.sg
 * Description:     TiongLian plugin with custom delivery date selection (range) & admin settings.
 * Author:          Creaworld
 * Author URI:      https://www.creaworld.com.sg
 * Text Domain:     creaworld-eshop
 * Domain Path:     /languages
 * Version:         1.0.0 
 *
 * @package         Creaworld_TiongLian
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die();
}

class Creaworld_TiongLian {

    // Store the calculated date options to avoid recalculating
    private $delivery_options = null;
    // Define the meta key for saving the date

    // Store the parsed time slots to avoid recalculating
    private $parsed_time_slots = null;
    const DELIVERY_DATE_META_KEY = 'tionglian_delivery_date';
    // Define the meta key for saving the time slot
    const DELIVERY_TIME_SLOT_META_KEY = 'tionglian_delivery_time_slot';
   
    const SETTINGS_OPTION_NAME = 'creaworld_tionglian_delivery_settings';
    // Define the settings tab ID
    const SETTINGS_TAB_ID = 'tionglian_delivery';

    public function __construct() {
        // Keep existing filters if needed
        add_filter( 'cew_enable_mail_subscriber_module', '__return_true' );
        add_filter( 'ceweshop_enable_rfq_feature', '__return_true' );
        add_filter( 'woocommerce_settings_tabs_array', array( $this, 'filter_wc_settings_tabs_array' ), 220, 1 );

        add_filter( 'woocommerce_admin_disabled', '__return_false', 20 );
        add_filter( 'woocommerce_package_rates', array( $this,'conditionally_hide_local_pickup'), 10, 2  );
        add_filter( 'woocommerce_shipping_instance_form_fields_local_pickup', array( $this, 'add_min_order_to_local_pickup_settings' ), 10, 1 );

        // --- Delivery Date Functionality Hooks ---
        add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'add_delivery_date_checkout_field' ), 20 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_datepicker_scripts' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_delivery_date_order_meta' ), 10, 2 );
        // Add validation hook
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_delivery_date' ) );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_delivery_date_order_admin' ), 10, 1 );
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_delivery_date_order_details' ), 20, 1 );
        add_action( 'woocommerce_email_after_order_table', array( $this, 'display_delivery_date_order_details' ), 20, 1 );
        // --- End Delivery Date Hooks ---

        // --- Admin Settings Hooks ---
        add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_delivery_settings_tab' ), 50 );
        add_action( 'woocommerce_settings_' . self::SETTINGS_TAB_ID, array( $this, 'output_delivery_settings' ) );
        add_action( 'woocommerce_update_options_' . self::SETTINGS_TAB_ID, array( $this, 'save_delivery_settings' ) );
        add_action( 'wpo_wcpdf_after_order_data', array( $this, 'display_delivery_info_in_pdf' ), 10, 2 );

        // --- End Admin Settings Hooks ---
        if ( class_exists('\Creaworld_Eshop\Frontend\FrontendManager') && method_exists('\Creaworld_Eshop\Frontend\FrontendManager', 'instance') ) {
            $frontend_manager_instance = \Creaworld_Eshop\Frontend\FrontendManager::instance();
            // Check if the instance and the specific method exist before trying to remove
            if ( $frontend_manager_instance && method_exists($frontend_manager_instance, 'inject_product_cate_into_menu') ) {
                $removed = remove_filter(
                    'wp_get_nav_menu_items', // The filter tag
                    [ $frontend_manager_instance, 'inject_product_cate_into_menu' ], // The callback (instance + method name)
                    10 // The priority
                );
                if ($removed) {
                    error_log("Creaworld TiongLian: Successfully removed original inject_product_cate_into_menu filter.");
                } else {
                    error_log("Creaworld TiongLian: FAILED to remove original inject_product_cate_into_menu filter (maybe priority mismatch or already removed?).");
                }
            } else {
                 error_log("Creaworld TiongLian: Could not get FrontendManager instance or method inject_product_cate_into_menu not found.");
            }
        } else {
             error_log("Creaworld TiongLian: Creaworld_Eshop\Frontend\FrontendManager class not found. Cannot remove original filter.");
        }

        // 2. ADD our custom version of the filter from *this* plugin (Creaworld_TiongLian)
        add_filter( 'wp_get_nav_menu_items', array( $this, 'inject_product_cate_into_menu' ), 10, 3 );

    }

    /**
     * Hide 'Emails' tab text for non-admins
     */
    public function filter_wc_settings_tabs_array( $tabs_array ) {
        // ... (code unchanged) ...
        if ( ! current_user_can( 'administrator' ) ) {
             if ( isset( $tabs_array['email'] ) ) {
                 $tabs_array['email'] = 'Emails';
             }
        }
        return $tabs_array;
    }

    /**
     * Conditionally hide local pickup
     */
    public function conditionally_hide_local_pickup( $rates, $package ) { // MODIFIED

        if ( WC()->cart ) {
            // Get cart subtotal (usually excludes taxes, good for min spend thresholds)
            $cart_subtotal = WC()->cart->get_subtotal();

            foreach ( $rates as $rate_id => $rate ) {
                if ( 'local_pickup' === $rate->get_method_id() ) {
                    $instance_id = $rate->get_instance_id();
                    // Fetch the settings for this specific local_pickup instance
                    $shipping_method_settings = get_option( 'woocommerce_local_pickup_' . $instance_id . '_settings' );
                    
                    $min_amount_for_pickup = null;
                    if ( isset( $shipping_method_settings['min_order_amount_local_pickup'] ) ) {
                        $min_amount_setting_value = trim($shipping_method_settings['min_order_amount_local_pickup']);
                        if ( $min_amount_setting_value !== '' && is_numeric( $min_amount_setting_value ) ) {
                            $min_amount_for_pickup = floatval( $min_amount_setting_value );
                        }
                    }

                    // If a minimum amount is set and the cart subtotal is less than it, hide local pickup
                    if ( $min_amount_for_pickup !== null && $cart_subtotal < $min_amount_for_pickup ) {
                        unset( $rates[ $rate_id ] );
                        error_log("TiongLian: Hiding Local Pickup ($rate_id). Cart subtotal: $cart_subtotal, Min amount for pickup: $min_amount_for_pickup");
                    } else {
                        error_log("TiongLian: Showing Local Pickup ($rate_id). Cart subtotal: $cart_subtotal, Min amount for pickup: " . ($min_amount_for_pickup ?? 'Not set or not applicable'));
                    }
                }
            }
        }
        return $rates;
    }

    public function add_min_order_to_local_pickup_settings( $form_fields ) {
        // Insert the new field after the 'title' field, or at a specific position
        $new_fields = array();
        $new_fields['min_order_amount_local_pickup'] = array(
            'title'       => __( 'Minimum Order Amount', 'creaworld-eshop' ),
            'type'        => 'price', // WooCommerce 'price' type field
            'description' => __( 'Minimum order subtotal required to enable this Local Pickup option. Leave blank to always show (if available).', 'creaworld-eshop' ),
            'default'     => '',
            'desc_tip'    => true,
            'placeholder' => wc_format_localized_price( 0 ),
        );

        // A common place to add it is before 'cost' or 'tax_status' if they exist, or just append
        return array_slice($form_fields, 0, 1, true) + $new_fields + array_slice($form_fields, 1, null, true);
    }
    // --- Admin Settings Functions ---

    /**
     * Add the custom settings tab
     */
    public function add_delivery_settings_tab( $settings_tabs ) {
        // ... (code unchanged) ...
        $settings_tabs[self::SETTINGS_TAB_ID] = __('Delivery Date / Time', 'creaworld-eshop');
        return $settings_tabs;
    }

    /**
     * Get the settings array for the WooCommerce settings page. (MODIFIED)
     */
    private function get_delivery_settings_fields() {
        $days_options = array( /* ... (code unchanged) ... */
            '1' => __('Monday', 'creaworld-eshop'), '2' => __('Tuesday', 'creaworld-eshop'),
            '3' => __('Wednesday', 'creaworld-eshop'), '4' => __('Thursday', 'creaworld-eshop'),
            '5' => __('Friday', 'creaworld-eshop'), '6' => __('Saturday', 'creaworld-eshop'),
            '0' => __('Sunday', 'creaworld-eshop'),
        );

        // Define settings fields
        return apply_filters('creaworld_tionglian_delivery_settings_fields', array(
            'section_title' => array( /* ... (code unchanged) ... */
                'name' => __('Delivery Calculation Settings', 'creaworld-eshop'), 'type' => 'title',
                'desc' => __('Configure how the estimated delivery date is calculated.', 'creaworld-eshop'),
                'id'   => 'tionglian_delivery_section_title'
            ),
            'cutoff_hour' => array( /* ... (code unchanged) ... */
                'name' => __('Order Cut-off Hour (24h)', 'creaworld-eshop'), 'type' => 'number',
                'desc' => __('Enter the hour (0-23) for the daily order cut-off...', 'creaworld-eshop'),
                'id'   => self::SETTINGS_OPTION_NAME . '[cutoff_hour]', 'css'  => 'width:80px;',
                'default' => '12', 'custom_attributes' => array('min' => 0, 'max' => 23, 'step' => 1),
                'desc_tip' => true,
            ),
            'buffer_days' => array( // <-- NEW FIELD ADDED HERE
                'name' => __('Processing Buffer (Working Days)', 'creaworld-eshop'), 'type' => 'number',
                'desc' => __('Number of working days needed for processing before the first available delivery day.', 'creaworld-eshop'),
                'id'   => self::SETTINGS_OPTION_NAME . '[buffer_days]', 'css'  => 'width:80px;',
                'default' => '2', 'custom_attributes' => array('min' => 0, 'step' => 1), // Allow 0 buffer days
                'desc_tip' => true,
            ),
            'max_days_ahead' => array( /* ... (code unchanged) ... */
                'name' => __('Maximum Delivery Days Ahead', 'creaworld-eshop'), 'type' => 'number',
                'desc' => __('Maximum number of days into the future a user can select...', 'creaworld-eshop'),
                'id'   => self::SETTINGS_OPTION_NAME . '[max_days_ahead]', 'css'  => 'width:80px;',
                'default' => '14', 'custom_attributes' => array('min' => 1, 'step' => 1),
                'desc_tip' => true,
            ),
            'allowed_days' => array( /* ... (code unchanged) ... */
                'name'    => __('Allowed Delivery Days', 'creaworld-eshop'), 'type'    => 'multiselect',
                'class'   => 'wc-enhanced-select', 'id'      => self::SETTINGS_OPTION_NAME . '[allowed_days]',
                'options' => $days_options, 'default' => array('2', '4', '6'),
                'desc_tip' => __('Select the days of the week when delivery is possible.', 'creaworld-eshop'),
            ),
            'specific_holidays' => array(
                'name' => __('Specific Date Holidays (YYYY-MM-DD)', 'creaworld-eshop'),
                'type' => 'textarea', // <--- Changed back to textarea
                'desc' => __('Enter specific, non-recurring holiday dates, one per line, in YYYY-MM-DD format.', 'creaworld-eshop'), // <--- Updated description
                'id'   => self::SETTINGS_OPTION_NAME . '[specific_holidays]',
                'css'  => 'width:300px; height: 100px;', // Adjusted size
                'placeholder' => "YYYY-MM-DD\nYYYY-MM-DD\n...",
                'desc_tip' => true,
            ),
             'enable_recurring_holidays' => array(
                'name'    => __('Enable Recurring Holidays', 'creaworld-eshop'),
                'type'    => 'checkbox',
                'desc'    => __('Check this box to process the recurring holidays listed below.', 'creaworld-eshop'),
                'id'      => self::SETTINGS_OPTION_NAME . '[enable_recurring_holidays]',
                'default' => 'yes', // Default to enabled
                'desc_tip' => false, // Description is clear enough
            ),
            'recurring_holidays' => array(
                'name' => __('Recurring Holidays (MM-DD)', 'creaworld-eshop'),
                'type' => 'textarea',
                'desc' => __('Enter annually recurring holidays, one per line, in MM-DD format (e.g., 12-25 for Christmas).', 'creaworld-eshop'),
                'id'   => self::SETTINGS_OPTION_NAME . '[recurring_holidays]', // New key
                'css'  => 'width:300px; height: 100px;',
                'placeholder' => "MM-DD\nMM-DD\n...", // e.g., 01-01, 12-25
                'desc_tip' => true,
            ),
            'delivery_time_slots' => array(
                'name' => __('Delivery Time Slots', 'creaworld-eshop'),
                'type' => 'textarea',
                'desc' => __('Enter available time slots, one per line, in the format: <strong>value|Display Text</strong><br>Example:<br><code>9am-12pm|9am - 12noon</code><br><code>12pm-3pm|12noon - 3pm</code>', 'creaworld-eshop'),
                'id'   => self::SETTINGS_OPTION_NAME . '[delivery_time_slots]',
                'css'  => 'width:400px; height: 120px;',
                'default' => "9am-12pm|9am - 12noon\n12pm-3pm|12noon - 3pm\n3pm-6pm|3pm - 6pm\n6pm-10pm|6pm - 10pm", // Sensible default
            ),
            'section_end' => array( /* ... (code unchanged) ... */
                 'type' => 'sectionend', 'id' => 'tionglian_delivery_section_end'
            )
        ));
    }

    /**
     * Parses the time slots defined in the settings.
     * Returns a default array if the setting is empty or invalid.
     * Caches the result within the request.
     *
     * @return array Associative array of time slots [value => display_text].
     */
    private function get_parsed_time_slots() {
        // Return cached result if available
        if ($this->parsed_time_slots !== null) {
            return $this->parsed_time_slots;
        }

        $settings = get_option(self::SETTINGS_OPTION_NAME, []);
        $time_slots_string = isset($settings['delivery_time_slots']) ? $settings['delivery_time_slots'] : '';

        $default_slots = array(
            '9am-12pm' => __('9am - 12noon', 'creaworld-eshop'),
            '12pm-3pm' => __('12noon - 3pm', 'creaworld-eshop'),
            '3pm-6pm'  => __('3pm - 6pm', 'creaworld-eshop'),
            '6pm-10pm' => __('6pm - 10pm', 'creaworld-eshop'),
        );

        if (empty(trim($time_slots_string))) {
            $this->parsed_time_slots = $default_slots;
            return $this->parsed_time_slots;
        }

        $parsed = [];
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $time_slots_string)));

        foreach ($lines as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) === 2 && !empty(trim($parts[0])) && !empty(trim($parts[1]))) {
                $parsed[trim($parts[0])] = trim($parts[1]);
            }
        }
        $this->parsed_time_slots = !empty($parsed) ? $parsed : $default_slots; // Use default if parsing resulted in empty
        return $this->parsed_time_slots;
    }

    /**
     * Output the settings fields
     */
    public function output_delivery_settings() { /* ... (code unchanged) ... */
        woocommerce_admin_fields($this->get_delivery_settings_fields());
    }

    /**
     * Save the settings
     */
    public function save_delivery_settings() { /* ... (code unchanged) ... */
        woocommerce_update_options($this->get_delivery_settings_fields());
    }

    // --- END Admin Settings Functions ---


    /**
     * Get the list of holidays from saved settings.
     * Parses specific dates (YYYY-MM-DD, line-separated) and optionally recurring dates (MM-DD).
     * Generates recurring dates for the current and next year if enabled.
     *
     * @return array List of holiday dates ('Y-m-d').
     */
    private function get_holidays_from_settings() {
        $settings = get_option(self::SETTINGS_OPTION_NAME, []);
        $specific_holidays_string = isset($settings['specific_holidays']) ? $settings['specific_holidays'] : ''; // Expecting newline separated
        $recurring_holidays_string = isset($settings['recurring_holidays']) ? $settings['recurring_holidays'] : '';
        $enable_recurring = isset($settings['enable_recurring_holidays']) && $settings['enable_recurring_holidays'] === 'yes';

        $processed_holidays = [];

        // 1. Process Specific Date Holidays (YYYY-MM-DD, line-separated)
        if (!empty($specific_holidays_string)) {
            // --- REVERTED PARSING ---
            $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $specific_holidays_string))); // Explode by newline
            // --- END REVERTED PARSING ---
            foreach ($lines as $line) { // Use $line instead of $date_str for clarity
                if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $line)) {
                    $d = DateTime::createFromFormat('Y-m-d', $line);
                    if ($d && $d->format('Y-m-d') === $line) {
                        $processed_holidays[] = $line;
                    } else {
                         error_log("Creaworld TiongLian - Invalid specific holiday date format ignored: " . $line);
                    }
                } else {
                     error_log("Creaworld TiongLian - Invalid specific holiday date format ignored: " . $line);
                }
            }
        }

        // 2. Process Recurring Holidays (MM-DD) - Only if enabled
        if ($enable_recurring && !empty($recurring_holidays_string)) {
            // ... (recurring parsing logic remains the same) ...
            $current_year = (int)date('Y');
            $next_year = $current_year + 1;
            $lines_recurring = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $recurring_holidays_string)));
            foreach ($lines_recurring as $line) {
                if (preg_match("/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/", $line, $matches)) {
                    $month_day = $matches[0];
                    $date_str_current = $current_year . '-' . $month_day;
                    $d_current = DateTime::createFromFormat('Y-m-d', $date_str_current);
                    if ($d_current && $d_current->format('Y-m-d') === $date_str_current) { $processed_holidays[] = $date_str_current; }
                    $date_str_next = $next_year . '-' . $month_day;
                    $d_next = DateTime::createFromFormat('Y-m-d', $date_str_next);
                    if ($d_next && $d_next->format('Y-m-d') === $date_str_next) { $processed_holidays[] = $date_str_next; }
                } else { error_log("Creaworld TiongLian - Invalid recurring holiday format ignored: " . $line); }
            }
        }

        return array_unique($processed_holidays);
    }







    /**
     * Calculates the delivery date options (min, max, allowed days, holidays).
     *
     * @return array|null An array with 'min_date', 'max_date', 'allowed_days_num', 'holidays', or null on failure.
     */
    private function get_delivery_date_options() {
        // Return cached options if already calculated in this request
        if ($this->delivery_options !== null) {
            return $this->delivery_options ?: null; // Return null if previous calculation failed (stored as false)
        }

        try {
            // --- Get Configuration from Settings ---
            $settings = get_option(self::SETTINGS_OPTION_NAME, []);
            $cutoff_hour = isset($settings['cutoff_hour']) && is_numeric($settings['cutoff_hour']) ? (int)$settings['cutoff_hour'] : 12;
            $max_days_ahead = isset($settings['max_days_ahead']) && is_numeric($settings['max_days_ahead']) ? max(1, (int)$settings['max_days_ahead']) : 14; // Ensure at least 1, default 14
            $allowed_delivery_days_num = isset($settings['allowed_days']) && is_array($settings['allowed_days']) ? array_map('intval', $settings['allowed_days']) : [2, 4, 6];
            $holidays = $this->get_holidays_from_settings();
            $buffer_days = isset($settings['buffer_days']) && is_numeric($settings['buffer_days']) ? max(0, (int)$settings['buffer_days']) : 2; // <-- READ FROM SETTINGS, default 2
            $timezone = new DateTimeZone('Asia/Singapore');
            // --- End Configuration ---

            // --- Calculation Logic ---
            $now = current_time('timestamp');
            $order_date = new DateTime("@$now");
            $order_date->setTimezone($timezone);
            $order_date = new DateTime('now', $timezone);
            $order_date_ymd = $order_date->format('Y-m-d');

            // 1. Determine Processing Start Date
            $processing_start_date = clone $order_date;
            if ((int)$processing_start_date->format('H') >= $cutoff_hour) {
                $processing_start_date->modify('+1 day');
            }
            while (in_array((int)$processing_start_date->format('w'), [0, 6]) || in_array($processing_start_date->format('Y-m-d'), $holidays)) {
                $processing_start_date->modify('+1 day');
            }

            // 2. Add +2 Working Days Buffer
            $date_after_buffer = clone $processing_start_date;
            $working_days_added = 0;
            while ($working_days_added < $buffer_days) {
                $date_after_buffer->modify('+1 day');
                $weekday = (int)$date_after_buffer->format('w');
                $ymd = $date_after_buffer->format('Y-m-d');
                if ($weekday >= 1 && $weekday <= 5 && !in_array($ymd, $holidays)) {
                    $working_days_added++;
                }
            }

            // 3. Find the First Allowed Delivery Day (This is min_date)
            $min_delivery_dt = clone $date_after_buffer;
            while (true) {
                $dow = (int)$min_delivery_dt->format('w');
                $ymd = $min_delivery_dt->format('Y-m-d');
                if (in_array($dow, $allowed_delivery_days_num) && !in_array($ymd, $holidays)) {
                    break;
                }
                $min_delivery_dt->modify('+1 day');
            }

            // 4. Final Same-Day Check for min_date
            if ($min_delivery_dt->format('Y-m-d') <= $order_date_ymd) {
                $min_delivery_dt->modify('+1 day');
                while (true) {
                    $dow = (int)$min_delivery_dt->format('w');
                    $ymd = $min_delivery_dt->format('Y-m-d');
                    if (in_array($dow, $allowed_delivery_days_num) && !in_array($ymd, $holidays)) {
                        break;
                    }
                    $min_delivery_dt->modify('+1 day');
                }
            }
            $min_date_str = $min_delivery_dt->format('Y-m-d');

            // 5. Calculate Max Date
            // Start from min date and add X-1 days (since min_date is day 1 of the range)
            $max_delivery_dt = clone $min_delivery_dt;
            $max_delivery_dt->modify('+' . ($max_days_ahead - 1) . ' days');
            $max_date_str = $max_delivery_dt->format('Y-m-d');

            // Store and return the options
            $this->delivery_options = [
                'min_date' => $min_date_str,
                'max_date' => $max_date_str,
                'allowed_days_num' => $allowed_delivery_days_num,
                'holidays' => $holidays,
            ];
            return $this->delivery_options;

        } catch (Exception $e) {
            error_log("Error calculating delivery date options: " . $e->getMessage());
            $this->delivery_options = false; // Mark calculation as failed
            return null;
        }
    }

    // --- Functions for Delivery Date Field (MODIFIED) ---

    /**
     * Enqueue scripts
     */
    public function enqueue_datepicker_scripts() { /* ... (code unchanged) ... */
        if ( is_checkout() && ! is_order_received_page() ) {
            wp_enqueue_script('jquery-ui-datepicker');
            if (!wp_style_is('jquery-ui-style', 'registered')) {
                 wp_register_style('jquery-ui-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/base/jquery-ui.css');
            }
             wp_enqueue_style('jquery-ui-style');
            $this->add_datepicker_inline_script();
        }
    }

    /**
     * Add inline JavaScript to configure the datepicker. (Using NEW Key)
     */
    private function add_datepicker_inline_script() {
        $options = $this->get_delivery_date_options(); // Get the options array

        if ($options && isset($options['min_date'])) {
            $script_handle = 'jquery-ui-datepicker';
            $js_options = wp_json_encode([
                'minDate' => $options['min_date'],
                'maxDate' => $options['max_date'],
                'allowedDaysNum' => $options['allowed_days_num'],
                'holidays' => $options['holidays'],
            ]);

            // --- UPDATED SELECTOR to use the new key ---
            $field_selector_id = '#' . self::DELIVERY_DATE_META_KEY;
            // --- END UPDATED SELECTOR ---

            $script = "
                jQuery(document).ready(function($) {
                    if (typeof $.fn.datepicker === 'function') {
                        var deliveryOptions = " . $js_options . ";
                        // Use the updated selector variable
                        var fieldSelector = '" . esc_js($field_selector_id) . "';

                        // Function to disable specific dates
                        function disableDates(date) {
                            // ... (disableDates function remains the same) ...
                            var day = date.getDay();
                            var dateString = $.datepicker.formatDate('yy-mm-dd', date);
                            if ($.inArray(dateString, deliveryOptions.holidays) > -1) {
                                return [false, 'holiday', 'Holiday'];
                            }
                            if ($.inArray(day, deliveryOptions.allowedDaysNum) === -1) {
                                return [false, 'unavailable-weekday', 'Unavailable'];
                            }
                            return [true, 'available-day', 'Available'];
                        }

                        $(fieldSelector).datepicker({
                            dateFormat: 'yy-mm-dd',
                            minDate: deliveryOptions.minDate,
                            maxDate: deliveryOptions.maxDate,
                            beforeShowDay: disableDates,
                            constrainInput: true,
                            defaultDate: deliveryOptions.minDate,
                            numberOfMonths: 1,
                            beforeShow: function(input, inst) {
                                setTimeout(function() {
                                    $('#ui-datepicker-div').addClass('creaworld-datepicker');
                                }, 0);
                            }
                        });

                        // Set the input value explicitly
                        $(fieldSelector).val(deliveryOptions.minDate);

                    } else {
                        console.error('Creaworld TiongLian: jQuery UI Datepicker not loaded.');
                    }
                });
            ";
            if(wp_script_is($script_handle, 'enqueued')) {
                 wp_add_inline_script($script_handle, $script);
            } else {
                 wp_add_inline_script('jquery-core', $script);
                 error_log('Creaworld TiongLian: jquery-ui-datepicker was not enqueued when trying to add inline script.');
            }
        } else {
             error_log('Creaworld TiongLian: No delivery options calculated, datepicker script not added.');
        }
    }



    /**
     * Add the delivery date field to the checkout page. (MODIFIED)
     */
    public function add_delivery_date_checkout_field( $checkout ) {
        error_log("--- add_delivery_date_checkout_field RUNNING ---");
        echo '<div id="' . esc_attr(self::DELIVERY_DATE_META_KEY) . '_field_container" class="creaworld-checkout-field" style="clear:both; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">';
        echo '<h3>' . esc_html__('Delivery Information', 'creaworld-eshop') . '</h3>';

        $options = $this->get_delivery_date_options();
        error_log("Delivery Options in add_delivery_date_checkout_field: " . var_export($options, true));

        if ($options && isset($options['min_date'])) {
            woocommerce_form_field( self::DELIVERY_DATE_META_KEY, array(
                'type'          => 'text',
                'label'         => __('Select Delivery Date', 'creaworld-eshop'),
                'placeholder'   => 'YYYY-MM-DD',
                'required'      => true,
                'class'         => array('form-row-wide', 'delivery-date-picker-field'),
                'input_class'   => array('delivery-date-input'),
                'default'       => $options['min_date'], // Default to min date
                // Remove readonly attribute to allow selection, datepicker constraints handle validity
                // 'custom_attributes' => array('readonly' => 'readonly'),
                'id'            => self::DELIVERY_DATE_META_KEY
            ), $checkout->get_value( self::DELIVERY_DATE_META_KEY ) ?: $options['min_date']);

             error_log("Rendering delivery date field. Min: {$options['min_date']}, Max: {$options['max_date']}");
             // --- Generate Dynamic Delivery Note (organized into separate lines) ---
             $settings = get_option(self::SETTINGS_OPTION_NAME, []);
             $allowed_days_num = isset($settings['allowed_days']) && is_array($settings['allowed_days']) ? array_map('intval', $settings['allowed_days']) : [2, 4, 6];
             $buffer_days = isset($settings['buffer_days']) && is_numeric($settings['buffer_days']) ? max(0, (int)$settings['buffer_days']) : 2;
             $cutoff_hour = isset($settings['cutoff_hour']) && is_numeric($settings['cutoff_hour']) ? (int)$settings['cutoff_hour'] : 12;

             $day_map = ['Sun', 'Mon', 'Tues', 'Wed', 'Thurs', 'Fri', 'Sat'];
             $allowed_day_names = [];
             foreach ($allowed_days_num as $day_num) {
                 if (isset($day_map[$day_num])) {
                     $allowed_day_names[] = $day_map[$day_num];
                 }
             }
             // --- Format Delivery Days String ---
             $delivery_days_statement = '';
             $count_allowed_days = count($allowed_day_names);

             if ($count_allowed_days === 0) {
                 $delivery_days_statement = esc_html__('Delivery days are not currently specified.', 'creaworld-eshop');
             } elseif ($count_allowed_days === 1) {
                 $delivery_days_statement = sprintf(esc_html__('We deliver on %s.', 'creaworld-eshop'), esc_html($allowed_day_names[0]));
             } elseif ($count_allowed_days === 2) {
                 $delivery_days_statement = sprintf(esc_html__('We deliver on %s and %s.', 'creaworld-eshop'), esc_html($allowed_day_names[0]), esc_html($allowed_day_names[1]));
             } else { // 3 or more days
                 $last_day = array_pop($allowed_day_names); // Removes and returns the last day
                 $first_days_string = implode(', ', array_map('esc_html', $allowed_day_names));
                 $delivery_days_statement = sprintf(esc_html__('We deliver on %s, and %s.', 'creaworld-eshop'), $first_days_string, esc_html($last_day));
             }
             // Format cutoff time
             $cutoff_time_str = date("ga", mktime($cutoff_hour, 0)); // e.g., 5pm, 11am

            echo '<div class="delivery-note-details" style="margin-top: 8px; font-size: 0.95em; color: grey;">';
            echo '<p class="delivery-days-info">' . $delivery_days_statement . '</p>';
            echo '<p class="delivery-processing-info">' . sprintf(esc_html__('%d working day(s) required for processing.', 'creaworld-eshop'), (int)$buffer_days) . '</p>';
            echo '<p class="delivery-cutoff-info">' . sprintf(esc_html__('Order cut-off time: %s.', 'creaworld-eshop'), esc_html($cutoff_time_str)) . '</p>';
            echo '</div>';
        $parsed_time_slots = $this->get_parsed_time_slots();
            $time_slot_options = ['' => __('Select a time slot...', 'creaworld-eshop')] + $parsed_time_slots; // Add placeholder

            woocommerce_form_field( self::DELIVERY_TIME_SLOT_META_KEY, array(
                'type'        => 'select',
                'label'       => __('Select Delivery Time Slot', 'creaworld-eshop'),
                'required'    => true, // Make it mandatory
                'class'       => array('form-row-wide', 'delivery-time-slot-field'),
                'options'     => $time_slot_options, // Use parsed options
                'id'          => self::DELIVERY_TIME_SLOT_META_KEY, // Use the constant for the ID
                'default'     => '', // Default to the placeholder
            ), $checkout->get_value( self::DELIVERY_TIME_SLOT_META_KEY ) );
           

        } else {
            error_log("No delivery options calculated, showing error message.");
            echo '<p class="woocommerce-error">' . esc_html__('Delivery date/time cannot be determined at this time. Please contact us.', 'creaworld-eshop') . '</p>';
        }
        echo '</div>';
    }

     /**
     * Validate the selected delivery date during checkout processing.
     */
    public function validate_delivery_date() {
        $date_meta_key = self::DELIVERY_DATE_META_KEY;
        $time_meta_key = self::DELIVERY_TIME_SLOT_META_KEY;
        if ( isset( $_POST[$date_meta_key] ) && ! empty( $_POST[$date_meta_key] ) ) {
        
            $selected_date_str = sanitize_text_field( $_POST[$date_meta_key] );

            // 1. Basic Format Check
            if ( !preg_match("/^\d{4}-\d{2}-\d{2}$/", $selected_date_str) ) {
                 wc_add_notice( __( 'Please select a valid delivery date format (YYYY-MM-DD).', 'creaworld-eshop' ), 'error' );
                 return; // Stop validation
            }

            // 2. Check against calculated options
            $options = $this->get_delivery_date_options();
            if (!$options) {
                 wc_add_notice( __( 'Could not verify delivery date availability. Please contact us.', 'creaworld-eshop' ), 'error' );
                 return;
            }

            try {
                $selected_dt = new DateTime($selected_date_str);
                $min_dt = new DateTime($options['min_date']);
                $max_dt = new DateTime($options['max_date']);

                // 3. Check if within Min/Max range
                if ($selected_dt < $min_dt || $selected_dt > $max_dt) {
                    wc_add_notice( sprintf(__( 'Selected delivery date (%s) is outside the allowed range (%s to %s).', 'creaworld-eshop' ), $selected_date_str, $options['min_date'], $options['max_date']), 'error' );
                    return;
                }

                // 4. Check if it's an allowed day and not a holiday
                $dow = (int)$selected_dt->format('w');
                if (!in_array($dow, $options['allowed_days_num']) || in_array($selected_date_str, $options['holidays'])) {
                     wc_add_notice( sprintf(__( 'Selected delivery date (%s) is not an available delivery day.', 'creaworld-eshop' ), $selected_date_str), 'error' );
                     return;
                }

            } catch (Exception $e) {
                 wc_add_notice( __( 'There was an error validating the delivery date.', 'creaworld-eshop' ), 'error' );
                 error_log("Error validating delivery date: " . $e->getMessage());
                 return;
            }

        } elseif ( empty( $_POST[$date_meta_key] ) ) {
            // Check if it was required but submitted empty
             // Note: woocommerce_form_field 'required' should handle this, but double-check
             wc_add_notice( __( 'Please select a delivery date.', 'creaworld-eshop' ), 'error' );
        }

        if ( !isset( $_POST[$time_meta_key] ) || empty( $_POST[$time_meta_key] ) ) {
            wc_add_notice( __( 'Please select a delivery time slot.', 'creaworld-eshop' ), 'error' );
        } else {
            $selected_time = sanitize_text_field( $_POST[$time_meta_key] );
            // Get valid keys from parsed settings
            $valid_time_slots = array_keys($this->get_parsed_time_slots());
            if ( !in_array( $selected_time, $valid_time_slots ) ) {
                wc_add_notice( __( 'Please select a valid delivery time slot.', 'creaworld-eshop' ), 'error' );
            }
        }
    }


     /**
      * Save the selected delivery date to the order meta. (With Debugging)
      */
     public function save_delivery_date_order_meta( $order_id, $posted_data ) {
         $date_meta_key = self::DELIVERY_DATE_META_KEY;
         $time_meta_key = self::DELIVERY_TIME_SLOT_META_KEY;
 
         error_log("--- save_delivery_date_order_meta RUNNING for Order ID: " . $order_id); // Log 1: Did the function run?
 
         // Log the relevant part of the posted data
         if (isset($posted_data[$date_meta_key])) { // <-- Use $date_meta_key
             error_log("Posted Data [{$date_meta_key}]: " . print_r($posted_data[$date_meta_key], true)); // Log 2: What value was posted?
         } else {
             error_log("Posted Data [{$date_meta_key}] is NOT SET."); // Log 3: Was the field even posted?
         }
 
         // Check if the key exists and the value is not empty
         if ( isset( $posted_data[$date_meta_key] ) && ! empty( $posted_data[$date_meta_key] ) ) {
             $selected_date = sanitize_text_field( $posted_data[$date_meta_key] );
         } elseif ( isset( $_POST[$date_meta_key] ) && ! empty( $_POST[$date_meta_key] ) ) {
             $selected_date = sanitize_text_field( $_POST[$date_meta_key] );
             error_log("Posted Data found in \ for key {$date_meta_key}: " . $selected_date);
         } else {
             $selected_date = null;
         }
 
         if ($selected_date) {
             error_log("Sanitized Date: " . $selected_date); // Log 4: What does it look like after sanitizing?
 
             // Validate format
             if ( preg_match("/^\d{4}-\d{2}-\d{2}$/", $selected_date) ) {
                 error_log("Date format VALID. Saving meta..."); // Log 5a: Did validation pass?
                 // Use update_post_meta which is standard for post meta
                 update_post_meta( $order_id, $date_meta_key, $selected_date );
                 // Verify if meta was saved (optional check)
                 $saved_value = get_post_meta($order_id, $date_meta_key, true);
                 error_log("update_post_meta called for key: " . $date_meta_key . " with value: " . $selected_date . ". Value after save: " . $saved_value); // Log 6: Did we attempt to save & verify?
             } else {
                 error_log("Date format INVALID: " . $selected_date); // Log 5b: Did validation fail?
                 $order = wc_get_order($order_id);
                 if ($order) $order->add_order_note( sprintf( __('Invalid delivery date format received: %s', 'creaworld-eshop'), $selected_date ) );
             }
         } else {
             error_log("Posted data for {$date_meta_key} was not set or empty."); // Log 7: Condition failed (isset or empty)
             $order = wc_get_order($order_id);
              // Add note only if the field was expected but missing/empty
              if ($order) $order->add_order_note( __('Required delivery date was not selected or submitted.', 'creaworld-eshop') );
         }
 
         // --- SAVE TIME SLOT ---
         // Check if the key exists and the value is not empty in $posted_data or $_POST
         if ( isset( $posted_data[$time_meta_key] ) && ! empty( $posted_data[$time_meta_key] ) ) {
             $selected_time = sanitize_text_field( $posted_data[$time_meta_key] );
         } elseif ( isset( $_POST[$time_meta_key] ) && ! empty( $_POST[$time_meta_key] ) ) { // <-- FALLBACK CHECK
             $selected_time = sanitize_text_field( $_POST[$time_meta_key] );
             error_log("Posted Data found in \ for key {$time_meta_key}: " . $selected_time);
         } else {
             $selected_time = null;
         }
 
         if ( $selected_time ) {
             error_log("Saving time slot for Order ID {$order_id}: {$selected_time}");
             // Validation against parsed settings keys
             $valid_time_slots = array_keys($this->get_parsed_time_slots());
             if ( in_array( $selected_time, $valid_time_slots ) ) {
                 update_post_meta( $order_id, $time_meta_key, $selected_time );
                  $saved_time_value = get_post_meta($order_id, $time_meta_key, true);
                  error_log("update_post_meta called for key: " . $time_meta_key . " with value: " . $selected_time . ". Value after save: " . $saved_time_value);
             } else {
                  error_log("Invalid time slot value received, not saving: " . $selected_time);
                  $order = wc_get_order($order_id);
                  if ($order) $order->add_order_note( sprintf( __('Invalid delivery time slot received: %s', 'creaworld-eshop'), $selected_time ) );
             }
         } else {
              error_log("Posted data for {$time_meta_key} was not set or empty for Order ID {$order_id}.");
         }
     }
 


    /**
     * Display date in Admin
     */
    public function display_delivery_date_order_admin( $order ) { /* ... (code unchanged) ... */
        $delivery_date = $order->get_meta( self::DELIVERY_DATE_META_KEY );
        if ( $delivery_date ) {
            try {
                 $formatted_date = date_i18n( wc_date_format(), strtotime( $delivery_date ) );
                 echo '<p><strong>' . esc_html__( 'Selected Delivery Date:', 'creaworld-eshop' ) . '</strong> ' . esc_html( $formatted_date ) . '</p>';
            } catch (Exception $e) {
                 echo '<p><strong>' . esc_html__( 'Selected Delivery Date:', 'creaworld-eshop' ) . '</strong> ' . esc_html( $delivery_date ) . ' (Error formatting date)</p>';
            }
        }
        $delivery_time = $order->get_meta( self::DELIVERY_TIME_SLOT_META_KEY );
        if ( $delivery_time ) {
            // Map keys back to display text using helper function
            $time_slot_display = $this->get_time_slot_display_text($delivery_time);
            echo '<p><strong>' . esc_html__( 'Selected Delivery Time:', 'creaworld-eshop' ) . '</strong> ' . esc_html( $time_slot_display ) . '</p>';
        }
    }

    /**
     * Display date on Frontend / Emails
     */
    public function display_delivery_date_order_details( $order ) { /* ... (code unchanged) ... */
        if (!is_a($order, 'WC_Order')) return;
        $delivery_date = $order->get_meta( self::DELIVERY_DATE_META_KEY );
        if ( $delivery_date ) {
             try {
                 $formatted_date = date_i18n( wc_date_format(), strtotime( $delivery_date ) );
                 echo '<div style="margin-bottom: 15px;">';
                 echo '<h2>' . esc_html__( 'Delivery Information', 'creaworld-eshop' ) . '</h2>';
                 echo '<p style="margin-bottom: 5px;"><strong>' . esc_html__( 'Selected Delivery Date:', 'creaworld-eshop' ) . '</strong> ' . esc_html( $formatted_date ) . '</p>';
                 $delivery_time = $order->get_meta( self::DELIVERY_TIME_SLOT_META_KEY );
                 if ( $delivery_time ) {
                     $time_slot_display = $this->get_time_slot_display_text($delivery_time);
                     echo '<p style="margin-bottom: 5px;"><strong>' . esc_html__( 'Selected Delivery Time:', 'creaworld-eshop' ) . '</strong> ' . esc_html( $time_slot_display ) . '</p>';
                 }
                 echo '</div>';
            } catch (Exception $e) {
                 echo '<div style="margin-bottom: 15px;">';
                 echo '<h2>' . esc_html__( 'Delivery Information', 'creaworld-eshop' ) . '</h2>';
                 echo '<p><strong>' . esc_html__( 'Selected Delivery Date:', 'creaworld-eshop' ) . '</strong> ' . esc_html( $delivery_date ) . ' (Error formatting date)</p>';
                 $delivery_time = $order->get_meta( self::DELIVERY_TIME_SLOT_META_KEY );
                 if ( $delivery_time ) {
                     $time_slot_display = $this->get_time_slot_display_text($delivery_time);
                     echo '<p style="margin-bottom: 5px;"><strong>' . esc_html__( 'Selected Delivery Time:', 'creaworld-eshop' ) . '</strong> ' . esc_html( $time_slot_display ) . '</p>';
                 }
                 echo '</div>';
            }
        } elseif ( $order->get_meta( self::DELIVERY_TIME_SLOT_META_KEY ) ) {
            // Case where date might be missing but time slot exists (unlikely but possible)
            $delivery_time = $order->get_meta( self::DELIVERY_TIME_SLOT_META_KEY );
            $time_slot_display = $this->get_time_slot_display_text($delivery_time);
            echo '<div style="margin-bottom: 15px;">';
            echo '<h2>' . esc_html__( 'Delivery Information', 'creaworld-eshop' ) . '</h2>';
            echo '<p style="margin-bottom: 5px;"><strong>' . esc_html__( 'Selected Delivery Time:', 'creaworld-eshop' ) . '</strong> ' . esc_html( $time_slot_display ) . '</p>';
            echo '</div>';
        } else {
             error_log("No delivery date or time slot found for order ID: " . $order->get_id());
        }
    }

/**
     * Display selected delivery date and time in PDF documents (Invoices/Packing Slips).
     * Hooks into 'wpo_wcpdf_after_order_data'.
     *
     * @param string $template_type The type of PDF document (e.g., 'invoice', 'packing-slip').
     * @param \WC_Order $order The order object.
     */
    public function display_delivery_info_in_pdf( $template_type, $order ) {
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $delivery_date = $order->get_meta( self::DELIVERY_DATE_META_KEY );
        $delivery_time = $order->get_meta( self::DELIVERY_TIME_SLOT_META_KEY );

        if ( $delivery_date ) {
            try {
                $formatted_date = date_i18n( wc_date_format(), strtotime( $delivery_date ) );
                ?>
                <tr class="tionglian-delivery-date">
                    <th><?php esc_html_e( 'Delivery Date:', 'creaworld-eshop' ); ?></th>
                    <td><?php echo esc_html( $formatted_date ); ?></td>
                </tr>
                <?php
            } catch (Exception $e) {
                // Output raw date if formatting fails
                ?>
                <tr class="tionglian-delivery-date error">
                    <th><?php esc_html_e( 'Delivery Date:', 'creaworld-eshop' ); ?></th>
                    <td><?php echo esc_html( $delivery_date ); ?> (<?php esc_html_e('format error', 'creaworld-eshop'); ?>)</td>
                </tr>
                <?php
                 error_log("Error formatting delivery date for PDF (Order ID: {$order->get_id()}): " . $e->getMessage());
            }
        }

        if ( $delivery_time ) {
            $time_slot_display = $this->get_time_slot_display_text($delivery_time);
            ?>
            <tr class="tionglian-delivery-time">
                <th><?php esc_html_e( 'Delivery Time:', 'creaworld-eshop' ); ?></th>
                <td><?php echo esc_html( $time_slot_display ); ?></td>
            </tr>
            <?php
        }
    }
    private function get_time_slot_display_text( $time_slot_key ) {
        $parsed_time_slots = $this->get_parsed_time_slots();
        // Return the display text if found, otherwise return the key itself as a fallback
        return isset( $parsed_time_slots[$time_slot_key] ) ? $parsed_time_slots[$time_slot_key] : $time_slot_key;
    }
    
    /**
     * Add inline JavaScript for the admin multi-date picker. (Trying Class Selector + Timeout)
     */
    private function add_admin_datepicker_inline_script() {
        // --- UPDATED SELECTOR ---
        // Target using the specific class added to the input field
        $field_class_selector = '.tionglian-specific-holidays-input';
        // --- END UPDATED SELECTOR ---

        $script = "
            jQuery(document).ready(function($) {
                // Use the class selector
                var fieldSelector = '" . esc_js($field_class_selector) . "';

                // Add a slight delay to ensure the field is rendered
                setTimeout(function() {
                    var dateInput = $(fieldSelector);

                    console.log('Attempting to initialize datepicker (after delay) for:', fieldSelector, 'Found elements:', dateInput.length);

                    if (dateInput.length && typeof $.fn.datepicker === 'function') {

                        // Parse initial dates
                        var selectedDates = dateInput.val() ? dateInput.val().split(',').map(function(s){ return s.trim(); }).filter(Boolean) : [];

                        // Update input function
                        function updateSelectedDatesInput() {
                            selectedDates.sort();
                            dateInput.val(selectedDates.join(', '));
                        }

                        // Highlight function
                        function highlightSelected(date) {
                            var dateString = $.datepicker.formatDate('yy-mm-dd', date);
                            var isSelected = $.inArray(dateString, selectedDates) > -1;
                            return [true, isSelected ? 'multi-date-selected' : '', isSelected ? 'Selected' : ''];
                        }

                        // Initialize datepicker
                        dateInput.datepicker({
                            dateFormat: 'yy-mm-dd',
                            numberOfMonths: 1,
                            beforeShowDay: highlightSelected,
                            onSelect: function(dateText, inst) {
                                var index = $.inArray(dateText, selectedDates);
                                if (index > -1) {
                                    selectedDates.splice(index, 1);
                                } else {
                                    selectedDates.push(dateText);
                                }
                                updateSelectedDatesInput();
                                setTimeout(function() {
                                    if (dateInput.hasClass('hasDatepicker')) {
                                         dateInput.datepicker('show');
                                    }
                                }, 0);
                                $(this).datepicker('refresh');
                            },
                        });

                        // Initial update
                        updateSelectedDatesInput();
                        console.log('Datepicker initialized (after delay) for:', fieldSelector);

                    } else if (dateInput.length === 0) {
                         console.warn('Creaworld TiongLian (after delay): Specific holidays input field not found using class:', fieldSelector);
                    } else {
                         console.error('Creaworld TiongLian Admin (after delay): jQuery UI Datepicker not loaded.');
                    }
                }, 100); // Delay execution by 100 milliseconds

            });
        ";
        wp_add_inline_script('jquery-ui-datepicker', $script);
    }

    /**
     * Injects product categories (including subcategories) into designated menu items.
     * Includes a workaround for mobile navigation where parent links might be unclickable.
     *
     * @param array $items The current menu items.
     * @param object $menu The menu object.
     * @param object $args Menu arguments.
     * @return array Modified menu items.
     */
    public function inject_product_cate_into_menu( $items, $menu, $args ) {

            // --- START DEBUGGING ---
        // Generate a unique ID for this specific page load to group logs
        static $request_id = null;
        if ($request_id === null) {
            $request_id = uniqid('req_', true);
        }
        $menu_identifier = "Menu Name: " . ($menu->name ?? 'N/A') . " | Slug: " . ($menu->slug ?? 'N/A') . " | Location: " . ($args->theme_location ?? 'N/A');
        error_log("[$request_id] --- inject_product_cate_into_menu START --- $menu_identifier");
        // --- END DEBUGGING ---
        // don't add child categories in administration of menus
        if ( is_admin() ) {
            return $items;
        }

        // --- Safer counter initialization ---
        $last_item_id = 0;
        if (!empty($items)) {
            // Find the highest existing ID to avoid potential collisions
            foreach ($items as $item) {
                if (isset($item->ID) && $item->ID > $last_item_id) {
                    $last_item_id = $item->ID;
                }
            }
        }
        // Start our counter safely above existing IDs
        $ctr = $last_item_id + 1;
        // --- End Safer counter initialization ---

        $new_items_added = []; // Keep track of items added in this run

        foreach ( $items as $index => $i ) {

            // Skip items we might have added in a previous iteration of this loop
            if (in_array($i->ID, $new_items_added)) {
                continue;
            }

            // Check if the current item is the placeholder
            if ( ! isset($i->classes) || ! is_array($i->classes) || ! in_array( 'eshop_product_cate_main', $i->classes ) ) {
                continue;
            }

            // --- ADDED DEBUGGING FOR PLACEHOLDER FOUND ---
            error_log("[$request_id] >>> Found placeholder 'eshop_product_cate_main' in Item ID: " . ($i->ID ?? 'N/A') . " within $menu_identifier");
            // --- END DEBUGGING ---

            $hide_empty = true;
            if ( in_array( 'eshop_product_cate_show_empty', $i->classes ) ) {
                $hide_empty = false;
            }

            $menu_parent = $i->ID; // The ID of the placeholder menu item (e.g., "Categories")
            $terms_args = apply_filters( 'cew_menu_product_cate_args', array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => $hide_empty ), $i->classes );
            $terms       = get_terms( $terms_args );

            if (is_wp_error($terms) || empty($terms)) {
                error_log("[$request_id] --- No top-level terms found or WP_Error for placeholder Item ID: " . ($i->ID ?? 'N/A')); // Debugging
                continue; // Skip if no terms found
            }

            error_log("[$request_id] --- Processing " . count($terms) . " top-level terms for placeholder Item ID: " . ($i->ID ?? 'N/A')); // Debugging

            foreach ( $terms as $term ) {
                // 1. Create the main parent menu item
                $new_item = $this->custom_nav_menu_item( $term->name, get_term_link( $term ), $ctr, $menu_parent );
                $new_item->classes[] = 'eshop-generated-parent-item'; // Add a class for potential styling/JS hooks
                $items[]  = $new_item;
                $new_items_added[] = $new_item->ID; // Track added item
                $new_id   = $new_item->ID; // Get the ID of the item we just added
                $ctr ++;

                // 2. Check for children for this term
                $child_terms_args = array( 'taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => $hide_empty );
                $terms_child = get_terms( $child_terms_args );

                // 3. If children exist, add the workaround link and the actual children
                if ( ! is_wp_error($terms_child) && ! empty( $terms_child ) ) {

                    error_log("[$request_id] --- Found " . count($terms_child) . " child terms for Term ID: " . $term->term_id . " (Name: " . $term->name . ")"); // Debugging

                    // *** ADDED WORKAROUND LINK ***
                    $view_parent_item = $this->custom_nav_menu_item(
                        // Use the text domain of *this* plugin
                        sprintf( __('View All %s', 'creaworld-tionglian'), $term->name ), // <-- Changed text domain
                        get_term_link( $term ), // Links to the PARENT category
                        $ctr,                  // Next menu order
                        $new_id                // Child of the PARENT menu item ($new_item)
                    );
                    $view_parent_item->classes[] = 'eshop-view-parent-link'; // Add class for styling
                    $items[] = $view_parent_item;
                    $new_items_added[] = $view_parent_item->ID; // Track added item
                    $ctr++;
                    // *** END ADDED WORKAROUND LINK ***

                    // 4. Add the actual child category links
                    foreach ( $terms_child as $term_child ) {
                        $new_child = $this->custom_nav_menu_item( $term_child->name, get_term_link( $term_child ), $ctr, $new_id );
                        $new_child->classes[] = 'eshop-generated-child-item';
                        $items[]   = $new_child;
                        $new_items_added[] = $new_child->ID; // Track added item
                        $ctr ++;
                    }
                } else {
                     error_log("[$request_id] --- No child terms found (or WP_Error) for Term ID: " . $term->term_id . " (Name: " . $term->name . ")"); // Debugging
                }
            }
             error_log("[$request_id] <<< Finished adding categories for placeholder Item ID: " . ($i->ID ?? 'N/A')); // Debugging
        }

         error_log("[$request_id] --- inject_product_cate_into_menu END --- $menu_identifier"); // Debugging

        return $items;
    }


    /**
     * Helper function to create a custom menu item object.
     *
     * @param string $title The menu item title.
     * @param string $url The menu item URL.
     * @param int $order The menu item order.
     * @param int $parent The ID of the parent menu item (0 for top level).
     * @return object The menu item object.
     */
    private function custom_nav_menu_item( $title, $url, $order, $parent = 0 ) {
        $item                   = new \stdClass();
        // Using a large base number + order + parent helps avoid collisions with real menu item IDs.
        $item->ID               = 1000000 + $order + $parent;
        $item->db_id            = $item->ID;
        $item->title            = $title;
        $item->url              = $url;
        $item->menu_order       = $order;
        $item->menu_item_parent = $parent; // Parent *menu item* ID
        $item->post_parent      = 0; // *** ADD THIS LINE *** Initialize to 0 for custom items
        $item->type             = 'custom'; // Set type to custom
        $item->object           = 'custom'; // Set object to custom
        $item->object_id        = '';
        $item->classes          = array(); // Initialize classes array
        $item->target           = '';
        $item->attr_title       = '';
        $item->description      = '';
        $item->xfn              = '';
        $item->status           = 'publish'; // Set status

        // Apply filters similar to wp_setup_nav_menu_item for compatibility
        $item = apply_filters( 'wp_setup_nav_menu_item', $item );

        return $item;
    }


    // --- END: Product Category Menu Injection ---

    
} // End class Creaworld_TiongLian

// Initialize the plugin
$plugin_creaworld_tionglian = new Creaworld_TiongLian();
