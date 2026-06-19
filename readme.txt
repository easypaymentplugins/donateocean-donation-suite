=== DonateOcean – Donations via PayPal ===
Contributors: easypayment
Tags: paypal donations, recurring donations, donation plugin, fundraising, nonprofit
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PayPal donation plugin for nonprofits. Recurring donations, tax receipts, fundraising goals, and donor management — all free, no upsells.

== Description ==

**DonateOcean** is a free PayPal donation plugin for nonprofits, charities, churches, schools, and fundraising organizations. Accept one-time and recurring PayPal donations on your website, send automated tax receipts, track campaign progress, and let supporters manage their own subscriptions through a secure self-service portal.

Every PayPal donation is confirmed by **webhook-verified payment**: PayPal's servers notify your site directly, so donations are recorded even if the donor closes their browser before the redirect completes. If a webhook delivery is delayed or temporarily unavailable, the plugin retries verification and updates the donation record once confirmation is received from PayPal.

All features ship in the free GPL release. There is no premium tier, no paid add-ons, and no platform fees — you only pay PayPal's standard processing fees.

= Why nonprofits choose DonateOcean for PayPal donations =

* **No paid tier, no upsells** — every feature is free; you only pay PayPal's standard processing fees
* **Built for fundraising** — campaigns, goals, tributes, anonymous giving, and offline donations in one place
* **Webhook-backed reliability** — donations are recorded even when the browser callback drops
* **Donor self-service** — donors manage their own recurring donations through a secure magic-link portal, no account required
* **GDPR-aware** — built-in tools for personal data export, erasure, and retention

= Core features =

* **PayPal Orders API v2** — credit card, debit card, and PayPal balance
* **Webhook-verified donations** — every payment confirmed by PayPal signature
* **Recurring donations** — monthly and annual PayPal Subscriptions with admin controls
* **Donor self-service portal** — donors manage subscriptions via secure magic link
* **Four display modes** — inline, modal, widget, and full-page donation forms
* **Fundraising goals** — progress bar with manual or automatic amount tracking
* **Campaign date gating** — schedule campaign open and close dates
* **Tribute donations** — "In honor of" and "In memory of" giving
* **Anonymous donations** and **optional fee coverage**
* **Automated HTML receipts** and **PDF receipt downloads**
* **Year-end tax summaries** for each donor
* **Full admin suite** — donations list, detail page, donor profiles, dashboard widgets
* **CSV export** — up to 10,000 records with all metadata
* **Manual donations** — record cash, check, and bank transfer donations
* **Full and partial refunds** via the PayPal Captures API
* **Dispute management** with automatic tracking and alerts
* **Custom roles** — Donation Viewer (read-only) and Donation Manager
* **Capability-checked admin actions** — refunds, settings changes, exports, and manual donation entry are all gated by WordPress capability checks
* **GDPR compliant** — personal data export, erasure, and retention
* **Accessible donation form** — keyboard-navigable controls and screen-reader-friendly labels and validation messages
* **Gutenberg block** with all settings as Inspector Controls
* **Translation ready** with a complete `.pot` file
* **Zero runtime dependencies** — no Composer or npm required

= Perfect for =

* Nonprofit organizations and 501(c)(3) charities
* Churches and religious organizations collecting tithes and offerings
* Schools, PTAs, and educational fundraisers
* Animal shelters, hospitals, and community foundations
* Crowdfunding campaigns, memorial funds, and tribute giving
* Any site that needs to accept PayPal donations with recurring support

= Integrations =

Each integration is disabled by default and only activates after credentials are entered.

* **Mailchimp** — auto-subscribe donors to your mailing list
* **Constant Contact** — auto-subscribe donors on donation completion
* **ActiveCampaign** — add donors to your CRM and email lists
* **Brevo (Sendinblue)** — auto-subscribe with optional double opt-in
* **Zapier** — trigger 5,000+ app workflows on donation events
* **Slack** — receive rich donation notifications in any channel
* **Twilio SMS** — get text alerts for donations, refunds, and disputes
* **Google Sheets** — log every donation to a spreadsheet automatically

= Shortcodes =

Add the donation form to any page or post:

`[donadosu_donation]`

Examples:

`[donadosu_donation campaign="building-fund" goal_amount="50000" goal_current="auto"]`

`[donadosu_donation display_mode="modal" donation_mode="both" fee_coverage="1"]`

`[donadosu_donation amounts="25,50,100,250" min_amount="10" button_text="Donate Now"]`

Add a self-service subscription management portal:

`[donadosu_donor_portal]`

Donors enter their email, receive a secure magic link (valid for 30 minutes), and can view or cancel their active subscriptions. No account required.

Full shortcode and block attribute documentation is available in the plugin settings help tab.

= Translations =

DonateOcean is fully translation-ready and ships with a complete `.pot` file. Community translations are welcome through the translation platform once it is available for this plugin.

== External Services ==

This plugin relies on third-party services to process payments and (optionally) sync donor data to external systems. Each service is described below, including what it is, when data is sent to it, what data is sent, and links to its Terms of Service and Privacy Policy. The plugin only contacts a service when the relevant feature is configured by an administrator; optional integrations remain inactive until their credentials are entered on the plugin settings page.

**PayPal (required for online donation processing)**

PayPal is the payment processor that handles every online donation. Without PayPal credentials, the plugin cannot accept online donations; manual (offline) donations recorded in the admin are the only exception.

* What it is: PayPal's REST API (Orders v2, Subscriptions v1, Webhooks) and the PayPal JavaScript SDK used to render the payment buttons.
* When data is sent: each time a visitor initiates a donation (order creation), completes payment (order capture), creates or manages a subscription, when an administrator issues a refund, and whenever PayPal posts a webhook event that the plugin verifies.
* Endpoints contacted: `https://api-m.paypal.com` (Live mode) or `https://api-m.sandbox.paypal.com` (Sandbox mode). The PayPal JavaScript SDK is loaded from `https://www.paypal.com/sdk/js` on any page that renders the donation form.
* Data sent: donation amount, currency, frequency (one-time or recurring), donor name, donor email, billing address (when provided), shipping address (when provided), campaign identifier, and the order or subscription identifier.
* Partner attribution: the PayPal JavaScript SDK is loaded with a PayPal Partner Attribution ID (BN code) of `mbjtechnolabs_sp`. This is a non-personal integration identifier provided by the plugin's PayPal technology partner (MBJ Technolabs). It does not transmit any donor data, does not change the donation amount, fees, or where funds are deposited (donations are always paid into your own connected PayPal account), and is used only so PayPal can recognise the integration. It can be removed by filtering the SDK attributes if you prefer.
* PayPal Terms of Service: https://www.paypal.com/us/legalhub/paypal/useragreement-full
* PayPal Privacy Statement: https://www.paypal.com/us/legalhub/paypal/privacy-full

**Optional integrations**

* **Mailchimp** — an email marketing service. When enabled, the plugin contacts `https://<dc>.api.mailchimp.com/3.0/` on each completed donation to add the donor to the configured audience. Data sent: donor name, donor email, and the Mailchimp list ID. Terms of Service: https://mailchimp.com/legal/terms/ — Privacy Policy: https://mailchimp.com/legal/privacy/
* **Constant Contact** — an email marketing service. When enabled, the plugin contacts `https://api.cc.email/v3/` on each completed donation to subscribe the donor to the configured list. Data sent: donor name, donor email, and the Constant Contact list ID. Terms of Service: https://www.constantcontact.com/legal/terms — Privacy Policy: https://www.constantcontact.com/legal/privacy-center
* **ActiveCampaign** — a CRM and email marketing service. When enabled, the plugin contacts the administrator-supplied ActiveCampaign account URL on each completed donation to add the donor as a contact and attach them to the configured list. Data sent: donor name, donor email, and the ActiveCampaign list ID. Terms of Service: https://www.activecampaign.com/legal/ — Privacy Policy: https://www.activecampaign.com/legal/privacy-policy
* **Brevo (Sendinblue)** — an email marketing service. When enabled, the plugin contacts `https://api.brevo.com/v3/` on each completed donation to subscribe the donor to the configured list (optionally with double opt-in). Data sent: donor name, donor email, and the Brevo list ID. Terms of Service: https://www.brevo.com/legal/termsofuse/ — Privacy Policy: https://www.brevo.com/legal/privacypolicy/
* **Twilio SMS** — a cloud-based SMS service. When enabled, the plugin contacts `https://api.twilio.com/2010-04-01/` to send a text message to the administrator-configured notification phone number when a donation, refund, or dispute event occurs. Data sent: the administrator's notification phone number, the Twilio "from" phone number, and an SMS body containing the donation amount, currency, and campaign name. No donor personally identifiable information is sent by default. Terms of Service: https://www.twilio.com/legal/tos — Privacy Policy: https://www.twilio.com/legal/privacy
* **Google Sheets** — Google's spreadsheet service. When enabled, the plugin contacts `https://oauth2.googleapis.com/token` to authenticate with a service account, then `https://sheets.googleapis.com/v4/spreadsheets` to append a row on each completed donation. Data sent: donation date, amount, currency, donor name, donor email, campaign, and donation identifier, written to the administrator-supplied spreadsheet. Terms of Service: https://policies.google.com/terms — Privacy Policy: https://policies.google.com/privacy
* **Slack** — a team messaging service. When enabled, the plugin contacts the administrator-supplied Slack incoming-webhook URL on each completed donation to post a notification message. Data sent: donation amount, currency, campaign name, and (if the administrator has not disabled it in settings) the donor name. Terms of Service: https://slack.com/terms-of-service/user — Privacy Policy: https://slack.com/trust/privacy/privacy-policy
* **Zapier** — a workflow automation service. When enabled, the plugin contacts the administrator-supplied Zapier webhook URL on each donation event (completed, refunded, subscription created, subscription cancelled) to trigger a Zap. Data sent: the donation payload including amount, currency, frequency, donor name, donor email, campaign, event type, and donation identifier. Terms of Service: https://zapier.com/legal — Privacy Policy: https://zapier.com/privacy
* **Google Analytics 4 / Google Tag Manager (optional, disabled by default)** — Google's web-analytics and tag-management services. Only active when an administrator enables tracking and supplies a Measurement ID (`G-XXXXXXXXXX`) and/or Container ID (`GTM-XXXXXXX`). When active, the visitor's browser loads `https://www.googletagmanager.com/gtag/js` and/or `https://www.googletagmanager.com/gtm.js` and Google receives standard analytics data (such as IP address, page URL, and donation-conversion events). These tags are only rendered after analytics consent: the plugin integrates with the WordPress Consent API (`statistics` category) when a consent-management plugin is active, otherwise honours the plugin's "require consent" setting and the `donadosu_analytics_has_consent` filter. Terms of Service: https://marketingplatform.google.com/about/analytics/terms/us/ — Privacy Policy: https://policies.google.com/privacy

Apart from the optional Google Analytics / Tag Manager integration described above, the plugin does not send any data to DonateOcean servers or to any other analytics or telemetry service.

== Data Storage ==

Donation records, donor profiles, and plugin settings are stored locally in the site's database. Payment processing is handled by PayPal; full card details are never stored on the site server by the plugin. PDF receipts are generated on demand and are not retained on the server after delivery.

== Privacy ==

DonateOcean stores donor information (name, email, billing address, donation history) in your database as post meta. No donor data is transmitted to the plugin author.

For convenience, the donation form may also remember a returning donor's own contact details (name, email, phone, and company) in their browser's local storage on the device they donate from, so the form can pre-fill on their next visit. This data stays in the visitor's own browser, is not transmitted anywhere by this feature, and can be cleared by clearing the browser's site data.

The plugin integrates with the core privacy tools to fulfill data subject requests:

* **Personal data export** — a donor's complete giving history can be exported via **Tools > Export Personal Data**.
* **Personal data erasure** — a donor's personally identifiable information can be erased via **Tools > Erase Personal Data**, while preserving anonymized aggregate financial records.
* **Automatic retention** — administrators can configure automatic erasure of donor PII after a set number of months.
* **Uninstall cleanup** — before deleting the plugin, administrators can opt in from the settings page to remove all DonateOcean data from the database.

For details on data shared with third-party services (PayPal and optional integrations), see the **External Services** section above.

== Installation ==

= Automatic installation =

1. In your admin dashboard, go to **Plugins > Add New**.
2. Search for **DonateOcean**.
3. Click **Install Now**, then **Activate**.
4. Go to **DonateOcean > Settings** in the admin menu.
5. Enter your PayPal API credentials (Client ID and Secret).
6. Save settings — the plugin attempts to register the PayPal webhook endpoint automatically.
7. Add `[donadosu_donation]` to any page or use the **DonateOcean Form** Gutenberg block.

= Manual installation =

1. Download the plugin ZIP file from the Plugin Directory.
2. In your admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP file and click **Install Now**.
4. Activate **DonateOcean** through the Plugins screen.
5. Follow steps 4–7 from the automatic installation instructions above.

= Getting your PayPal API credentials =

1. Log in to the [PayPal Developer Dashboard](https://developer.paypal.com/).
2. Navigate to **Apps & Credentials**.
3. Create a new REST API app or select an existing one.
4. Copy the **Client ID** and **Secret** for your chosen environment (Sandbox or Live).
5. Paste them into the DonateOcean settings page and save.

We recommend testing with Sandbox credentials before switching to Live mode.

== Frequently Asked Questions ==

= How do I accept PayPal donations on my website? =

Install DonateOcean, enter your PayPal API credentials (Client ID and Secret) on the settings page, and place the `[donadosu_donation]` shortcode on any page or use the DonateOcean Gutenberg block. The plugin attempts to register the PayPal webhook automatically, so completed donations are recorded even if the donor closes their browser before the redirect completes.

= Is DonateOcean really free? =

Yes, and there is no paid tier. Every feature shipped in the plugin is available to every user under the GPLv2 or later license. There are no locked features, premium add-ons, or upsells from the plugin author. The only cost of using the plugin is PayPal's standard payment processing fees, which are paid directly to PayPal.

= Does this work for nonprofits, churches, charities, and schools? =

Yes. DonateOcean is designed for any 501(c)(3), registered charity, religious organization, school, or community fundraising group. It supports tax-deductible receipts with your organization name, tax ID, and a customizable tax disclaimer; tribute donations ("In honor of" / "In memory of"); and recurring giving for sustaining donors.

= How do I add a PayPal donate button to my site? =

Place the `[donadosu_donation]` shortcode on any page or post, or insert the **DonateOcean Form** block in the Gutenberg editor. You can also choose between four display modes — inline, modal popup, sidebar widget, or a dedicated full-page form — to match your site's design.

= How do I set up recurring PayPal donations? =

Recurring donations are enabled by default. In the Donation Experience settings, choose which frequencies to offer (one-time, monthly, annual, or all three) and which is selected by default. Donors can manage or cancel their own subscriptions through the donor portal — no account needed.

= Which payment methods are supported? =

The plugin processes payments through PayPal, which supports PayPal balance, credit cards, debit cards, and local payment methods available in the donor's country. It uses PayPal's Orders API v2 and optionally supports Advanced Credit and Debit Card Fields for direct card entry without leaving your site.

= Can I test PayPal donations before going live? =

Yes. Enable Sandbox mode in the plugin settings and enter your PayPal Sandbox API credentials to test donations, receipts, webhooks, and refunds before switching to Live mode.

= Do I need to configure the PayPal webhook manually? =

No. The plugin automatically registers the webhook endpoint with PayPal when you save your API credentials. If automatic registration fails (for example, on a localhost environment), you can configure it manually in your PayPal Developer Dashboard and paste the Webhook ID into the plugin settings.

= Does the plugin send donation receipts automatically? =

Yes. When a donation is completed, the plugin sends an HTML receipt email to the donor and a notification email to your organization. Receipts include your organization name, tax ID, and a customizable tax disclaimer. Administrators can also download a PDF receipt from the admin panel.

= Can I issue PayPal refunds from the admin? =

Yes. On the donation detail page, administrators can process full or partial refunds via the PayPal Captures API. The donation status updates automatically and the refund is recorded in the status history timeline.

= Can I record offline donations like cash and checks? =

Yes. Go to **DonateOcean > Add Manual Donation** to record cash, check, bank transfer, or other offline donations. These appear in all reports, CSV exports, and donor profiles alongside PayPal donations.

= Is the plugin GDPR compliant? =

Yes. DonateOcean integrates with the core privacy tools for personal data export and erasure requests. You can also configure automatic data retention to erase donor personally identifiable information after a set number of months.

= Can donors manage their own recurring donations? =

Yes. Place the `[donadosu_donor_portal]` shortcode on any page to create a self-service subscription management portal. Donors enter their email, receive a secure magic link valid for 30 minutes, and can view or cancel their active subscriptions.

= Is donor data secure? =

Donor information is stored as post meta and is protected by the built-in security model. The plugin uses PayPal webhook signature verification, nonce verification for all authenticated requests, and capability checks for all admin operations.

= Does DonateOcean work with my theme? =

Yes. DonateOcean is theme-agnostic and works with any well-coded theme. The donation form inherits your theme's typography and base styles, and you can customize colors and spacing from the plugin's appearance settings.

== Screenshots ==

1. Plugin settings page showing the Environment and API Credentials tab with PayPal connection status.
2. Plugin settings page showing the Donation Experience tab with recurring donations, fee coverage, and amount configuration.
3. Plugin settings page showing the Organization and Compliance tab for charity details, tax ID, and receipt customization.
4. Advanced & Security.
5. Plugin settings page showing the Integrations tab.
6. Shortcode Builder.
7. Donation Reports.
8. Donation page.

== Changelog ==

= 1.0.6 =
* Added: Scheduled CSV exports — automatically email a donation export on a weekly or monthly schedule.
* Added: Recurring donors can update their PayPal payment method directly from the donor portal.
* Added: Marketing opt-in consent checkbox on the donation form, so donors are only added to email/CRM integrations when they agree.
* Added: Resend receipt button on the admin donation detail screen.
* Added: Community Support and Rate this Plugin links on the Plugins screen.
* Improved: Google Analytics and Tag Manager tags now honour visitor consent (WordPress Consent API) before loading.
* Security: PayPal and integration secrets are now encrypted at rest and no longer loaded on every page request.
* Fixed: Partial refunds received via a PayPal webhook are now recorded as partial instead of marking the whole donation refunded.

= 1.0.5 =
* Added: Google Analytics 4 and Google Tag Manager tag output, with an optional `donation_complete` event pushed to the data layer (and GA4) on successful donations.
* Improved: Constant Contact now connects securely via OAuth2 (Connect button) with automatic access-token refresh, replacing the unsupported static API key.
* Added: "Test connection" button for the Mailchimp integration.

= 1.0.4 =
* Added: Currency switcher on donation form. 
* Improved: Shortcode Builder.

= 1.0.3 =
* Improved: Admin side CSS.

= 1.0.2 =
* Improved - Admin UI.

= 1.0.1 =
* Improved – Donation block.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.6 =
Adds scheduled CSV exports, donor self-service payment-method updates, and marketing consent; encrypts stored credentials; and fixes partial-refund tracking from PayPal webhooks.

= 1.0.0 =
Initial release of DonateOcean.