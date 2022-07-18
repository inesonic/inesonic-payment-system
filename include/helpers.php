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
    /**
     * Trivial class that provides a small number of useful static methods.
     */
    class Helpers {
        /**
         * Flag indicating if we should use the un-minified versions of our JavaScript and CSS in order to perform
         * debugging.
         */
        const DEBUG_JAVASCRIPT = false;

        /**
         * Static method that obtains the correct JavaScript URL based on a JavaScript module name.
         *
         * \param $module_name  The name of the JavaScript module to be fetched.
         *
         * \param $under_parent If true, then the Javascript is under the parent directory.  If false, then the
         *                      Javascript is under this directory.
         *
         * \return Returns the requested JavaScript URL.
         */
        static public function javascript_url(string $module_name, bool $under_parent) {
            if ($under_parent) {
                $d = dirname(__DIR__);
                $u = plugin_dir_url(__DIR__);
            } else {
                $d = dirname(__FILE__);
                $u = plugin_dir_url(__FILE__);
            }

            if (self::DEBUG_JAVASCRIPT) {
                $unminified_file = $d . '/assets/js/' . $module_name . '.js';
                if (file_exists($unminified_file)) {
                    $extension = '.js';
                }
                else {
                    $extension = '.min.js';
                }
            } else {
                $minified_file = $d . '/assets/js/' . $module_name . '.min.js';
                if (file_exists($minified_file)) {
                    $extension = '.min.js';
                }
                else {
                    $extension = '.js';
                }
            }

            return $u . 'assets/js/' . $module_name . $extension;
        }

        /**
         * Function that obtains the correct CSS URL based on a CSS module name.
         *
         * \param $module_name The name of the JavaScript module to be fetched.
         *
         * \param $under_parent If true, then the Javascript is under the parent directory.  If false, then the
         *                      Javascript is under this directory.
         *
         * \return Returns the requested JavaScript URL.
         */
        static public function css_url(string $module_name, bool $under_parent) {
            if ($under_parent) {
                $d = dirname(__DIR__);
                $u = plugin_dir_url(__DIR__);
            } else {
                $d = dirname(__FILE__);
                $u = plugin_dir_url(__FILE__);
            }

            if (self::DEBUG_JAVASCRIPT) {
                $extension = '.css';
            } else {
                $minified_file = $d . '/assets/css/' . $module_name . '.min.css';
                if (file_exists($minified_file)) {
                    $extension = '.min.css';
                }
                else {
                    $extension = '.css';
                }
            }

            return $u . 'assets/css/' . $module_name . $extension;
        }
    }
