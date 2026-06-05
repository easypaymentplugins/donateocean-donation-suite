<?php
/**
 * Deactivation feedback modal markup.
 *
 * Printed in the footer of the Plugins screen by
 * DonationSuite\Admin\DeactivationFeedback::render_modal().
 *
 * @package    Donation_Suite
 * @subpackage Admin
 * @since      1.0.6
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || die( 'Cheatin&#8217; uh?' );

$donadosu_deactivation_url = wp_nonce_url(
	'plugins.php?action=deactivate&amp;plugin=' . rawurlencode( DONADOSU_BASENAME ),
	'deactivate-plugin_' . DONADOSU_BASENAME
);
?>
<div class="donadosu-deactivation-Modal">
	<div class="donadosu-deactivation-Modal-header">
		<div>
			<button class="donadosu-deactivation-Modal-return deactivation-icon-chevron-left"><?php esc_html_e( 'Return', 'donateocean-donation-suite' ); ?></button>
			<h2><?php esc_html_e( 'We’re sorry to see you go! 💔', 'donateocean-donation-suite' ); ?></h2>
		</div>
		<button class="donadosu-deactivation-Modal-close deactivation-icon-close"><?php esc_html_e( 'Close', 'donateocean-donation-suite' ); ?></button>
	</div>
	<div class="donadosu-deactivation-Modal-content">
		<div class="donadosu-deactivation-Modal-question deactivation-isOpen">
			<p><?php esc_html_e( 'Can you please tell us why you’re deactivating the plugin? Your feedback helps us make it better.', 'donateocean-donation-suite' ); ?></p>
			<ul>
				<li>
					<input type="radio" name="reason" id="reason-temporary" value="Temporary Deactivation">
					<label for="reason-temporary"><?php esc_html_e( 'Temporary deactivation (troubleshooting)', 'donateocean-donation-suite' ); ?></label>
				</li>
				<li>
					<input type="radio" name="reason" id="reason-broke" value="Broken Layout">
					<label for="reason-broke"><?php esc_html_e( 'Compatibility issue', 'donateocean-donation-suite' ); ?></label>
					<div class="donadosu-deactivation-Modal-fieldHidden">
						<textarea placeholder="<?php esc_attr_e( 'Please describe what part of the layout or functionality was affected.', 'donateocean-donation-suite' ); ?>"></textarea>
					</div>
				</li>
				<li>
					<input type="radio" name="reason" id="reason-complicated" value="Complicated">
					<label for="reason-complicated"><?php esc_html_e( 'Difficult to set up', 'donateocean-donation-suite' ); ?></label>
					<div class="donadosu-deactivation-Modal-fieldHidden">
						<textarea placeholder="<?php esc_attr_e( 'What part of the setup was confusing or unclear?', 'donateocean-donation-suite' ); ?>"></textarea>
					</div>
				</li>
				<li>
					<input type="radio" name="reason" id="not-provided" value="features not provided">
					<label for="not-provided"><?php esc_html_e( 'Missing features', 'donateocean-donation-suite' ); ?></label>
					<div class="donadosu-deactivation-Modal-fieldHidden">
						<textarea placeholder="<?php esc_attr_e( 'Which features were you looking for?', 'donateocean-donation-suite' ); ?>"></textarea>
					</div>
				</li>
				<li>
					<input type="radio" name="reason" id="reason-other" value="Other">
					<label for="reason-other"><?php esc_html_e( 'Other', 'donateocean-donation-suite' ); ?></label>
					<div class="donadosu-deactivation-Modal-fieldHidden">
						<textarea placeholder="<?php esc_attr_e( 'Please share why you’re deactivating DonateOcean so we can make improvements.', 'donateocean-donation-suite' ); ?>"></textarea>
					</div>
				</li>
			</ul>
			<input id="deactivation-reason" type="hidden" value="">
			<input id="deactivation-details" type="hidden" value="">
		</div>
		<p style="margin-top: 20px;">
			<?php esc_html_e( 'Your privacy is important to us. No personal data is collected with this form—just your valuable feedback and basic system information (such as WordPress and plugin versions) to help us improve our plugin.', 'donateocean-donation-suite' ); ?>
		</p>
	</div>

	<div class="donadosu-deactivation-Modal-footer">
		<a href="https://wordpress.org/support/plugin/donateocean-donation-suite" class="button button-primary" target="_blank" title="<?php esc_attr_e( 'Visit our support page for assistance', 'donateocean-donation-suite' ); ?>"><?php esc_html_e( 'Get Support', 'donateocean-donation-suite' ); ?></a>
		<div>
			<a href="<?php echo esc_attr( $donadosu_deactivation_url ); ?>" class="button button-primary deactivation-isDisabled" disabled id="donadosu-send-deactivation"><?php esc_html_e( 'Send & Deactivate', 'donateocean-donation-suite' ); ?></a>
		</div>
		<a id="donadosu-deactivation-no-reason" href="<?php echo esc_attr( $donadosu_deactivation_url ); ?>" class=""><?php esc_html_e( 'I rather wouldn\'t say', 'donateocean-donation-suite' ); ?></a>
	</div>
</div>
<div class="donadosu-deactivation-Modal-overlay"></div>
