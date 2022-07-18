 /**********************************************************************************************************************
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
 * \file check-transaction-processed.js
 *
 * JavaScript module that checks if the customer has a pending transaction.
 */

/***********************************************************************************************************************
 * Parameters:
 */

/**
 * The polling interval, in mSec.
 */
const POLLING_INTERVAL = 1000;

/**
 * Timeout for our AJAX requests.
 */
const AJAX_TIMEOUT = 30 * 1000;

/***********************************************************************************************************************
 * Functions:
 */

/**
 * Function that is triggered periodically to check our capabilities.
 */
function inesonicPaymentSystemCheckTransactionProcessed() {
    jQuery.ajax(
        {
            type: "POST",
            url: ajax_object.ajax_url,
            timeout: AJAX_TIMEOUT,
            data: { "action" : "inesonic_payment_system_check_transaction" },
            dataType: "json",
            success: function(response) {
                if (response != null && response.status == 'OK') {
                    if (!response.transaction_pending) {
                        clearInterval(checkTransactionTimer);
                        let currentLocation = window.location.href.split('?')[0];
                        window.location.replace(currentLocation);
                    }
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log("Failed to obtain transaction status: " + errorThrown);
            }
        }
    );
}

/***********************************************************************************************************************
 * Main:
 */

var checkTransactionTimer = setInterval(inesonicPaymentSystemCheckTransactionProcessed, POLLING_INTERVAL);
