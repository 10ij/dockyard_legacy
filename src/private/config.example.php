<?php
/**
 * Configuration file for Dockyard.
 * - Update the constants below with your specific settings.
 * - This file is included in various parts of the application to access configuration values.
 * - Keep this file secure and do not expose it publicly.
 * Please read docs at https://ismailj.eu.org/dockyard/start for detailed setup instructions.
 * @package Dockyard
 * @author 10ij
 */

// Full URL e.g. https://dockyard.example.org/
define('APP_BASE_URL', '');

// OIDC Setup:
define('OIDC_CLIENT_ID', '');
define('OIDC_CLIENT_SECRET', '');
define('OIDC_PROVIDER_URL', '');

// Defining as false means the first user to login will be admin
define('DERIVE_ADMIN_BY_GROUP', false);
define('OIDC_ADMIN_GROUP', 'dy_admin');

