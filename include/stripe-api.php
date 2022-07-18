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
     * Class that provides a wrapper around the Stripe API.
     */
    class StripeApi {
        /**
         * Constructor
         *
         * \param[in] $stripe_public_key The Stripe public key.
         *
         * \param[in] $stripe_secrt_key  The Stripe secret key.
         */
        public function __construct(
                string    $stripe_public_key,
                string    $stripe_secret_key
            ) {
            $this->stripe_public_key = $stripe_public_key;
            $this->stripe_secret_key = $stripe_secret_key;

            $this->stripe_client = new \Stripe\StripeClient($stripe_secret_key);

            $this->all_products = null;
            $this->all_prices = null;
        }

        /**
         * Method that creates a new Stripe customer.
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
         * \return Returns the resulting raw response from Stripe.
         */
        public function stripe_create_customer(
                $inesonic_customer_id,
                $first_name,
                $last_name,
                $company,
                $phone_number,
                $email_address
            ) {
            $request = array(
                'description' => 'Customer ' . $inesonic_customer_id,
                'metadata' => array(
                    'inesonic_customer_id' => $inesonic_customer_id,
                ),
                'name' => $first_name . ' ' . $last_name,
                'email' => $email_address,
                'phone' => $phone_number,
                'expand' => array('tax')
            );

            $response = $this->stripe_client->customers->create($request);
            return $response;
        }

        /**
         * Method that retrieves the raw Stripe data for a given customer.
         *
         * \param[in] $stripe_customer_id The customer ID of the customer in question.
         *
         * \return Returns the raw Stripe data for the customer.
         */
        public function stripe_retrieve_customer($stripe_customer_id) {
            return $this->stripe_client->customers->retrieve(
                $stripe_customer_id,
                array(
                    'expand' => array('tax_ids')
                )
            );
        }

        /**
         * Method that deletes a customer from the Stripe database.
         *
         * \param[in] $stripe_customer_id The customer ID of the customer to be deleted.
         */
        public function stripe_delete_customer($stripe_customer_id) {
            $this->stripe_client->customers->delete($stripe_customer_id, array());
        }

        /**
         * Method that obtains a list of all active products known by Stripe.
         *
         * \return Returns an array of all know Stripe products.  Note that the list is cached.
         */
        public function stripe_list_active_products() {
            if ($this->all_products === null) {
                $this->all_products = $this->stripe_client->products->all(array('active' => true));
            }

            return $this->all_products;
        }

        /**
         * Method that obtains a list of all active prices known by stripe.
         *
         * \return Returns an array of all known Stripe prices.  Note that the list is cached.
         */
        public function stripe_list_active_prices() {
            if ($this->all_prices === null) {
                $this->all_prices = $this->stripe_client->prices->all(array('active' => true));
            }

            return $this->all_prices;
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
         * \return Returns the checkout session ID.
         */
        public function stripe_create_checkout_session(
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
                $is_subscription = true,
                $automatic_tax = false
            ) {
            $description = 'Customer ' . $inesonic_customer_id .
                                   ' ' . $inesonic_product_id .
                                  ' (' . $inesonic_payment_term . ')';

            $arguments = array(
                'success_url' => $success_url,
                'cancel_url' => $cancel_url,
                'line_items' => array(
                    array(
                        'price' => $stripe_price_id,
                        'quantity' => $quantity,
                        'description' => $description
                    )
                ),
                'customer' => $stripe_customer_id,
                'client_reference_id' => $inesonic_customer_id
            );

            if ($is_subscription) {
                $arguments['mode'] = 'subscription';

                $subscription_data = array(
                    'metadata' => array(
                        'inesonic_product_id' => $inesonic_product_id,
                        'inesonic_payment_term' => $inesonic_payment_term,
                        'inesonic_customer_id' => $inesonic_customer_id
                    )
                );

                if ($trial_period_days !== null && $trial_period_days > 0) {
                    $subscription_data['trial_period_days'] = $trial_period_days;
                }

                $arguments['subscription_data'] = $subscription_data;
            } else {
                $arguments['mode'] = 'payment';
            }

            if ($enable_billing_address) {
                $arguments['billing_address_collection'] = 'required';
            }

            if ($automatic_tax) {
                $arguments['automatic_tax'] = array('enabled' => true);
            }

            if ($collect_customer_data) {
                $arguments['customer_update'] = array(
                    'shipping' => 'auto',
                    'address' => 'auto'
                );
            }

            $checkout_session_data = $this->stripe_client->checkout->sessions->create($arguments);
            $checkout_session_id = $checkout_session_data['id'];

            return $checkout_session_id;
        }

        /**
         * Method that obtains data on a checkout session.
         *
         * \param[in] $checkout_session_id The ID of the checkout session to retrieve information for.
         *
         * \return Returns information about the checkout session.
         */
        public function stripe_retrieve_checkout_session($checkout_session_id) {
            return $this->stripe_client->checkout->retrieve($checkout_session_id);
        }

        /**
         * Method that expires a checkout session.
         *
         * \param[in] $checkout_session_id The ID of the checkout session to expire.
         *
         * \return Returns information about the expire operation.
         */
        public function stripe_expire_checkout_session($checkout_session_id) {
            return $this->stripe_client->checkout->expire($checkout_session_id);
        }

        /**
         * Method that creates a new Stripe billing page.
         *
         * \param[in] $stripe_customer_id The Stripe customer ID of the purchasing customer.
         *
         * \return Returns the billing page URL for this customer.
         */
        public function stripe_create_billing_page($stripe_customer_id) {
            $response = $this->stripe_client->billingPortal->sessions->create(
                array('customer' => $stripe_customer_id)
            );

            return $response['url'];
        }

        /**
         * Method that obtains Stripe subscription information.
         *
         * \param[in] $stripe_subscription_id The Stripe customer ID of the purchasing customer.
         *
         * \return Returns the Stripe subscription data.
         */
        public function stripe_retrieve_subscription($stripe_subscription_id) {
            return $this->stripe_client->subscriptions->retrieve($stripe_subscription_id);
        }

        /**
         * Method that cancels a subscription immediately.
         *
         * \param[in] $stripe_subscription_id The Stripe subscription ID of the purchasing customer.
         *
         * \return Returns true on success, false on failure.
         */
        public function stripe_cancel_subscription($stripe_subscription_id) {
            $this->stripe_client->subscriptions->cancel(
                $stripe_subscription_id,
                array('invoice_now' => true)
            );
        }

        /**
         * Method that updates a subscription quantity.
         *
         * \param[in] $stripe_subscription_id The Stripe customer ID of the purchasing customer.
         *
         * \param[in] $new_quantity           The new subscription quantity.
         */
        public function stripe_update_subscription_quantity($stripe_subscription_id, $new_quantity) {
            $this->stripe_client->subscriptions->update(
                $stripe_subscription_id,
                array('quantity' => $new_quantity)
            );
        }

        /**
         * Method that updates a subscription product.
         *
         * \param[in] $current_subscription  The current subscription object.
         *
         * \param[in] $inesonic_customer_id   The Inesonic customer ID.  Added to the transaction metadata.
         *
         * \param[in] $inesonic_product_id    The Inesonic internal product ID.  Added to the transaction metadata.
         *
         * \param[in] $inesonic_payment_term  The Inesonic payment term value.  Added to the transaction metadata.
         *
         * \param[in] $stripe_price_id        The Stripe price ID for the new product.
         */
        public function stripe_update_subscription_product(
                $current_subscription,
                $inesonic_customer_id,
                $inesonic_product_id,
                $inesonic_payment_term,
                $stripe_price_id
            ) {
            $current_items = $current_subscription['items']['data'];
            $current_item_id = $current_items[0]->id;

            $payload = array(
                'cancel_at_period_end' => false,
                'metadata' => array(
                    'inesonic_customer_id' => $inesonic_customer_id,
                    'inesonic_product_id' => $inesonic_product_id,
                    'inesonic_payment_term' => $inesonic_payment_term
                ),
                'items' => array(
                    array(
                        'id' => $current_items[0]->id,
                        'price' => $stripe_price_id
                    )
                )
            );

            $subscription_id = $current_subscription->id;
            $success = true;
            try {
                $this->stripe_client->subscriptions->update($subscription_id, $payload);
            } catch (Exception $e) {
                $success = false;
            }

            return $success;
        }
    };
