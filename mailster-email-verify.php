<?php
/*
Plugin Name: Mailster Email Verify
Plugin URI: http://rxa.li/mailster?utm_campaign=wporg&utm_source=Email+Verify+for+Mailster
Description: Verifies your subscribers email addresses
Version: 1.2.1
Author: revaxarts.com
Author URI: https://mailster.co
Text Domain: mailster-email-verify
License: GPLv2 or later
*/


define( 'MAILSTER_SEV_VERSION', '1.2.1' );
define( 'MAILSTER_SEV_REQUIRED_VERSION', '2.2' );

class MailsterEmailVerify {

	private $plugin_path;
	private $plugin_url;

	public function __construct() {

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( &$this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );

		load_plugin_textdomain( 'mailster-email-verify' );

		add_action( 'init', array( &$this, 'init' ), 1 );
	}


	/**
	 *
	 *
	 * @param unknown $network_wide
	 */
	public function activate( $network_wide ) {

		if ( function_exists( 'mailster' ) ) {

			mailster_notice( sprintf( __( 'Define your verification options on the %s!', 'mailster-email-verify' ), '<a href="edit.php?post_type=newsletter&page=mailster_settings&mailster_remove_notice=sev#sev">Settings Page</a>' ), '', false, 'sev' );

			$defaults = array(
				'sev_import' => false,
				'sev_check_mx' => true,
				'sev_check_smtp' => false,
				'sev_check_error' => __( 'Sorry, your email address is not accepted!', 'mailster-email-verify' ),
				'sev_dep' => false,
				'sev_dep_error' => __( 'Sorry, your email address is not accepted!', 'mailster-email-verify' ),
				'sev_domains' => '',
				'sev_domains_error' => __( 'Sorry, your email address is not accepted!', 'mailster-email-verify' ),
				'sev_emails' => '',
				'sev_emails_error' => __( 'Sorry, your email address is not accepted!', 'mailster-email-verify' ),
			);

			$mailster_options = mailster_options();

			foreach ( $defaults as $key => $value ) {
				if ( ! isset( $mailster_options[ $key ] ) ) {
					mailster_update_option( $key, $value );
				}
			}
		}

	}


	/**
	 *
	 *
	 * @param unknown $network_wide
	 */
	public function deactivate( $network_wide ) {}


	public function init() {

		if ( ! function_exists( 'mailster' ) ) {

			add_action( 'admin_notices', array( $this, 'notice' ) );

		} else {

			if ( is_admin() ) {

				add_filter( 'mailster_setting_sections', array( &$this, 'settings_tab' ) );
				add_action( 'mailster_section_tab_sev', array( &$this, 'settings' ) );

				add_filter( 'mailster_verify_options', array( &$this, 'verify_options' ) );
			}

			add_action( 'mailster_verify_subscriber', array( $this, 'verify_subscriber' ) );
			add_action( 'wp_version_check', array( $this, 'get_dea_domains' ) );

		}

	}


	/**
	 *
	 *
	 * @param unknown $entry
	 * @return unknown
	 */
	public function verify_subscriber( $entry ) {

		if ( ! isset( $entry['email'] ) ) {
			return $entry;
		}
		if ( ! mailster_option( 'sev_import' ) && defined( 'MAILSTER_DO_BULKIMPORT' ) && MAILSTER_DO_BULKIMPORT ) {
			return $entry;
		}

		$is_valid = $this->verify( $entry['email'] );
		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		return $entry;

	}


	/**
	 *
	 *
	 * @param unknown $email
	 * @return unknown
	 */
	public function verify( $email ) {

		list( $user, $domain ) = explode( '@', $email );

		// check for email addresses
		$blacklisted_emails = explode( "\n", mailster_option( 'sev_emails', '' ) );
		if ( in_array( $email, $blacklisted_emails ) ) {
			return new WP_Error( 'sev_emails_error', mailster_option( 'sev_emails_error' ), 'email' );
		}

		// check for white listed
		$whitelisted_domains = explode( "\n", mailster_option( 'sev_whitelist', '' ) );
		if ( in_array( $domain, $whitelisted_domains ) ) {
			return true;
		}

		// check for domains
		$blacklisted_domains = explode( "\n", mailster_option( 'sev_domains', '' ) );
		if ( in_array( $domain, $blacklisted_domains ) ) {
			return new WP_Error( 'sev_domains_error', mailster_option( 'sev_domains_error' ), 'email' );
		}

		// check DEP
		if ( $dea_domains = $this->get_dea_domains( false ) ) {
			if ( in_array( $domain, $dea_domains ) ) {
				return new WP_Error( 'sev_dep_error', mailster_option( 'sev_dep_error' ), 'email' );
			}
		}

		// check MX record
		if ( mailster_option( 'sev_check_mx' ) && function_exists( 'checkdnsrr' ) ) {
			if ( ! checkdnsrr( $domain, 'MX' ) ) {
				return new WP_Error( 'sev_mx_error', mailster_option( 'sev_check_error' ), 'email' );
			}
		}

		// check via SMTP server
		if ( mailster_option( 'sev_check_smtp' ) ) {

			require_once $this->plugin_path . '/classes/smtp-validate-email.php';

			$from = mailster_option( 'from' );

			$validator = new SMTP_Validate_Email( $email, $from );
			$smtp_results = $validator->validate();
			$valid = (isset( $smtp_results[ $email ] ) && 1 == $smtp_results[ $email ]) || ! ! array_sum( $smtp_results['domains'][ $domain ]['mxs'] );
			if ( ! $valid ) {
				return new WP_Error( 'sev_smtp_error', mailster_option( 'sev_check_error' ), 'email' );
			}
		}

		return true;

	}


	/**
	 *
	 *
	 * @param unknown $check (optional)
	 * @return unknown
	 */
	public function get_dea_domains() {

		if ( ! mailster_option( 'sev_dep' ) ) {
			return array();
		}

		$file = $this->plugin_path . '/dea.txt';
		if ( ! file_exists( $file )  ) {
			mailster_update_option( 'sev_dep', false );
			return array();
		}
		$raw = file_get_contents( $file );
		$domains = explode( "\n", $raw );
		return $domains;

	}


	/**
	 *
	 *
	 * @param unknown $settings
	 * @return unknown
	 */
	public function settings_tab( $settings ) {

		$position = 3;
		$settings = array_slice( $settings, 0, $position, true ) +
			array( 'sev' => __( 'Email Verification', 'mailster-email-verify' ) ) +
			array_slice( $settings, $position, null, true );

		return $settings;
	}


	public function settings() {

?>
	<table class="form-table">
		<tr valign="top">
			<th scope="row"><?php _e( 'Simple Checks' , 'mailster-email-verify' ) ?></th>
			<td>
			<p><label><input type="hidden" name="mailster_options[sev_check_mx]" value=""><input type="checkbox" name="mailster_options[sev_check_mx]" value="1" <?php checked( mailster_option( 'sev_check_mx' ) ); ?>><?php _e( 'Check MX record', 'mailster' );?></label><br><span class="description"><?php _e( 'Check the domain for an existing MX record.', 'mailster-email-verify' );?></span>
			</p>
			<p><label><input type="hidden" name="mailster_options[sev_check_smtp]" value=""><input type="checkbox" name="mailster_options[sev_check_smtp]" value="1" <?php checked( mailster_option( 'sev_check_smtp' ) ); ?>><?php _e( 'Validate via SMTP', 'mailster' );?></label><br><span class="description"><?php _e( 'Connects the domain\'s SMTP server to check if the address really exists.', 'mailster-email-verify' );?></span></p>
			<p><strong><?php _e( 'Error Message' , 'mailster-email-verify' ) ?>:</strong>
			<input type="text" name="mailster_options[sev_check_error]" value="<?php echo esc_attr( mailster_option( 'sev_check_error' ) ) ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Disposable Email Provider' , 'mailster-email-verify' ) ?></th>
			<td>
			<p><label><input type="hidden" name="mailster_options[sev_dep]" value=""><input type="checkbox" name="mailster_options[sev_dep]" value="1" <?php checked( mailster_option( 'sev_dep' ) ); ?>><?php _e( 'reject email addresses from disposable email providers (DEP).', 'mailster' );?></label></p>
			<p><strong><?php _e( 'Error Message' , 'mailster-email-verify' ) ?>:</strong>
			<input type="text" name="mailster_options[sev_dep_error]" value="<?php echo esc_attr( mailster_option( 'sev_dep_error' ) ) ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Blacklisted Email Addresses' , 'mailster-email-verify' ) ?></th>
			<td>
			<p><?php _e( 'List of blacklisted email addresses. One email each line.' , 'mailster-email-verify' ) ?><br>
			<textarea name="mailster_options[sev_emails]" placeholder="<?php echo "john@blacklisted.com\njane@blacklisted.co.uk\nhans@blacklisted.de"?>" class="code large-text" rows="10"><?php echo esc_attr( mailster_option( 'sev_emails' ) ) ?></textarea></p>
			<p><strong><?php _e( 'Error Message' , 'mailster-email-verify' ) ?>:</strong>
			<input type="text" name="mailster_options[sev_emails_error]" value="<?php echo esc_attr( mailster_option( 'sev_emails_error' ) ) ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Reject Domains' , 'mailster-email-verify' ) ?></th>
			<td>
			<p><?php _e( 'List of blacklisted domains. One domain each line.' , 'mailster-email-verify' ) ?><br>
			<textarea name="mailster_options[sev_domains]" placeholder="<?php echo "blacklisted.com\nblacklisted.co.uk\nblacklisted.de"?>" class="code large-text" rows="10"><?php echo esc_attr( mailster_option( 'sev_domains' ) ) ?></textarea></p>
			<p><strong><?php _e( 'Error Message' , 'mailster-email-verify' ) ?>:</strong>
			<input type="text" name="mailster_options[sev_domains_error]" value="<?php echo esc_attr( mailster_option( 'sev_domains_error' ) ) ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'White listed Domains' , 'mailster-email-verify' ) ?></th>
			<td>
			<p><?php _e( 'List domains which bypass the above rules. One domain each line.' , 'mailster-email-verify' ) ?><br>
			<textarea name="mailster_options[sev_whitelist]" placeholder="<?php echo "whitelisted.com\nwhitelisted.co.uk\nwhitelisted.de"?>" class="code large-text" rows="10"><?php echo esc_attr( mailster_option( 'sev_whitelist' ) ) ?></textarea></p>
			</td>
		</tr>
	</table>
	<table class="form-table">
		<tr valign="top">
			<th scope="row"><?php _e( 'Import' , 'mailster-email-verify' ) ?></th>
			<td><p><label><input type="hidden" name="mailster_options[sev_import]" value=""><input type="checkbox" name="mailster_options[sev_import]" value="1" <?php checked( mailster_option( 'sev_import' ) ) ?>> use for import</label></p>
			<p class="description">This will significantly increase import time because for every subscriber WordPress needs to verify the email address on the given domain. It's better to import a cleaned list.</p>
			</td>
		</tr>
	</table>
<?php
	}


	/**
	 *
	 *
	 * @param unknown $options
	 * @return unknown
	 */
	public function verify_options( $options ) {

		$options['sev_domains'] = trim( preg_replace( '/(?:(?:\r\n|\r|\n|\s)\s*){2}/s', "\n", $options['sev_domains'] ) );
		$options['sev_domains'] = explode( "\n", $options['sev_domains'] );
		$options['sev_domains'] = array_unique( $options['sev_domains'] );
		sort( $options['sev_domains'] );
		$options['sev_domains'] = implode( "\n", $options['sev_domains'] );

		$options['sev_whitelist'] = trim( preg_replace( '/(?:(?:\r\n|\r|\n|\s)\s*){2}/s', "\n", $options['sev_whitelist'] ) );
		$options['sev_whitelist'] = explode( "\n", $options['sev_whitelist'] );
		$options['sev_whitelist'] = array_unique( $options['sev_whitelist'] );
		sort( $options['sev_whitelist'] );
		$options['sev_whitelist'] = implode( "\n", $options['sev_whitelist'] );

		if ( $options['sev_dep'] ) {
			$this->get_dea_domains();
		}

		return $options;
	}


	public function notice() {
?>
	<div id="message" class="error">
	  <p>
	   <strong>Email Verify for Mailster</strong> requires the <a href="http://rxa.li/mailster?utm_campaign=wporg&utm_source=Email+Verify+for+Mailster">Mailster Newsletter Plugin</a>, at least version <strong><?php echo MAILSTER_SEV_REQUIRED_VERSION ?></strong>. Plugin deactivated.
	  </p>
	</div>
		<?php
	}


}


new MailsterEmailVerify();
