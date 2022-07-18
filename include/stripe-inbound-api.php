<?php
/***********************************************************************************************************************
 * Copyright 2021 - 2022, Inesonic, LLC
 *
 * GNU Public License, Version 3:
 *   This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 *   License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any
 *   later version.
 *   
 *   This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 *   warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
 *   details.
 *   
 *   You should have received a copy of the GNU General Public License along with this program.  If not, see
 *   <https://www.gnu.org/licenses/>.
 ***********************************************************************************************************************
 */

namespace Inesonic\PaymentSystem;
    require_once __DIR__ . "/vendor/stripe-php-8.4.0/init.php";

    /**
     * Class that provides a basic webhook to receive and process inbound Stripe messages.  The webhook converts
     * received messages to WordPress actions.
     *
     * You should direct Stripe to send messages to:
     *     https://<site>/wp-json/<rest_namespace>/stripe/
     */
    class StripeInboundApi {
        /**
         * The Stripe endpoint.
         */
        const STRIPE_ENDPOINT = 'stripe';

        /**
         * An optional file to send Stripe webhook events.  Set to null to disable.
         */
        const STRIPE_WEBHOOK_LOG = null; // '/home/www/stripe_inbound.dat';

        /**
         * Constructor
         *
         * \param[in] $rest_namespace    The namespace we should use for our REST API routes.
         *
         * \param[in] $stripe_secret_key The secret key used to authenticate messages.
         */
        public function __construct(string $rest_namespace, string $stripe_secret_key) {
            $this->rest_namespace = $rest_namespace;
            $this->stripe_secret_key = $stripe_secret_key;

            add_action('init', array($this, 'on_initialization'));
            add_action('rest_api_init', array($this, 'rest_api_initialization'));
        }

        /**
         * Method that is triggered to initialize this plug-in on WordPress initialization.
         */
        public function on_initialization() {
        }

        /**
         * Method that initializes our REST API.
         */
        public function rest_api_initialization() {
            register_rest_route(
                $this->rest_namespace,
                self::STRIPE_ENDPOINT,
                array(
                    'methods' => 'POST',
                    'callback' => array($this, 'process_message'),
                    'permission_callback' => '__return_true'
                )
            );
        }

        /**
         * Method that is triggered on receipt of a message from Stripe.
         *
         * \param $request Request data from this REST API handler.
         */
        public function process_message(\WP_REST_Request $request) {
            $headers = $request->get_headers();

            $cleaned_headers = array();
            foreach ($headers as $k => $v) {
                $cleaned_headers[str_replace('_', '-', strtolower($k))] = $v;
            }

            if (array_key_exists('user-agent', $cleaned_headers)) {
                $user_agent = $cleaned_headers['user-agent'][0];
            } else {
                $user_agent = null;
            }

            if (array_key_exists('content-type', $cleaned_headers)) {
                $content_type = $cleaned_headers['content-type'][0];
            } else {
                $content_type = null;
            }

            if ($user_agent !== null && str_starts_with(strtolower($user_agent), 'stripe/1.0')           &&
                $content_type !== null && str_starts_with(strtolower($content_type), 'application/json')    ) {
                $message = $request->get_json_params();

                try {
                    $event = \Stripe\Event::constructFrom(
                        $message,
                        array(
                            'api_key' => $this->stripe_secret_key
                        )
                    );
                } catch (\UnexpectedValueException $e) {
                    $error_string = var_export($e, true);
                    $event = null;
                }

                if (self::STRIPE_WEBHOOK_LOG) {
                    $fh = fopen(self::STRIPE_WEBHOOK_LOG, 'a');
                    fwrite($fh, "---------------------------------------------------------------------------------\n");
                    fwrite($fh, "Received: " . var_export($event, true) . "\n");
                    fclose($fh);
                }

                if ($event !== null) {
                    $event_type = $event->type;
                    $action_name = 'inesonic-stripe-message-' . strtr($event_type, '._', '--');

                    if (self::STRIPE_WEBHOOK_LOG) {
                        $fh = fopen(self::STRIPE_WEBHOOK_LOG, 'a');
                        fwrite($fh, "Triggering Action: " . $action_name . "\n");
                        fclose($fh);
                    }

                    /* Action: inesonic-stripe-message-<stripe event type>
                     *
                     * Triggered when Stripe sends us a message.
                     *
                     * Takes the event data as a parameter.
                     * Returns true or null on success.  Returns false on error.
                     */
                    do_action($action_name, $event);

                    $response = new \WP_REST_Response(array());
                    $response->set_status(200);
                } else {
                    $response = new \WP_Error(
                        'bad request: ' . $error_string,
                        'Bad Request',
                        array('status' => 400)
                    );
                }
            } else {
                $response = new \WP_Error(
                    'bad request',
                    'Bad Request',
                    array('status' => 400)
                );
            }

            return $response;
        }
    };
