<?php
/*
Plugin Name: Mailster Email Verify
Plugin URI: https://mailster.co/?utm_campaign=wporg&utm_source=wordpress.org&utm_medium=plugin&utm_term=Email+Verify
Description: Verifies your subscribers email addresses
Version: 1.6.0
Author: EverPress
Author URI: https://mailster.co
Text Domain: mailster-email-verify
License: GPLv2 or later
*/


define( 'MAILSTER_EMAILVERIFY_VERSION', '1.6.0' );
define( 'MAILSTER_EMAILVERIFY_REQUIRED_VERSION', '2.3' );
define( 'MAILSTER_EMAILVERIFY_FILE', __FILE__ );

require_once __DIR__ . '/classes/emailverify.class.php';
new MailsterEmailVerify();
