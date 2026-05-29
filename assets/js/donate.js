(function () {
  'use strict';

  // ── Currency helpers ─────────────────────────────────────────────────────

  /**
   * Return the display symbol for a currency code (e.g. "USD" → "$").
   * Falls back to the uppercase code when the symbol is unknown.
   */
  function currencySymbol(code) {
    const symbols = {
      USD: '$', EUR: '€', GBP: '£', JPY: '¥', CNY: '¥',
      CAD: 'CA$', AUD: 'A$', CHF: 'CHF', INR: '₹', KRW: '₩',
      BRL: 'R$', MXN: 'MX$', SGD: 'S$', HKD: 'HK$', NZD: 'NZ$',
      SEK: 'kr', NOK: 'kr', DKK: 'kr', ZAR: 'R', PLN: 'zł',
      THB: '฿', IDR: 'Rp', MYR: 'RM', PHP: '₱', CZK: 'Kč',
      ILS: '₪', TWD: 'NT$', TRY: '₺', RUB: '₽', HUF: 'Ft',
    };
    return symbols[(code || '').toUpperCase()] || code.toUpperCase();
  }

  /**
   * Format an amount with its currency symbol (e.g. "$100.00").
   */
  function formatCurrency(code, amount) {
    const sym = currencySymbol(code);
    return `${sym}${amount.toFixed(2)}`;
  }

  // ── API helpers ───────────────────────────────────────────────────────────

  async function fetchConfig() {
    const res = await fetch(`${donadosuDonation.apiBase}/config`);
    if (!res.ok) throw new Error(`Config fetch failed HTTP ${res.status}`);
    return res.json();
  }

  async function apiFetch(path, payload) {
    const res = await fetch(`${donadosuDonation.apiBase}${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': donadosuDonation.nonce },
      body: JSON.stringify(payload),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body.error || `HTTP ${res.status}`);
    return body;
  }

  function loadPayPalSdk(clientId, currency, cardFieldsEnabled) {
    return new Promise((resolve, reject) => {
      if (window.paypal) return resolve(window.paypal);
      const components = cardFieldsEnabled ? 'buttons,card-fields' : 'buttons';
      const s = document.createElement('script');
      s.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=${encodeURIComponent(currency)}&intent=capture&vault=true&components=${components}`;
      s.setAttribute('data-partner-attribution-id', 'mbjtechnolabs_sp');
      s.onload = () => resolve(window.paypal);
      s.onerror = () => reject(new Error('PayPal SDK failed to load'));
      document.head.appendChild(s);
    });
  }

  function toBoolean(value) {
    return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
  }

  function generateIdempotencyKey() {
    return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }

  // ── Returning donor: localStorage helpers ─────────────────────────────────

  var DONOR_STORAGE_KEY = 'donadosu_donor_details';

  var DONOR_FIELDS = [
    { id: 'donadosu-name',    key: 'donor_name' },
    { id: 'donadosu-email',   key: 'donor_email' },
    { id: 'donadosu-phone',   key: 'donor_phone' },
    { id: 'donadosu-company', key: 'donor_company' },
    { id: 'donadosu-address', key: 'donor_address' },
    { id: 'donadosu-city',    key: 'donor_city' },
    { id: 'donadosu-postal',  key: 'donor_postal' },
  ];

  function saveDonorToStorage() {
    var rememberCb = document.getElementById('donadosu-remember-donor');
    if (rememberCb && !rememberCb.checked) {
      try { localStorage.removeItem(DONOR_STORAGE_KEY); } catch (_e) { /* noop */ }
      return;
    }
    var data = {};
    DONOR_FIELDS.forEach(function (f) {
      var el = document.getElementById(f.id);
      if (el && el.value.trim()) data[f.key] = el.value.trim();
    });
    // Remember fee-coverage preference alongside donor details.
    var feeCb = document.getElementById('donadosu-fee-coverage');
    if (feeCb) data.fee_coverage_checked = feeCb.checked ? '1' : '0';
    if (Object.keys(data).length > 0) {
      try { localStorage.setItem(DONOR_STORAGE_KEY, JSON.stringify(data)); } catch (_e) { /* noop */ }
    }
  }

  function loadDonorFromStorage() {
    try {
      var raw = localStorage.getItem(DONOR_STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (_e) {
      return null;
    }
  }

  function prefillDonorFields(data) {
    if (!data) return;
    DONOR_FIELDS.forEach(function (f) {
      var el = document.getElementById(f.id);
      if (el && !el.value && data[f.key]) el.value = data[f.key];
    });
  }

  function clearDonorStorage() {
    try { localStorage.removeItem(DONOR_STORAGE_KEY); } catch (_e) { /* noop */ }
  }

  // ── Modal ─────────────────────────────────────────────────────────────────

  function initModal(displayMode, wrap) {
    if (displayMode !== 'modal') return;
    wrap.hidden = true;
    const backdrop = document.createElement('div');
    backdrop.className = 'donadosu-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.appendChild(backdrop);

    const openBtn = document.getElementById('donadosu-open-modal');
    const closeBtn = document.getElementById('donadosu-close-modal');

    function openModal() {
      wrap.hidden = false;
      wrap.classList.add('donadosu-wrap--modal-open');
      backdrop.classList.add('donadosu-backdrop--visible');
      document.body.classList.add('donadosu-body--modal-open');
      const firstFocusable = wrap.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (firstFocusable) firstFocusable.focus();
    }

    function closeModal() {
      wrap.hidden = true;
      wrap.classList.remove('donadosu-wrap--modal-open');
      backdrop.classList.remove('donadosu-backdrop--visible');
      document.body.classList.remove('donadosu-body--modal-open');
      if (openBtn) openBtn.focus();
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !wrap.hidden && displayMode === 'modal') closeModal();
    });
  }

  // ── Button / accent colour ────────────────────────────────────────────────

  function applyButtonColor(wrap) {
    const color = (wrap.dataset.buttonColor || '').trim();
    if (/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(color)) {
      wrap.style.setProperty('--donadosu-accent', color);
    }
  }

  // ── Feature 7: Giving levels / Preset amount buttons ─────────────────────

  // Programmatically setting input.value does not dispatch an 'input' event,
  // so dependent listeners (fee calculation, live summary) never re-run.
  // Use this helper whenever the amount input is set from code.
  function setAmountValue(amountInput, value) {
    amountInput.value = value;
    amountInput.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function buildPresetButtons(container, givingLevels, presetAmounts, amountInput, customAmountEnabled, onSelect) {
    if (!container) return;
    container.innerHTML = '';

    const useGivingLevels = Array.isArray(givingLevels) && givingLevels.length > 0;

    function deactivateAll() {
      container.querySelectorAll('.donadosu-preset-btn').forEach((b) => b.classList.remove('donadosu-preset-btn--active'));
    }

    if (useGivingLevels) {
      // Feature 7: named giving levels
      givingLevels.forEach((level) => {
        const amt = parseFloat(String(level.amount || 0));
        if (!Number.isFinite(amt) || amt <= 0) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'donadosu-preset-btn donadosu-preset-btn--level';
        btn.dataset.amount = String(amt);
        btn.dataset.level  = String(level.label || '');

        const labelEl = document.createElement('strong');
        labelEl.textContent = level.label || `${amt}`;
        const amtEl = document.createElement('span');
        amtEl.className = 'donadosu-preset-btn__amount';
        amtEl.textContent = amt % 1 === 0 ? String(Math.round(amt)) : amt.toFixed(2);
        btn.appendChild(labelEl);
        btn.appendChild(amtEl);

        if (level.description) {
          btn.title = level.description;
        }
        btn.setAttribute('aria-label', `${level.label || ''} – ${amt % 1 === 0 ? Math.round(amt) : amt.toFixed(2)}`);

        btn.addEventListener('click', () => {
          deactivateAll();
          btn.classList.add('donadosu-preset-btn--active');
          amountInput.readOnly = true;
          setAmountValue(amountInput, amt.toFixed(2));
          onSelect(level.label || '');
        });

        container.appendChild(btn);
      });
    } else {
      // Plain preset amounts
      const amounts = Array.isArray(presetAmounts)
        ? presetAmounts.map((v) => parseFloat(String(v))).filter((v) => Number.isFinite(v) && v > 0)
        : [];

      amounts.forEach((amt) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'donadosu-preset-btn';
        btn.textContent = amt % 1 === 0 ? String(Math.round(amt)) : amt.toFixed(2);
        btn.dataset.amount = String(amt);
        btn.setAttribute('aria-label', `Donate ${amt % 1 === 0 ? Math.round(amt) : amt.toFixed(2)}`);

        btn.addEventListener('click', () => {
          deactivateAll();
          btn.classList.add('donadosu-preset-btn--active');
          amountInput.readOnly = true;
          setAmountValue(amountInput, amt.toFixed(2));
          onSelect('');
        });

        container.appendChild(btn);
      });
    }

    if (customAmountEnabled) {
      const customBtn = document.createElement('button');
      customBtn.type = 'button';
      customBtn.className = 'donadosu-preset-btn donadosu-preset-btn--custom';
      customBtn.textContent = 'Custom';
      customBtn.setAttribute('aria-label', 'Enter a custom donation amount');

      customBtn.addEventListener('click', () => {
        deactivateAll();
        customBtn.classList.add('donadosu-preset-btn--active');
        amountInput.readOnly = false;
        setAmountValue(amountInput, '');
        amountInput.focus();
        onSelect('');
      });

      container.appendChild(customBtn);
    }

    const firstPreset = container.querySelector('.donadosu-preset-btn:not(.donadosu-preset-btn--custom)');
    if (firstPreset) firstPreset.click();
  }

  // ── Feature 1: Frequency toggle ───────────────────────────────────────────

  function initFrequencyToggle(freqInput, onFreqChange) {
    const group = document.getElementById('donadosu-frequency-group');
    if (!group) return;

    group.querySelectorAll('.donadosu-freq-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        group.querySelectorAll('.donadosu-freq-btn').forEach((b) => {
          b.classList.remove('donadosu-freq-btn--active');
          b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('donadosu-freq-btn--active');
        btn.setAttribute('aria-pressed', 'true');
        if (freqInput) freqInput.value = btn.dataset.frequency || 'one_time';
        onFreqChange(btn.dataset.frequency || 'one_time');
      });
    });
  }

  // ── Feature 3: Tribute toggle ─────────────────────────────────────────────

  function initTributeToggle() {
    const toggle  = document.getElementById('donadosu-tribute-toggle');
    const section = document.getElementById('donadosu-tribute-section');
    if (!toggle || !section) return;

    toggle.addEventListener('change', () => {
      section.hidden = !toggle.checked;
    });
  }

  // ── Donor fields ──────────────────────────────────────────────────────────

  function setDonorFieldsState(enabled) {
    const donorSection = document.getElementById('donadosu-donor-section');
    if (!donorSection) return;
    donorSection.hidden = !enabled;
    ['donadosu-name', 'donadosu-email', 'donadosu-phone', 'donadosu-company',
      'donadosu-address', 'donadosu-city', 'donadosu-postal', 'donadosu-message']
      .map((id) => document.getElementById(id))
      .filter(Boolean)
      .forEach((input) => {
        input.disabled = !enabled;
        if (!enabled) input.value = '';
      });
  }

  // ── Feature 2: Fee coverage ───────────────────────────────────────────────

  function calcFee(amount, feePercent) {
    return Math.round(((amount + 0.30) / (1.0 - feePercent / 100) - amount) * 100) / 100;
  }

  function initFeeCoverage(amountInput, currencySelect, feePercent, onFeeChange, defaultChecked) {
    const checkbox  = document.getElementById('donadosu-fee-coverage');
    const feeLabel  = document.getElementById('donadosu-fee-amount-label');
    const feeRow    = document.getElementById('donadosu-summary-fee-row');
    const totalRow  = document.getElementById('donadosu-summary-total-row');
    const feeEl     = document.getElementById('donadosu-summary-fee');
    const totalEl   = document.getElementById('donadosu-summary-total');

    // Determine initial checkbox state: remembered preference > admin default > unchecked.
    if (checkbox) {
      var stored = loadDonorFromStorage();
      if (stored && typeof stored.fee_coverage_checked !== 'undefined') {
        checkbox.checked = stored.fee_coverage_checked === '1';
      } else {
        checkbox.checked = !!defaultChecked;
      }
    }

    function update() {
      const amt = parseFloat(amountInput.value) || 0;
      const fee = calcFee(amt, feePercent);
      const cur = currencySelect ? currencySelect.value : 'USD';
      if (feeLabel) feeLabel.textContent = formatCurrency(cur, fee);
      const checked = checkbox ? checkbox.checked : false;
      if (feeRow) feeRow.hidden = !checked;
      if (totalRow) totalRow.hidden = !checked;
      if (feeEl) feeEl.textContent = checked ? formatCurrency(cur, fee) : '—';
      if (totalEl) totalEl.textContent = checked ? formatCurrency(cur, amt + fee) : '—';
      onFeeChange(checked, fee);
    }

    if (checkbox) checkbox.addEventListener('change', update);
    if (amountInput) amountInput.addEventListener('input', update);
    update();
  }

  // ── Live summary ──────────────────────────────────────────────────────────

  let _selectedLevel = '';

  function updateSummary(frequency) {
    const amount     = document.getElementById('donadosu-amount');
    const currency   = document.getElementById('donadosu-currency');
    const donorToggle = document.getElementById('donadosu-donor-fields');
    const donorName  = document.getElementById('donadosu-name');
    const summaryAmount    = document.getElementById('donadosu-summary-amount');
    const summaryDonor     = document.getElementById('donadosu-summary-donor');
    const summaryFrequency = document.getElementById('donadosu-summary-frequency');

    if (summaryAmount && amount && currency) {
      const v = parseFloat(amount.value);
      const levelNote = _selectedLevel ? ` · ${_selectedLevel}` : '';
      summaryAmount.textContent = Number.isFinite(v)
        ? `${formatCurrency(currency.value, v)}${levelNote}`
        : '—';
    }

    if (summaryFrequency) {
      const labels = { one_time: 'One-time donation', monthly: 'Monthly recurring', annual: 'Annual recurring' };
      summaryFrequency.textContent = labels[frequency || 'one_time'] || 'One-time donation';
    }

    if (summaryDonor) {
      if (!donorToggle || !donorToggle.checked) {
        summaryDonor.textContent = 'Not included';
      } else if (donorName && donorName.value.trim()) {
        summaryDonor.textContent = donorName.value.trim();
      } else {
        summaryDonor.textContent = 'Included';
      }
    }
  }

  // ── Thank-you display ─────────────────────────────────────────────────────

  function showThankYou(wrap, resultEl) {
    wrap.querySelectorAll('.donadosu-panel, .donadosu-goal').forEach((el) => { el.hidden = true; });
    const headerSubtitle = wrap.querySelector('.donadosu-form-header p');
    if (headerSubtitle) headerSubtitle.hidden = true;
    const paypalContainer = document.getElementById('donadosu-paypal-button-container');
    if (paypalContainer) paypalContainer.hidden = true;
    const paymentMethod = document.getElementById('donadosu-payment-method');
    if (paymentMethod) paymentMethod.hidden = true;
    if (resultEl) resultEl.textContent = '';
    const thankYou = document.getElementById('donadosu-thankyou');
    if (thankYou) {
      thankYou.hidden = false;
      thankYou.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  // ── Payment method tabs ───────────────────────────────────────────────────

  function initPaymentMethodTabs() {
    const tabBtns = document.querySelectorAll('.donadosu-payment-tab');
    const panels  = {
      paypal: document.getElementById('donadosu-tab-paypal'),
      card:   document.getElementById('donadosu-tab-card'),
    };

    if (!tabBtns.length || !panels.paypal) return;

    tabBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        const method = btn.dataset.method;

        // Update tab states.
        tabBtns.forEach((b) => {
          const isActive = b.dataset.method === method;
          b.classList.toggle('donadosu-payment-tab--active', isActive);
          b.setAttribute('aria-selected', String(isActive));
        });

        // Show/hide panels.
        Object.entries(panels).forEach(([key, panel]) => {
          if (panel) panel.hidden = key !== method;
        });
      });
    });
  }

  // ── Shared payload builder ────────────────────────────────────────────────

  function buildDonationPayload(amountInput, currencySelect, campaignInput, purposeInput, freqInput, donorToggle, donorFieldsEnabled, feeCovered, cfg, paymentMethod) {
    const freq = freqInput ? freqInput.value : 'one_time';

    const payload = {
      amount:             amountInput.value,
      currency:           currencySelect.value,
      campaign:           campaignInput ? campaignInput.value : '',
      purpose:            purposeInput  ? purposeInput.value  : '',
      idempotency_key:    generateIdempotencyKey(),
      donation_frequency: freq,
      fee_covered:        feeCovered ? '1' : '0',
      locale:             (donadosuDonation.atts && donadosuDonation.atts.locale) || cfg.locale || '',
      // Tell the backend which flow is active so recurring + card triggers
      // the Orders-v2 vault path instead of the PayPal v1 Subscriptions path.
      payment_method:     paymentMethod === 'card' ? 'card' : 'paypal',
      // Feature 10: honeypot — pass actual field value so bots are detected
      _confirm_email:     (document.getElementById('donadosu-confirm-email') || {}).value || '',
    };

    // Feature 7: giving level
    if (_selectedLevel) {
      payload.giving_level = _selectedLevel;
    }

    const donorActive = donorToggle ? donorToggle.checked : donorFieldsEnabled;
    if (donorActive) {
      Object.assign(payload, {
        donor_name:    document.getElementById('donadosu-name')?.value    ?? '',
        donor_email:   document.getElementById('donadosu-email')?.value   ?? '',
        donor_phone:   document.getElementById('donadosu-phone')?.value   ?? '',
        donor_company: document.getElementById('donadosu-company')?.value ?? '',
        donor_address: document.getElementById('donadosu-address')?.value ?? '',
        donor_city:    document.getElementById('donadosu-city')?.value    ?? '',
        donor_postal:  document.getElementById('donadosu-postal')?.value  ?? '',
        donor_message: document.getElementById('donadosu-message')?.value ?? '',
        // Feature 4: anonymous
        is_anonymous:  document.getElementById('donadosu-anonymous')?.checked ? '1' : '0',
        // Feature 3: tribute
        is_tribute:         document.getElementById('donadosu-tribute-toggle')?.checked ? '1' : '0',
        tribute_type:       document.getElementById('donadosu-tribute-type')?.value       ?? '',
        tribute_name:       document.getElementById('donadosu-tribute-name')?.value       ?? '',
        tribute_notify_email: document.getElementById('donadosu-tribute-notify')?.value  ?? '',
      });
    }

    // Custom fields registered via the donadosu_register_custom_fields hook.
    // Collect every named input/select/textarea inside the custom-fields
    // container and add them to the payload, respecting radio/checkbox
    // semantics. Field IDs are validated server-side against registrations,
    // so any stray DOM injection cannot add arbitrary post meta.
    const customContainer = document.getElementById('donadosu-custom-fields');
    if (customContainer) {
      const customInputs = customContainer.querySelectorAll('input[name], select[name], textarea[name]');
      customInputs.forEach((el) => {
        const name = el.getAttribute('name');
        if (!name) return;

        if (el.type === 'radio') {
          if (el.checked) {
            payload[name] = el.value;
          } else if (!(name in payload)) {
            // Preserve empty string for unselected radio groups so required
            // validation can detect the missing selection.
            payload[name] = '';
          }
          return;
        }

        if (el.type === 'checkbox') {
          payload[name] = el.checked ? '1' : '0';
          return;
        }

        payload[name] = el.value ?? '';
      });
    }

    return payload;
  }

  // ── Main init ─────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', async () => {
    const root = document.querySelector('[data-donadosu]');
    if (!root) return;

    const displayWrapper = root.closest('[data-donadosu-display]');
    const displayMode    = displayWrapper ? displayWrapper.dataset.donadosuDisplay : 'inline';

    applyButtonColor(root);
    initModal(displayMode, root);
    initTributeToggle();

    const resultEl = document.getElementById('donadosu-result');

    // If the donor just returned from PayPal subscription approval,
    // show the thank-you screen immediately instead of the form.
    if (new URLSearchParams(window.location.search).has('donadosu_subscription_confirmed')) {
      // Ensure the wrapper is visible (modal mode hides it by default).
      root.hidden = false;
      showThankYou(root, resultEl);
      // Clean the query parameter from the URL so a page refresh shows the form again.
      const cleanUrl = new URL(window.location);
      cleanUrl.searchParams.delete('donadosu_subscription_confirmed');
      window.history.replaceState(null, '', cleanUrl);
      return;
    }

    let cfg;
    try {
      // Primary: read config embedded in the DOM (immune to REST / caching issues).
      // The inline data now contains { apiBase, nonce, defaults, atts } so that
      // the form works even when wp_localize_script output is unavailable (e.g.
      // due to page caching or late shortcode rendering).
      var inlineJson = root.dataset.donadosuConfig || '';
      if (inlineJson) {
        var inlineData = JSON.parse(inlineJson);
        // If inline data has the full envelope, extract defaults and bootstrap
        // the global donadosuDonation object when it is missing.
        if (inlineData && inlineData.defaults) {
          cfg = inlineData.defaults;
          if (typeof donadosuDonation === 'undefined') {
            window.donadosuDonation = {
              apiBase:  inlineData.apiBase  || '',
              nonce:    inlineData.nonce    || '',
              defaults: inlineData.defaults,
              atts:     inlineData.atts     || {},
            };
          }
        } else {
          // Legacy format: inline data IS the defaults object directly.
          cfg = inlineData;
        }
      }
      // Fallback 1: localized script variable.
      if (!cfg && typeof donadosuDonation !== 'undefined' && donadosuDonation.defaults && Object.keys(donadosuDonation.defaults).length) {
        cfg = donadosuDonation.defaults;
      }
      // Fallback 2: REST API.
      if (!cfg) {
        cfg = await fetchConfig();
      }
    } catch (_err) {
      if (resultEl) resultEl.textContent = 'Configuration could not be loaded. Please refresh and try again.';
      return;
    }

    const amountInput    = document.getElementById('donadosu-amount');
    const currencySelect = document.getElementById('donadosu-currency');
    const donorToggle    = document.getElementById('donadosu-donor-fields');
    const campaignInput  = document.getElementById('donadosu-campaign');
    const purposeInput   = document.getElementById('donadosu-purpose');
    const freqInput      = document.getElementById('donadosu-donation-frequency');
    const thankYouUrl       = root.dataset.thankYouUrl || '';
    const redirectOnSuccess = toBoolean(root.dataset.redirectOnSuccess || donadosuDonation.atts.redirect_on_success);

    // Frequency state
    let currentFrequency = (freqInput && freqInput.value) ? freqInput.value : 'one_time';

    // Fee coverage state
    let feeCovered = false;
    let feeAmount  = 0;

    // Populate currency selector.
    const allowedCurrencies = Array.isArray(cfg.allowedCurrencies) && cfg.allowedCurrencies.length
      ? cfg.allowedCurrencies
      : [cfg.currency || 'USD'];

    currencySelect.innerHTML = '';
    allowedCurrencies.forEach((code) => {
      const option = document.createElement('option');
      option.value = code;
      option.textContent = code;
      currencySelect.appendChild(option);
    });
    // Honour a previously-chosen currency from this tab session — the PayPal
    // SDK bakes a single currency into its script URL and can't be safely
    // re-initialised in place, so currency switching is done via page reload.
    var _storedCurrency = null;
    try { _storedCurrency = sessionStorage.getItem('donadosu_currency'); } catch (_e) { /* noop */ }
    if (_storedCurrency && allowedCurrencies.indexOf(_storedCurrency) !== -1) {
      currencySelect.value = _storedCurrency;
    } else {
      currencySelect.value = donadosuDonation.atts.currency || cfg.currency || allowedCurrencies[0];
    }

    // Per-shortcode overrides take precedence over global defaults.
    var attsMinAmount = parseFloat(donadosuDonation.atts.min_amount);
    var attsMaxAmount = parseFloat(donadosuDonation.atts.max_amount);
    amountInput.min = String(Number.isFinite(attsMinAmount) && attsMinAmount > 0 ? attsMinAmount : (cfg.minAmount || 1));
    amountInput.max = String(Number.isFinite(attsMaxAmount) && attsMaxAmount > 0 ? attsMaxAmount : (cfg.maxAmount || 100000));

    // Feature 7 + preset buttons — per-shortcode amounts override global presets.
    const presetContainer = document.getElementById('donadosu-preset-buttons');
    const givingLevels    = Array.isArray(cfg.givingLevels) ? cfg.givingLevels : [];
    const attsAmountsRaw  = (donadosuDonation.atts.amounts || '').trim();
    const presetAmounts   = attsAmountsRaw
      ? attsAmountsRaw.split(',').map((v) => v.trim()).filter(Boolean)
      : (Array.isArray(cfg.presetAmounts) ? cfg.presetAmounts : []);

    if (givingLevels.length > 0 || presetAmounts.length > 0) {
      buildPresetButtons(presetContainer, givingLevels, presetAmounts, amountInput, cfg.customAmountEnabled, (levelName) => {
        _selectedLevel = levelName;
        updateSummary(currentFrequency);
      });
    } else {
      amountInput.value = String(cfg.minAmount || 10);
      amountInput.readOnly = !cfg.customAmountEnabled;
      if (presetContainer) presetContainer.hidden = true;
    }

    // Donor fields toggle.
    const donorFieldsEnabled = toBoolean(root.dataset.donorFieldsEnabled ?? donadosuDonation.atts.donor_fields);
    if (donorToggle) {
      donorToggle.checked = donorFieldsEnabled;
      donorToggle.addEventListener('change', () => {
        setDonorFieldsState(donorToggle.checked);
        updateSummary(currentFrequency);
      });
    }
    setDonorFieldsState(donorFieldsEnabled);

    // ── Returning donor: prefill from localStorage ────────
    var _storedDonor = loadDonorFromStorage();
    if (_storedDonor) {
      prefillDonorFields(_storedDonor);
      // Auto-enable donor fields if we have stored data and they are toggleable.
      if (donorToggle && !donorToggle.checked && _storedDonor.donor_name) {
        donorToggle.checked = true;
        setDonorFieldsState(true);
      }
    }

    // Handle "Remember my details" checkbox — clear storage when unchecked.
    var _rememberCb = document.getElementById('donadosu-remember-donor');
    if (_rememberCb) {
      _rememberCb.addEventListener('change', function () {
        if (!_rememberCb.checked) clearDonorStorage();
      });
    }

    // Feature 1: Frequency toggle
    initFrequencyToggle(freqInput, (freq) => {
      currentFrequency = freq;
      updateSummary(freq);
    });

    // Feature 2: Fee coverage — per-shortcode override or global setting.
    var feeCoverageActive = toBoolean(donadosuDonation.atts.fee_coverage) || toBoolean(cfg.feeCoverageEnabled);
    if (feeCoverageActive) {
      initFeeCoverage(amountInput, currencySelect, cfg.feePercentage || 2.9, (covered, fee) => {
        feeCovered = covered;
        feeAmount  = fee;
        updateSummary(currentFrequency);
      }, cfg.feeCoverageDefaultChecked);
    }

    // Live summary on key inputs.
    ['donadosu-amount', 'donadosu-currency', 'donadosu-name'].forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', () => updateSummary(currentFrequency));
      el.addEventListener('change', () => updateSummary(currentFrequency));
    });
    updateSummary(currentFrequency);

    // Load PayPal SDK — the SDK bakes a single currency into its script URL
    // and registers global zoid message listeners, so it cannot be safely
    // re-initialised in place. Currency switching is handled via page reload
    // (see the currencySelect change handler below).
    let paypalSdk;
    var _sdkCurrency = currencySelect.value;
    try {
      paypalSdk = await loadPayPalSdk(cfg.clientId, _sdkCurrency, cfg.cardFieldsEnabled);
    } catch (_err) {
      if (resultEl) resultEl.textContent = 'Payment provider could not be loaded. Please refresh and try again.';
      return;
    }

    if (!paypalSdk || !paypalSdk.Buttons) return;

    // Flag to suppress onError when we are redirecting to PayPal for subscription approval.
    let redirectingToPayPal = false;

    // ── Shared createOrder callback for PayPal Buttons and Card Fields ─────

    function makeCreateOrder(paymentMethod) {
      return async function handleCreateOrder() {
        if (resultEl) { resultEl.textContent = ''; resultEl.classList.remove('donadosu-result--error'); }
        const freq = freqInput ? freqInput.value : 'one_time';
        const payload = buildDonationPayload(amountInput, currencySelect, campaignInput, purposeInput, freqInput, donorToggle, donorFieldsEnabled, feeCovered, cfg, paymentMethod);

        // Feature 1: For recurring via PayPal Smart Button, the backend returns an
        // approveUrl and we redirect to PayPal. For recurring via Card Fields,
        // the backend creates an Orders-v2 order with vault.store_in_vault so
        // onApprove flows through the normal capture path.
        if (freq !== 'one_time' && cfg.recurringEnabled) {
          payload.return_page = (redirectOnSuccess && thankYouUrl) ? thankYouUrl : window.location.href;
          try {
            const response = await apiFetch('/order/create', payload);
            if (response.isSubscription && response.approveUrl) {
              saveDonorToStorage();
              redirectingToPayPal = true;
              window.location.assign(response.approveUrl);
              // Return a never-resolving promise so PayPal SDK does not process a null order ID.
              return new Promise(function() {});
            }
            return response.orderID;
          } catch (err) {
            if (resultEl) {
              resultEl.textContent = `Subscription could not be created: ${err.message}`;
              resultEl.classList.add('donadosu-result--error');
              resultEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            throw err;
          }
        }

        try {
          const response = await apiFetch('/order/create', payload);
          return response.orderID;
        } catch (err) {
          if (resultEl) {
            resultEl.textContent = `Order could not be created: ${err.message}`;
            resultEl.classList.add('donadosu-result--error');
            resultEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
          throw err;
        }
      };
    }

    const handleCreateOrder     = makeCreateOrder('paypal');
    const handleCreateOrderCard = makeCreateOrder('card');

    // ── Shared onApprove callback for PayPal Buttons and Card Fields ──────

    async function handleOnApprove(data) {
      if (resultEl) { resultEl.textContent = 'Processing payment\u2026'; resultEl.classList.remove('donadosu-result--error'); }
      try {
        const capture = await apiFetch('/order/capture', {
          order_id:        data.orderID,
          idempotency_key: data.orderID,
        });

        if (capture && capture.ok) {
          saveDonorToStorage();
          if (redirectOnSuccess && thankYouUrl) {
            window.location.assign(thankYouUrl);
            return;
          }
          showThankYou(root, resultEl);
        } else {
          if (resultEl) { resultEl.textContent = 'Payment capture failed. Please contact support.'; resultEl.classList.add('donadosu-result--error'); }
        }
      } catch (err) {
        if (resultEl) { resultEl.textContent = `Payment failed: ${err.message}`; resultEl.classList.add('donadosu-result--error'); }
      }
    }

    function handleOnError(err) {
      if (redirectingToPayPal) return;
      if (resultEl) { resultEl.textContent = 'A payment error occurred. Please try again or contact support.'; resultEl.classList.add('donadosu-result--error'); }
      console.error('[donadosuDonation] PayPal SDK error:', err);
    }

    function handleOnCancel() {
      if (resultEl) { resultEl.textContent = 'Payment was cancelled. You can try again whenever you\u2019re ready.'; resultEl.classList.remove('donadosu-result--error'); }
    }

    // ── Render PayPal Smart Buttons ─────────────────────────────────────────

    const paypalButtonContainer = document.getElementById('donadosu-paypal-button-container');

    if (paypalButtonContainer) {
      paypalSdk.Buttons({
        createOrder: handleCreateOrder,
        onApprove:   handleOnApprove,
        onError:     handleOnError,
        onCancel:    handleOnCancel,
      }).render('#donadosu-paypal-button-container');
    }

    // ── Payment method tabs (PayPal vs Card) ────────────────────────────────

    const paymentMethodEl = document.getElementById('donadosu-payment-method');

    if (cfg.cardFieldsEnabled && paymentMethodEl) {
      paymentMethodEl.hidden = false;
      initPaymentMethodTabs();
    }

    // ── Card Fields (Advanced Credit & Debit Card Payments) ─────────────────

    if (cfg.cardFieldsEnabled && paypalSdk.CardFields) {
      const cardSubmitBtn = document.getElementById('donadosu-card-submit');
      let cardFieldsInstance = null;
      let cardFieldsReady = false;

      const cardFieldStyle = {
        input: {
          'font-size':   '14px',
          'font-family': 'inherit',
          'color':       '#27272a',
          'padding':     '8px 10px',
        },
        '.invalid': {
          'color': '#b91c1c',
        },
      };

      // Single CardFields instance regardless of frequency. Recurring is
      // handled server-side by opting the first-charge order into vaulting
      // (Orders v2 + payment_source.card.attributes.vault.store_in_vault),
      // so the browser always follows createOrder/onApprove.
      function initCardFields() {
        if (cardFieldsInstance) return cardFieldsInstance;

        cardFieldsReady = false;
        if (cardSubmitBtn) cardSubmitBtn.disabled = true;

        cardFieldsInstance = paypalSdk.CardFields({
          createOrder: handleCreateOrderCard,
          onApprove:   handleOnApprove,
          onError:     handleOnError,
          style:       cardFieldStyle,
        });

        // Check eligibility — card fields may not be available for the merchant.
        if (cardFieldsInstance.isEligible()
            && document.getElementById('donadosu-card-number')
            && document.getElementById('donadosu-card-expiry')
            && document.getElementById('donadosu-card-cvv')
            && document.getElementById('donadosu-card-name')) {
          cardFieldsInstance.NumberField().render('#donadosu-card-number');
          cardFieldsInstance.ExpiryField().render('#donadosu-card-expiry');
          cardFieldsInstance.CVVField().render('#donadosu-card-cvv');
          cardFieldsInstance.NameField().render('#donadosu-card-name');
          cardFieldsReady = true;
          if (cardSubmitBtn) cardSubmitBtn.disabled = false;
        } else {
          // Card fields not eligible — hide the card tab.
          var cardTab = document.getElementById('donadosu-tab-btn-card');
          if (cardTab) cardTab.hidden = true;
          var cardPanel = document.getElementById('donadosu-tab-card');
          if (cardPanel) cardPanel.hidden = true;
          /* Card fields not eligible for this merchant/currency — hide tab silently. */
        }

        return cardFieldsInstance;
      }

      initCardFields();

      // ── Card submit button handler ──────────────────────────────────────

      if (cardSubmitBtn) {
        cardSubmitBtn.addEventListener('click', async () => {
          if (!cardFieldsReady || !cardFieldsInstance) return;
          if (resultEl) { resultEl.textContent = ''; resultEl.classList.remove('donadosu-result--error'); }

          var _cardBtnOriginal = cardSubmitBtn.textContent;
          cardSubmitBtn.disabled = true;
          cardSubmitBtn.textContent = (donadosuDonation.atts.processing_text || 'Processing\u2026');

          try {
            if (resultEl) resultEl.textContent = 'Processing card payment\u2026';
            await cardFieldsInstance.submit();
            // onApprove (one-time capture or vault subscription) handles the rest.
          } catch (err) {
            if (resultEl) {
              resultEl.textContent = `Card payment failed: ${err.message}`;
              resultEl.classList.add('donadosu-result--error');
              resultEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            console.error('[donadosuDonation] Card payment error:', err);
          } finally {
            if (!redirectingToPayPal) {
              cardSubmitBtn.disabled = false;
              cardSubmitBtn.textContent = _cardBtnOriginal;
            }
          }
        });
      }
    }

    // ── Currency switching ───────────────────────────────────────────────────
    // The PayPal SDK can't be re-initialised in place (zoid registers global
    // window-level message listeners that throw on a second script load), so
    // switching currency requires a page reload with the choice persisted in
    // sessionStorage. The init code above reads the same key to seed the
    // dropdown after reload, which makes the SDK boot with the new currency.
    if (allowedCurrencies.length > 1) {
      currencySelect.addEventListener('change', () => {
        const newCurrency = currencySelect.value;
        if (newCurrency === _sdkCurrency) return;
        try { sessionStorage.setItem('donadosu_currency', newCurrency); } catch (_e) { /* noop */ }
        if (resultEl) {
          resultEl.textContent = 'Updating payment provider…';
          resultEl.classList.remove('donadosu-result--error');
        }
        currencySelect.disabled = true;
        window.location.reload();
      });
    }
  });
})();
