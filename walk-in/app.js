/**
 * walk-in/app.js
 *
 * Walk-in customer (no login, no rep) Rate Sheet Sign Up form -- added
 * 2026-09-22 per Michael's request: "We have walk in customers that need
 * a version of the rate sheet that mimics the function of the current
 * version. A static page that we can bookmark on the portal
 * https://portal.codebluetechnology.com/walk-in would be ideal. The walk
 * in customer should see the page that allows them to enter all of their
 * contact and company information, and on submitting, it triggers the
 * same actions as if they were emailing in."
 *
 * Same one-screen design as ratesheet/signup.js (info + address + Card/
 * ACH preference + terms + signature, submitted in one call), with two
 * differences instead of one shared file:
 *   1. There's no rep and no emailed token -- this page IS the rep's
 *      "send" step and the customer's "complete it" step, combined. So
 *      unlike signup.js, there's no ?action=context load and no
 *      'pending'/'submitted' resume state -- every page load is a fresh
 *      blank form (a "Start a new sign up" button resets it in place
 *      after a successful submit, so the same bookmarked page can be
 *      reused back-to-back at the counter without a reload).
 *   2. Since no rep picked Location/Kind of Account ahead of time (see
 *      _util.php's ratesheet_hourly_rate()/ratesheet_rep_territory_search_term()
 *      docblocks), the walk-in customer picks their own -- per Michael,
 *      asked directly during planning ("Walk-in customer selects it").
 *
 * Talks only to ../ratesheet/api/walkin.php (walk-in/ and ratesheet/ are
 * sibling top-level directories -- see that file's header). Submits
 * through the exact same ConnectWise-create + Credit Hold + notification-
 * email logic as a rep-sent signup (ratesheet/api/submit-core.php).
 *
 * PAYMENT DATA / LEGAL TEXT: identical policy to signup.js -- see that
 * file's header. RATESHEET_LEGAL_TEXT/CHECKBOX_TEXT below are kept in
 * sync with it and with api/_util.php's ratesheet_legal_text()/
 * ratesheet_checkbox_text() -- update all three together if either ever
 * changes.
 */

(function () {
  'use strict';

  var root = document.getElementById('app-root');

  // Kept in sync with _util.php's ratesheet_hourly_rate() -- Warsaw /
  // Richmond, same rate for Commercial or Residential (per Michael,
  // 2026-09-17). Only used to show a live rate preview as the customer
  // picks a Location; the server resolves the real value itself from
  // the Location it receives, same as every other rate lookup in this
  // app -- this is display-only, never trusted as-is.
  var RATE_BY_LOCATION = { Warsaw: 173.25, Richmond: 180.00 };

  // Verbatim, per Michael (2026-09-17) -- shown under the signature block.
  // Keep in sync with ratesheet/signup.js and api/_util.php's ratesheet_legal_text().
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

  function blankForm() {
    return {
      location: '', account_kind: '',
      first_name: '', last_name: '', email: '', phone: '',
      address_line1: '', address_line2: '', city: '', state: '', zip: '',
      business_name: '',
      payment_method: '',
      want_copy_of_signup: false,
      invoices_emailed: false,
      agreed_to_terms: false
    };
  }

  var state = {
    submitting: false,
    submitError: null,
    submitted: false,
    form: blankForm()
  };

  var sigCanvas = null, sigCtx = null, sigHasStroke = false, sigDrawing = false;

  function setSubmitButtonState() {
    var btn = root.querySelector('[data-action="submit-form"]');
    if (!btn) return;
    btn.disabled = state.submitting;
    btn.textContent = state.submitting ? 'Submitting…' : btn.getAttribute('data-idle-label');
  }

  // See signup.js's identical helper for why this avoids a full render()
  // on a validation error -- re-running render() would reset the
  // signature pad canvas.
  function showFormError(msg) {
    state.submitError = msg;
    var wrap = root.querySelector('.wrap');
    if (!wrap) { render(); return; }
    var banner = wrap.querySelector('.js-submit-error');
    if (msg) {
      if (!banner) {
        banner = document.createElement('div');
        banner.className = 'error-banner js-submit-error';
        wrap.insertBefore(banner, wrap.querySelector('.card'));
      }
      banner.textContent = msg;
    } else if (banner) {
      banner.remove();
    }
  }

  function e(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
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

  // ---- Signature pad (identical to signup.js) ------------------------

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

  // ---- Submit ---------------------------------------------------------

  function validateForm() {
    var f = state.form;
    if (!f.location) return 'Please choose a location.';
    if (!f.account_kind) return 'Please choose Commercial or Residential.';
    if (!f.first_name.trim()) return 'First name is required.';
    if (!f.last_name.trim()) return 'Last name is required.';
    if (!f.email.trim() || f.email.indexOf('@') === -1) return 'A valid email address is required.';
    if (f.phone.replace(/\D/g, '').length < 7) return 'A valid phone number is required.';
    if (!f.address_line1.trim()) return 'Address is required.';
    if (!f.city.trim()) return 'City is required.';
    if (!f.state.trim()) return 'State is required.';
    if (!f.zip.trim()) return 'ZIP code is required.';
    if (f.account_kind === 'Commercial' && !f.business_name.trim()) return 'Business name is required for a Commercial account.';
    if (!f.payment_method) return 'Please choose a payment method.';
    if (!f.agreed_to_terms) return 'Please check the box acknowledging the terms and conditions.';
    if (!sigHasStroke) return 'Please sign in the signature box before submitting.';
    return null;
  }

  function submitForm() {
    var err = validateForm();
    if (err) {
      showFormError(err);
      return;
    }
    state.submitting = true;
    showFormError(null);
    setSubmitButtonState();

    var f = state.form;
    var body = {
      location: f.location, account_kind: f.account_kind,
      first_name: f.first_name, last_name: f.last_name, email: f.email, phone: f.phone,
      business_name: f.business_name, address_line1: f.address_line1, address_line2: f.address_line2,
      city: f.city, state: f.state, zip: f.zip,
      payment_method: f.payment_method,
      want_copy_of_signup: f.want_copy_of_signup, invoices_emailed: f.invoices_emailed,
      agreed_to_terms: f.agreed_to_terms, signature_data_url: sigCanvas.toDataURL('image/png')
    };
    apiPost('../ratesheet/api/walkin.php?action=submit', body).then(function (r) {
      state.submitting = false;
      if (r.data && r.data.ok) {
        state.submitted = true;
        render(); // done -- safe (and expected) to fully swap to the "Thank You" screen
      } else {
        showFormError((r.data && r.data.error) || 'Something went wrong submitting this information. Please try again, or contact CodeBlue Technology.');
        setSubmitButtonState();
      }
    }).catch(function () {
      state.submitting = false;
      showFormError('Could not reach CodeBlue Technology — check your connection and try again.');
      setSubmitButtonState();
    });
  }

  // Location/Account Type changes need a full render() (rate preview
  // text + the Business Name field's presence both depend on them), but
  // a full render() rebuilds #app-root from scratch, which would wipe
  // out whatever the customer has already drawn in the signature pad --
  // a real risk if they sign first and then go back and change their
  // selection. Captures the signature as a data URL first and redraws
  // it onto the freshly-rendered canvas, so nothing already signed is
  // ever lost by an earlier-field edit.
  function rerenderPreservingSignature() {
    var savedDataUrl = (sigCanvas && sigHasStroke) ? sigCanvas.toDataURL('image/png') : null;
    render();
    if (savedDataUrl && sigCanvas && sigCtx) {
      var ratio = window.devicePixelRatio || 1;
      var img = new Image();
      img.onload = function () {
        sigCtx.drawImage(img, 0, 0, sigCanvas.width / ratio, sigCanvas.height / ratio);
        sigHasStroke = true;
      };
      img.src = savedDataUrl;
    }
  }

  function startOver() {
    state.submitting = false;
    state.submitError = null;
    state.submitted = false;
    state.form = blankForm();
    sigHasStroke = false;
    render();
  }

  // ---- Render -----------------------------------------------------------

  function letterheadHtml() {
    return '<div class="letterhead"><div class="name">CodeBlue Technology</div><div class="sub">Walk-In Rate Sheet Sign Up</div></div>';
  }

  function renderForm() {
    var f = state.form;
    var isCommercial = f.account_kind === 'Commercial';
    var rate = RATE_BY_LOCATION[f.location];

    root.innerHTML = '' +
      '<div class="wrap">' +
      letterheadHtml() +

      (state.submitError ? '<div class="error-banner js-submit-error">' + e(state.submitError) + '</div>' : '') +

      '  <div class="card">' +
      (rate ? '    <div class="rate-highlight">$' + rate.toFixed(2) + ' <span>per hour — ' + e(f.location === 'Richmond' ? 'Richmond' : 'Northern Neck (Warsaw)') + (f.account_kind ? ' (' + e(f.account_kind) + ')' : '') + '</span></div>' : '') +
      '    <p style="font-size:13px;color:#5A6472;line-height:1.6;">Thank you for considering CodeBlue Technology for your business IT needs. Please choose your location and account type, then complete this form to get started.</p>' +
      '    <div class="field-label" style="margin-top:0;">Location</div>' +
      '    <select data-select="location">' +
      '      <option value="">Choose one…</option>' +
      '      <option value="Warsaw" ' + (f.location === 'Warsaw' ? 'selected' : '') + '>Northern Neck (Warsaw) — $173.25/hr</option>' +
      '      <option value="Richmond" ' + (f.location === 'Richmond' ? 'selected' : '') + '>Richmond — $180.00/hr</option>' +
      '    </select>' +
      '    <div class="field-label">Kind of Account</div>' +
      '    <div class="radio-row">' +
      '      <label class="radio-option"><input type="radio" name="account_kind" value="Commercial" ' + (f.account_kind === 'Commercial' ? 'checked' : '') + ' data-radio="account_kind" /> Commercial</label>' +
      '      <label class="radio-option"><input type="radio" name="account_kind" value="Residential" ' + (f.account_kind === 'Residential' ? 'checked' : '') + ' data-radio="account_kind" /> Residential</label>' +
      '    </div>' +
      '    <p style="font-size:12.5px;color:#33394A;line-height:1.6;margin-top:16px;"><strong>Onsite Service:</strong> 1-hour minimum, 30-minute increments thereafter.<br>' +
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
      '    <div class="field-label">Phone Number</div><input type="tel" data-field="phone" value="' + e(f.phone) + '" />' +
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
      '    <p style="font-size:12px;color:#5A6472;line-height:1.5;margin-top:-4px;">Just let us know your preference for now — our Invoicing team will follow up separately to securely add your card or bank details.</p>' +
      '    <div class="method-row">' +
      '      <label class="method-option"><input type="radio" name="payment_method" value="card" ' + (f.payment_method === 'card' ? 'checked' : '') + ' data-radio="payment_method" />' +
      '        <span>Credit Card<span class="method-note">Standard rate</span></span></label>' +
      '      <label class="method-option"><input type="radio" name="payment_method" value="ach" ' + (f.payment_method === 'ach' ? 'checked' : '') + ' data-radio="payment_method" />' +
      '        <span>ACH (Bank Transfer)<span class="method-note">Save 3% on transactions</span></span></label>' +
      '    </div>' +
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

      '  <button class="submit-btn" data-action="submit-form" data-idle-label="Submit" ' + (state.submitting ? 'disabled' : '') + '>' + (state.submitting ? 'Submitting…' : 'Submit') + '</button>' +
      '  <div class="footer-contact">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div>' +
      '</div>';

    setupSignaturePad();
  }

  function render() {
    if (state.submitted) {
      root.innerHTML = '<div class="wrap">' + letterheadHtml() +
        '<div class="card success-card"><div class="big">✅</div><h2>Thank You!</h2>' +
        '<p style="color:#5A6472;">This rate sheet sign up has been received. A CodeBlue Technology team member will follow up shortly to finish adding the payment method on file.</p>' +
        '<button type="button" class="submit-btn" data-action="start-over" style="margin-top:8px;">Start a New Sign Up</button></div>' +
        '<div class="footer-contact">CodeBlue Technology &nbsp;|&nbsp; (804) 521-7660 &nbsp;|&nbsp; Service@codebluetechnology.com</div></div>';
      return;
    }
    renderForm();
  }

  root.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.hasAttribute('data-field')) {
      state.form[el.getAttribute('data-field')] = el.value;
    }
  });
  root.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.hasAttribute('data-select')) {
      state.form[el.getAttribute('data-select')] = el.value;
      rerenderPreservingSignature(); // rate preview depends on Location
    } else if (el.hasAttribute('data-radio')) {
      state.form[el.getAttribute('data-radio')] = el.value;
      if (el.getAttribute('data-radio') === 'account_kind') rerenderPreservingSignature(); // Business Name field depends on this
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
    } else if (action === 'submit-form') {
      submitForm();
    } else if (action === 'start-over') {
      startOver();
    }
  });

  render();
})();
