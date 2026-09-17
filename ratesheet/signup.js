/**
 * ratesheet/signup.js
 *
 * Customer-facing (no login) Rate Sheet Sign Up form (added 2026-09-17,
 * per Michael). Loaded by signup.html with a ?t=<token> query param,
 * talks only to api/public.php (no auth/session involved at all).
 *
 * PAYMENT DATA (updated 2026-09-17, follow-up #2 -- Alternative Payments
 * is now wired in, see api/public.php's and api/altpay.php's headers for
 * the full picture):
 *   - Card: mounts Evervault's hosted Inputs card form (js.evervault.com/v2,
 *     loaded in signup.html) via ensureCardFormMounted() below. The card
 *     number/expiry/CVV are typed into Evervault's own iframe and
 *     encrypted client-side -- this page's own JS never sees plaintext
 *     card data, only the three ENCRYPTED strings Evervault exposes on
 *     `cardForm.values.card.*`, read at submit time.
 *   - ACH: routing number / account number / account type are plain
 *     fields on this page (Alternative Payments' bank vaulting API has no
 *     documented client-side tokenization step) -- submitted straight to
 *     api/public.php, which relays them to Alternative Payments and
 *     discards them (never stored in our database).
 *
 * Do not regress this back to a "choice only" form without revisiting
 * that decision explicitly.
 *
 * LEGAL TEXT: RATESHEET_LEGAL_TEXT and CHECKBOX_TEXT below are both
 * Michael's real, verbatim wording (chat, 2026-09-17). Keep
 * RATESHEET_LEGAL_TEXT identical to api/public.php's matching
 * RATESHEET_LEGAL_SIGNATURE_TEXT constant if either is ever updated.
 */

(function () {
  'use strict';

  var root = document.getElementById('app-root');
  var params = new URLSearchParams(window.location.search);
  var token = params.get('t') || '';

  // Verbatim, per Michael (2026-09-17) -- shown under the signature block.
  // Keep in sync with api/public.php's RATESHEET_LEGAL_SIGNATURE_TEXT.
  var RATESHEET_LEGAL_TEXT =
    'I agree to pay CodeBlue Technology for services performed in the amounts specified within this rate agreement.\n\n' +
    'Taxes, shipping, handling and other fees may apply. We reserve the right to cancel orders arising from pricing or other errors.\n\n' +
    'Acceptance and Incorporation by Reference This Order together with the Master Services Agreement and Service Attachments and other terms and conditions identified on Exhibit A, all of which are incorporated herein by reference (collectively, the “Agreement”) is between CodeBlue Technology (sometimes referred to as “we,” “us,” “our,” “CBT,” or “Provider”), and the customer identified on the Order (sometimes referred to as “you,” “your,” or “Client”). This Agreement is effective as of the date the Client accepts the Order (the “Effective Date”).\n\n' +
    'By signing or accepting this Order, Client acknowledges, represents, and warrants that it has read and agrees to the terms and conditions identified on Exhibit A to this Order which are incorporated as if fully set forth herein. The parties hereby agree that electronic signatures to this Order shall be relied upon and will bind them to the obligations stated herein. Each party hereby warrants and represents that it has the express authority to execute this Agreement(s). Provider may make changes to the Agreement at any time. If there are changes, Provider will revise the date at the top of the document. Provider may or may not provide Client with additional notice regarding such changes. Client should review the terms and conditions regularly. Unless otherwise noted, the amended terms and conditions will be effective immediately, and your continued use of the Services thereafter constitutes your acceptance of the changes.\n\n' +
    'If you do not agree to the amended terms and conditions, you must stop using the Services immediately. Please note, you may incur a termination fee or other third-party fees, if applicable. You may access the current version of the terms and conditions at any time by visiting https://codebluetechnology.com/legal. The parties, acting through their authorized officers, hereby execute this Agreement.';

  // Verbatim, per Michael (2026-09-17).
  var CHECKBOX_TEXT = 'By signing below or clicking, Client acknowledges, represents and warrants that it has read and ' +
    'agrees to the terms and conditions in the following documents, which are incorporated herein by reference and can ' +
    'be found on Exhibit A in the PDF Version of any subsequent proposal.';

  var state = {
    loading: true,
    loadError: null,
    context: null, // { location, account_kind, hourly_rate, status }
    submitting: false,
    submitError: null,
    submitted: false,
    form: {
      first_name: '', last_name: '', email: '',
      address_line1: '', address_line2: '', city: '', state: '', zip: '',
      business_name: '',
      payment_method: '',
      bank_routing_number: '',
      bank_account_number: '',
      bank_account_type: '',
      want_copy_of_signup: false,
      invoices_emailed: false,
      agreed_to_terms: false
    }
  };

  var sigCanvas = null, sigCtx = null, sigHasStroke = false, sigDrawing = false;

  // ---- Evervault card form (see this file's PAYMENT DATA header note) --

  var cardFormCreds = null;   // { app_id, team_id } -- fetched once, cached
  var evervaultCard = null;   // most recently mounted card component instance
  var cardFormError = null;
  var cardFormValid = false;
  var cardFormLoading = false;

  // render() rebuilds #app-root's innerHTML from scratch on every call
  // (see render() below), which destroys any previously-mounted Evervault
  // iframe along with the rest of the DOM. So this checks the CURRENT
  // mount element's emptiness (not just a "did we already mount once" JS
  // flag) and remounts fresh whenever the container came back empty --
  // e.g. after a validation-error render while Card was selected.
  function ensureCardFormMounted() {
    var mount = document.getElementById('card-form-mount');
    if (!mount || mount.children.length > 0) return; // not on screen, or already has a live form in it

    function mountNow(creds) {
      try {
        var client = new window.Evervault(creds.team_id, creds.app_id);
        evervaultCard = client.ui.card({ theme: client.ui.themes.clean() });
        evervaultCard.mount('#card-form-mount');
        evervaultCard.on('change', function (data) {
          cardFormValid = !!(data && data.card && data.card.isValid);
        });
      } catch (err) {
        cardFormError = 'Could not load the secure card form. Please refresh the page, or choose ACH instead.';
        render();
      }
    }

    if (cardFormCreds) {
      mountNow(cardFormCreds);
      return;
    }
    cardFormLoading = true;
    apiGet('api/public.php?action=card-form-credentials&t=' + encodeURIComponent(token)).then(function (r) {
      cardFormLoading = false;
      if (r.data && r.data.ok) {
        cardFormCreds = { app_id: r.data.app_id, team_id: r.data.team_id };
        cardFormError = null;
        mountNow(cardFormCreds);
      } else {
        cardFormError = (r.data && r.data.error) || 'Could not load the card form. Please try again, or choose ACH instead.';
        render();
      }
    }).catch(function () {
      cardFormLoading = false;
      cardFormError = 'Could not load the card form — check your connection and try again, or choose ACH instead.';
      render();
    });
  }

  function e(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function apiGet(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }
  function apiPost(url, body) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().then(function (data) { return { status: r.status, data: data }; });
    });
  }

  function loadContext() {
    if (!token) {
      state.loading = false;
      state.loadError = 'This link is missing its signup code — please use the link from your email.';
      render();
      return;
    }
    apiGet('api/public.php?action=context&t=' + encodeURIComponent(token)).then(function (r) {
      state.loading = false;
      if (r.data && r.data.ok) {
        state.context = r.data;
      } else {
        state.loadError = (r.data && r.data.error) || 'This signup link is not valid.';
      }
      render();
    }).catch(function () {
      state.loading = false;
      state.loadError = 'Could not load this signup link — check your connection and try again.';
      render();
    });
  }

  // ---- Signature pad ---------------------------------------------------

  function setupSignaturePad() {
    sigCanvas = document.getElementById('sig-pad');
    if (!sigCanvas) return;
    var ratio = window.devicePixelRatio || 1;
    var rect = sigCanvas.getBoundingClientRect();
    sigCanvas.width = rect.width * ratio;
    sigCanvas.height = rect.height * ratio;
    sigCtx = sigCanvas.getContext('2d');
    sigCtx.scale(ratio, ratio);
    sigCtx.lineWidth = 2;
    sigCtx.lineCap = 'round';
    sigCtx.strokeStyle = '#182857';
    sigHasStroke = false;

    function pos(ev) {
      var rect2 = sigCanvas.getBoundingClientRect();
      var point = ev.touches ? ev.touches[0] : ev;
      return { x: point.clientX - rect2.left, y: point.clientY - rect2.top };
    }
    function start(ev) {
      ev.preventDefault();
      sigDrawing = true;
      var p = pos(ev);
      sigCtx.beginPath();
      sigCtx.moveTo(p.x, p.y);
    }
    function move(ev) {
      if (!sigDrawing) return;
      ev.preventDefault();
      var p = pos(ev);
      sigCtx.lineTo(p.x, p.y);
      sigCtx.stroke();
      sigHasStroke = true;
    }
    function end(ev) {
      if (ev) ev.preventDefault();
      sigDrawing = false;
    }

    sigCanvas.addEventListener('mousedown', start);
    sigCanvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    sigCanvas.addEventListener('touchstart', start, { passive: false });
    sigCanvas.addEventListener('touchmove', move, { passive: false });
    sigCanvas.addEventListener('touchend', end, { passive: false });
  }

  function clearSignature() {
    if (!sigCtx || !sigCanvas) return;
    sigCtx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
    sigHasStroke = false;
  }

  // ---- Submit ------------------------------------------------------------

  function validate() {
    var f = state.form;
    if (!f.first_name.trim()) return 'First name is required.';
    if (!f.last_name.trim()) return 'Last name is required.';
    if (!f.email.trim() || f.email.indexOf('@') === -1) return 'A valid email address is required.';
    if (!f.address_line1.trim()) return 'Address is required.';
    if (!f.city.trim()) return 'City is required.';
    if (!f.state.trim()) return 'State is required.';
    if (!f.zip.trim()) return 'ZIP code is required.';
    if (state.context.account_kind === 'Commercial' && !f.business_name.trim()) return 'Business name is required for a Commercial account.';
    if (!f.payment_method) return 'Please choose a payment method.';
    if (f.payment_method === 'card') {
      if (!evervaultCard || !cardFormValid) return 'Please complete the card form before submitting.';
    } else if (f.payment_method === 'ach') {
      if (!/^\d{9}$/.test(f.bank_routing_number.trim())) return 'A valid 9-digit routing number is required.';
      if (!/^\d{4,17}$/.test(f.bank_account_number.trim())) return 'A valid bank account number is required.';
      if (f.bank_account_type !== 'checking' && f.bank_account_type !== 'savings') return 'Please choose checking or savings.';
    }
    if (!f.agreed_to_terms) return 'Please check the box acknowledging the terms and conditions.';
    if (!sigHasStroke) return 'Please sign in the signature box before submitting.';
    return null;
  }

  function submit() {
    var err = validate();
    if (err) {
      state.submitError = err;
      render();
      return;
    }
    state.submitting = true;
    state.submitError = null;
    render();

    var body = Object.assign({}, state.form, { signature_data_url: sigCanvas.toDataURL('image/png') });
    if (state.form.payment_method === 'card' && evervaultCard) {
      // Evervault-ENCRYPTED strings only -- see this file's PAYMENT DATA
      // header note. Never the plaintext card.values.card fields as-is
      // logged/inspected; they're already ciphertext by this point.
      body.card_evervault_number = evervaultCard.values.card.number;
      body.card_evervault_expiry = evervaultCard.values.card.expiry;
      body.card_evervault_cvc = evervaultCard.values.card.cvc;
    }
    apiPost('api/public.php?action=submit&t=' + encodeURIComponent(token), body).then(function (r) {
      state.submitting = false;
      if (r.data && r.data.ok) {
        state.submitted = true;
      } else {
        state.submitError = (r.data && r.data.error) || 'Something went wrong submitting your sign up. Please try again, or contact CodeBlue Technology.';
      }
      render();
    }).catch(function () {
      state.submitting = false;
      state.submitError = 'Could not reach CodeBlue Technology — check your connection and try again.';
      render();
    });
  }

  // ---- Render -----------------------------------------------------------

  function render() {
    if (state.loading) {
      root.innerHTML = '<div class="wrap"><div class="card" style="text-align:center;color:#5A6472;">Loading…</div></div>';
      return;
    }
    if (state.loadError) {
      root.innerHTML = '<div class="wrap"><div class="card"><div class="error-banner">' + e(state.loadError) + '</div></div></div>';
      return;
    }
    if (state.context.status === 'submitted' && !state.submitted) {
      root.innerHTML = '<div class="wrap"><div class="card success-card"><div class="big">✅</div><h2>Already Submitted</h2>' +
        '<p style="color:#5A6472;">This rate sheet has already been signed and submitted. If you need to make a change, please contact CodeBlue Technology.</p></div></div>';
      return;
    }
    if (state.submitted) {
      root.innerHTML = '<div class="wrap"><div class="card success-card"><div class="big">✅</div><h2>Thank You!</h2>' +
        '<p style="color:#5A6472;">Your rate sheet sign up has been received. A CodeBlue Technology team member will follow up with you shortly to finish setting up your account.</p></div>' +
        '<div class="footer-contact">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div></div>';
      return;
    }

    var c = state.context;
    var f = state.form;
    var locationLabel = c.location === 'Richmond' ? 'Richmond' : 'Northern Neck (Warsaw)';
    var isCommercial = c.account_kind === 'Commercial';

    root.innerHTML = '' +
      '<div class="wrap">' +
      '  <div class="letterhead"><div class="name">CodeBlue Technology</div><div class="sub">Customer Rate Sheet Sign Up</div></div>' +

      (state.submitError ? '<div class="error-banner">' + e(state.submitError) + '</div>' : '') +

      '  <div class="card">' +
      '    <div class="rate-highlight">$' + c.hourly_rate.toFixed(2) + ' <span>per hour — ' + e(locationLabel) + ' (' + e(c.account_kind) + ')</span></div>' +
      '    <p style="font-size:13px;color:#5A6472;line-height:1.6;">Thank you for considering CodeBlue Technology for your business IT needs. Please review the rate information below and complete this form to get started.</p>' +
      '    <p style="font-size:12.5px;color:#33394A;line-height:1.6;"><strong>Onsite Service:</strong> 1-hour minimum, 30-minute increments thereafter.<br>' +
      '    <strong>Remote Service:</strong> 30-minute minimum, 30-minute increments thereafter.<br>' +
      '    <strong>After-Hour Emergency Support:</strong> 2-hour minimum, 1.5x your hourly rate (before 8am and after 5pm).<br>' +
      '    <strong>Holidays:</strong> 2-hour minimum, 2x your hourly rate.<br>' +
      '    <strong>Travel:</strong> Billed for 1 direction only, for distances of 20 miles or more.<br>' +
      '    <strong>Payment Terms:</strong> Per-hour work is invoiced upon completion. Recurring Services are charged to your ACH or Credit Card on file, on the date it’s due.</p>' +
      '  </div>' +

      '  <div class="card">' +
      '    <div class="field-label">First Name</div><input type="text" data-field="first_name" value="' + e(f.first_name) + '" />' +
      '    <div class="field-label">Last Name</div><input type="text" data-field="last_name" value="' + e(f.last_name) + '" />' +
      '    <div class="field-label">Email Address</div><input type="email" data-field="email" value="' + e(f.email) + '" />' +
      (isCommercial ? '    <div class="field-label">Business Name</div><input type="text" data-field="business_name" value="' + e(f.business_name) + '" />' : '') +
      '    <div class="field-label">Address</div><input type="text" placeholder="Street address" data-field="address_line1" value="' + e(f.address_line1) + '" />' +
      '    <input type="text" placeholder="Apt / Suite (optional)" data-field="address_line2" value="' + e(f.address_line2) + '" style="margin-top:8px;" />' +
      '    <div class="row2" style="margin-top:8px;">' +
      '      <div><input type="text" placeholder="City" data-field="city" value="' + e(f.city) + '" /></div>' +
      '      <div style="max-width:90px;"><input type="text" placeholder="State" data-field="state" value="' + e(f.state) + '" /></div>' +
      '      <div style="max-width:130px;"><input type="text" placeholder="ZIP" data-field="zip" value="' + e(f.zip) + '" /></div>' +
      '    </div>' +
      '  </div>' +

      '  <div class="card">' +
      '    <div class="field-label">Payment Method</div>' +
      '    <div class="method-row">' +
      '      <label class="method-option"><input type="radio" name="payment_method" value="card" ' + (f.payment_method === 'card' ? 'checked' : '') + ' data-radio="payment_method" />' +
      '        <span>Credit Card<span class="method-note">Standard rate</span></span></label>' +
      '      <label class="method-option"><input type="radio" name="payment_method" value="ach" ' + (f.payment_method === 'ach' ? 'checked' : '') + ' data-radio="payment_method" />' +
      '        <span>ACH (Bank Transfer)<span class="method-note">Save 3% on transactions</span></span></label>' +
      '    </div>' +
      (f.payment_method === 'card' ? (
        '    <div id="card-form-mount" class="card-form-mount"></div>' +
        (cardFormLoading ? '    <div class="card-form-loading">Loading secure card form…</div>' : '') +
        (cardFormError ? '    <div class="card-form-error">' + e(cardFormError) + '</div>' : '') +
        '    <div class="payment-note">Your card details are encrypted in your browser and sent directly to our payment processor — CodeBlue Technology never sees or stores your card number.</div>'
      ) : f.payment_method === 'ach' ? (
        '    <div class="bank-fields">' +
        '      <div class="field-label">Routing Number</div><input type="text" inputmode="numeric" maxlength="9" placeholder="9 digits" data-field="bank_routing_number" value="' + e(f.bank_routing_number) + '" />' +
        '      <div class="field-label">Account Number</div><input type="text" inputmode="numeric" data-field="bank_account_number" value="' + e(f.bank_account_number) + '" />' +
        '      <div class="field-label">Account Type</div>' +
        '      <div class="account-type-row">' +
        '        <label><input type="radio" name="bank_account_type" value="checking" ' + (f.bank_account_type === 'checking' ? 'checked' : '') + ' data-radio="bank_account_type" /> Checking</label>' +
        '        <label><input type="radio" name="bank_account_type" value="savings" ' + (f.bank_account_type === 'savings' ? 'checked' : '') + ' data-radio="bank_account_type" /> Savings</label>' +
        '      </div>' +
        '    </div>' +
        '    <div class="payment-note">Your bank details are sent securely and stored only with our payment processor — CodeBlue Technology does not keep your account or routing number.</div>'
      ) : '') +
      '  </div>' +

      '  <div class="card">' +
      '    <div class="field-label">Would you like a copy of this sign up (and our terms &amp; conditions) emailed to you?</div>' +
      '    <div class="radio-row">' +
      '      <label class="radio-option"><input type="radio" name="want_copy" value="yes" ' + (f.want_copy_of_signup ? 'checked' : '') + ' data-yesno="want_copy_of_signup" /> Yes</label>' +
      '      <label class="radio-option"><input type="radio" name="want_copy" value="no" ' + (!f.want_copy_of_signup ? 'checked' : '') + ' data-yesno-no="want_copy_of_signup" /> No</label>' +
      '    </div>' +
      '    <div class="field-label">Would you like invoice copies emailed to you?</div>' +
      '    <div class="radio-row">' +
      '      <label class="radio-option"><input type="radio" name="invoices_emailed" value="yes" ' + (f.invoices_emailed ? 'checked' : '') + ' data-yesno="invoices_emailed" /> Yes</label>' +
      '      <label class="radio-option"><input type="radio" name="invoices_emailed" value="no" ' + (!f.invoices_emailed ? 'checked' : '') + ' data-yesno-no="invoices_emailed" /> No</label>' +
      '    </div>' +
      '  </div>' +

      '  <div class="card">' +
      '    <div class="field-label">Terms &amp; Signature</div>' +
      '    <div class="legal-block">' + e(RATESHEET_LEGAL_TEXT) + '</div>' +
      '    <div class="checkbox-row">' +
      '      <input type="checkbox" id="agree-check" ' + (f.agreed_to_terms ? 'checked' : '') + ' data-check="agreed_to_terms" />' +
      '      <label for="agree-check">' + e(CHECKBOX_TEXT) + '</label>' +
      '    </div>' +
      '    <div class="field-label" style="margin-top:20px;">Sign Below</div>' +
      '    <div class="sig-wrap"><canvas id="sig-pad"></canvas></div>' +
      '    <div class="sig-actions"><button type="button" class="clear-sig-btn" data-action="clear-sig">Clear signature</button></div>' +
      '  </div>' +

      '  <button class="submit-btn" data-action="submit" ' + (state.submitting ? 'disabled' : '') + '>' + (state.submitting ? 'Submitting…' : 'Submit') + '</button>' +
      '  <div class="footer-contact">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div>' +
      '</div>';

    setupSignaturePad();
    if (f.payment_method === 'card') ensureCardFormMounted();
  }

  root.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.hasAttribute('data-field')) {
      state.form[el.getAttribute('data-field')] = el.value;
    }
  });
  root.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.hasAttribute('data-radio')) {
      var field = el.getAttribute('data-radio');
      state.form[field] = el.value;
      // payment_method swaps between the card form and the ACH fields, so
      // (unlike every other field on this page) it needs a full re-render.
      if (field === 'payment_method') {
        cardFormError = null;
        render();
      }
    } else if (el.hasAttribute('data-yesno')) {
      state.form[el.getAttribute('data-yesno')] = true;
    } else if (el.hasAttribute('data-yesno-no')) {
      state.form[el.getAttribute('data-yesno-no')] = false;
    } else if (el.hasAttribute('data-check')) {
      state.form[el.getAttribute('data-check')] = el.checked;
    }
  });
  root.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');
    if (action === 'clear-sig') {
      clearSignature();
    } else if (action === 'submit') {
      submit();
    }
  });

  loadContext();
})();
