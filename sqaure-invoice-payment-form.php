<?php
/**
 * Plugin Name: Square Invoice Plugin
 * Description: Multi-step form that sends Square invoices with a payment link. SC: [square_invoice_form]
 * Version: 1.7
 * Author: FLK
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load Required Square API SDK
require_once __DIR__ . '/vendor/autoload.php';
use Square\SquareClient;
use Square\Environments;
use Square\Checkout\PaymentLinks\Requests\CreatePaymentLinkRequest;
use Square\Types\QuickPay;
use Square\Types\Money;
use Square\Types\Currency;

// Register Custom Post Type for Bookings
function register_bookings_post_type() {
    register_post_type('bookings', [
        'label'  => 'Square Invoices',
        'public' => false,  // Not publicly queryable
        'show_ui' => true,  // Show in admin dashboard
        'supports' => ['title'],  // Only title support, as ACF will handle custom fields
        'capability_type' => 'post',
        'capabilities' => [
            'create_posts' => 'do_not_allow',  // Disable creating new posts manually
        ],
        'map_meta_cap' => true,
    ]);
}
add_action('init', 'register_bookings_post_type');

// Register ACF Fields for Bookings
function register_acf_fields_for_bookings() {
    if (function_exists('acf_add_local_field_group')) {
        acf_add_local_field_group([
            'key' => 'group_bookings_fields',
            'title' => 'Booking Details',
            'fields' => [
                [
                    'key' => 'field_full_name',
                    'label' => 'Full Name',
                    'name' => 'full_name',
                    'type' => 'text',
                    'required' => 1,
                    'readonly' => true,
                ],
                [
                    'key' => 'field_customer_email',
                    'label' => 'Email',
                    'name' => 'customer_email',
                    'type' => 'email',
                    'required' => 1,
                    'readonly' => true,
                ],
                [
                    'key' => 'field_phone',
                    'label' => 'Phone',
                    'name' => 'phone',
                    'type' => 'text',
                    'required' => 1,
                    'readonly' => true,
                ],
                [
                    'key' => 'field_message',
                    'label' => 'Message',
                    'name' => 'message',
                    'type' => 'textarea',
                    'readonly' => true,
                ],
                [
                    'key' => 'field_total_amount',
                    'label' => 'Total Amount',
                    'name' => 'total_amount',
                    'type' => 'number',
                    'required' => 1,
                    'step' => '0.01',
                    'readonly' => true,
                ],
                [
                    'key' => 'field_is_scheduled',
                    'label' => 'Is Scheduled?',
                    'name' => 'is_scheduled',
                    'type' => 'true_false',
                    'ui' => 1,
                    'readonly' => true,
                ],
                [
                    'key' => 'field_scheduled_date',
                    'label' => 'Scheduled Date',
                    'name' => 'scheduled_date',
                    'type' => 'date_picker',
                    'conditional_logic' => [
                        [
                            [
                                'field' => 'field_is_scheduled',
                                'operator' => '==',
                                'value' => '1',
                            ],
                        ],
                    ],
                    'readonly' => true,
                ],
                [
                    'key' => 'field_payment_link',
                    'label' => 'Payment Link',
                    'name' => 'payment_link',
                    'type' => 'url',
                    'readonly' => true,
                ],
            ],
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'bookings',
                    ],
                ],
            ],
        ]);
    }
}
add_action('acf/init', 'register_acf_fields_for_bookings');

// Make ACF fields read-only in the admin
function make_acf_fields_read_only($field) {
    $read_only_fields = [
        'field_full_name',
        'field_customer_email',
        'field_phone',
        'field_message',
        'field_total_amount',
        'field_is_scheduled',
        'field_scheduled_date',
        'field_payment_link',
    ];

    if (in_array($field['key'], $read_only_fields)) {
        $field['readonly'] = true;
    }

    return $field;
}
add_filter('acf/load_field', 'make_acf_fields_read_only');

// Enqueue custom stylesheet and Flatpickr
function square_invoice_enqueue_scripts() {
    wp_register_style('square-invoice-style', plugins_url('styles.css', __FILE__));
    wp_enqueue_style('square-invoice-style');

    wp_enqueue_style('flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
    wp_enqueue_script('flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js', [], null, true);
}
add_action('wp_enqueue_scripts', 'square_invoice_enqueue_scripts');

// Admin Settings Menu for Square API
function square_invoice_admin_menu() {
    add_menu_page('Square Settings', 'Square Config ', 'manage_options', 'square-api-settings', 'square_invoice_settings_page');
}
add_action('admin_menu', 'square_invoice_admin_menu');

// Admin Settings Page
function square_invoice_settings_page() {
    ?>
    <div class="wrap">
        <h1>Square API Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('square_invoice_settings_group');
            do_settings_sections('square-api-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register Settings
function square_invoice_register_settings() {
    register_setting('square_invoice_settings_group', 'square_access_token');
    register_setting('square_invoice_settings_group', 'square_location_id');
    register_setting('square_invoice_settings_group', 'square_environment');
    
    add_settings_section('square_api_section', 'Square API Configuration', null, 'square-api-settings');

    add_settings_field('square_access_token', 'Square Access Token', 'square_access_token_callback', 'square-api-settings', 'square_api_section');
    add_settings_field('square_location_id', 'Square Location ID', 'square_location_id_callback', 'square-api-settings', 'square_api_section');
    add_settings_field('square_environment', 'Square Environment', 'square_environment_callback', 'square-api-settings', 'square_api_section');
}
add_action('admin_init', 'square_invoice_register_settings');

// Callback for Access Token Field
function square_access_token_callback() {
    $access_token = get_option('square_access_token');
    echo '<input type="text" name="square_access_token" value="' . esc_attr($access_token) . '" class="regular-text">';
}

// Callback for Location ID Field
function square_location_id_callback() {
    $location_id = get_option('square_location_id');
    echo '<input type="text" name="square_location_id" value="' . esc_attr($location_id) . '" class="regular-text">';
}

// Callback for Environment Dropdown
function square_environment_callback() {
    $environment = get_option('square_environment');
    ?>
    <select name="square_environment">
        <option value="sandbox" <?php selected($environment, 'sandbox'); ?>>Sandbox</option>
        <option value="production" <?php selected($environment, 'production'); ?>>Production</option>
    </select>
    <?php
}

// Shortcode to Render the Multi-step Form
function render_square_invoice_form() {
    wp_enqueue_script('jquery');
    ob_start();
    ?>
    <form id="multi-step-form">
        <!-- Step 1: User Info -->
        <div class="step step-1">
            <div class="squp-field">
                <input type="text" name="full_name" placeholder="Full Name" required>
            </div>
            <div class="squp-field">
                <input type="email" name="customer_email" placeholder="Email" required>
            </div>
            <div class="squp-field">
                <input type="tel" name="phone" placeholder="Phone" required>
            </div>
            <div class="squp-field">
                <textarea name="message" placeholder="Invoice Description"></textarea>
            </div>
            <div class="squp-field squp-pricebox">
                <span class="dollar-symbol">$</span>
                <input type="number" name="price" placeholder="100" step="1" min="0" required>
            </div>
            <div class="squp-field">
                <label>
                    <input type="checkbox" name="schedule_invoice" id="schedule-invoice-checkbox"> Schedule Invoice
                </label>
            </div>
            <div class="squp-field" id="schedule-date-field" style="display: none;">
                <input type="text" name="schedule_date" id="schedule-date" placeholder="Select Date">
            </div>
            <div class="squp-field">
                <p class="error-message" style="color:red;display:none;">Please fill all fields.</p>
                <div class="button-flex">
                    <button type="button" class="next-step">Next</button>
                </div>
            </div>
        </div>

        <!-- Step 2: Show Invoice Summary -->
        <div class="step step-2" style="display: none;">
            <h3>Invoice Summary</h3>
            <table class="invoice-summary-table">
                <tr>
                    <td><strong>Name</strong></td>
                    <td><span id="user-name"></span></td>
                </tr>
                <tr>
                    <td><strong>Email</strong></td>
                    <td><span id="user-email"></span></td>
                </tr>
                <tr>
                    <td><strong>Phone</strong></td>
                    <td><span id="user-phone"></span></td>
                </tr>
                <tr>
                    <td><strong>Message</strong></td>
                    <td><span id="user-message"></span></td>
                </tr>
                <tr>
                    <td><strong>Total</strong></td>
                    <td>$<span id="total-price">0</span></td>
                </tr>
                <tr id="schedule-summary" style="display: none;">
                    <td><strong>Scheduled Date</strong></td>
                    <td><span id="scheduled-date"></span></td>
                </tr>
            </table>
            <div class="button-flex">
                <button type="button" class="prev-step">Back</button>
                <button type="submit" class="gen-invoice">Generate Invoice <span class="loading-button" style="display:none;"></span></button>
            </div>
        </div>

        <!-- Step 3: Invoice Response -->
        <div class="step step-3" style="display: none;">
            <h3>Invoice Generated</h3>
            <div class="link-copier">
                <label>Copy Link to share</label>
                <input type="url" value="" id="paymentLink"/>
            </div>
        </div>
    </form>

    <script>
    jQuery(document).ready(function($) {
        let currentStep = 1;
        const steps = $(".step");

        // Variables to store user details from Step 1
        let userName = '';
        let userEmail = '';
        let userPhone = '';
        let userMessage = '';
        let userPrice = 0;
        let isScheduled = false;
        let scheduledDate = '';

        // Initialize Flatpickr
        $("#schedule-date").flatpickr({
            inline: false,
            enableTime: false,
            todayHighlight: true,
            minDate: "today",
            dateFormat: "F j, Y",
            disable: [
                function(date) {
                    // Disable the next X days after today
                    let disabledDaysCount = 3;
                    let today = new Date();
                    today.setHours(0, 0, 0, 0);
                    let disabledUntil = new Date(today);
                    disabledUntil.setDate(today.getDate() + disabledDaysCount);
                    return date < disabledUntil;
                }
            ]
        });

        // Toggle Schedule Date Field
        $("#schedule-invoice-checkbox").on("change", function() {
            if ($(this).is(":checked")) {
                $("#schedule-date-field").show();
            } else {
                $("#schedule-date-field").hide();
            }
        });

        // Function to show the current step
        function showStep(step) {
            steps.hide();
            $(".step-" + step).show();
            currentStep = step;

            // If step 2 is shown, populate user details
            if (step === 2) {
                $("#user-name").text(userName);
                $("#user-email").text(userEmail);
                $("#user-phone").text(userPhone);
                $("#user-message").text(userMessage);
                $("#total-price").text(userPrice.toFixed(2));

                if (isScheduled) {
                    $("#schedule-summary").show();
                    $("#scheduled-date").text(scheduledDate);
                } else {
                    $("#schedule-summary").hide();
                }
            }
        }

        // Next Step Button Click Handler
        $(".next-step").on("click", function() {
            let valid = true;
            $(".error-message").hide();

            // Validate Step 1
            if (currentStep === 1) {
                userName = $("input[name='full_name']").val();
                userEmail = $("input[name='customer_email']").val();
                userPhone = $("input[name='phone']").val();
                userMessage = $("textarea[name='message']").val();
                userPrice = parseFloat($("input[name='price']").val());
                isScheduled = $("#schedule-invoice-checkbox").is(":checked");
                scheduledDate = $("#schedule-date").val();

                if (!userName || !userEmail || !userPhone || isNaN(userPrice) || userPrice <= 0) {
                    $(".step-1 .error-message").show();
                    valid = false;
                }

                if (isScheduled && !scheduledDate) {
                    alert("Please select a date for scheduling.");
                    valid = false;
                }
            }

            // Proceed to the next step if validation passes
            if (valid) {
                showStep(currentStep + 1);
            }
        });

        // Previous Step Button Click Handler
        $(".prev-step").on("click", function() {
            showStep(currentStep - 1);
        });

        // Form Submit Handler
        $("#multi-step-form").on("submit", function(e) {
            e.preventDefault();
            
            // Get all form values
            let formData = $(this).serializeArray();
            // Add the price as a separate field to ensure proper formatting
            formData.push({name: 'price_value', value: userPrice});

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: $.param(formData) + '&action=square_create_payment_link',
                beforeSend: function() {
                    $('.gen-invoice').text('Generating..');
                    $('span.loading-button').show();
                },
                success: function(response) {
                    showStep(3);
                    $('.gen-invoice').text('Generate Invoice');
                    $('span.loading-button').hide();
                    $('#paymentLink').val(response.data.response.payment_link.url);

                    // Save the payment link to the backend
                    SavePaymaneLink(response.data.response.payment_link.url, response.data.booking_id);
                },
                error: function(xhr, status, error) {
                    alert('Error: ' + error);
                    $('.gen-invoice').text('Generate Invoice');
                    $('span.loading-button').hide();
                }
            });
        });

        // Function to save the payment link to the backend
        function SavePaymaneLink(PayLink, bookingId) {
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'save_pay_link_backend',
                    paylink: PayLink,
                    booking_id: bookingId
                },
                success: function(response) {
                    console.log('Payment link saved:', response);
                }
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('square_invoice_form', 'render_square_invoice_form');

function square_create_payment_link() {
    // Check if required fields are set
    if (!isset($_POST['full_name']) || !isset($_POST['customer_email']) || !isset($_POST['phone']) || !isset($_POST['price'])) {
        wp_send_json_error(['message' => 'Required fields are missing.']);
    }

    // Sanitize input data
    $fullname = sanitize_text_field($_POST['full_name']);
    $customer_email = sanitize_email($_POST['customer_email']);
    $phonenumber = sanitize_text_field($_POST['phone']);
    $message = sanitize_textarea_field($_POST['message']);
    $price = floatval($_POST['price']);
    $isScheduled = isset($_POST['schedule_invoice']) ? true : false;
    $scheduledDate = $isScheduled ? sanitize_text_field($_POST['schedule_date']) : '';

    // Validate price
    if ($price <= 0) {
        wp_send_json_error(['message' => 'Price must be greater than 0.']);
    }

    // Convert price to cents for Square API
    $priceInCents = (int) round($price * 100);

    // Fetch Square API credentials
    $access_token = get_option('square_access_token');
    $location_id = get_option('square_location_id');
    $environment = get_option('square_environment', 'sandbox');

    if (empty($access_token) || empty($location_id)) {
        wp_send_json_error(['message' => 'Square API credentials are missing.']);
    }

    try {
        // Initialize Square Client
        $client = new SquareClient(
            token: $access_token,
            options: [
                'baseUrl' => ($environment === 'production') ? Environments::Production->value : Environments::Sandbox->value,
            ],
        );

        // Create Payment Link
        $response = $client->checkout->paymentLinks->create(
            new CreatePaymentLinkRequest([
                'idempotencyKey' => uniqid(),
                'quickPay' => new QuickPay([
                    'locationId' => $location_id,
                    'priceMoney' => new Money([
                        'amount' => $priceInCents,
                        'currency' => Currency::Usd->value,
                    ]),
                    'name' => $fullname,
                ]),
                'description' => $message,
                'paymentNote' => $isScheduled ? 'This invoice is Scheduled on: '. $scheduledDate : ''
            ]),
        );

        // Save the booking details to the custom post type
        $booking_id = wp_insert_post([
            'post_title' => 'Invoice - ' . $fullname,
            'post_type' => 'bookings',
            'post_status' => 'publish',
        ]);

        if ($booking_id) {
            // Save data to ACF fields
            update_field('field_full_name', $fullname, $booking_id);
            update_field('field_customer_email', $customer_email, $booking_id);
            update_field('field_phone', $phonenumber, $booking_id);
            update_field('field_message', $message, $booking_id);
            update_field('field_total_amount', $price, $booking_id);
            update_field('field_is_scheduled', $isScheduled, $booking_id);

            if ($isScheduled && !empty($scheduledDate)) {
                update_field('field_scheduled_date', $scheduledDate, $booking_id);
            }

            wp_send_json_success([
                'message' => "Invoice sent. Payment Link:",
                'booking_data' => [
                    'full_name' => $fullname,
                    'customer_email' => $customer_email,
                    'phone' => $phonenumber,
                    'message' => $message,
                    'total_amount' => $price,
                    'is_scheduled' => $isScheduled,
                    'scheduled_date' => $scheduledDate,
                    'payment_link' => $response->payment_link->url,
                ],
                'response' => $response,
                'booking_id' => $booking_id,
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to save booking.']);
        }
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
    }
}
add_action('wp_ajax_square_create_payment_link', 'square_create_payment_link');
add_action('wp_ajax_nopriv_square_create_payment_link', 'square_create_payment_link');

// Save Payment Link Backend
function save_pay_link_backend() {
    if (isset($_POST['paylink']) && isset($_POST['booking_id'])) {
        $paylink = sanitize_text_field($_POST['paylink']);
        $booking_id = intval($_POST['booking_id']);

        if ($booking_id && $paylink) {
            update_field('field_payment_link', $paylink, $booking_id);
            wp_send_json_success(['message' => 'Payment link saved successfully.']);
        } else {
            wp_send_json_error(['message' => 'Invalid data.']);
        }
    } else {
        wp_send_json_error(['message' => 'Required fields are missing.']);
    }
}
add_action('wp_ajax_save_pay_link_backend', 'save_pay_link_backend');
add_action('wp_ajax_nopriv_save_pay_link_backend', 'save_pay_link_backend');