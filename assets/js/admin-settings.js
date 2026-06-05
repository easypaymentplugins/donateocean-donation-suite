(function () {
    const root = document.querySelector('.donadosu-admin-wrap');

    if (!root) {
        return;
    }

    // ── Inline admin notice helper (replaces alert()) ────────────────────────
    function showAdminNotice(message, type) {
        var existing = root.querySelector('.donadosu-inline-notice');
        if (existing) existing.remove();
        var notice = document.createElement('div');
        notice.className = 'notice notice-' + (type || 'error') + ' is-dismissible donadosu-inline-notice';
        var p = document.createElement('p');
        p.textContent = message;
        notice.appendChild(p);
        var wrap = root.querySelector('.donadosu-settings-form') || root;
        wrap.parentNode.insertBefore(notice, wrap);
        notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(function () { if (notice.parentNode) notice.remove(); }, 8000);
    }

    // ── Dismissible inline state banners ─────────────────────────────────────
    // The "Setup required" / "Sandbox (Test Mode) active" banners carry a
    // dismiss button. Remove the banner on click and persist the choice so it
    // does not render again on the next visit.
    root.querySelectorAll('[data-donadosu-dismissible-notice]').forEach(function (notice) {
        var dismissBtn = notice.querySelector('.donadosu-notice-dismiss');
        if (!dismissBtn) return;
        dismissBtn.addEventListener('click', function () {
            notice.remove();
            if (typeof donadosuAdmin === 'undefined') return;
            var body = new URLSearchParams({
                action: 'donadosu_dismiss_notice',
                nonce: donadosuAdmin.nonce,
                notice: notice.getAttribute('data-donadosu-dismissible-notice') || '',
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body }).catch(function () {});
        });
    });

    // ── Copy-to-clipboard helper ──────────────────────────────────────────────
    root.querySelectorAll('.donadosu-copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('data-donadosu-copy');
            const input = targetId ? document.getElementById(targetId) : null;
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            const original = btn.textContent;
            function showCopied() {
                btn.textContent = 'Copied!';
                btn.disabled = true;
                setTimeout(function () {
                    btn.textContent = original;
                    btn.disabled = false;
                }, 1800);
            }
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(showCopied).catch(function () {
                    document.execCommand('copy');
                    showCopied();
                });
            } else {
                document.execCommand('copy');
                showCopied();
            }
        });
    });

    // ── Save & Continue (next step) ───────────────────────────────────────────
    var nextStepBtn = root.querySelector('#donadosu-next-step');
    if (nextStepBtn) {
        nextStepBtn.addEventListener('click', function () {
            var form = nextStepBtn.closest('form');
            var refererInput = form ? form.querySelector('[name="_wp_http_referer"]') : null;
            var nextTab = nextStepBtn.dataset.nextTab;
            if (refererInput && nextTab) {
                try {
                    var url = new URL(refererInput.value, window.location.origin);
                    url.searchParams.set('tab', nextTab);
                    refererInput.value = url.pathname + url.search;
                } catch (_) {}
            }
        });
    }

    // ── Helper: read active credentials from the form ──────────────────────
    function getFormCredentials() {
        var envSelect = root.querySelector('#donadosu-environment');
        var isSandbox = envSelect ? envSelect.value === '1' : true;
        var prefix = isSandbox ? 'sandbox' : 'live';
        var clientIdInput = root.querySelector('#donadosu-' + prefix + '-client-id');
        var secretInput = root.querySelector('#donadosu-' + prefix + '-secret');
        return {
            sandbox: isSandbox ? '1' : '0',
            client_id: clientIdInput ? clientIdInput.value : '',
            secret: secretInput ? secretInput.value : '',
        };
    }

    // ── Test PayPal connection (uses form values, works before save) ─────
    root.querySelectorAll('.donadosu-test-connection-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var resultSpan = btn.parentNode.querySelector('.donadosu-test-connection-result');
            if (!resultSpan || typeof donadosuAdmin === 'undefined') return;

            var creds = getFormCredentials();
            if (!creds.client_id || !creds.secret) {
                resultSpan.textContent = 'Please enter both Client ID and Secret before testing.';
                resultSpan.className = 'donadosu-inline-result donadosu-inline-result--error';
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Testing…';
            resultSpan.textContent = '';
            resultSpan.className = 'donadosu-inline-result';

            var body = new URLSearchParams({
                action: 'donadosu_test_connection',
                nonce: donadosuAdmin.nonce,
                sandbox: creds.sandbox,
                client_id: creds.client_id,
                secret: creds.secret,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    resultSpan.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Success.' : 'Failed.');
                    resultSpan.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    resultSpan.textContent = 'Request failed. Check browser console.';
                    resultSpan.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = 'Test PayPal connection';
                });
        });
    });

    // ── Validate credentials before save ─────────────────────────────────
    var settingsForm = root.querySelector('.donadosu-settings-form');
    var activeTabInput = settingsForm ? settingsForm.querySelector('[name="donadosu_settings[_active_tab]"]') : null;
    if (settingsForm && activeTabInput && typeof donadosuAdmin !== 'undefined') {
        var donadosuValidated = false;
        var donadosuValidating = false;

        settingsForm.addEventListener('submit', function (e) {
            // Only validate on the environment tab
            if (activeTabInput.value !== 'environment') {
                return;
            }

            // If already validated, allow through
            if (donadosuValidated) {
                donadosuValidated = false;
                return;
            }

            // Block concurrent validation requests (e.g. double-click)
            if (donadosuValidating) {
                e.preventDefault();
                return;
            }

            var creds = getFormCredentials();

            // If both fields are empty, allow save (user may be clearing credentials)
            if (!creds.client_id && !creds.secret) {
                return;
            }

            // If only one field is filled, block immediately
            if (!creds.client_id || !creds.secret) {
                e.preventDefault();
                showAdminNotice('Please enter both Client ID and Secret before saving.', 'error');
                return;
            }

            // Prevent form submission until validation completes
            e.preventDefault();
            donadosuValidating = true;

            // Disable all submit buttons in the form to prevent double-clicks
            var formButtons = settingsForm.querySelectorAll('[type="submit"]');
            var nextBtn = root.querySelector('#donadosu-next-step');
            formButtons.forEach(function (btn) { btn.disabled = true; });
            if (nextBtn) { nextBtn.disabled = true; nextBtn.textContent = 'Validating…'; }

            var body = new URLSearchParams({
                action: 'donadosu_test_connection',
                nonce: donadosuAdmin.nonce,
                sandbox: creds.sandbox,
                client_id: creds.client_id,
                secret: creds.secret,
            });

            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        // Credentials valid — flag and re-submit outside the promise chain
                        donadosuValidated = true;
                        setTimeout(function () { HTMLFormElement.prototype.submit.call(settingsForm); }, 0);
                    } else {
                        var msg = (data.data && data.data.message) ? data.data.message : 'Credentials could not be validated.';
                        showAdminNotice(msg, 'error');
                    }
                })
                .catch(function () {
                    showAdminNotice('Validation request failed. Please check your connection and try again.', 'error');
                })
                .finally(function () {
                    if (!donadosuValidated) {
                        donadosuValidating = false;
                        formButtons.forEach(function (btn) { btn.disabled = false; });
                        if (nextBtn) { nextBtn.disabled = false; nextBtn.textContent = 'Save & Continue →'; }
                    }
                });
        });
    }

    // ── Confirm-before-navigate helper (year-end emails, etc.) ─────────────
    root.querySelectorAll('[data-donadosu-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            var msg = el.getAttribute('data-donadosu-confirm');
            if (msg && !confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // ── Finish setup → land on the donations list ─────────────────────────
    var finishBtn = root.querySelector('#donadosu-finish-setup');
    if (finishBtn) {
        finishBtn.addEventListener('click', function () {
            var form = finishBtn.closest('form');
            var refererInput = form ? form.querySelector('[name="_wp_http_referer"]') : null;
            var target = finishBtn.dataset.finishTarget;
            if (refererInput && target) {
                try {
                    var url = new URL(target, window.location.origin);
                    refererInput.value = url.pathname + url.search;
                } catch (_) {}
            }
        });
    }

    // ── Disconnect PayPal account ───────────────────────────────────────────
    root.querySelectorAll('.donadosu-disconnect-paypal').forEach(function (btn) {
        if (typeof donadosuAdmin === 'undefined') return;

        btn.addEventListener('click', function () {
            if (!confirm('Are you sure you want to disconnect your PayPal account? Your API credentials will be removed.')) {
                return;
            }

            var env = btn.getAttribute('data-donadosu-env');
            var isSandbox = env === 'sandbox' ? '1' : '0';
            btn.disabled = true;
            btn.textContent = 'Disconnecting…';

            var body = new URLSearchParams({
                action: 'donadosu_disconnect_paypal',
                nonce: donadosuAdmin.nonce,
                sandbox: isSandbox,
            });

            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        var msg = (data.data && data.data.message) ? data.data.message : 'Disconnect failed.';
                        showAdminNotice(msg, 'error');
                        btn.disabled = false;
                        btn.textContent = 'Disconnect PayPal Account';
                    }
                })
                .catch(function () {
                    showAdminNotice('Request failed. Please check your connection and try again.', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Disconnect PayPal Account';
                });
        });
    });

    // ── Send test receipt email ───────────────────────────────────────────────
    const testEmailBtn = root.querySelector('#donadosu-send-test-email');
    const testEmailResult = root.querySelector('#donadosu-test-email-result');
    if (testEmailBtn && testEmailResult && typeof donadosuAdmin !== 'undefined') {
        testEmailBtn.addEventListener('click', function () {
            testEmailBtn.disabled = true;
            testEmailBtn.textContent = 'Sending…';
            testEmailResult.textContent = '';
            testEmailResult.className = 'donadosu-inline-result';

            const body = new URLSearchParams({
                action: 'donadosu_send_test_email',
                nonce: donadosuAdmin.nonce,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    testEmailResult.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Sent.' : 'Failed.');
                    testEmailResult.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    testEmailResult.textContent = 'Request failed. Check browser console.';
                    testEmailResult.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    testEmailBtn.disabled = false;
                    testEmailBtn.textContent = 'Send test email';
                });
        });
    }

    // ── Test Mailchimp connection ───────────────────────────────────────────────
    var mailchimpTestBtn = root.querySelector('#donadosu-mailchimp-test');
    var mailchimpTestResult = root.querySelector('#donadosu-mailchimp-test-result');
    if (mailchimpTestBtn && mailchimpTestResult && typeof donadosuAdmin !== 'undefined') {
        mailchimpTestBtn.addEventListener('click', function () {
            var apiKeyInput = root.querySelector('#donadosu-mailchimp-api-key');
            var listIdInput = root.querySelector('#donadosu-mailchimp-list-id');
            var apiKey = apiKeyInput ? apiKeyInput.value.trim() : '';
            var listId = listIdInput ? listIdInput.value.trim() : '';

            if (!apiKey || !listId) {
                mailchimpTestResult.textContent = 'Please enter both the API key and audience ID.';
                mailchimpTestResult.className = 'donadosu-inline-result donadosu-inline-result--error';
                return;
            }

            mailchimpTestBtn.disabled = true;
            mailchimpTestBtn.textContent = 'Testing…';
            mailchimpTestResult.textContent = '';
            mailchimpTestResult.className = 'donadosu-inline-result';

            var body = new URLSearchParams({
                action: 'donadosu_test_mailchimp',
                nonce: donadosuAdmin.nonce,
                api_key: apiKey,
                list_id: listId,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    mailchimpTestResult.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Connected.' : 'Failed.');
                    mailchimpTestResult.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    mailchimpTestResult.textContent = 'Request failed. Check browser console.';
                    mailchimpTestResult.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    mailchimpTestBtn.disabled = false;
                    mailchimpTestBtn.textContent = 'Test connection';
                });
        });
    }

    // ── Test Zapier webhook ─────────────────────────────────────────────────────
    var zapierTestBtn = root.querySelector('#donadosu-zapier-test');
    var zapierTestResult = root.querySelector('#donadosu-zapier-test-result');
    if (zapierTestBtn && zapierTestResult && typeof donadosuAdmin !== 'undefined') {
        zapierTestBtn.addEventListener('click', function () {
            var webhookUrlInput = root.querySelector('#donadosu-zapier-webhook-url');
            var webhookUrl = webhookUrlInput ? webhookUrlInput.value.trim() : '';
            if (!webhookUrl) {
                zapierTestResult.textContent = 'Please enter a webhook URL first.';
                zapierTestResult.className = 'donadosu-inline-result donadosu-inline-result--error';
                return;
            }

            zapierTestBtn.disabled = true;
            zapierTestBtn.textContent = 'Sending…';
            zapierTestResult.textContent = '';
            zapierTestResult.className = 'donadosu-inline-result';

            var body = new URLSearchParams({
                action: 'donadosu_test_zapier',
                nonce: donadosuAdmin.nonce,
                webhook_url: webhookUrl,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    zapierTestResult.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Sent.' : 'Failed.');
                    zapierTestResult.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    zapierTestResult.textContent = 'Request failed. Check browser console.';
                    zapierTestResult.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    zapierTestBtn.disabled = false;
                    zapierTestBtn.textContent = 'Send test event';
                });
        });
    }

    // ── Test Slack webhook ──────────────────────────────────────────────────────
    var slackTestBtn = root.querySelector('#donadosu-slack-test');
    var slackTestResult = root.querySelector('#donadosu-slack-test-result');
    if (slackTestBtn && slackTestResult && typeof donadosuAdmin !== 'undefined') {
        slackTestBtn.addEventListener('click', function () {
            var webhookUrlInput = root.querySelector('#donadosu-slack-webhook-url');
            var channelInput = root.querySelector('#donadosu-slack-channel');
            var webhookUrl = webhookUrlInput ? webhookUrlInput.value.trim() : '';
            var channel = channelInput ? channelInput.value.trim() : '';
            if (!webhookUrl) {
                slackTestResult.textContent = 'Please enter a webhook URL first.';
                slackTestResult.className = 'donadosu-inline-result donadosu-inline-result--error';
                return;
            }

            slackTestBtn.disabled = true;
            slackTestBtn.textContent = 'Sending…';
            slackTestResult.textContent = '';
            slackTestResult.className = 'donadosu-inline-result';

            var body = new URLSearchParams({
                action: 'donadosu_test_slack',
                nonce: donadosuAdmin.nonce,
                webhook_url: webhookUrl,
                channel: channel,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    slackTestResult.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Sent.' : 'Failed.');
                    slackTestResult.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    slackTestResult.textContent = 'Request failed. Check browser console.';
                    slackTestResult.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    slackTestBtn.disabled = false;
                    slackTestBtn.textContent = 'Send test notification';
                });
        });
    }

    // ── Test Twilio SMS ─────────────────────────────────────────────────────────
    var twilioTestBtn = root.querySelector('#donadosu-twilio-test');
    var twilioTestResult = root.querySelector('#donadosu-twilio-test-result');
    if (twilioTestBtn && twilioTestResult && typeof donadosuAdmin !== 'undefined') {
        twilioTestBtn.addEventListener('click', function () {
            var sidInput = root.querySelector('#donadosu-twilio-account-sid');
            var tokenInput = root.querySelector('#donadosu-twilio-auth-token');
            var fromInput = root.querySelector('#donadosu-twilio-from-number');
            var toInput = root.querySelector('#donadosu-twilio-to-number');

            var sid = sidInput ? sidInput.value.trim() : '';
            var token = tokenInput ? tokenInput.value.trim() : '';
            var from = fromInput ? fromInput.value.trim() : '';
            var to = toInput ? toInput.value.trim() : '';

            if (!sid || !token || !from || !to) {
                twilioTestResult.textContent = 'Please fill in all Twilio fields first.';
                twilioTestResult.className = 'donadosu-inline-result donadosu-inline-result--error';
                return;
            }

            twilioTestBtn.disabled = true;
            twilioTestBtn.textContent = 'Sending…';
            twilioTestResult.textContent = '';
            twilioTestResult.className = 'donadosu-inline-result';

            var body = new URLSearchParams({
                action: 'donadosu_test_twilio',
                nonce: donadosuAdmin.nonce,
                account_sid: sid,
                auth_token: token,
                from_number: from,
                to_number: to,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    twilioTestResult.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Sent.' : 'Failed.');
                    twilioTestResult.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    twilioTestResult.textContent = 'Request failed. Check browser console.';
                    twilioTestResult.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    twilioTestBtn.disabled = false;
                    twilioTestBtn.textContent = 'Send test SMS';
                });
        });
    }

    // ── Test ActiveCampaign connection ──────────────────────────────────────────
    var acTestBtn = root.querySelector('#donadosu-ac-test');
    var acTestResult = root.querySelector('#donadosu-ac-test-result');
    if (acTestBtn && acTestResult && typeof donadosuAdmin !== 'undefined') {
        acTestBtn.addEventListener('click', function () {
            var apiUrlInput = root.querySelector('#donadosu-ac-api-url');
            var apiKeyInput = root.querySelector('#donadosu-ac-api-key');
            var apiUrl = apiUrlInput ? apiUrlInput.value.trim() : '';
            var apiKey = apiKeyInput ? apiKeyInput.value.trim() : '';

            if (!apiUrl || !apiKey) {
                acTestResult.textContent = 'Please enter both API URL and API Key.';
                acTestResult.className = 'donadosu-inline-result donadosu-inline-result--error';
                return;
            }

            acTestBtn.disabled = true;
            acTestBtn.textContent = 'Testing…';
            acTestResult.textContent = '';
            acTestResult.className = 'donadosu-inline-result';

            var body = new URLSearchParams({
                action: 'donadosu_test_activecampaign',
                nonce: donadosuAdmin.nonce,
                api_url: apiUrl,
                api_key: apiKey,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    acTestResult.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Connected.' : 'Failed.');
                    acTestResult.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    acTestResult.textContent = 'Request failed. Check browser console.';
                    acTestResult.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    acTestBtn.disabled = false;
                    acTestBtn.textContent = 'Test connection';
                });
        });
    }

    // ── Test Brevo connection ────────────────────────────────────────────────────
    var brevoTestBtn = root.querySelector('#donadosu-brevo-test');
    var brevoTestResult = root.querySelector('#donadosu-brevo-test-result');
    if (brevoTestBtn && brevoTestResult && typeof donadosuAdmin !== 'undefined') {
        brevoTestBtn.addEventListener('click', function () {
            var apiKeyInput = root.querySelector('#donadosu-brevo-api-key');
            var apiKey = apiKeyInput ? apiKeyInput.value.trim() : '';

            if (!apiKey) {
                brevoTestResult.textContent = 'Please enter an API key.';
                brevoTestResult.className = 'donadosu-inline-result donadosu-inline-result--error';
                return;
            }

            brevoTestBtn.disabled = true;
            brevoTestBtn.textContent = 'Testing…';
            brevoTestResult.textContent = '';
            brevoTestResult.className = 'donadosu-inline-result';

            var body = new URLSearchParams({
                action: 'donadosu_test_brevo',
                nonce: donadosuAdmin.nonce,
                api_key: apiKey,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    brevoTestResult.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Connected.' : 'Failed.');
                    brevoTestResult.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    brevoTestResult.textContent = 'Request failed. Check browser console.';
                    brevoTestResult.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    brevoTestBtn.disabled = false;
                    brevoTestBtn.textContent = 'Test connection';
                });
        });
    }

    // ── Test Google Sheets connection ────────────────────────────────────────────
    var gsheetsTestBtn = root.querySelector('#donadosu-gsheets-test');
    var gsheetsTestResult = root.querySelector('#donadosu-gsheets-test-result');
    if (gsheetsTestBtn && gsheetsTestResult && typeof donadosuAdmin !== 'undefined') {
        gsheetsTestBtn.addEventListener('click', function () {
            var credentialsInput = root.querySelector('#donadosu-gsheets-credentials');
            var spreadsheetIdInput = root.querySelector('#donadosu-gsheets-spreadsheet-id');
            var credentials = credentialsInput ? credentialsInput.value.trim() : '';
            var spreadsheetId = spreadsheetIdInput ? spreadsheetIdInput.value.trim() : '';

            if (!credentials || !spreadsheetId) {
                gsheetsTestResult.textContent = 'Please enter both credentials JSON and Spreadsheet ID.';
                gsheetsTestResult.className = 'donadosu-inline-result donadosu-inline-result--error';
                return;
            }

            gsheetsTestBtn.disabled = true;
            gsheetsTestBtn.textContent = 'Testing…';
            gsheetsTestResult.textContent = '';
            gsheetsTestResult.className = 'donadosu-inline-result';

            var body = new URLSearchParams({
                action: 'donadosu_test_gsheets',
                nonce: donadosuAdmin.nonce,
                credentials_json: credentials,
                spreadsheet_id: spreadsheetId,
            });
            fetch(donadosuAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    gsheetsTestResult.textContent = data.data && data.data.message ? data.data.message : (data.success ? 'Connected.' : 'Failed.');
                    gsheetsTestResult.classList.add(data.success ? 'donadosu-inline-result--success' : 'donadosu-inline-result--error');
                })
                .catch(function () {
                    gsheetsTestResult.textContent = 'Request failed. Check browser console.';
                    gsheetsTestResult.classList.add('donadosu-inline-result--error');
                })
                .finally(function () {
                    gsheetsTestBtn.disabled = false;
                    gsheetsTestBtn.textContent = 'Test connection';
                });
        });
    }

    // ── Shortcode builder ─────────────────────────────────────────────────────
    var scOutput = root.querySelector('#donadosu-sc-output');

    function esc(str) {
        return String(str).replace(/[\[\]"]/g, '');
    }

    function buildShortcode() {
        if (!scOutput) return;
        var sc = '[donadosu_donation';

        // display_mode — omit default 'inline'
        var scMode = root.querySelector('#donadosu-sc-mode');
        if (scMode && scMode.value && scMode.value !== 'inline') {
            sc += ' display_mode="' + esc(scMode.value) + '"';
        }

        // donation_mode — omit default 'both'
        var scDonationMode = root.querySelector('#donadosu-sc-donation-mode');
        if (scDonationMode && scDonationMode.value && scDonationMode.value !== 'both') {
            sc += ' donation_mode="' + esc(scDonationMode.value) + '"';
        }

        // Fields pre-filled with their default: emit empty to hide, omit when
        // unchanged so the renderer's default applies, otherwise emit the value.
        function emitDefaultable(el, attr) {
            if (!el) return;
            var val = el.value.trim();
            var def = (el.getAttribute('data-donadosu-sc-default') || '').trim();
            if (val === '') {
                sc += ' ' + attr + '=""';
            } else if (val !== def) {
                sc += ' ' + attr + '="' + esc(val) + '"';
            }
        }

        emitDefaultable(root.querySelector('#donadosu-sc-title'), 'title');
        emitDefaultable(root.querySelector('#donadosu-sc-description'), 'description');
        emitDefaultable(root.querySelector('#donadosu-sc-button-text'), 'button_text');

        // button_color — omit if empty
        var scButtonColorText = root.querySelector('#donadosu-sc-button-color-text');
        if (scButtonColorText && /^#[0-9a-fA-F]{6}$/.test(scButtonColorText.value.trim())) {
            sc += ' button_color="' + esc(scButtonColorText.value.trim()) + '"';
        }

        // donor_fields — omit default (checked = 1)
        var scDonorFields = root.querySelector('#donadosu-sc-donor-fields');
        if (scDonorFields && !scDonorFields.checked) {
            sc += ' donor_fields="0"';
        }

        // Campaign & Purpose group — gated by the "Link this form to a
        // campaign" master toggle. When the toggle is off, skip every
        // campaign_* / purpose attribute regardless of field values.
        var scEnableCampaign = root.querySelector('#donadosu-sc-enable-campaign');
        var campaignEnabled = !scEnableCampaign || scEnableCampaign.checked;
        if (campaignEnabled) {
            // campaign — omit if empty
            var scCampaign = root.querySelector('#donadosu-sc-campaign');
            if (scCampaign && scCampaign.value.trim()) {
                sc += ' campaign="' + esc(scCampaign.value.trim()) + '"';
            }

            // purpose — omit if empty
            var scPurpose = root.querySelector('#donadosu-sc-purpose');
            if (scPurpose && scPurpose.value.trim()) {
                sc += ' purpose="' + esc(scPurpose.value.trim()) + '"';
            }

            // campaign_start — omit if empty
            var scCampaignStart = root.querySelector('#donadosu-sc-campaign-start');
            if (scCampaignStart && scCampaignStart.value) {
                sc += ' campaign_start="' + esc(scCampaignStart.value) + '"';
            }

            // campaign_end — omit if empty
            var scCampaignEnd = root.querySelector('#donadosu-sc-campaign-end');
            if (scCampaignEnd && scCampaignEnd.value) {
                sc += ' campaign_end="' + esc(scCampaignEnd.value) + '"';
            }
        }

        // goal_amount — omit if zero / empty, OR if the "Set a fundraising
        // goal" toggle is off (so unchecking the toggle clears all goal_*
        // attributes from the shortcode regardless of field values).
        var scEnableGoal = root.querySelector('#donadosu-sc-enable-goal');
        var goalEnabled = !scEnableGoal || scEnableGoal.checked;
        var scGoal = root.querySelector('#donadosu-sc-goal');
        var goalAmount = scGoal ? parseFloat(scGoal.value) : 0;
        if (goalEnabled && goalAmount > 0) {
            sc += ' goal_amount="' + goalAmount + '"';

            // goal_current
            var scGoalCurrentMode = root.querySelector('#donadosu-sc-goal-current-mode');
            if (scGoalCurrentMode) {
                if (scGoalCurrentMode.value === 'auto') {
                    sc += ' goal_current="auto"';
                } else if (scGoalCurrentMode.value === 'custom') {
                    var scGoalCurrentCustom = root.querySelector('#donadosu-sc-goal-current-custom');
                    var customCurrent = scGoalCurrentCustom ? parseFloat(scGoalCurrentCustom.value) : 0;
                    if (customCurrent > 0) {
                        sc += ' goal_current="' + customCurrent + '"';
                    }
                }
            }

            emitDefaultable(root.querySelector('#donadosu-sc-goal-label'), 'goal_label');

            // goal_close — omit default (unchecked = 0)
            var scGoalClose = root.querySelector('#donadosu-sc-goal-close');
            if (scGoalClose && scGoalClose.checked) {
                sc += ' goal_close="1"';
            }
        }

        // thank_you_url — when set, always pair with redirect_on_success="1"
        // (the redirect is the only thing the URL can do; the builder no
        // longer exposes a separate toggle).
        var scThankyouUrl = root.querySelector('#donadosu-sc-thankyou-url');
        if (scThankyouUrl && scThankyouUrl.value.trim()) {
            sc += ' thank_you_url="' + esc(scThankyouUrl.value.trim()) + '"';
            sc += ' redirect_on_success="1"';
        }

        // Advanced Overrides group — gated by the "Set advanced overrides"
        // master toggle. When off, skip every advanced attribute regardless
        // of field values.
        var scEnableAdvanced = root.querySelector('#donadosu-sc-enable-advanced');
        var advancedEnabled = !scEnableAdvanced || scEnableAdvanced.checked;
        if (advancedEnabled) {
            // currency — omit if empty; uppercase
            var scCurrency = root.querySelector('#donadosu-sc-currency');
            var currencyVal = scCurrency ? scCurrency.value.trim().toUpperCase() : '';
            if (currencyVal && /^[A-Z]{3}$/.test(currencyVal)) {
                sc += ' currency="' + esc(currencyVal) + '"';
            }

            // locale — omit if empty or malformed; accept BCP-47-ish codes only
            var scLocale = root.querySelector('#donadosu-sc-locale');
            var localeVal = scLocale ? scLocale.value.trim() : '';
            if (localeVal && /^[a-z]{2,3}(_[A-Z]{2})?$/.test(localeVal)) {
                sc += ' locale="' + esc(localeVal) + '"';
            }

            // amounts — omit if empty
            var scAmounts = root.querySelector('#donadosu-sc-amounts');
            if (scAmounts && scAmounts.value.trim()) {
                sc += ' amounts="' + esc(scAmounts.value.trim()) + '"';
            }

            // min_amount — omit if empty
            var scMinAmount = root.querySelector('#donadosu-sc-min-amount');
            if (scMinAmount && scMinAmount.value.trim()) {
                var minVal = parseFloat(scMinAmount.value);
                if (minVal > 0) {
                    sc += ' min_amount="' + minVal + '"';
                }
            }

            // max_amount — omit if empty
            var scMaxAmount = root.querySelector('#donadosu-sc-max-amount');
            if (scMaxAmount && scMaxAmount.value.trim()) {
                var maxVal = parseFloat(scMaxAmount.value);
                if (maxVal > 0) {
                    sc += ' max_amount="' + maxVal + '"';
                }
            }

            // fee_coverage — omit default (unchecked = 0)
            var scFeeCoverage = root.querySelector('#donadosu-sc-fee-coverage');
            if (scFeeCoverage && scFeeCoverage.checked) {
                sc += ' fee_coverage="1"';
            }

            // css_class — omit if empty
            var scCssClass = root.querySelector('#donadosu-sc-css-class');
            if (scCssClass && scCssClass.value.trim()) {
                sc += ' css_class="' + esc(scCssClass.value.trim()) + '"';
            }
        }

        sc += ']';
        scOutput.value = sc;
    }

    // Attach 'input' listeners to all builder controls
    var scBuilderInputs = root.querySelectorAll(
        '#donadosu-sc-mode, #donadosu-sc-donation-mode, #donadosu-sc-title, ' +
        '#donadosu-sc-description, #donadosu-sc-button-text, ' +
        '#donadosu-sc-button-color-text, #donadosu-sc-donor-fields, #donadosu-sc-campaign, ' +
        '#donadosu-sc-purpose, #donadosu-sc-campaign-start, #donadosu-sc-campaign-end, ' +
        '#donadosu-sc-goal, #donadosu-sc-goal-current-mode, #donadosu-sc-goal-current-custom, ' +
        '#donadosu-sc-goal-label, #donadosu-sc-goal-close, #donadosu-sc-thankyou-url, ' +
        '#donadosu-sc-currency, #donadosu-sc-locale, ' +
        '#donadosu-sc-amounts, #donadosu-sc-min-amount, #donadosu-sc-max-amount, ' +
        '#donadosu-sc-fee-coverage, #donadosu-sc-css-class'
    );
    scBuilderInputs.forEach(function (el) {
        el.addEventListener('input', buildShortcode);
        el.addEventListener('change', buildShortcode);
    });

    // Toggle custom goal-current input visibility
    var scGoalCurrentMode = root.querySelector('#donadosu-sc-goal-current-mode');
    var scGoalCurrentCustomWrap = root.querySelector('#donadosu-sc-goal-current-custom-wrap');
    if (scGoalCurrentMode && scGoalCurrentCustomWrap) {
        scGoalCurrentMode.addEventListener('change', function () {
            scGoalCurrentCustomWrap.hidden = scGoalCurrentMode.value !== 'custom';
        });
    }

    // "Link this form to a campaign" master toggle: show/hide the whole
    // Campaign & Purpose fields block. Field values are preserved when
    // hidden; the shortcode builder skips campaign_*/purpose attributes
    // when the checkbox is off.
    var scEnableCampaignEl = root.querySelector('#donadosu-sc-enable-campaign');
    var scCampaignFieldsWrap = root.querySelector('#donadosu-sc-campaign-fields');
    if (scEnableCampaignEl && scCampaignFieldsWrap) {
        var updateCampaignVisibility = function () {
            scCampaignFieldsWrap.hidden = !scEnableCampaignEl.checked;
            buildShortcode();
        };
        scEnableCampaignEl.addEventListener('change', updateCampaignVisibility);
        updateCampaignVisibility();
    }

    // "Set a fundraising goal" master toggle: show/hide the whole Fundraising
    // Goal fields block. Field values are preserved when hidden; the shortcode
    // builder already skips goal_* attributes when the checkbox is off.
    var scEnableGoalEl = root.querySelector('#donadosu-sc-enable-goal');
    var scGoalFieldsWrap = root.querySelector('#donadosu-sc-goal-fields');
    if (scEnableGoalEl && scGoalFieldsWrap) {
        var updateGoalVisibility = function () {
            scGoalFieldsWrap.hidden = !scEnableGoalEl.checked;
            buildShortcode();
        };
        scEnableGoalEl.addEventListener('change', updateGoalVisibility);
        updateGoalVisibility();
    }

    // "Set advanced overrides" master toggle: show/hide the whole Advanced
    // Overrides block (description + 7 fields). Field values are preserved
    // when hidden; the shortcode builder skips advanced attributes when the
    // checkbox is off.
    var scEnableAdvancedEl = root.querySelector('#donadosu-sc-enable-advanced');
    var scAdvancedFieldsWrap = root.querySelector('#donadosu-sc-advanced-fields');
    if (scEnableAdvancedEl && scAdvancedFieldsWrap) {
        var updateAdvancedVisibility = function () {
            scAdvancedFieldsWrap.hidden = !scEnableAdvancedEl.checked;
            buildShortcode();
        };
        scEnableAdvancedEl.addEventListener('change', updateAdvancedVisibility);
        updateAdvancedVisibility();
    }

    // Recurring-disabled warning: only present in the DOM when the recurring
    // setting is off; hide it when the user picks "One-time only".
    var scDonationModeEl = root.querySelector('#donadosu-sc-donation-mode');
    var scRecurringWarning = root.querySelector('#donadosu-sc-recurring-warning');
    if (scDonationModeEl && scRecurringWarning) {
        var updateRecurringWarning = function () {
            scRecurringWarning.hidden = scDonationModeEl.value === 'one_time';
        };
        scDonationModeEl.addEventListener('change', updateRecurringWarning);
        updateRecurringWarning();
    }

    // Dependency gating: disable child fields when their parent isn't set, so
    // the user can see at a glance that the child setting has no effect.
    function setFieldDisabled(el, disabled) {
        if (!el) return;
        el.disabled = disabled;
        var field = el.closest('.donadosu-sc-field');
        if (field) field.classList.toggle('donadosu-sc-field--disabled', disabled);
    }

    // Goal-amount gates the rest of the Fundraising Goal group.
    var scGoalInput = root.querySelector('#donadosu-sc-goal');
    var goalDependents = [
        root.querySelector('#donadosu-sc-goal-current-mode'),
        root.querySelector('#donadosu-sc-goal-current-custom'),
        root.querySelector('#donadosu-sc-goal-label'),
        root.querySelector('#donadosu-sc-goal-close'),
    ];
    if (scGoalInput) {
        var updateGoalDependents = function () {
            var hasGoal = parseFloat(scGoalInput.value) > 0;
            goalDependents.forEach(function (el) { setFieldDisabled(el, !hasGoal); });
        };
        scGoalInput.addEventListener('input', updateGoalDependents);
        updateGoalDependents();
    }

    // Color picker ↔ text input sync. When the text input is empty the
    // shortcode emits no button_color attribute, so the form falls back to
    // its default accent color. We dim the swatch in that state so the user
    // sees the picker is inactive, even though <input type="color"> always
    // carries a value.
    var scColorPicker = root.querySelector('#donadosu-sc-button-color-picker');
    var scColorText = root.querySelector('#donadosu-sc-button-color-text');
    var scColorClear = root.querySelector('#donadosu-sc-button-color-clear');

    function syncColorActiveState() {
        if (!scColorPicker || !scColorText) return;
        var active = /^#[0-9a-fA-F]{6}$/.test(scColorText.value.trim());
        scColorPicker.classList.toggle('donadosu-sc-color-picker--inactive', !active);
    }

    if (scColorPicker && scColorText) {
        scColorPicker.addEventListener('input', function () {
            scColorText.value = scColorPicker.value;
            syncColorActiveState();
            buildShortcode();
        });
        scColorText.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(scColorText.value)) {
                scColorPicker.value = scColorText.value;
            }
            syncColorActiveState();
            buildShortcode();
        });
        syncColorActiveState();
    }
    if (scColorClear && scColorText && scColorPicker) {
        scColorClear.addEventListener('click', function () {
            scColorText.value = '';
            scColorPicker.value = '#0070ba';
            syncColorActiveState();
            buildShortcode();
        });
    }

    // ── Min/Max amount client-side validation message ─────────────────────
    // The static "must be lower than the maximum" guidance now lives in the
    // field's ? tooltip; this element only surfaces the live error when the
    // minimum is not below the maximum.
    var minAmountInput = root.querySelector('#donadosu-min-amount');
    var maxAmountInput = root.querySelector('#donadosu-max-amount');
    var minMaxHint = root.querySelector('.donadosu-min-max-hint');
    if (minAmountInput && maxAmountInput && minMaxHint) {
        var validateMinMax = function () {
            var min = parseFloat(minAmountInput.value);
            var max = parseFloat(maxAmountInput.value);
            if (!isNaN(min) && !isNaN(max) && min >= max) {
                minMaxHint.textContent = 'Minimum must be lower than the maximum.';
                minMaxHint.style.color = '#b91c1c';
                minMaxHint.hidden = false;
                minAmountInput.setCustomValidity('Minimum must be lower than the maximum.');
            } else {
                minMaxHint.textContent = '';
                minMaxHint.hidden = true;
                minAmountInput.setCustomValidity('');
            }
        };
        minAmountInput.addEventListener('input', validateMinMax);
        maxAmountInput.addEventListener('input', validateMinMax);
    }

    // "Fee coverage" master toggle: show/hide the dependent "Transaction fee %"
    // and "Fee coverage pre-checked" rows. Field values are preserved when
    // hidden, so re-enabling restores the previously entered values.
    var feeCoverageToggle = root.querySelector('#donadosu-enable-fee-coverage');
    var feeCoverageRows = root.querySelectorAll('.donadosu-fee-coverage-dependent');
    if (feeCoverageToggle && feeCoverageRows.length) {
        var updateFeeCoverageVisibility = function () {
            feeCoverageRows.forEach(function (row) {
                row.hidden = !feeCoverageToggle.checked;
            });
        };
        feeCoverageToggle.addEventListener('change', updateFeeCoverageVisibility);
        updateFeeCoverageVisibility();
    }

    // Locale field — flag invalid format visually so the user knows their
    // override isn't being emitted into the shortcode.
    var scLocaleInput = root.querySelector('#donadosu-sc-locale');
    if (scLocaleInput) {
        scLocaleInput.addEventListener('input', function () {
            var val = scLocaleInput.value.trim();
            var invalid = val !== '' && !/^[a-z]{2,3}(_[A-Z]{2})?$/.test(val);
            scLocaleInput.classList.toggle('donadosu-sc-input--invalid', invalid);
        });
    }

    // Currency field: force uppercase on input
    var scCurrencyInput = root.querySelector('#donadosu-sc-currency');
    if (scCurrencyInput) {
        scCurrencyInput.addEventListener('input', function () {
            var pos = scCurrencyInput.selectionStart;
            scCurrencyInput.value = scCurrencyInput.value.toUpperCase();
            scCurrencyInput.setSelectionRange(pos, pos);
        });
    }

    // ── Integration sub-tabs ──────────────────────────────────────────────────
    var integrationTabBtns = root.querySelectorAll('.donadosu-integration-tab-btn');
    if (integrationTabBtns.length) {
        integrationTabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('aria-controls');
                var targetPanel = targetId ? root.querySelector('#' + targetId) : null;

                integrationTabBtns.forEach(function (b) {
                    b.classList.remove('is-active');
                    b.setAttribute('aria-selected', 'false');
                });
                root.querySelectorAll('.donadosu-integration-panel').forEach(function (p) {
                    p.classList.remove('is-active');
                    p.hidden = true;
                });

                btn.classList.add('is-active');
                btn.setAttribute('aria-selected', 'true');
                if (targetPanel) {
                    targetPanel.classList.add('is-active');
                    targetPanel.hidden = false;
                }
            });
        });
    }

    // ── Integration enable/disable toggles ───────────────────────────────────
    var integrationToggles = [
        { toggleId: 'donadosu-ga-enable',         fieldsId: 'donadosu-ga-fields',         tabKey: 'ga' },
        { toggleId: 'donadosu-mailchimp-enable',   fieldsId: 'donadosu-mailchimp-fields',   tabKey: 'mailchimp' },
        { toggleId: 'donadosu-cc-enable',          fieldsId: 'donadosu-cc-fields',          tabKey: 'cc' },
        { toggleId: 'donadosu-zapier-enable',      fieldsId: 'donadosu-zapier-fields',      tabKey: 'zapier' },
        { toggleId: 'donadosu-slack-enable',       fieldsId: 'donadosu-slack-fields',       tabKey: 'slack' },
        { toggleId: 'donadosu-twilio-enable',      fieldsId: 'donadosu-twilio-fields',      tabKey: 'twilio' },
        { toggleId: 'donadosu-ac-enable',          fieldsId: 'donadosu-ac-fields',          tabKey: 'ac' },
        { toggleId: 'donadosu-brevo-enable',       fieldsId: 'donadosu-brevo-fields',       tabKey: 'brevo' },
        { toggleId: 'donadosu-gsheets-enable',     fieldsId: 'donadosu-gsheets-fields',     tabKey: 'gsheets' },
    ];
    integrationToggles.forEach(function (cfg) {
        var toggle = root.querySelector('#' + cfg.toggleId);
        var fields = root.querySelector('#' + cfg.fieldsId);
        var tabBtn = root.querySelector('[data-donadosu-integration-tab="' + cfg.tabKey + '"]');

        if (!toggle || !fields) return;

        var syncToggle = function () {
            fields.hidden = !toggle.checked;
            if (tabBtn) {
                tabBtn.classList.toggle('is-enabled', toggle.checked);
            }
        };

        toggle.addEventListener('change', syncToggle);
        syncToggle();
    });

    // ── Environment dropdown toggle ─────────────────────────────────────────
    const envSelect = root.querySelector('#donadosu-environment');
    const environmentRows = root.querySelectorAll('[data-donadosu-env-row]');

    const syncEnvironmentFields = function () {
        if (!envSelect || !environmentRows.length) {
            return;
        }

        const activeEnvironment = envSelect.value === '1' ? 'sandbox' : 'live';

        environmentRows.forEach(function (row) {
            const isActive = row.getAttribute('data-donadosu-env-row') === activeEnvironment;
            row.hidden = !isActive;
        });
    };

    if (envSelect) {
        envSelect.addEventListener('change', syncEnvironmentFields);
        syncEnvironmentFields();
    }

    // ── Password field show/hide toggles ─────────────────────────────────
    root.querySelectorAll('.donadosu-toggle-visibility').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-donadosu-toggle');
            var input = targetId ? document.getElementById(targetId) : null;
            if (!input) return;
            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            var icon = btn.querySelector('.dashicons');
            if (icon) {
                icon.classList.toggle('dashicons-visibility', !isPassword);
                icon.classList.toggle('dashicons-hidden', isPassword);
            }
        });
    });

    const tabButtons = root.querySelectorAll('.donadosu-tab-trigger');
    const panels = root.querySelectorAll('.donadosu-tab-panel');

    if (!tabButtons.length || !panels.length) {
        return;
    }

    const activateTab = function (tabName) {
        tabButtons.forEach(function (button) {
            const isActive = button.dataset.donadosuTab === tabName;
            button.classList.toggle('nav-tab-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        panels.forEach(function (panel) {
            const isActive = panel.dataset.donadosuPanel === tabName;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
    };

    const focusTabAtIndex = function (index) {
        if (index < 0 || index >= tabButtons.length) {
            return;
        }

        const target = tabButtons[index];
        target.focus();
        activateTab(target.dataset.donadosuTab || '');
    };

    tabButtons.forEach(function (button, index) {
        button.addEventListener('click', function () {
            activateTab(button.dataset.donadosuTab || '');
        });

        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                focusTabAtIndex((index + 1) % tabButtons.length);
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                focusTabAtIndex((index - 1 + tabButtons.length) % tabButtons.length);
            }

            if (event.key === 'Home') {
                event.preventDefault();
                focusTabAtIndex(0);
            }

            if (event.key === 'End') {
                event.preventDefault();
                focusTabAtIndex(tabButtons.length - 1);
            }
        });
    });
})();
