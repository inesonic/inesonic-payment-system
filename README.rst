=======================
inesonic-payment-system
=======================
You can use the Inesonic Payment System plugin to connect your WordPress site
to Stripe checkout.

The plugin is intended to be use primarily as a building block in a larger
system.  You will need to add additional PHP code to integrate this plugin into
your larter site.  This plugin was intended to be used on an improved version
of the `Inesonic, LLC website <https://inesonic.com>`.

Note that this plugin will take advantage of the
`Inesonic Logger <https://github.com/tuxidriver/inesonic-logger>` plugin, if
installed and activated.


Setting Stripe Public/Private Keys
==================================
You will need to add a small PHP file to your site outside of the WordPress
directory that will contain your Stripe public/private secrets.  The format of
the file should be:

.. code-block:: php

   <?php
   class StripeSecrets {
       public function stripe_public_key() {
           // Read the public key from a system config file here.
           return $public_key;
       }

       public functin stripe_private_key() {
           // Read the private key from a system config file here.
           return $private_key;
       }
   };

Exactly where and how the Stripe public/private key are stored is up to you.
This approach is used so that you can more easily maintain test and production
keys independently on your testing, development, and production sites.  This
approach also allows you to define the mechanisms used to track the Stripe keys
for your website.


Stripe Events
=============
This plugin includes a REST API Stripe can use to report events back to your
site.  You should direct Stripe events to
``<your site url>/wp-json/v1/stripe/``.  You should enable the following Stripe
events:

* ``invoice.payment_succeeded``
* ``invoice.payment_failed``
* ``invoice.payment_action_required``
* ``customer.subscription.created``
* ``customer.subscription.updated``
* ``customer.subscription.deleted``
* ``customer.subscription.trial_will_end``

You can intercept Stripe events directly by adding your own actions.  Stripe
events will trigger actions of the name ``inesonic-stripe-message-<event>``
Where ``<event>`` is the Stripe event with periods and underscores replaced
by dashes.  As an example, to trigger the function ``tax_id_created`` on the
Stripe ``customer.tax_id.created`` event, you would add the following PHP
code to your child theme or plugin:

.. code-block:: php

   add_action(
       'inesonic-stripe-message-customer-tax-id-created',
       'tax_id_created',
       10,
       1
   );

   . . .

   function tax_id_created($event_data) {
       // Handle the event here
   }

The supplied ``$event_data`` is simply the raw data sent by Stripe for the
event.


Product Settings
================
The Stripe plugin will read product information directly from Stripe allowing
you to keep your site sync'd with data known by Stripe.  The plugin provides
facilities you can use to obtain product information from other plugins.

To define a product, simply create the product on the Stripe dashboard.  You
can define multiple price points with different payment periods.  You can also
define tax codes, etc.

For each product, you must define a ``product_id`` metadata field.  This value
will be used internally by your site to refer to this product.

For each price setting, you should also define a number of metadata fields that
this plugin will look for:

+-----------------------+-----------------------------------------------------+
| Metadata              | Purpose                                             |
+=======================+=====================================================+
| ``payment_term``      | The payment term.  Value is used for upsells and    |
|                       | reported to other plugins.  Value should not        |
|                       | contain any special characters or whitespace.       |
|                       | Example values are:                                 |
|                       |                                                     |
|                       | * monthly                                           |
|                       | * quarterly                                         |
|                       | * annually                                          |
|                       | * biannually                                        |
+-----------------------+-----------------------------------------------------+
| ``success_slug``      | The slug that the checkout page should return to on |
|                       | success.                                            |
+-----------------------+-----------------------------------------------------+
| ``cancel_slug``       | The slug that the checkout page should return to    |
|                       | when the user cancels the transaction.              |
+-----------------------+-----------------------------------------------------+
| ``trial_period_days`` | If non-zero, then the product will include a trial  |
|                       | period.  Note that the minimum trial period         |
|                       | supported by Stripe is 3 days.                      |
+-----------------------+-----------------------------------------------------+
| ``upsells``           | A space separated list of product_id/payment_term   |
|                       | values that customers can upsell to.  Each entry    |
|                       | should be of the form ``product_id/payment_term``.  |
+-----------------------+-----------------------------------------------------+

.. note::

   Stripe currently supports a "Cross-sells" setting.  We should look to use
   that rather than a distinct ``upsells`` metadata field.


Using the Plugin
================
This section outlines how you can use this plugin.


Page Filters
------------
You should trigger a number of page filters when specific pages are rendered
for a customer.  All page filters begin with ``inesonic-filter-page-`` and are
followed by a page name.

All page filters will return either ``null`` if the default page should be
rendered or specific HTML content if special content should be rendered.  The
exact content will vary depending on the page filter used.

You can trigger these page filters programmatically by creating a template page
as part of your child theme that triggers the appropriate filter.  If using
**Divi** by **Elegant Themes**, this filter may look like:

.. code-block:: php

   <?php
   /* Template Name: Inesonic Filterable */

   if (!current_user_can('edit_pages') || !array_key_exists('et_fb', $_GET)) {
       $request_slug = trim(
           parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
           '/'
       );
       $filter_result = apply_filters(
           "inesonic-filter-page-" . $request_slug,
           null
       );
   } else {
       $filter_result = null;
   }

   if ($filter_result !== null) {
       echo $filter_result;
   } else {
       get_header();

       . . .

       get_footer();

   }

You can, of course use other mechanisms provides you trigger the appropriate
page filters.

The following page filters are supported.


inesonic-filter-page-registration-complete
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
You should trigger this page filter to sign up new customers to your
subscription service, typically just after the user registers with your site.
Users must be logged into the site for this filter to operate.  The filter
will render a user friendly error message if the user is not logged in.

The page filter will look for the following query strings.

+--------------+--------------------------------------------------------------+
| Query String | Function                                                     |
+==============+==============================================================+
| pi           | This query string should contain the product ID contained in |
|              | the Stripe metadata for the product the customer is          |
|              | purchasing.                                                  |
+--------------+--------------------------------------------------------------+
| pt           | This query string should contain the payment term contained  |
|              | in the Stripe metadata for the product the customer is       |
+--------------+--------------------------------------------------------------+

.. note::

   The plugin currently has a small amount of cruft code to look for ``er`` and
   ``ar`` query strings.  This code should be removed at some point.

If the ``pi`` and ``pt`` query strings are provided, and the values match
a ``product_id`` and ``payment_term`` field, and there is no subscription on
record for this customer, then this page filter will configure a Stripe
checkout and render a small JavaScript snippet that will redirect the user's
browser to the Stripe checkout form.  The checkout form will return to the
pages specified by the ``success_url`` or ``cancel_url`` metadata fields.

In any of the conditions in the last paragraph are not true, then this page
filter will trigger the filter
``inesonic-payment-system-render-registration-completed`` to handle other
scenarios.  This filter accepts 3 parameters:

+---------------+-------------------------------------------------------------+
| Parameter     | Purpose                                                     |
+===============+=============================================================+
| $page_value   | The default page value, initially set to ``null``.          |
+---------------+-------------------------------------------------------------+
| $user         | The WordPress WP_User instance for the currently logged in  |
|               | user.                                                       |
+---------------+-------------------------------------------------------------+
| $product_data | An associative array or arrays, keyed by product_id then by |
|               | payment_term for all active products.  The data in this     |
|               | array is pulled from Stripe.                                |
+---------------+-------------------------------------------------------------+


inesonic-filter-page-purchase
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
You should trigger this page filter when an existing user wishes to upgrade
their subscription or purchase a new product.

The page accepts the ``pi`` and ``pt`` query strings in the same manner as the
``inesonic-filter-page-registration-completed`` page.

If the conditions required for the ``pi`` and ``pt`` query strings are not met
then this page filter will trigger the
``inesonic-payment-system-render-purchase`` filter with the same parameters as
the ``inesonic-payment-system-render-registration-completed`` filter.

The page filter will generate a user friendly error message if the user is not
currently logged in.


inesonic-filter-page-my-account
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
You should trigger this page filter after the user performs a checkout to block
the user from viewing their account page until Stripe has reported the status
of the checkout back to your site.

If a checkout is pending for the user and the ``tp`` query string is ``1``, the
page filter will render JavaScript that checks your site once per second until
Stripe sends notification of payment status for the checkout session.  This
prevents your site from displaying a customer account page before Stripe has
had the opportunity to update the customer status.

If the ``tp`` query string is not present, the value is not ``1``, or there is
no pending checkout, the page filter will trigger the
``inesonic-payment-system-my-account`` filter.  You can use this filter to
intercept customer requests for a ``my-account`` page.

The filter accepts the same parameters as the
``inesonic-payment-system-render-registration-completed`` filter discussed
above.

Note that calling this page filter with a ``tp`` query string value that is not
``1`` will cancel monitoring for pending transactions; however, if a pending
transaction is reported, the customer status will be updated.


inesonic-filter-page-billing
^^^^^^^^^^^^^^^^^^^^^^^^^^^^
Triggering this page filter will render HTML content redirecting the user to
their Stripe billing page.  A user friendly message will be displayed if the
customer never had a subscription tracked by Stripe.


AJAX
----
The Inesonic Payment System plugin includes a handful of supported AJAX
messages you can use from your own JavaScript.


inesonic_payment_system_check_transaction
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
You can use this AJAX message to check if a pending transaction has been
completed.  The request will return a JSON dictionary of the form:

.. code-block:: json

   {
       "status" : "<status>",
       "transaction_pending" : <pending_status>
   }

If the user is logged in, the status will be "OK".  If there is no logged
in user, the status will be "failed".  The "transaction_pending" value will
either be true or false.


inesonic_payment_system_product_data
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
You can use this AJAX plugin to get both product data and Stripe subscription
data for the currently logged in user.

On success, the AJAX message will return a dictionary of the form:

.. code-block:: json

   {
       "status" : "OK",
       "products" : {
           <product data>
       "subscription" : {
           <stripe subscription data>
       }
   }

If the user is not logged in, the returned dictionary will be:

.. code-block:: json

   {
       "status" : "failed"
   }

The ``<product data>`` field will be indexed by product ID, then by payment
term.  The ``<stripe subscription data>`` field will be the raw subscription
data returned by Stripe.

.. note::

   I will likely remove the "subscription" field in future as it was originally
   added to facilitate debugging.


inesonic_payment_system_upgrade
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
You can use this AJAX message to trigger a product upgrade through JavaScript
for the currently logged-in user.  The user must have an existing subscription
and the upgrade must be one supported by the ``upsells`` field in Stripe for
the currently active subscription.

The request most include a ``product_id`` value indicating the desired
product to upgrade to as well as a ``payment_term`` value indicating the new
payment term.  Note that you can upgrade to the same product with a different
payment term.

The response will be of the form:

.. code-block:: json

   {
       "status" : "<status>",
       "redirect_url" : redirect_url
   }

On success, ``<status>`` will be OK.  ``redirect_url`` will either be null if
the upgrade does not require use of Stripe checkout or the URL to redirect the
user to in order to perform a Stripe checkout.

On failure, ``<status>`` will contain a failure status message.


Deleting Users
==============
When a user is deleted from WordPress, this plugin will automatically delete
any associated data maintained by Stripe.


Additional Filters You Can Define
=================================
The Inesonic Payment System includes several filters you can use to modify the
behavior of the system.


inesonic-payment-system-update-trial-term
-----------------------------------------
You can use the ``inesonic-payment-system-update-trial-term`` filter to adjust
the trial term to apply to subscriptions when an existing user purchases a new
product or performs an update.

The filter accepts four parameters:

* The default product trial period, in days for the new product.
* The WordPress WP_User instance for the current user.
* The product ID for the newly purchased product.
* The default payment term for the newly purchased product.

When using this filter, you should return the new trial term for the product.
Return a value of 0 to disable a trial term for the product.

Below is an example showing how you can use this filter:

.. code-block:: php

   add_filter(
       'inesonic-payment-system-update-trial-term``,
       'disable_trial_term',
       10,
       4
   );

   . . .

   function disable_trial_term($default_value, $user, $product_id, $payment_term) {
       return 0;
   }


Filters You Can Trigger
=======================
The Inesonic Payment System includes several filters you can use to obtain
product and payment data.


inesonic-payment-system-subscription-data
-----------------------------------------
You can use this filter to obtain the raw Stripe subscription data for a given
user.  The filter accepts two parameters:

* The default value
* The user ID of the user of interest.

Below is a short example using this filter:

.. code-block:: php

   $current_user = wp_get_current_user();

   . . .

   $stripe_subscription_data = apply_filters(
       'inesonic-payment-system-subscription-data',
       null,
       $current_user->ID
   );


inesonic-payment-system-product-data
------------------------------------
You can use this filter to obtain the product data maintained in Stripe.  The
filter returns an associative array of associative arrays by product ID by
payment term.  Each payment term will be represented as an associative array
with the following fields:

* ``stripe_price_id``
* ``unit_amount``
* ``trial_period_days``
* ``upsells``
* ``success_url``
* ``cancel_url``

The ``upsells`` will be an array of associative arrays.  Each upsells array
entry will contain:

* ``product_id``
* ``payment_term``

The filter accepts a default value to be returned.

Below is a short example using this filter:

.. code-block:: php

   $product_data = apply_filters('inesonic-payment-system-product-data', null);


Actions You Can Define
======================
The Inesonic Payment System will trigger a handful of actions you can use to
receive notification for events.


inesonic-payment-system-payment-succeeded
-----------------------------------------
You can define a handler for this action to receive notification when a Stripe
payment has succeeded.  The action will be triggered both on new purchases and
on product renewals.

The action provides 5 parameters:

* The WordPress WP_User instance for the user.

* The internal ``product_id`` field for the purchased or upgraded product.

* The internal ``payment_term`` field for the purchased or upgraded product.

* The Stripe payment object that ultimately triggered this action.

* The internal product data for all products.

Below is an example showing how you might use this action to change a user's
WordPress role.

.. code-block:: php

   add_action(
       'inesonic-payment-system-payment-succeeded',
       'update_customer',
       10,
       5
   );

   . . .

   function update_customer($user, $product, $term, $stripe_obj, $products) {
       if ($product == 'cheap_product') {
           $user->set_role('basic_user');
       } else if ($product == 'normal_product') {
           $user->set_role('normal_user');
       } else if ($product == 'delux_product') {
           $user->set_role('favorite_user');
       }
   }


inesonic-payment-system-payment-failed
--------------------------------------
You can define a handler for this action to receive notification when a Stripe
payment has failed.  The action may be triggered both on new purchases and
on product renewals.

The action provides 5 parameters:

* The WordPress WP_User instance for the user.

* The internal ``product_id`` field for the purchased or upgraded product.

* The internal ``payment_term`` field for the purchased or upgraded product.

* The Stripe payment object that ultimately triggered this action.

* The internal product data for all products.

Below is an example showing how you might use this action to change a user's
WordPress role to inactive.

.. code-block:: php

   add_action(
       'inesonic-payment-system-payment-failed',
       'disable_customer',
       10,
       5
   );

   . . .

   function disable_customer($user, $product, $term, $stripe_obj, $products) {
       $user->set_role('inactive');
   }


inesonic-payment-system-payment-action-required
-----------------------------------------------
You can define a handler for this action to receive notification when a Stripe
payment requires additional customer action.  The action may be triggered both
on new purchases and on product renewals.

The action provides 5 parameters:

* The WordPress WP_User instance for the user.

* The internal ``product_id`` field for the purchased or upgraded product.

* The internal ``payment_term`` field for the purchased or upgraded product.

* The Stripe payment object that ultimately triggered this action.

* The internal product data for all products.

Below is an example showing how you might use this action to send the user an
email.

.. code-block:: php

   add_action(
       'inesonic-payment-system-payment-action-required',
       'confirm_payment',
       10,
       5
   );

   . . .

   function confirm_payment($user, $product, $term, $stripe_obj, $products) {
       wp_mail(
           $user->user_email,
           __('Please confirm payment'),
           "Please confirm payment.  You should have received an email from " .
           "from Stripe providing a link.\n\n" .
           "Thank you."
       );
   }


inesonic-payment-system-subscription-updated
--------------------------------------------
You can define a handler for this action to receive notification whenever
Stripe reports an update to a customer subscription.  Reasons this action may
be triggered can include:

* The subscription payment status has changed.

* The user cancelled the subscription through the billing page.

* The subscription product or payment term was updated through Stripe's billing
  page.

* The subscription was renewed.

* The subscription was updated via the Stripe dashboard.

The action provides 5 parameters:

* The WP_User instance for the user.

* The product ID for the current subscription product.

* The payment term for the current subscription.

* The stripe status which can be ``active``, ``past_due``, ``unpaid``,
  ``canceled``, ``incomplete``, ``incomplete_expired``, or ``trialing``.

* A boolean indicating if the subscription is being cancelled at the end of the
  period.

* The raw Stripe subscription object.

* And the product data for all products.

Below is an example showing you might use this action.

.. code-block:: php

   add_action(
       'inesonic-payment-system-subscription-updated',
       'subscription_updated',
       10,
       7
   )

   . . .

   function subscription_updated(
           $user_data,
           $product_id,
           $payment_term,
           $current_status,
           $cancel_at_period_end,
           $subscription_object,
           $product_data
       ) {
       // Do stuff here.
   }


inesonic-payment-system-subscription-deleted
--------------------------------------------
You can define a handler for this action to receive notification whenever
Stripe deletes a customer subscription.  The action provides 4 parameters:

* The WP_User instance for the user.

* The product ID for the current subscription product.

* The payment term for the current subscription.

* And the product data for all products.

Below is an example showing you might use this action.

.. code-block:: php

   add_action(
       'inesonic-payment-system-subscription-deleted',
       'subscription_deleted',
       10,
       4
   )

   . . .

   function subscription_deleted($user_data, $product, $term, $product_data) {
       $user_data->set_role('inactive');
   }


inesonic-payment-system-subscription-trial-ending
-------------------------------------------------
You can use this action to receive notification that a customer subscription's
trial period is about to end and they will be charged shortly.  The action is
normally triggered by Stripe 3 days before the subscription trial period ends.

The action provides 5 parameters:

* The WP_User instance for the user.

* The product ID for the current subscription product.

* The payment term for the current subscription.

* The stripe object for the subscription.

* And the product data for all products.

You can use this action to trigger an email be sent to a customer in order to
comply with terms required by most credit card companies.  Below is a simple
example.

.. code-block:: php

   add_action(
       'inesonic-payment-system-payment-subscription-trial-ending,
       'trial_ending',
       10,
       5
   );

   . . .

   function trial_ending($user, $product, $term, $stripe_obj, $products) {
       wp_mail(
           $user->user_email,
           __('Your trial is ending'),
           "Your subscription to de-lux will be ending in 3 days.  Please " .
           "note that we will charge your credit card $69.99.  Charges are " .
           "not refundable.  If you do not wish to be charged, please " .
           "cancel your subscription through our billing page.\n\n" .
           "Thank you."
       );
   }


inesonic-payment-system-registration-completed
----------------------------------------------
You can use this action to receive notification when a new user has registered
with the system.  Note that this action largely duplicates the functionality of
the WordPress ``user_register`` action except that it's triggered by the
``inesonic-filter-page-registration-completed`` filter.

The action provides two parameters:

* The WordPress WP_User instance.

* Product data for all active products.


Actions You Can Trigger
=======================
This plugin includes a number of actions you can trigger to perform specific
tasks.


inesonic-payment-system-update-stripe-ids
-----------------------------------------
You can use this action to manually update Stripe customer and subscription IDs
for a given customer.  The action is useful when you want to be able to
manually configure a customer through the Stripe dashboard and WordPress admin
panels.

The action accepts three parameters:

* The WordPress user ID of the user to be updated.

* The Stripe customer ID.  A value of null will delete the database entry tying
  WordPress to Stripe.

* The Stripe subscription ID.  A value of null will delete any reference to a
  Stripe subscription ID for this customer.

Below is a simple example showing how to update customer data.

.. code-block:: php

   do_action(
       'inesonic-payment-system-update-stripe-ids',
       5376,                      // The customer ID
       'cus_axkljaskljsdfkljsdf', // The stripe customer ID
       'sub_owuierwoeicvbopi',    // The stripe subscription ID
   );


inesonic-payment-system-update-quantity
---------------------------------------
You can use this action to update the quantity tied to a given customer
subscription.  The hook exists to allow management of Inesonic teams and
enterprise accounts supporting multiple users.

The action accepts 2 parameters:

* The WordPress customer ID

* The new subscription quantity.

Note that charges for subscription quantities are updated immediately; however,
payment currently is prorated to the start of the next billing cycle.
Triggering this action will cause Stripe to report events back almost
immediately indicating the quantity change.  As a general rule, you'll want to
use this action to request a quantity change and then the triggered actions to
actually process the quantity change internally.

Below is a simple example showing how to update subscription quantities.

.. code-block:: php

   do_action(
       'inesonic-payment-system-update-quantity',
       5376, // The customer ID
       9     // The new quantity value.
   );


inesonic-payment-system-cancel-subscription
-------------------------------------------
You can use this action to manually cancel a subscription. The action takes a
single customer ID as a parameter.  Below is an example showing how you can use
this action.

.. code-block:: php

   do_action(
       'inesonic-payment-system-cancel-subscription',
       5376 // The customer ID of the customer to cancel the subscription for.
   );
