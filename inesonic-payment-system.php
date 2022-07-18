<?php
/**
 * Plugin Name: Inesonic Payment System
 * Plugin URI: http://www.inesonic.com
 * Description: A small proprietary plug-in that manages the Stripe payment system.
 * Version: 1.0.0
 * Author: Inesonic, LLC
 * Author URI: http://www.inesonic.com
 */

/***********************************************************************************************************************
 * Copyright 2022, Inesonic, LLC.
 * All Rights Reserved
 ***********************************************************************************************************************
 */

require_once "/home/www/stripe_secrets.php";

require_once dirname(__FILE__) . "/include/helpers.php";

/* Inesonic WordPress customization class. */
class InesonicPaymentSystem extends StripeSecrets {
    const VERSION = '1.0.0';
    const SLUG    = 'inesonic-payment-system';
    const NAME    = 'Inesonic Payment System';
    const AUTHOR  = 'Inesonic, LLC';
    const PREFIX  = 'InesonicPaymentSystem';

    /**
     * The namespace that we need to perform auto-loading for.
     */
    const PLUGIN_NAMESPACE = 'Inesonic\\PaymentSystem\\';

    /**
     * The plug-in include path.
     */
    const INCLUDE_PATH = __DIR__ . '/include/';

    /**
     * Stripe inbound API endpoint.
     */
    const REST_API_NAMESPACE = 'v1';

    /**
     * The subscription status change webhook
     */
    const CUSTOMER_STATUS_CHANGED_ENDPOINT = "customer_status_changed";

    /**
     * The checkout page slug.
     */
    const CHECKOUT_SLUG = "checkout";

    /**
     * The purchase webhook
     */
    const PURCHASE_ENDPOINT = "purchase";

    /* The email address to report fatal errors to. */
    const ERROR_REPORT_DESTINATION = "website.administrator@autonoma.inesonic.com";

    /**
     * The singleton instance.
     */
    private static $instance;

    /**
     * The plug-in directory.
     */
    public static  $dir = '';

    /**
     * The plug-in URL.
     */
    public static  $url = '';

    /* Method that is called to initialize a single instance of the plug-in */
    public static function instance() {
        if (!isset(self::$instance) && !(self::$instance instanceof InesonicPaymentSystem)) {
            spl_autoload_register(array(self::class, 'autoloader'));

            self::$instance = new InesonicPaymentSystem();
            self::$dir      = plugin_dir_path(__FILE__);
            self::$url      = plugin_dir_url(__FILE__);
        }
    }

    /**
     * Static method that is triggered when the plug-in is activated.
     */
    public static function plugin_activated() {
        if (defined('ABSPATH') && current_user_can('activate_plugins')) {
            $plugin = isset($_REQUEST['plugin']) ? sanitize_text_field($_REQUEST['plugin']) : '';
            if (check_admin_referer('activate-plugin_' . $plugin)) {
                global $wpdb;
                $wpdb->query(
                    'CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 'inesonic_subscriptions' . ' (' .
                        'user_id BIGINT UNSIGNED NOT NULL,' .
                        'stripe_customer_id VARCHAR(32) DEFAULT NULL,' .
                        'stripe_subscription_id VARCHAR(32) DEFAULT NULL,' .
                        'PRIMARY KEY (user_id),' .
                        'FOREIGN KEY (user_id) REFERENCES ' . $wpdb->prefix . 'users (ID) ' .
                            'ON DELETE CASCADE ' .
                    ')'
                );
                $wpdb->query(
                    'CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 'inesonic_checkout_session' . ' (' .
                        'user_id BIGINT UNSIGNED NOT NULL,' .
                        'stripe_checkout_session_id VARCHAR(72) NOT NULL,' .
                        'product_id VARCHAR(12) NOT NULL,' .
                        'payment_term VARCHAR(12) NOT NULL,' .
                        'quantity MEDIUMINT NOT NULL,' .
                        'PRIMARY KEY (user_id),' .
                        'FOREIGN KEY (user_id) REFERENCES ' . $wpdb->prefix . 'users (ID) ' .
                            'ON DELETE CASCADE ' .
                    ')'
                );
            }
        }
    }

    /**
     * Static method that is triggered when the plug-in is deactivated.
     */
    public static function plugin_deactivated() {
    }

    /**
     * Static method that is triggered when the plug-in is uninstalled.
     */
    public static function plugin_uninstalled() {
        if (defined('ABSPATH') && current_user_can('activate_plugins')) {
            $plugin = isset($_REQUEST['plugin']) ? sanitize_text_field($_REQUEST['plugin']) : '';
            if (check_admin_referer('deactivate-plugin_' . $plugin)) {
                global $wpdb;
                $wpdb->query('DROP TABLE ' . $wpdb->prefix . 'inesonic_subscriptions');
                $wpdb->query('DROP TABLE ' . $wpdb->prefix . 'inesonic_checkout_session');
            }
        }
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->stripe_api = new \Inesonic\PaymentSystem\StripeApi(
            $this->stripe_public_key(),
            $this->stripe_secret_key()
        );

        $this->stripe_inbound_api = new \Inesonic\PaymentSystem\StripeInboundApi(
            self::REST_API_NAMESPACE,
            $this->stripe_secret_key()
        );

        add_action('init', array($this, 'customize_on_initialization'));

        add_action('delete_user', array($this, 'about_to_delete_user'), 10, 3);

        add_filter('inesonic-filter-page-registration-complete', array($this, 'registration_completed'));
        add_filter('inesonic-filter-page-purchase', array($this, 'purchase'));
        add_filter('inesonic-filter-page-my-account', array($this, 'my_account'));
        add_filter('inesonic-filter-page-billing', array($this, 'billing'));

        add_action(
            'inesonic-stripe-message-invoice-payment-succeeded',
            array($this, 'invoice_payment_succeeded'),
            10,
            1
        );
        add_action(
            'inesonic-stripe-message-customer-subscription-created',
            array($this, 'stripe_subscription_updated'),
            10,
            1
        );
        add_action(
            'inesonic-stripe-message-customer-subscription-updated',
            array($this, 'stripe_subscription_updated'),
            10,
            1
        );
        add_action(
            'inesonic-stripe-message-customer-subscription-deleted',
            array($this, 'stripe_subscription_deleted'),
            10,
            1
        );
        add_action(
            'inesonic-stripe-message-customer-subscription-trial-will-end',
            array($this, 'stripe_subscription_trial_ending'),
            10,
            1
        );
        add_action(
            'inesonic-stripe-message-invoice-payment-failed',
            array($this, 'invoice_payment_failed'),
            10,
            1
        );
        add_action(
            'inesonic-stripe-message-invoice-payment-action-required',
            array($this, 'invoice_payment_action_required'),
            10,
            1
        );

        /* Action: inesonic-payment-system-update-stripe-ids
         *
         * Action you can use to manually update Stripe IDs from other plugins.
         *
         * Parameters:
         *
         *    $inesonic_customer_id -   The user ID of the customer to tie the Stripe IDs to.
         *
         *    $stripe_customer_id -     The Stripe customer ID.  Value can be set to null.
         *
         *    $stripe_subscription_id - The Stripe subscription ID.  Value can be set to null.
         */
        add_action(
            'inesonic-payment-system-update-stripe-ids',
            array($this, 'update_stripe_ids'),
            10,
            3
        );

        /* Action: inesonic-payment-system-update-quantity
         *
         * Action you can use to manually update a product quantity associated with a customer.  The subscription will
         * be prorated based on the increased/decreased quantity value.
         *
         * Parameters:
         *
         *    $inesonic_customer_id - The user ID of the customer to tie the Stripe IDs to.
         *
         *    $new_quantity -         The new quantity for the customer.
         */
        add_action(
            'inesonic-payment-system-update-quantity',
            array($this, 'update_quantity'),
            10,
            2
        );

        /* Filter: inesonic-payment-system-subscription-data
         *
         * Filter that gets updated subscription data for a given customer.
         *
         * Parameters:
         *
         *    $default_value - The default value to be returned if the customer has no subscription.
         *
         *    $user_id -       The user ID of the user to get subscription data for.
         *
         * Returns:
         *
         *    Returns the updated subscription data information.
         */
        add_filter(
            'inesonic-payment-system-subscription-data',
            array($this, 'get_subscription_data'),
            10,
            2
        );

        /* Filter: inesonic-payment-system-product-data
         *
         * Filter that gets updated product data.
         *
         * Parameters:
         *
         *    $default_value - The default value to be returned if the customer has no subscription.
         *
         * Returns:
         *
         *    Returns the current product data.  Information is retrieved from Stripe.
         */
        add_filter(
            'inesonic-payment-system-product-data',
            array($this, 'get_payment_product_data'),
            10,
            1
        );

        /* Action: inesonic-payment-system-cancel-subscription
         *
         * Action you can use to manually cancel a subscription.
         *
         * Parameters:
         *
         *    $inesonic_customer_id - The user ID of the customer to tie the Stripe IDs to.
         */
        add_action(
            'inesonic-payment-system-cancel-subscription',
            array($this, 'cancel_subscription'),
            10,
            1
        );

        $this->all_product_data = null;
    }

    /**
     * Autoloader callback.
     *
     * \param[in] $class_name The name of this class.
     */
    static public function autoloader($class_name) {
        if (!class_exists($class_name) && str_starts_with($class_name, self::PLUGIN_NAMESPACE)) {
            $class_basename = str_replace(self::PLUGIN_NAMESPACE, '', $class_name);
            $last_was_lower = false;
            $filename = "";
            for ($i=0 ; $i<strlen($class_basename) ; ++$i) {
                $c = $class_basename[$i];
                if (ctype_upper($c)) {
                    if ($last_was_lower) {
                        $filename .= '-' . strtolower($c);
                        $last_was_lower = false;
                    } else {
                        $filename .= strtolower($c);
                    }
                } else {
                    $filename .= $c;
                    $last_was_lower = true;
                }
            }

            $filename .= '.php';
            $filepath = self::INCLUDE_PATH . $filename;
            if (file_exists($filepath)) {
                include $filepath;
            } else {
                $filepath = __DIR__ . DIRECTORY_SEPARATOR . $filename;
                if (file_exists($filepath)) {
                    include $filepath;
                }
            }
        }
    }

    /**
     * Method that performs various initialization tasks during WordPress init phase.
     */
    function customize_on_initialization() {
        add_action('wp_ajax_inesonic_payment_system_check_transaction' , array($this, 'check_transaction'));
        add_action('wp_ajax_inesonic_payment_system_product_data' , array($this, 'product_data'));
        add_action('wp_ajax_inesonic_payment_system_upgrade' , array($this, 'upgrade'));

        add_shortcode('inesonic_payment_system_add_javascript', array($this, 'inesonic_payment_system_add_javascript'));
    }

    /**
     * Method that is triggered just before we delete a user from the database.
     *
     * \param[in] $user_id       The user ID of the user that is being deleted.
     *
     * \param[in] $reassigned_to The user ID of the user taking over this user.  A value of null indicates no
     *                           reassignemnt.
     *
     * \param[in] $user_data     The WP_User object for the user being deleted.
     */
    public function about_to_delete_user($user_id, $reassigned_to, $user_data) {
        // We don't use get_stripe_customer_id because we don't want to create the Stripe customer record if it doesn't
        // exist.

        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stripe_customer_id FROM ' . $wpdb->prefix . 'inesonic_subscriptions' . ' WHERE ' .
                    'user_id = %d',
                $user_id
            ),
            OBJECT
        );

        if (count($query_result) > 0) {
            $stripe_customer_id = $query_result[0]->stripe_customer_id;
            $this->stripe_api->stripe_delete_customer($stripe_customer_id);
        }
    }

    /**
     * Method that is triggered when an invoice payment has occurred.
     *
     * \param[in] $event_data Data from Stripe describing the event.
     */
    public function invoice_payment_succeeded($event_data) {
        $payment_object = $event_data['data']['object'];
        $stripe_customer_id = sanitize_text_field($payment_object['customer']);

        // On updates, we can end up with two lines in the invoice so we find the first line holding the meta we
        // inserted.
        $line_items = $payment_object['lines']['data'];
        $number_line_items = count($line_items);
        $index = 0;
        while ($index < $number_line_items && intval($line_items[$index]['metadata']['inesonic_customer_id']) == 0) {
            ++$index;
        }

        if ($index < $number_line_items) {
            $metadata = $line_items[$index]['metadata'];

            $inesonic_product_id = sanitize_key($metadata['inesonic_product_id']);
            $inesonic_payment_term = sanitize_key($metadata['inesonic_payment_term']);
            $inesonic_customer_id = intval(sanitize_key($metadata['inesonic_customer_id']));

            $quantity = intval(sanitize_key($payment_object['quantity']));

            // Validate that the message is for a real customer.
            if ($inesonic_customer_id > 0) {
                $user_data = get_user_by('ID', $inesonic_customer_id);
                if ($user_data !== null && $user_data !== false && $user_data->ID == $inesonic_customer_id) {
                    /* Action: inesonic-payment-system-payment-succeeded.
                     *
                     * Action that is triggered whenever a payment has succeeded.
                     *
                     * Parameters:
                     *    $user_data -             The user data for the newly registered user.
                     *
                     *    $inesonic_product_id -   The Inesonic product ID of the subscription object.
                     *
                     *    $inesonic_payment_term - The Inesonic payment term of the subscription object.
                     *
                     *    $stripe_object -         The raw Stripe object.
                     *
                     *    $product_data -          The product data reported from Stripe.
                     */
                    do_action(
                        'inesonic-payment-system-payment-succeeded',
                        $user_data,
                        $inesonic_product_id,
                        $inesonic_payment_term,
                        $payment_object,
                        $this->get_product_data()
                    );
                }
            } else {
                self::log_error(
                    'Inesonic\PaymentSystem\invoice_payment_succeeded: ' .
                    'Customer ' . $inesonic_customer_id . ' does not exist.'
                );
            }
        } else {
            self::log_error(
                'Inesonic\PaymentSystem\invoice_payment_succeeded: ' .
                'Customer ' . $payment_object->id . ' is invalid.'
            );
        }
    }

    /**
     * Method that is triggered when an invoice payment has failed.
     *
     * \param[in] $event_data Data from Stripe describing the event.
     */
    public function invoice_payment_failed($event_data) {
        $payment_object = $event_data['data']['object'];
        $stripe_customer_id = sanitize_text_field($payment_object['customer']);

        $line_items = $payment_object['lines'];
        if (count($line_items) > 0) {
            $line_item = $line_items[0];
            $metadata = $line_item['metadata'];

            $inesonic_product_id = sanitize_key($metadata['inesonic_product_id']);
            $inesonic_payment_term = sanitize_key($metadata['inesonic_payment_term']);
            $inesonic_customer_id = intval(sanitize_key($metadata['inesonic_customer_id']));

            $quantity = intval(sanitize_key($payment_object['quantity']));

            // Validate that the message is for a real customer.
            if ($inesonic_customer_id > 0) {
                $user_data = get_user_by('ID', $inesonic_customer_id);
                if ($user_data !== null && $user_data !== false && $user_data->ID == $inesonic_customer_id) {
                    /* Action: inesonic-payment-system-payment-failed.
                     *
                     * Action that is triggered whenever a payment failed.
                     *
                     * Parameters:
                     *    $user_data -             The user data for the newly registered user.
                     *
                     *    $inesonic_product_id -   The Inesonic product ID of the subscription object.
                     *
                     *    $inesonic_payment_term - The Inesonic payment term of the subscription object.
                     *
                     *    $stripe_object -         The raw Stripe object.
                     *
                     *    $product_data -          The product data reported from Stripe.
                     */
                    do_action(
                        'inesonic-payment-system-payment-failed',
                        $user_data,
                        $inesonic_product_id,
                        $inesonic_payment_term,
                        $payment_object,
                        $this->get_product_data()
                    );
                }
            } else {
                self::log_error(
                    'Inesonic\PaymentSystem\invoice_payment_failed: ' .
                    'Customer ' . $inesonic_customer_id . ' does not exist.'
                );
            }
        } else {
            self::log_error(
                'Inesonic\PaymentSystem\invoice_payment_failed: ' .
                'Customer ' . $inesonic_customer_id . ' is invalid.'
            );
        }
    }

    /**
     * Method that is triggered when an invoice payment requires user action.
     *
     * \param[in] $event_data Data from Stripe describing the event.
     */
    public function invoice_payment_action_required($event_data) {
        $payment_object = $event_data['data']['object'];
        $stripe_customer_id = sanitize_text_field($payment_object['customer']);

        $line_items = $payment_object['lines'];
        if (count($line_items) > 0) {
            $line_item = $line_items[0];
            $metadata = $line_item['metadata'];

            $inesonic_product_id = sanitize_key($metadata['inesonic_product_id']);
            $inesonic_payment_term = sanitize_key($metadata['inesonic_payment_term']);
            $inesonic_customer_id = intval(sanitize_key($metadata['inesonic_customer_id']));

            $quantity = intval(sanitize_key($payment_object['quantity']));

            // Validate that the message is for a real customer.
            if ($inesonic_customer_id > 0) {
                $user_data = get_user_by('ID', $inesonic_customer_id);
                if ($user_data !== null && $user_data !== false && $user_data->ID == $inesonic_customer_id) {
                    /* Action: inesonic-payment-system-payment-action-required.
                     *
                     * Action that is triggered whenever a payment requires customer action.
                     *
                     * Parameters:
                     *    $user_data -             The user data for the newly registered user.
                     *
                     *    $inesonic_product_id -   The Inesonic product ID of the subscription object.
                     *
                     *    $inesonic_payment_term - The Inesonic payment term of the subscription object.
                     *
                     *    $stripe_object -         The raw Stripe object.
                     *
                     *    $product_data -          The product data reported from Stripe.
                     */
                    do_action(
                        'inesonic-payment-system-payment-action-required',
                        $user_data,
                        $inesonic_product_id,
                        $inesonic_payment_term,
                        $payment_object,
                        $this->get_product_data()
                    );
                }
            } else {
                self::log_error(
                    'Inesonic\PaymentSystem\invoice_payment_action_required: ' .
                    'Customer ' . $inesonic_customer_id . ' does not exist.'
                );
            }
        } else {
            self::log_error(
                'Inesonic\PaymentSystem\invoice_payment_action_required: ' .
                'Customer ' . $inesonic_customer_id . ' is invalid.'
            );
        }
    }

    /**
     * Method that is triggered when a Stripe subscription has been created or updated.
     *
     * \param[in] $event_data Data from Stripe describing the event.
     */
    public function stripe_subscription_updated($event_data) {
        $subscription_object = $event_data['data']['object'];
        $stripe_subscription_id = sanitize_text_field($subscription_object['id']);
        $stripe_customer_id = sanitize_text_field($subscription_object['customer']);

        $metadata = $subscription_object['metadata'];
        $inesonic_product_id = sanitize_key($metadata['inesonic_product_id']);
        $inesonic_payment_term = sanitize_key($metadata['inesonic_payment_term']);
        $inesonic_customer_id = intval(sanitize_key($metadata['inesonic_customer_id']));

        $quantity = intval(sanitize_key($subscription_object['quantity']));
        $cancel_at_period_end = $subscription_object['cancel_at_period_end'] === true;
        $current_status = sanitize_key($subscription_object['status']);

        // Validate that the message is for a real customer.
        if ($inesonic_customer_id > 0) {
            $user_data = get_user_by('ID', $inesonic_customer_id);
            if ($user_data !== null && $user_data !== false) {
                $this->clear_pending_transaction(
                    $inesonic_customer_id,
                    $inesonic_product_id,
                    $inesonic_payment_term,
                    $quantity
                );

                $this->update_stripe_subscription_id(
                    $inesonic_customer_id,
                    $stripe_customer_id,
                    $stripe_subscription_id
                );

                /* Action: inesonic-payment-system-subscription-updated.
                 *
                 * Action that is triggered whenever a subscription is updated.
                 *
                 * Parameters:
                 *    $user_data -             The user data for the newly registered user.
                 *
                 *    $inesonic_product_id -   The Inesonic product ID of the subscription object.
                 *
                 *    $inesonic_payment_term - The Inesonic payment term of the subscription object.
                 *
                 *    $status -                The new status.  This is the raw value from Stripe and will be one of:
                 *                             - active
                 *                             - past_due
                 *                             - unpaid
                 *                             - canceled
                 *                             - incomplete
                 *                             - incomplete_expired
                 *                             - trialing
                 *
                 *    $cancel_at_period_end -  Flag indicating that the subscription is to be cancelled at period end.
                 *
                 *    $stripe_object -         The raw Stripe object.
                 *
                 *    $product_data -          The product data reported from Stripe.
                 */
                do_action(
                    'inesonic-payment-system-subscription-updated',
                    $user_data,
                    $inesonic_product_id,
                    $inesonic_payment_term,
                    $current_status,
                    $cancel_at_period_end,
                    $subscription_object,
                    $this->get_product_data()
                );
            } else {
                self::log_error(
                    'Inesonic\PaymentSystem\stripe_subscription_updated: ' .
                    'Customer ' . $inesonic_customer_id . ' does not exist.'
                );
            }
        } else {
            self::log_error(
                'Inesonic\PaymentSystem\stripe_subscription_updated: ' .
                'Customer ' . $inesonic_customer_id . ' is invalid.'
            );
        }
    }

    /**
     * Method that is triggered when a Stripe subscription has been deleted.
     *
     * \param[in] $event_data Data from Stripe describing the event.
     */
    public function stripe_subscription_deleted($event_data) {
        $subscription_object = $event_data['data']['object'];
        $stripe_subscription_id = sanitize_text_field($subscription_object['id']);
        $stripe_customer_id = sanitize_text_field($subscription_object['customer']);

        $metadata = $subscription_object['metadata'];
        $inesonic_product_id = sanitize_key($metadata['inesonic_product_id']);
        $inesonic_payment_term = sanitize_key($metadata['inesonic_payment_term']);
        $inesonic_customer_id = intval(sanitize_key($metadata['inesonic_customer_id']));

        // Validate that the message is for a real customer.
        if ($inesonic_customer_id > 0) {
            $user_data = get_user_by('ID', $inesonic_customer_id);
            if ($user_data !== null && $user_data !== false) {
                /* Action: inesonic-payment-system-subscription-deleted.
                 *
                 * Action that is triggered whenever a subscription is deleted.
                 *
                 * Parameters:
                 *    $user_data -             The user data for the newly registered user.
                 *
                 *    $inesonic_product_id -   The Inesonic product ID of the subscription object.
                 *
                 *    $inesonic_payment_term - The Inesonic payment term of the subscription object.
                 *
                 *    $product_data -          The product data structures.
                 */
                do_action(
                    'inesonic-payment-system-subscription-deleted',
                    $user_data,
                    $inesonic_product_id,
                    $inesonic_payment_term,
                    $this->get_product_data()
                );
            } else {
                self::log_error(
                    'Inesonic\PaymentSystem\stripe_subscription_deleted: ' .
                    'Customer ' . $inesonic_customer_id . ' does not exist.'
                );
            }
        } else {
            self::log_error(
                'Inesonic\PaymentSystem\stripe_subscription_deleted: ' .
                'Customer ' . $inesonic_customer_id . ' is invalid.'
            );
        }
    }

    /**
     * Method that is triggered when a Stripe subscription trial is about to end.
     *
     * \param[in] $event_data Data from Stripe describing the event.
     */
    public function stripe_subscription_trial_ending($event_data) {
        $subscription_object = $event_data['data']['object'];
        $stripe_subscription_id = sanitize_text_field($subscription_object['id']);
        $stripe_customer_id = sanitize_text_field($subscription_object['customer']);

        $metadata = $subscription_object['metadata'];
        $inesonic_product_id = sanitize_key($metadata['inesonic_product_id']);
        $inesonic_payment_term = sanitize_key($metadata['inesonic_payment_term']);
        $inesonic_customer_id = intval(sanitize_key($metadata['inesonic_customer_id']));

        $trial_end_timestamp = intval($subscription_object['trial_end']);

        $user_data = get_user_by('ID', $inesonic_customer_id);
        if ($user_data != null && $user_data->ID != 0) {
            /* Action: inesonic-payment-system-subscription-trial-ending.
             *
             * Action that is triggered when a trial period for a subscription is about to end.  You should use this
             * action to send a notification email to the user indicating that they are about to be changed.  This
             * action is triggered roughly 3 days prior to trial end.
             *
             * Parameters:
             *    $user_data -             The user data for the newly registered user.
             *
             *    $inesonic_product_id -   The Inesonic product ID of the subscription object.
             *
             *    $inesonic_payment_term - The Inesonic payment term of the subscription object.
             *
             *    $stripe_object -         The raw Stripe object.
             *
             *    $product_data -          The product data reported from Stripe.
             */
            do_action(
                'inesonic-payment-system-subscription-trial-ending',
                $user_data,
                $inesonic_product_id,
                $inesonic_payment_term,
                $subscription_object,
                $this->get_product_data()
            );
        } else {
            self::log_error(
                'Inesonic\PaymentSystem\stripe_subscription_trial_ending: ' .
                'Customer ' . $inesonic_customer_id . ' is invalid.'
            );
        }
    }

    /**
     * Method that is triggered when the user has completed the registration and WordPress is actively rendering the
     * registration_completed slug.
     *
     * \param[in] $page_value The current page value.
     *
     * \return Return either the contents to be rendered within the page or the supplied $page_value which will cause
     *         the default page contents to be displayed.
     */
    public function registration_completed($page_value) {
        $user = wp_get_current_user();
        if ($user !== null && $user->ID != 0) {
            /* Action: inesonic-payment-system-registration-completed.
             *
             * Action that is triggered whenever a new user is registered through the registration_completed form.
             *
             * Parameters:
             *    $user_data -    The user data for the newly registered user.
             *
             *    $product_data - The product data reported from Stripe.
             */
            do_action('inesonic-payment-system-registration-completed', $user, $this->get_product_data());

            $enterprise_request = false;
            if (array_key_exists('er', $_GET)) {
                $enterprise_request = ($_GET['er'] == '1');
            }

            $academic_request = false;
            if (array_key_exists('ar', $_GET)) {
                $academic_request = ($_GET['ar'] == '1');
            }

            if (array_key_exists('pi', $_GET) && array_key_exists('pt', $_GET)) {
                $product_id = sanitize_key($_GET['pi']);
                $payment_term = sanitize_key($_GET['pt']);
                if ($product_id != '' && $payment_term != '') {
                    $page_value = $this->process_first_time_purchase_request($user, $product_id, $payment_term);
                } else {
                    /* Filter: inesonic-payment-system-render-registration-completed
                     *
                     * Filter that allows you to update the registration completed page when not performing a product
                     * update.
                     *
                     * Parameters:
                     *    $page_value -   The default content to use to render the page.  A value of null will cause
                     *                    page to be rendered by WordPress.
                     *
                     *    $user_data -    The user data for the newly registered user.
                     *
                     *    $product_data - The product data reported from Stripe.
                     *
                     * Return:
                     *    Return the new page content between the header and page footer.  Returning the supplied page
                     *    value will cause the page to be rendered by WordPress.
                     */
                    $page_value = apply_filters(
                        'inesonic-payment-system-render-registration-completed',
                        $page_value,
                        $user,
                        $this->get_product_data()
                    );
                }
            } else {
                $page_value = apply_filters(
                    'inesonic-payment-system-render-registration-completed',
                    $page_value,
                    $user,
                    $this->get_product_data()
                );
            }
        } else {
            $page_value = __(
                '<p>&nbsp;</p>
                 <p>&nbsp;</p>
                 <p align="center"
                    style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                 >
                   You must be logged in to perform a first-time purchase.
                 </p>
                 <p>&nbsp;</p>
                 <p>&nbsp;</p>',
                'inesonic-password-reset-handler'
            );
        }

        return $page_value;
    }

    /**
     * Method that is triggered when the user requests a new purchase or upgrade.
     *
     * \param[in] $page_value The current page value.
     *
     * \return Return either the contents to be rendered within the page or the supplied $page_value which will cause
     *         the default page contents to be displayed.
     */
    public function purchase($page_value) {
        $user = wp_get_current_user();
        if ($user !== null && $user->ID != 0) {
            if (array_key_exists('pi', $_GET) && array_key_exists('pt', $_GET)) {
                $product_id = sanitize_key($_GET['pi']);
                $payment_term = sanitize_key($_GET['pt']);

                if ($product_id != '' && $payment_term != '') {
                    $page_value = $this->process_purchase_request($user, $product_id, $payment_term);
                } else {
                    /* Filter: inesonic-payment-system-render-purchase
                     *
                     * Filter that allows you to update purchase page.
                     *
                     * Parameters:
                     *    $page_value -   The default content to use to render the page.  A value of null will cause
                     *                    page to be rendered by WordPress.
                     *
                     *    $user_data -    The user data for the newly registered user.
                     *
                     *    $product_data - The product data reported from Stripe.
                     *
                     * Return:
                     *    Return the new page content between the header and page footer.  Returning the supplied page
                     *    value will cause the page to be rendered by WordPress.
                     */
                    $page_value = apply_filters(
                        'inesonic-payment-system-render-purchase',
                        $page_value,
                        $user,
                        $this->get_product_data()
                    );
                }
            } else {
                $page_value = apply_filters(
                    'inesonic-payment-system-render-purchase',
                    $page_value,
                    $user,
                    $this->get_product_data()
                );
            }
        } else {
            $page_value = __(
                '<p>&nbsp;</p>
                 <p>&nbsp;</p>
                 <p align="center"
                    style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                 >
                   You must be logged in to purchase a subscription.
                 </p>
                 <p>&nbsp;</p>
                 <p>&nbsp;</p>',
                'inesonic-password-reset-handler'
            );
        }

        return $page_value;
    }

    /**
     * Method that is triggered when the user requests an upgrade via AJAX.
     */
    public function upgrade($page_value) {
        $status_response = null;
        $redirect_url = null;

        $user = wp_get_current_user();
        if ($user !== null && $user->ID != 0) {
            if (array_key_exists('product_id', $_POST) && array_key_exists('payment_term', $_POST)) {
                $new_product_id = sanitize_key($_POST['product_id']);
                $new_payment_term = sanitize_key($_POST['payment_term']);

                $subscription_data = $this->get_subscription_data(null, $user->ID);
                if ($subscription_data !== null) {
                    $subscription_status = $subscription_data->status;
                    if ($subscription_status == 'active' || $subscription_status == 'trialing') {
                        $metadata = $subscription_data->metadata;
                        $current_product_id = $metadata['inesonic_product_id'];
                        $current_payment_term = $metadata['inesonic_payment_term'];

                        $product_data = $this->get_product_data();
                        if (array_key_exists($current_product_id, $product_data) &&
                            array_key_exists($new_product_id, $product_data)        ) {
                            $current_product_data = $product_data[$current_product_id];
                            $new_product_data = $product_data[$new_product_id];
                            $current_product_pricing = $current_product_data['pricing'];
                            $new_product_pricing = $new_product_data['pricing'];
                            if (array_key_exists($current_payment_term, $current_product_pricing) &&
                                array_key_exists($new_payment_term, $new_product_pricing)            ) {
                                $current_product_info = $current_product_pricing[$current_payment_term];
                                $new_product_info = $new_product_pricing[$new_payment_term];

                                $upsells = $current_product_info['upsells'];
                                $number_upsells = count($upsells);
                                $index = 0;
                                while ($index < $number_upsells                                    &&
                                       ($upsells[$index]['product_id'] != $new_product_id     ||
                                        $upsells[$index]['payment_term'] != $new_payment_term    )    ) {
                                    ++$index;
                                }

                                if ($index < $number_upsells) {
                                    $success = $this->process_upgrade_request(
                                        $user,
                                        $subscription_data,
                                        $new_product_id,
                                        $new_payment_term
                                    );

                                    if ($success) {
                                        $redirect_url = $new_product_info['success_url'];
                                        $status_response = 'OK';
                                    } else {
                                        $status_response = __(
                                            'Could not update your subscription',
                                            'inesonic-payment-system'
                                        );
                                    }
                                } else {
                                    $status_response = __('Not an allowed upgrade.', 'inesonic-payment-system');
                                }
                            } else {
                                $status_response = __('Unknown payment term.', 'inesonic-payment-system');
                            }
                        } else {
                            $status_response = __('Unknown product ID.', 'inesonic-payment-system');
                        }
                        // Everything looks good.  Check if the upgrade is allowed.
                    } else {
                        $status_response = __(
                            'You must have an active subscription to upgrade it.',
                            'inesonic-payment-system'
                        );
                    }
                } else {
                    $status_response = __(
                        'No active subscription.  Please purchase a new subscription.',
                        'inesonic-payment-system'
                    );
                }
            } else {
                $status_response = __('Invalid request.', 'inesonic-payment-system');
            }
        } else {
            $status_response = __('You must be logged in to upgrade your subscription.', 'inesonic-payment-system');
        }

        $response = array(
            'status' => $status_response,
            'redirect_url' => $redirect_url
        );

        echo json_encode($response);
        wp_die();
    }

    /**
     * Method that is triggered when the user has completed the registration and WordPress is actively rendering the
     * registration_completed slug.
     *
     * \param[in] $page_value The current page value.
     *
     * \return Return either the contents to be rendered within the page or the supplied $page_value which will cause
     *         the default page contents to be displayed.
     */
    public function my_account($page_value) {
        $user = wp_get_current_user();
        if ($user !== null && $user->ID != 0) {
            $transaction_pending = false;
            if (array_key_exists('tp', $_GET)) {
                $transaction_pending = ($_GET['tp'] == '1');
            }

            if ($this->is_transaction_pending($user->ID)) {
                if ($transaction_pending) {
                    wp_enqueue_script(
                        'inesonic-payment-system-check-transaction-processed',
                        \Inesonic\PaymentSystem\Helpers::javascript_url('check-transaction-processed', true),
                        array(),
                        null,
                        true
                    );
                    wp_localize_script(
                        'inesonic-payment-system-check-transaction-processed',
                        'ajax_object',
                        array('ajax_url' => admin_url('admin-ajax.php'))
                    );

                    $page_value = __(
                        '<p>&nbsp;</p>
                         <p>&nbsp;</p>
                         <p align="center"
                           style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                         >
                           Your transaction is being processed.  You will be redirected shortly.
                         </p>
                         <p>&nbsp;</p>
                         <p>&nbsp;</p>',
                         'inesonic-password-reset-handler'
                    );
                } else {
                    $this->clear_pending_transaction($user->ID);
                    /* Filter: inesonic-payment-system-my-account
                     *
                     * Can be used to override what's displayed in the my-account page.  This filter is only triggered
                     * if there is no pending transaction and the user is already logged in.
                     *
                     * Parameters:
                     *    user_data -    The user's WP_User instance.
                     *
                     *    product_data - The product data reported from Stripe.
                     */
                    $page_value = apply_filters(
                        'inesonic-payment-system-my-account',
                        $page_value,
                        $user,
                        $this->get_product_data()
                    );
                }
            } else {
                $page_value = apply_filters(
                    'inesonic-payment-system-my-account',
                    $page_value,
                    $user,
                    $this->get_product_data()
                );
            }
        } else {
            $page_value = __(
                '<p>&nbsp;</p>
                 <p>&nbsp;</p>
                 <p align="center"
                    style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                 >
                   You must be logged in to view your account.
                 </p>
                 <p>&nbsp;</p>
                 <p>&nbsp;</p>',
                'inesonic-password-reset-handler'
            );
        }

        return $page_value;
    }

    /**
     * Method that is triggered when the user has requested the billing page.
     *
     * \param[in] $page_value The current page value.
     *
     * \return Return either the contents to be rendered within the page or the supplied $page_value which will cause
     *         the default page contents to be displayed.
     */
    public function billing($page_value) {
        $current_user = wp_get_current_user();
        if ($current_user !== NULL && $current_user->ID != 0) {
            global $wpdb;
            $query_result = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT stripe_customer_id FROM ' . $wpdb->prefix . 'inesonic_subscriptions' . ' WHERE ' .
                        'user_id = %d',
                    $current_user->ID
                ),
                OBJECT
            );

            if (count($query_result) > 0) {
                $stripe_customer_id = $query_result[0]->stripe_customer_id;
                $billing_portal_url = $this->stripe_api->stripe_create_billing_page($stripe_customer_id);

                $page_value = '<script type="text/javascript">window.location.replace("' .
                                  $billing_portal_url .
                              '");</script>';
            } else {
                $page_value = __(
                    '<p>&nbsp;</p>
                     <p>&nbsp;</p>
                     <p align="center"
                        style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                     >
                       No billing information on file.
                     </p>
                     <p>&nbsp;</p>
                     <p>&nbsp;</p>',
                    'inesonic-password-reset-handler'
                );
            }
        } else {
            $page_value = __(
                '<p>&nbsp;</p>
                 <p>&nbsp;</p>
                 <p align="center"
                    style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                 >
                   You must be logged in to view your billing information.
                 </p>
                 <p>&nbsp;</p>
                 <p>&nbsp;</p>',
                'inesonic-password-reset-handler'
            );
        }

        return $page_value;
    }

    /**
     * Method that is triggered to update a subscription quantity.
     *
     * \param[in] $inesonic_customer_id The WordPress customer ID of the customer to update the quantity for.
     *
     * \param[in] $new_quantity         The new quantity to apply to this customer's subscription.
     */
    public function update_quantity($inesonic_customer_id, $new_quantity) {
        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stripe_subscription_id FROM ' . $wpdb->prefix . 'inesonic_subscriptions' . ' WHERE ' .
                    'user_id = %d',
                $inesonic_customer_id
            )
        );

        if (count($query_result) > 0) {
            $stripe_subscription_id = $query_result[0]->stripe_subscription_id;
            $this->stripe_api->stripe_update_subscription_quantity($stripe_subscription_id, $new_quantity);
        }
    }

    /**
     * Method that is triggered to update customer stripe ID data.
     *
     * \param[in] $inesonic_customer_id   The WordPress user ID of the user tied to the Stripe subscription.
     *
     * \param[in] $stripe_customer_id     The new Stripe customer ID.  A value of null forces the value to default.
     *
     * \param[in] $stripe_subscription_id The new Stripe subscription ID.  A vale of null forces the value to default.
     */
    public function update_stripe_ids($inesonic_customer_id, $stripe_customer_id, $stripe_subscription_id) {
        global $wpdb;
        $query_result = $wpdb->delete(
            $wpdb->prefix . 'inesonic_subscriptions',
            array('user_id' => $inesonic_customer_id),
            array('%d')
        );

        $inserion_data = array();

        $insertion_data['user_id'] = $inesonic_customer_id;
        $insertion_format = array('%d');

        if ($stripe_customer_id != null) {
            $insertion_data['stripe_customer_id'] = $stripe_customer_id;
            $insertion_format[] = '%s';
        }

        if ($stripe_subscription_id != null) {
            $insertion_data['stripe_subscription_id'] = $stripe_subscription_id;
            $insertion_format[] = '%s';
        }

        $wpdb->insert(
            $wpdb->prefix . 'inesonic_subscriptions',
            $insertion_data,
            $insertion_format
        );
    }

    /**
     * Method that is triggered by AJAX to check if a pending transaction has completed.
     */
    public function check_transaction() {
        $user = wp_get_current_user();
        if ($user !== null && $user->ID != 0) {
            $response = array(
                'status' => 'OK',
                'transaction_pending' => $this->is_transaction_pending($user->ID)
            );
        } else {
            $response = array('status' => 'failed');
        }

        echo json_encode($response);
        wp_die();
    }

    /**
     * Method that is triggered by AJAX to supply product data.
     */
    public function product_data() {
        $user = wp_get_current_user();
        if ($user !== null && $user->ID != 0) {
            global $wpdb;
            $query_result = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT stripe_subscription_id FROM ' . $wpdb->prefix . 'inesonic_subscriptions' . ' WHERE ' .
                        'user_id = %d',
                    $current_user->ID
                ),
                OBJECT
            );

            if (count($query_result) > 0) {
                $stripe_subscription_id = $query_result[0]->stripe_subscription_id;
                $stripe_subscription_data = $this->stripe_api->stripe_retrieve_subscription($stripe_subscription_id);
            } else {
                $stripe_subscription_data = null;
            }

            $response = array(
                'status' => 'OK',
                'products' => $this->get_product_data(),
                'subscription' => $stripe_subscription_data
            );
        } else {
            $response = array('status' => 'failed');
        }

        echo json_encode($response);
        wp_die();
    }

    /**
     * Method that is triggered to forcibly cancel a user's subscriptions.
     *
     * \param[in] $customer_id The user ID of the customer to cancel the subscription of.
     */
    public function cancel_subscription($user_id) {
        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stripe_customer_id,stripe_subscription_id FROM ' .
                    $wpdb->prefix . 'inesonic_subscriptions' . ' ' .
                'WHERE user_id = %d',
                $user_id
            )
        );

        if (count($query_result) > 0) {
            $stripe_subscription_id = $query_result[0]->stripe_subscription_id;
            if ($stripe_subscription_id) {
                $this->stripe_api->stripe_cancel_subscription($stripe_subscription_id);

                $wpdb->query(
                    'UPDATE ' . $wpdb->prefix . 'inesonic_subscriptions' . ' ' .
                        'SET stripe_subscription_id=NULL ' .
                        'WHERE user_id=' . $user_id
                );
            }
        }
    }

    /**
     * Method that is called just after customer registration to handle a first-time purchase.
     *
     * \param[in] $user_data    The WP_User record for the new user.
     *
     * \param[in] $product_id   The product ID for the desired product.
     *
     * \param[in] $payment_term The payment term for the desired product.
     *
     * \return Returns the appropriate redirection JavaScript.
     */
    private function process_first_time_purchase_request($user_data, $product_id, $payment_term) {
        $product_data = $this->get_product_data();

        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stripe_customer_id FROM ' . $wpdb->prefix . 'inesonic_subscriptions' . ' WHERE ' .
                    'user_id = %d',
                $user_data->ID
            ),
            OBJECT
        );

        if (empty($query_result)) {
            if (array_key_exists($product_id, $product_data)) {
                $product_entry = $product_data[$product_id];
                $pricing = $product_entry['pricing'];
                if (array_key_exists($payment_term, $pricing)) {
                    $pricing_entry = $pricing[$payment_term];

                    $trial_term = null;

                    // The user is logged in and we have the required product data in the query string.

                    $first_name = get_user_meta($user_data->ID, 'first_name', true);
                    $last_name = get_user_meta($user_data->ID, 'last_name', true);
                    $company = get_user_meta($user_data->ID, 'company', true);
                    $phone_number = get_user_meta($user_data->ID, 'phone', true);
                    $email_address = $user_data->user_email;

                    $stripe_result = $this->stripe_api->stripe_create_customer(
                        $user_data->ID,
                        $first_name,
                        $last_name,
                        $company,
                        $phone_number,
                        $email_address
                    );

                    $stripe_customer_id = $stripe_result['id'];

                    $wpdb->insert(
                        $wpdb->prefix . 'inesonic_subscriptions',
                        array(
                            'user_id' => $user_data->ID,
                            'stripe_customer_id' => $stripe_customer_id,
                            'stripe_subscription_id' => null
                        ),
                        array(
                            '%d',
                            '%s',
                            '%s'
                        )
                    );

                    $stripe_price_id = $pricing_entry['stripe_price_id'];
                    $success_url = $pricing_entry['success_url'];
                    $cancel_url = $pricing_entry['cancel_url'];
                    $quantity = 1; // Always start with a quantity of 1.
                    $trial_period_days = $pricing_entry['trial_period_days'];
                    $enable_billing_address = true;
                    $collect_customer_data = true;
                    $is_subscription = true;
                    $automatic_tax = true;

                    $page_value = $this->create_checkout_session(
                        $user_data->ID,
                        $stripe_customer_id,
                        $stripe_price_id,
                        $product_id,
                        $payment_term,
                        $success_url,
                        $cancel_url,
                        $quantity,
                        $trial_period_days,
                        $enable_billing_address,
                        $collect_customer_data,
                        $is_subscription,
                        $automatic_tax
                    );
                } else {
                    $page_value = __(
                        '<p>&nbsp;</p>
                         <p>&nbsp;</p>
                         <p align="center"
                            style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                         >
                           Invalid payment term.
                         </p>
                         <p>&nbsp;</p>
                         <p>&nbsp;</p>',
                        'inesonic-payment-system'
                    );
                }
            } else {
                $page_value = __(
                    '<p>&nbsp;</p>
                     <p>&nbsp;</p>
                     <p align="center"
                        style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                     >
                       Invalid product.
                     </p>
                     <p>&nbsp;</p>
                     <p>&nbsp;</p>',
                    'inesonic-payment-system'
                );
            }
        } else {
            $page_value = __(
                '<p>&nbsp;</p>
                 <p>&nbsp;</p>
                 <p align="center"
                    style="font-size: 18px; color: #006DFA; font-family: Open Sans, Arial, sans-serif"
                 >
                   You\'ve already purchased a plan.  Please upgrade through your account.
                 </p>
                 <p>&nbsp;</p>
                 <p>&nbsp;</p>',
                'inesonic-payment-system'
            );
        }

        return $page_value;
    }

    /**
     * Method that is called to perform purchases for existing users.
     *
     * \param[in] $user_data    The WP_User record for the new user.
     *
     * \param[in] $product_id   The product ID for the desired product.
     *
     * \param[in] $payment_term The payment term for the desired product.
     *
     * \return Returns the appropriate redirection JavaScript.
     */
    private function process_purchase_request($user_data, $product_id, $payment_term) {
        $product_data = $this->get_product_data();

        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stripe_customer_id,stripe_subscription_id ' .
                    'FROM ' . $wpdb->prefix . 'inesonic_subscriptions' . ' ' .
                    'WHERE user_id = %d',
                $user_data->ID
            ),
            OBJECT
        );

        if (empty($query_result)) {
            $page_value = $this->process_first_time_purchase_request($user_data, $product_id, $payment_term);
        } else {
            $stripe_customer_id = $query_result[0]->stripe_customer_id;

            if (array_key_exists($product_id, $product_data)) {
                $product_entry = $product_data[$product_id];
                $pricing = $product_entry['pricing'];
                if (array_key_exists($payment_term, $pricing)) {
                    $stripe_subscription_id = $query_result[0]->stripe_subscription_id;

                    if ($stripe_subscription_id) {
                        $subscription_data = $this->stripe_api->stripe_retrieve_subscription($stripe_subscription_id);
                        $subscription_status = $subscription_data['status'];
                        if ($subscription_status == 'incomplete'         ||
                            $subscription_status == 'incomplete_expired' ||
                            $subscription_status == 'canceled'              ) {
                            $stripe_subscription_id = null;
                        }
                    }

                    if (!$stripe_subscription_id) {
                        $pricing_entry = $pricing[$payment_term];

                        /* Filter: inesonic-payment-update-purchase-trial-term
                         *
                         * Can be used to override the default trial term for a product.  This filter is only triggered
                         * on product purchases where there is already an existing subscription entry.
                         *
                         * Parameters:
                         *    $default_term - The default trial term.
                         *
                         *    $user_data -    The customer's user data.
                         *
                         *    $product_id -   The internal product ID.
                         *
                         *    $payment_term - The internal payment term.
                         *
                         * Return:
                         *     Returns the updated trial term, in days.
                         */
                        $trial_period_days = apply_filters(
                            'inesonic-payment-system-update-purchase-trial-term',
                            $pricing_entry['trial_period_days'],
                            $user_data,
                            $product_id,
                            $payment_term
                        );

                        // The user is logged in and we have the required product data in the query string.

                        $stripe_price_id = $pricing_entry['stripe_price_id'];
                        $success_url = $pricing_entry['success_url'];
                        $cancel_url = $pricing_entry['cancel_url'];
                        $quantity = 1; // Always start with a quantity of 1.
                        $enable_billing_address = true;
                        $collect_customer_data = true;
                        $is_subscription = true;
                        $automatic_tax = true;

                        $page_value = $this->create_checkout_session(
                            $user_data->ID,
                            $stripe_customer_id,
                            $stripe_price_id,
                            $product_id,
                            $payment_term,
                            $success_url,
                            $cancel_url,
                            $quantity,
                            $trial_period_days,
                            $enable_billing_address,
                            $collect_customer_data,
                            $is_subscription,
                            $automatic_tax
                        );
                    } else {
                        $page_value = '<p>&nbsp;</p>' .
                                      '<p>&nbsp;</p>' .
                                      '<p align="center" ' .
                                         'style="font-size: 18px; color: #006DFA; ' .
                                                'font-family: Open Sans, Arial, sans-serif"' .
                                      '>' .
                                        __(
                                          'You can not purchase a new product while your subscription is active.  ' .
                                          'Please upgrade instead.',
                                          'inesonic-payment-system'
                                        ) .
                                      '</p>' .
                                      '<p>&nbsp;</p>' .
                                      '<p>&nbsp;</p>';
                    }
                } else {
                    $page_value = '<p>&nbsp;</p>' .
                                  '<p>&nbsp;</p>' .
                                  '<p align="center" ' .
                                     'style="font-size: 18px; color: #006DFA; ' .
                                            'font-family: Open Sans, Arial, sans-serif"' .
                                  '>' .
                                  __('Invalid payment term', 'inesonic-payment-system') .
                                  '</p>' .
                                  '<p>&nbsp;</p>' .
                                  '<p>&nbsp;</p>';
                }
            } else {
                $page_value = '<p>&nbsp;</p>' .
                              '<p>&nbsp;</p>' .
                              '<p align="center" ' .
                                 'style="font-size: 18px; color: #006DFA; ' .
                                        'font-family: Open Sans, Arial, sans-serif"' .
                              '>' .
                              __('Invalid product ID', 'inesonic-payment-system') .
                              '</p>' .
                              '<p>&nbsp;</p>' .
                              '<p>&nbsp;</p>';
            }
        }

        return $page_value;
    }

    /**
     * Method that is called to perform upgrades for existing users.
     *
     * \param[in] $user_data                 The WP_User record for the new user.
     *
     * \param[in] $current_subscription_data Data on the currently held subscription.
     *
     * \param[in] $new_product_id            The product ID for the desired product.
     *
     * \param[in] $new_payment_term          The payment term for the desired product.
     *
     * \return Returns true on success.  Returns false on error.
     */
    private function process_upgrade_request(
            $user_data,
            $current_subscription_data,
            $new_product_id,
            $new_payment_term
        ) {
        $product_data = $this->get_product_data();

        $metadata = $current_subscription_data->metadata;
        $current_product_id = $metadata['inesonic_product_id'];
        $current_payment_term = $metadata['inesonic_payment_term'];

        $current_product_data = $product_data[$current_product_id];
        $new_product_data = $product_data[$new_product_id];
        $current_product_pricing = $current_product_data['pricing'][$current_payment_term];
        $new_product_pricing = $new_product_data['pricing'][$new_payment_term];

        $stripe_subscription_id = $current_subscription_data->id;
        $stripe_customer_id = $current_subscription_data->customer;

        $stripe_price_id = $new_product_pricing['stripe_price_id'];
        $quantity = $current_subscription_data->quantity;

        $customer_id = $user_data->ID;
        $page_value = $this->stripe_api->stripe_update_subscription_product(
            $current_subscription_data,
            $customer_id,
            $new_product_id,
            $new_payment_term,
            $stripe_price_id
        );

        return $page_value;
    }

    /**
     * Method that creates a new Stripe checkout session.
     *
     * \param[in] $inesonic_customer_id   The Inesonci customer ID.  Added to the transaction metadata.
     *
     * \param[in] $stripe_customer_id     The Stripe customer ID of the purchasing customer.
     *
     * \param[in] $stripe_price_id        The Stripe price ID of the product to be purchased.
     *
     * \param[in] $inesonic_product_id    The Inesonic internal product ID.  Added to the transaction metadata.
     *
     * \param[in] $inesonic_payment_term  The Inesonic payment term value.  Added to the transaction metadata.
     *
     * \param[in] $success_url            The URL to redirect the customer to if the purchase is successful.
     *
     * \param[in] $cancel_url             The URL to redirect the customer to if the purchase has been cancelled.
     *
     * \param[in] $quantity               The quantity of the item to be purchased.
     *
     * \param[in] $trial_period_days      An optional trial period in days.  Set to null or 0 to disable a trial
     *                                    period.
     *
     * \param[in] $enable_billing_address If true, then billing address information fields will be displayed in the
     *                                    checkout form.  If false, then no billing address information will be
     *                                    displayed.
     *
     * \param[in] $collect_customer_data  If true, then additional customer data for tax collection will be
     *                                    gathered.  Note: Do not set to true if we already have customer data on
     *                                    file.
     *
     * \param[in] $is_subscription        If true, then the purchase will be configured for a subscription.  If
     *                                    false, then the purchase will be handled as a one-time purchase.
     *
     * \param[in] $automatic_tax          If true, then taxes will be collected for this purchase.
     *
     * \return Returns the JavaScript to embed into the page to trigger a Stripe checkout.
     */
    public function create_checkout_session(
            $inesonic_customer_id,
            $stripe_customer_id,
            $stripe_price_id,
            $inesonic_product_id,
            $inesonic_payment_term,
            $success_url,
            $cancel_url,
            $quantity = 1,
            $trial_period_days = 0,
            $enable_billing_address = true,
            $collect_customer_data = true,
            $is_subscription = true,
            $automatic_tax = false
        ) {
        $session_id = $this->stripe_api->stripe_create_checkout_session(
            $inesonic_customer_id,
            $stripe_customer_id,
            $stripe_price_id,
            $inesonic_product_id,
            $inesonic_payment_term,
            $success_url,
            $cancel_url,
            $quantity,
            $trial_period_days,
            $enable_billing_address,
            $collect_customer_data,
            $is_subscription,
            $automatic_tax
        );

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'inesonic_checkout_session',
            array('user_id' => $inesonic_customer_id),
            array('%d')
        );

        $wpdb->insert(
            $wpdb->prefix . 'inesonic_checkout_session',
            array(
                'user_id' => $inesonic_customer_id,
                'stripe_checkout_session_id' => $session_id,
                'product_id' => $inesonic_product_id,
                'payment_term' => $inesonic_payment_term,
                'quantity' => $quantity
            ),
            array(
                '%d',
                '%s',
                '%s',
                '%s',
                '%d'
            )
        );

        $page_value = '<script src="' . $this->stripe_javascript_url() . '"></script>
                       <script type="text/javascript">
                         var stripe = Stripe(\'' . $this->stripe_public_key() . '\');
                         try {
                             redirect_result = stripe.redirectToCheckout({ sessionId: \'' . $session_id . '\' });
                             if (result.error) {
                                 alert(redirect_result.error.message);
                             }
                         } catch(error) {
                             console.error(\'Error: \', error);
                         }
                       </script>';

        return $page_value;
    }

    /**
     * Filter method you can use to obtain subscription data.
     *
     * \param[in] $defaults The defaults to use if no subscription exists for the customer.
     *
     * \param[in] $user_id  The ID of the user to obtain the subscription data for.
     *
     * \return Returns the updated subscription data.  A value of null is returned if there is no subscription for this
     *         user.
     */
    public function get_subscription_data($defaults, $user_id) {
        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stripe_subscription_id FROM ' . $wpdb->prefix . 'inesonic_subscriptions' . ' WHERE ' .
                    'user_id = %d',
                $user_id
            ),
            OBJECT
        );

        if (count($query_result) > 0) {
            $stripe_subscription_id = $query_result[0]->stripe_subscription_id;
            if ($stripe_subscription_id !== null) {
                $subscription_data = $this->stripe_api->stripe_retrieve_subscription($stripe_subscription_id);
                $result = $subscription_data;
            } else {
                $result = null;
            }
        } else {
            $result = $defaults;
        }

        return $result;
    }

    /**
     * Filter method you can use to obtain the product data stored on Stripe.
     *
     * \param[in] $defaults The defaults returned if the product data could not be obtained.
     *
     * \return Returns the requested product data.
     */
    public function get_payment_product_data($defaults) {
        return $this->get_product_data();
    }

    /**
     * Method that determines if a transaction is pending for a customer.
     *
     * \param[in] $inesonic_customer_id The WordPress customer ID for the customer.
     *
     * \return Returns true if a transaction is pending for this customer.  Returns false otherwise.
     */
    private function is_transaction_pending($inesonic_customer_id) {
        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'inesonic_checkout_session' . ' WHERE user_id = %d',
                $inesonic_customer_id
            ),
            OBJECT
        );

        return count($query_result) > 0;
    }

    /**
     * Method that deletes a pending transaction flag.
     *
     * \param[in] $inesonic_customer_id The WordPress customer ID for the customer associated with the transaction.
     *
     * \param[in] $product_id           The Inesonic product ID.
     *
     * \param[in] $payment_term         The Inesonic payment term.
     *
     * \param[in] $quantity             The transaction quantity.
     */
    private function clear_pending_transaction(
            $inesonic_customer_id,
            $product_id = null,
            $payment_term = null,
            $quantity = null
        ) {
        $where_fields = array('user_id' => $inesonic_customer_id);
        $where_field_types = array('%d');

        if ($product_id !== null) {
            $where_fields['product_id'] = $product_id;
            $where_field_types[] = '%s';
        }

        if ($payment_term !== null) {
            $where_fields['payment_term'] = $payment_term;
            $where_field_types[] = '%s';
        }

        if ($quantity !== null) {
            $where_fields['quantity'] = $quantity;
            $where_field_types[] = '%d';
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'inesonic_checkout_session', $where_fields, $where_field_types);
    }

    /**
     * Method that gets the Stripe customer ID for a customer.  The customer will be created if it doesn't already
     * exist.
     *
     * \param[in] $inesonic_customer_id The WordPress customer ID for this customer.
     *
     * \param[in] $first_name           The first name for the customer.
     *
     * \param[in] $last_name            The last name for the customer.
     *
     * \param[in] $company              The company or institution the customer is tied to.
     *
     * \param[in] $phone_number         The customer phone number.
     *
     * \param[in] $email_address        The customer email address.
     *
     * \return Returns the Stripe customer ID for the requested customer.
     */
    private function get_stripe_customer_id(
            $inesonic_customer_id,
            $first_name,
            $last_name,
            $company,
            $phone_number,
            $email_address
        ) {
        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stripe_customer_id FROM ' . $wpdb->prefix . 'inesonic_subscriptions' . ' WHERE ' .
                    'user_id = %d',
                $inesonic_customer_id
            ),
            OBJECT
        );

        if (count($query_result) > 0) {
            $result = $query_result[0]->stripe_customer_id;
        } else {
            $stripe_result = $this->stripe_api->stripe_create_customer(
                $inesonic_customer_id,
                $first_name,
                $last_name,
                $company,
                $phone_number,
                $email_address
            );

            $result = $stripe_result['id'];

            $wpdb->insert(
                $wpdb->prefix . 'inesonic_subscriptions',
                array(
                    'user_id' => $inesonic_customer_id,
                    'stripe_customer_id' => $result,
                    'stripe_subscription_id' => null
                ),
                array(
                    '%d',
                    '%s',
                    '%s'
                )
            );
        }

        return $result;
    }

    /**
     * Method that updates the Stripe subscription entry for a customer.
     *
     * \param[in] $inesonic_customer_id
     *
     * \param[in] $stripe_customer_id
     *
     * \param[in] $stripe_subscription_id
     */
    private function update_stripe_subscription_id(
            $inesonic_customer_id,
            $stripe_customer_id,
            $stripe_subscription_id
        ) {
        global $wpdb;
        $query_result = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stripe_customer_id,stripe_subscription_id FROM ' .
                    $wpdb->prefix . 'inesonic_subscriptions' .
                    ' WHERE ' .
                        'user_id = %d',
                $inesonic_customer_id
            ),
            OBJECT
        );

        if (count($query_result) == 1) {
            $expected_customer_id = $query_result[0]->stripe_customer_id;
            if ($expected_customer_id == $stripe_customer_id) {
                $current_subscription_id = $query_result[0]->stripe_subscription_id;
                if ($current_subscription_id !== $stripe_subscription_id) {
                    $wpdb->update(
                        $wpdb->prefix . 'inesonic_subscriptions',
                        array('stripe_subscription_id' => $stripe_subscription_id),
                        array('user_id' => $inesonic_customer_id),
                        array('%s'),
                        array('%d')
                    );
                }
            } else {
                self::log_error(
                    'Inesonic\PaymentSystem\stripe_subscription_updated: ' .
                    'Customer ' . $inesonic_customer_id . ' expected stripe customer id ' .
                    $expected_customer_id . ' received ' . $stripe_customer_id . '.'
                );
            }
        } else {
            self::log_error(
                'Inesonic\PaymentSystem\stripe_subscription_updated: ' .
                'Customer ' . $inesonic_customer_id . ' does not have a subscription entry.'
            );
        }
    }

    /**
     * Method that gets product data from Stripe.  Note that the data is cached.
     *
     * \return Returns all product data.  The returned data is an associative array indexed by the Inesonic product ID.
     *         each entry is a array containing:
     *
     *         - 'stripe_product_id' - The Stripe product ID.
     *         - 'description' - A textual description of the product.
     *         - 'pricing' - An associative array by payment term holding the stripe_price_id, unit_amount (cents),
     *           trial_period_days, and an array of allowed upsells.
     *           raw pricing data from Stripe.
     */
    private function get_product_data() {
        if ($this->all_product_data === null) {
            $stripe_product_data = $this->stripe_api->stripe_list_active_products();
            $stripe_product_data = $stripe_product_data['data'];

            $stripe_price_data = $this->stripe_api->stripe_list_active_prices();
            $stripe_price_data = $stripe_price_data['data'];

            $prices_by_product = array();
            foreach($stripe_price_data as $stripe_price_entry) {
                $stripe_product_id = $stripe_price_entry['product'];

                if (array_key_exists($stripe_product_id, $prices_by_product)) {
                    $prices_by_product[$stripe_product_id][] = $stripe_price_entry;
                } else {
                    $prices_by_product[$stripe_product_id] = array($stripe_price_entry);
                }
            }

            $product_data = array();
            foreach ($stripe_product_data as $stripe_product_entry) {
                $product_id = $stripe_product_entry['metadata']['product_id'];
                $stripe_product_id = $stripe_product_entry['id'];
                $product_description = $stripe_product_entry['description'];

                $pricing = array();
                if (array_key_exists($stripe_product_id, $prices_by_product)) {
                    foreach ($prices_by_product[$stripe_product_id] as $price_data) {
                        $payment_term = $price_data['metadata']['payment_term'];
                        $trial_period_days = intval($price_data['metadata']['trial_period_days']);
                        $upsells_field = $price_data['metadata']['upsells'];
                        $success_url = site_url($price_data['metadata']['success_slug']);
                        $cancel_url = site_url($price_data['metadata']['cancel_slug']);

                        $upsells = array();
                        foreach (explode(" ", $upsells_field) as $upsells_entry) {
                            $upsells_data = explode("/", $upsells_entry);
                            if (count($upsells_data) == 2) {
                                $upsells[] = array(
                                    'product_id' => $upsells_data[0],
                                    'payment_term' => $upsells_data[1]
                                );
                            }
                        }

                        $pricing[$payment_term] = array(
                            'stripe_price_id' => $price_data['id'],
                            'unit_amount' => $price_data['unit_amount'],
                            'trial_period_days' => $trial_period_days,
                            'upsells' => $upsells,
                            'success_url' => $success_url,
                            'cancel_url' => $cancel_url
                        );
                    }
                }

                $product_data[$product_id] = array(
                    'stripe_product_id' => $stripe_product_id,
                    'description' => $product_description,
                    'pricing' => $pricing
                );
            }

            $this->all_product_data = $product_data;
        }

        return $this->all_product_data;
    }

    /**
     * Static method that logs an error.
     *
     * \param[in] $error_message The error to be logged.
     */
    static private function log_error($error_message) {
        error_log($error_message);
        do_action('inesonic-logger-1', $error_message);
    }
}

/* Instatiate our plug-in. */
InesonicPaymentSystem::instance();

/* Define critical global hooks. */
register_activation_hook(__FILE__, array('InesonicPaymentSystem', 'plugin_activated'));
register_deactivation_hook(__FILE__, array('InesonicPaymentSystem', 'plugin_deactivated'));
register_uninstall_hook(__FILE__, array('InesonicPaymentSystem', 'plugin_uninstalled'));

