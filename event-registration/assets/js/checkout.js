/* Event Registration — front-end checkout (vanilla JS) */
(function () {
	'use strict';

	document.querySelectorAll('[data-evr-config]').forEach(initForm);

	function initForm(root) {
		var cfg = JSON.parse(root.getAttribute('data-evr-config'));
		var state = {
			tier: '',
			tierLabel: '',
			membershipChecked: false,
			ticketKey: '',
			promo: '',
			stripe: null,
			elements: null,
			regId: 0,
			quoteTimer: null,
			payPlan: false
		};

		var mains = cfg.tickets.filter(function (t) { return t.type === 'main'; });
		var addons = cfg.tickets.filter(function (t) { return t.type === 'addon'; });
		var waitlist = cfg.waitlist && cfg.waitlist.active;

		// UTM tags from the visitor's link, persisted so they survive page
		// reloads and payment redirects within this browser session.
		var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
		var utm = (function () {
			var qs = new URLSearchParams(window.location.search);
			var found = {};
			var any = false;
			UTM_KEYS.forEach(function (k) {
				var v = qs.get(k);
				if (v) { found[k] = v; any = true; }
			});
			try {
				if (any) {
					sessionStorage.setItem('evr_utm', JSON.stringify(found));
					return found;
				}
				return JSON.parse(sessionStorage.getItem('evr_utm') || '{}');
			} catch (e) { return found; }
		})();

		/* ---------- Handle return from a redirect payment method ---------- */
		var params = new URLSearchParams(window.location.search);
		if (params.get('evr_reg') && params.get('payment_intent') && params.get('redirect_status') === 'succeeded') {
			post('evr_client_confirm', {
				registration_id: params.get('evr_reg'),
				payment_intent: params.get('payment_intent')
			}).then(function () {
				root.innerHTML = banner() + '<div class="evr-success">' + esc(cfg.success_message) + '</div>';
			});
			return;
		}

		/* ---------- Build the form ---------- */
		var html = banner();
		if (waitlist) {
			html += '<div class="evr-waitlist-notice">' + esc(cfg.waitlist.notice) + '</div>';
		}
		// Built-in field placeholders, with the historical defaults as fallback.
		var ph = cfg.placeholders || {};
		function phAttr(key, def) {
			var v = (ph[key] != null) ? ph[key] : def;
			return v ? ' placeholder="' + esc(v) + '"' : '';
		}

		html += '<form class="evr-reg-form" novalidate>';
		html += '<div class="evr-row"><label>First Name *<input type="text" name="first_name"' + phAttr('first_name', 'First Name') + ' required></label></div>';
		html += '<div class="evr-row"><label>Last Name *<input type="text" name="last_name"' + phAttr('last_name', 'Last Name') + ' required></label></div>';
		if (cfg.collect_phone) {
			html += '<div class="evr-row"><label>Phone *<input type="tel" name="phone"' + phAttr('phone', 'Phone') + ' required></label></div>';
		}
		html += '<div class="evr-row"><label>Email *<input type="email" name="email"' + phAttr('email', 'Email') + ' required></label>' +
			(cfg.email_note ? '<div class="evr-email-note">' + esc(cfg.email_note) + '</div>' : '') +
			'<div class="evr-membership" style="display:none"></div></div>';

		// Ticket choice: only rendered when there is more than one main ticket.
		if (!waitlist && mains.length > 1) {
			html += '<fieldset class="evr-row evr-full evr-tickets"><legend>Select your ticket</legend>';
			mains.forEach(function (t) {
				html += '<label class="evr-ticket-option' + (t.sold_out ? ' evr-soldout' : '') + '">' +
					'<input type="radio" name="evr_ticket" value="' + esc(t.key) + '"' + (t.sold_out ? ' disabled' : '') + '>' +
					'<span>' + esc(t.label) + '</span><span class="evr-ticket-price">' + priceLabel(t) + '</span>' +
					(t.sold_out ? '<span class="evr-soldout-tag">Sold out</span>' : '') +
					'</label>';
			});
			html += '</fieldset>';
		}

		if (!waitlist && addons.length) {
			html += '<fieldset class="evr-row evr-full evr-addons" style="display:none"><legend>Add-ons</legend>';
			addons.forEach(function (t) {
				html += '<label class="evr-ticket-option' + (t.sold_out ? ' evr-soldout' : '') + '">' +
					'<input type="checkbox" name="evr_addon" value="' + esc(t.key) + '"' + (t.sold_out ? ' disabled' : '') + '>' +
					'<span>' + esc(t.label) + '</span><span class="evr-ticket-price">' + priceLabel(t) + '</span>' +
					(t.sold_out ? '<span class="evr-soldout-tag">Sold out</span>' : '') +
					'</label>';
			});
			html += '</fieldset>';
		}

		cfg.fields.forEach(function (f) {
			var full = (f.type === 'textarea' || f.type === 'checkbox') ? ' evr-full' : '';
			var fph = f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '';
			html += '<div class="evr-row' + full + '" data-field-key="' + esc(f.key) + '"><label>' + esc(f.label) + (f.required ? ' *' : '');
			if (f.type === 'textarea') {
				html += '<textarea name="evr_field_' + esc(f.key) + '"' + fph + (f.required ? ' required' : '') + '></textarea>';
			} else if (f.type === 'select') {
				html += '<select name="evr_field_' + esc(f.key) + '"' + (f.required ? ' required' : '') + '><option value="">' + esc(f.placeholder || '— select —') + '</option>';
				f.options.forEach(function (o) { html += '<option>' + esc(o) + '</option>'; });
				html += '</select>';
			} else if (f.type === 'checkbox') {
				html += '<input type="checkbox" name="evr_field_' + esc(f.key) + '" value="Yes">';
			} else {
				html += '<input type="' + (f.type === 'date' ? 'date' : 'text') + '" name="evr_field_' + esc(f.key) + '"' + fph + (f.required ? ' required' : '') + '>';
			}
			html += '</label></div>';
		});

		if (!waitlist && cfg.has_promos) {
			html += '<div class="evr-row evr-full evr-promo"><label>Promo code<input type="text" name="evr_promo"></label>' +
				'<button type="button" class="evr-apply-promo">Apply</button></div>';
		}

		if (cfg.consent_text) {
			html += '<div class="evr-row evr-full evr-consent"><label>' +
				'<input type="checkbox" name="evr_consent" value="1" required>' +
				'<span>' + esc(cfg.consent_text) + '</span></label></div>';
		}

		html += '<div class="evr-summary evr-full" style="display:none"></div>';
		html += '<div class="evr-plan-choice evr-full" style="display:none"></div>';
		html += '<div class="evr-error evr-full" style="display:none"></div>';
		html += '<div class="evr-payment-element evr-full"></div>';
		html += '<button type="submit" class="evr-submit evr-full">' + (waitlist ? 'Join waitlist' : 'Register') + '</button>';
		html += '</form>';
		root.innerHTML = html;

		var form = root.querySelector('form');
		balanceRows(form);
		setupConditions();
		setupSelectPlaceholders();
		var summaryEl = root.querySelector('.evr-summary');
		var planChoiceEl = root.querySelector('.evr-plan-choice');
		var errorEl = root.querySelector('.evr-error');
		var submitBtn = root.querySelector('.evr-submit');

		// Single main ticket: auto-select it (simple registration fee — no
		// ticket UI shown at all).
		if (!waitlist && mains.length === 1) {
			state.ticketKey = mains[0].key;
			if (addons.length) { showAddons(); }
			requestQuote();
		}

		/* ---------- Events ---------- */

		form.querySelector('[name=email]').addEventListener('blur', function () {
			var email = this.value.trim();
			if (!email || email.indexOf('@') < 0) { return; }
			post('evr_check_membership', { email: email }).then(function (r) {
				state.tier = r.success ? r.data.tier : '';
				state.tierLabel = r.success ? r.data.label : '';
				state.membershipChecked = true;
				renderMembership();
				requestQuote();
			});
		});

		form.querySelectorAll('[name=evr_ticket]').forEach(function (radio) {
			radio.addEventListener('change', function () {
				state.ticketKey = this.value;
				showAddons();
				requestQuote();
			});
		});

		form.querySelectorAll('[name=evr_addon]').forEach(function (cb) {
			cb.addEventListener('change', requestQuote);
		});

		var applyBtn = root.querySelector('.evr-apply-promo');
		if (applyBtn) {
			applyBtn.addEventListener('click', function () {
				state.promo = form.querySelector('[name=evr_promo]').value.trim();
				requestQuote();
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			hideError();
			if (waitlist) { joinWaitlist(); return; }
			if (state.elements) { payNow(); return; }
			startCheckout();
		});

		/* ---------- Helpers ---------- */

		// The form is a 2-column grid; rows with .evr-full span both columns.
		// Walk the grid in order (full rows always start a fresh row, so they
		// reset the column cursor) and make any single-column field that ends
		// up alone on its line span full width too — no half-empty rows.
		function balanceRows(grid) {
			var col = 0;
			var prev = null;
			Array.prototype.forEach.call(grid.children, function (el) {
				if (el.style.display === 'none') { return; } // not in the grid flow
				if (el.classList.contains('evr-full')) {
					if (col === 1 && prev) { prev.classList.add('evr-full'); }
					col = 0;
					prev = null;
					return;
				}
				if (col === 0) { col = 1; prev = el; }
				else { col = 0; prev = null; }
			});
			if (col === 1 && prev) { prev.classList.add('evr-full'); }
		}

		function showAddons() {
			var fs = root.querySelector('.evr-addons');
			if (fs) { fs.style.display = state.ticketKey ? '' : 'none'; }
		}

		/* ---------- Conditional fields ("Show when") ---------- */

		function setupConditions() {
			var hasConds = (cfg.fields || []).some(function (f) { return f.conditions && f.conditions.length; });
			if (!hasConds) { return; } // nothing conditional — leave everything visible
			// Any custom field can be a controller; re-evaluate on any change.
			form.querySelectorAll('[name^="evr_field_"]').forEach(function (el) {
				el.addEventListener('change', evaluateConditions);
				el.addEventListener('input', evaluateConditions);
			});
			evaluateConditions();
		}

		// Grey out a dropdown while its empty "placeholder" option is showing,
		// matching the muted look of text-field placeholders.
		function setupSelectPlaceholders() {
			form.querySelectorAll('select[name^="evr_field_"]').forEach(function (sel) {
				var sync = function () { sel.classList.toggle('evr-select-placeholder', sel.value === ''); };
				sel.addEventListener('change', sync);
				sync();
			});
		}

		function customFieldValue(f) {
			var input = form.querySelector('[name="evr_field_' + f.key + '"]');
			if (!input) { return ''; }
			if (f.type === 'checkbox') { return input.checked ? 'Yes' : ''; }
			return input.value || '';
		}

		function conditionMet(c, val) {
			var cv = c.value != null ? String(c.value) : '';
			if (c.operator === 'not_equals') { return val !== cv; }
			if (c.operator === 'one_of') {
				return cv.split(',').map(function (s) { return s.trim(); })
					.filter(function (s) { return s.length; }).indexOf(val) >= 0;
			}
			if (c.operator === 'not_empty') { return String(val).trim() !== ''; }
			return val === cv; // equals (default)
		}

		// Resolve visibility for every field, repeating until stable so that
		// chained conditions (a field controlled by another conditional field)
		// settle correctly. Mirrors EVR_DB::evaluate_field_visibility on the server.
		function evaluateConditions() {
			var fields = cfg.fields || [];
			var byKey = {};
			fields.forEach(function (f) { byKey[f.key] = f; });
			var visible = {};
			fields.forEach(function (f) { visible[f.key] = true; });

			for (var pass = 0; pass <= fields.length; pass++) {
				var changed = false;
				fields.forEach(function (f) {
					var v = true;
					var conds = f.conditions || [];
					if (conds.length) {
						var results = conds.map(function (c) {
							var ctrl = byKey[c.field];
							var ctrlVisible = !ctrl || visible[c.field] !== false;
							var val = (ctrl && ctrlVisible) ? customFieldValue(ctrl) : '';
							return conditionMet(c, val);
						});
						v = (f.cond_logic === 'or')
							? results.some(Boolean)
							: results.every(Boolean);
					}
					if (visible[f.key] !== v) { visible[f.key] = v; changed = true; }
				});
				if (!changed) { break; }
			}

			fields.forEach(function (f) {
				var row = form.querySelector('.evr-row[data-field-key="' + f.key + '"]');
				if (!row) { return; }
				var show = visible[f.key] !== false;
				row.style.display = show ? '' : 'none';
				// Disable hidden inputs so they're skipped by validation + submission.
				row.querySelectorAll('input, select, textarea').forEach(function (el) {
					el.disabled = !show;
				});
			});
		}

		// Membership tag under the email field. Shows the verified tier when
		// recognized, otherwise a "Not a member" prompt with a per-event
		// sign-up link. The wording only mentions ticket savings once
		// early-bird has ended, since members aren't discounted further during it.
		function tierDisplay(tier) {
			if (!tier) { return ''; }
			if (tier.toLowerCase() === 'vip') { return 'VIP'; }
			return tier.charAt(0).toUpperCase() + tier.slice(1).toLowerCase();
		}

		function renderMembership() {
			var el = root.querySelector('.evr-membership');
			if (!el) { return; }
			if (!state.membershipChecked) { el.style.display = 'none'; return; }
			el.className = 'evr-membership';

			if (state.tier) {
				el.innerHTML = '<span class="evr-tier-badge evr-tier-verified">' +
					esc(tierDisplay(state.tier)) + ' Member Verified</span>';
				el.style.display = '';
				return;
			}

			var html = '<span class="evr-tier-badge evr-tier-none">Not a member</span>';
			var url = cfg.member_signup_url || '';
			if (url) {
				var safeUrl = esc(url);
				var cta = cfg.early_bird_active
					? '<a class="evr-member-link" href="' + safeUrl + '" target="_blank" rel="noopener">View member benefits here &rarr;</a>'
					: 'You could get this ticket cheaper if you were a member. ' +
					  '<a class="evr-member-link" href="' + safeUrl + '" target="_blank" rel="noopener">Click here to learn more &rarr;</a>';
				html += '<div class="evr-member-cta">' + cta + '</div>';
			}
			el.innerHTML = html;
			el.style.display = '';
		}

		function selectedAddons() {
			return Array.prototype.map.call(
				form.querySelectorAll('[name=evr_addon]:checked'),
				function (cb) { return cb.value; }
			);
		}

		function registrationData() {
			var data = {
				event_id: cfg.event_id,
				email: form.querySelector('[name=email]').value.trim(),
				first_name: form.querySelector('[name=first_name]').value.trim(),
				last_name: form.querySelector('[name=last_name]').value.trim(),
				phone: cfg.collect_phone ? form.querySelector('[name=phone]').value.trim() : '',
				ticket_key: state.ticketKey,
				promo_code: state.promo,
				tier: state.tier,
				consent: (function () {
					var cb = form.querySelector('[name=evr_consent]');
					return cb ? (cb.checked ? '1' : '') : '1';
				})(),
				pay_plan: state.payPlan ? '1' : '0'
			};
			selectedAddons().forEach(function (key, i) {
				data['addon_keys[' + i + ']'] = key;
			});
			UTM_KEYS.forEach(function (k) {
				if (utm[k]) { data['utm[' + k + ']'] = utm[k]; }
			});
			cfg.fields.forEach(function (f) {
				var input = form.querySelector('[name=evr_field_' + f.key + ']');
				if (!input) { return; }
				// Hidden-by-conditions fields are disabled — submit them empty.
				if (input.disabled) { data['fields[' + f.key + ']'] = ''; return; }
				data['fields[' + f.key + ']'] = (input.type === 'checkbox') ? (input.checked ? 'Yes' : '') : input.value;
			});
			return data;
		}

		function requestQuote() {
			if (!state.ticketKey) { return; }
			clearTimeout(state.quoteTimer);
			state.quoteTimer = setTimeout(function () {
				post('evr_quote', {
					event_id: cfg.event_id,
					ticket_key: state.ticketKey,
					promo_code: state.promo,
					tier: state.tier,
					addon_keys: null // replaced below
				}, selectedAddons()).then(function (r) {
					if (!r.success) { showError(r.data.message); return; }
					hideError();
					renderSummary(r.data);
					renderPlanChoice(r.data.total_cents, r.data.currency);
				});
			}, 200);
		}

		function renderSummary(quote) {
			var html = '<table class="evr-summary-table">';
			quote.lines.forEach(function (line) {
				html += '<tr><td>' + esc(line.label) + '</td><td>' + money(line.cents, quote.currency) + '</td></tr>';
			});
			html += '<tr class="evr-total"><td>' + esc(quote.total_label || 'Total') + '</td><td>' + money(quote.total_cents, quote.currency) + '</td></tr></table>';
			summaryEl.innerHTML = html;
			summaryEl.style.display = '';
			if (!state.elements) {
				submitBtn.textContent = quote.total_cents > 0 ? 'Register' : 'Complete registration';
			}
		}

		/* ---------- Payment plan (installments) ---------- */

		var planCfg = cfg.payment_plan || { enabled: false };

		// Add N months or days to a date (months use JS date arithmetic).
		function addInterval(date, n, unit) {
			var d = new Date(date.getTime());
			if (unit === 'day') { d.setDate(d.getDate() + n); }
			else { d.setMonth(d.getMonth() + n); }
			return d;
		}

		// Client-side mirror of EVR_Installments::build_schedule — equal split,
		// remainder on the first payment, one payment due per interval from now.
		function planSchedule(totalCents) {
			var count = Math.max(2, planCfg.count || 2);
			var base = Math.floor(totalCents / count);
			var first = base + (totalCents - base * count);
			var every = Math.max(1, planCfg.interval_count || 1);
			var unit = planCfg.interval_unit === 'day' ? 'day' : 'month';
			var now = new Date();
			var out = [];
			for (var i = 0; i < count; i++) {
				out.push({ amount_cents: i === 0 ? first : base, due: addInterval(now, every * i, unit) });
			}
			return out;
		}

		// Mirror of EVR_Installments::plan_available (server is authoritative).
		function planAvailable(totalCents) {
			if (!planCfg.enabled || totalCents <= 0) { return false; }
			if (totalCents < (planCfg.min_total_cents || 0)) { return false; }
			if (planCfg.final_due) {
				var sched = planSchedule(totalCents);
				var last = sched[sched.length - 1].due;
				var finalDue = new Date(planCfg.final_due);
				if (!isNaN(finalDue.getTime()) && last.getTime() > finalDue.getTime()) { return false; }
			}
			return true;
		}

		function fmtDate(d) {
			return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
		}

		// Render the "pay in full / installments" choice as two selectable cards
		// (matching the payment-method tabs). Skipped entirely (and the plan
		// de-selected) whenever the plan isn't available for the current total.
		// Locked once payment starts.
		function renderPlanChoice(totalCents, currency) {
			if (state.elements) { return; } // Payment already started — choice is locked.
			if (!planAvailable(totalCents)) {
				planChoiceEl.style.display = 'none';
				planChoiceEl.innerHTML = '';
				state.payPlan = false;
				return;
			}
			var sched = planSchedule(totalCents);
			var recur = sched.length - 1;
			var label = planCfg.label || 'Split into payments';
			var note = 'Today, then ' + recur + ' more of ' + money(sched[1].amount_cents, currency);

			function card(v, name, price, cnote) {
				var on = state.payPlan === (v === 'plan');
				return '<div class="evr-plan-card' + (on ? ' evr-selected' : '') + '" data-plan="' + v + '"' +
					' role="radio" tabindex="0" aria-checked="' + (on ? 'true' : 'false') + '">' +
					'<div class="evr-plan-card-top"><span class="evr-plan-card-name">' + name + '</span>' +
					'<span class="evr-plan-check"></span></div>' +
					'<div class="evr-plan-card-price">' + price + '</div>' +
					'<div class="evr-plan-card-note">' + cnote + '</div></div>';
			}

			var html = '<div class="evr-plan-title">Payment options</div><div class="evr-plan-cards">';
			html += card('full', 'Pay in full', money(totalCents, currency), 'One payment today');
			html += card('plan', esc(label), money(sched[0].amount_cents, currency), note);
			html += '</div>';

			html += '<div class="evr-plan-schedule"' + (state.payPlan ? '' : ' style="display:none"') + '>';
			sched.forEach(function (inst, i) {
				html += '<div class="evr-plan-row"><span>Payment ' + (i + 1) + (i === 0 ? ' (today)' : '') +
					'</span><span>' + money(inst.amount_cents, currency) + '</span><span>due ' + esc(fmtDate(inst.due)) + '</span></div>';
			});
			html += '</div>';

			planChoiceEl.innerHTML = html;
			planChoiceEl.style.display = '';

			function select(v) {
				if (state.elements) { return; } // Locked once payment has started.
				state.payPlan = (v === 'plan');
				planChoiceEl.querySelectorAll('.evr-plan-card').forEach(function (el) {
					var on = el.getAttribute('data-plan') === v;
					el.classList.toggle('evr-selected', on);
					el.setAttribute('aria-checked', on ? 'true' : 'false');
				});
				var sch = planChoiceEl.querySelector('.evr-plan-schedule');
				if (sch) { sch.style.display = state.payPlan ? '' : 'none'; }
			}
			planChoiceEl.querySelectorAll('.evr-plan-card').forEach(function (el) {
				el.addEventListener('click', function () { select(el.getAttribute('data-plan')); });
				el.addEventListener('keydown', function (e) {
					if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); select(el.getAttribute('data-plan')); }
				});
			});
		}

		// Once a payment plan is accepted server-side, replace the choice with a
		// locked confirmation of what happens (first payment now, rest scheduled).
		function renderPlanConfirmation(data) {
			var currency = data.currency;
			var insts = data.installments || [];
			var html = '<div class="evr-plan-title">Payment plan</div>';
			html += '<div class="evr-plan-note">You\'ll be charged ' + money(data.first_amount_cents, currency) +
				' today. The remaining ' + (insts.length - 1) + ' payment' + (insts.length - 1 === 1 ? '' : 's') +
				' will be charged automatically to this card:</div>';
			html += '<div class="evr-plan-schedule">';
			insts.forEach(function (inst, i) {
				var due = new Date((inst.due_date || '').replace(' ', 'T'));
				html += '<div class="evr-plan-row"><span>Payment ' + (i + 1) + (i === 0 ? ' (today)' : '') +
					'</span><span>' + money(inst.amount_cents, currency) + '</span><span>' +
					(isNaN(due.getTime()) ? '' : 'due ' + esc(fmtDate(due))) + '</span></div>';
			});
			html += '</div>';
			planChoiceEl.innerHTML = html;
			planChoiceEl.style.display = '';
		}

		function joinWaitlist() {
			if (!form.checkValidity()) { form.reportValidity(); return; }
			busy(true);
			post('evr_join_waitlist', registrationData()).then(function (r) {
				busy(false);
				if (!r.success) { showError(r.data.message); return; }
				root.innerHTML = banner() + '<div class="evr-success">' + esc(r.data.message) + '</div>';
				root.scrollIntoView({ behavior: 'smooth', block: 'center' });
			});
		}

		function startCheckout() {
			if (!state.ticketKey) { showError('Please select a ticket.'); return; }
			if (!form.checkValidity()) { form.reportValidity(); return; }
			busy(true);
			// Create the pending registration + price it. No Stripe PaymentIntent
			// is created yet — that's deferred until the visitor actually submits
			// payment (payNow), so an abandoned form-fill never produces an
			// Incomplete transaction in Stripe.
			post('evr_prepare_checkout', registrationData(), selectedAddons()).then(function (r) {
				if (!r.success) { busy(false); showError(r.data.message); return; }
				if (r.data.free) {
					post('evr_register_free', registrationData(), selectedAddons()).then(function (r2) {
						busy(false);
						if (!r2.success) { showError(r2.data.message); return; }
						success();
					});
					return;
				}
				state.regId = r.data.registration_id;
				renderSummary(r.data.quote);
				// r.data.amount_cents is the first installment when the plan was
				// accepted server-side, otherwise the full total.
				if (r.data.pay_plan) { renderPlanConfirmation(r.data); }
				mountPaymentElement(r.data.amount_cents, r.data.currency, !!r.data.pay_plan);
				busy(false);
			});
		}

		// Deferred mode: Elements is initialized with just the amount/currency,
		// so no PaymentIntent exists until payNow() creates one at submit time.
		// When a plan is active, tell Stripe the card will be reused off-session
		// so it shows the correct mandate text and saves the card.
		function mountPaymentElement(amountCents, currency, savePlan) {
			state.stripe = Stripe(cfg.publishable_key);
			// Match the Stripe Element's field labels to the form's own label
			// color (e.g. white on a dark page) so they're legible on any bg.
			var labelColor = getComputedStyle(root).color || '#ffffff';
			var opts = {
				mode: 'payment',
				amount: amountCents,
				currency: (currency || 'usd').toLowerCase(),
				appearance: { rules: { '.Label': { color: labelColor } } }
			};
			if (savePlan) { opts.setupFutureUsage = 'off_session'; }
			state.elements = state.stripe.elements(opts);
			state.elements.create('payment').mount(root.querySelector('.evr-payment-element'));
			submitBtn.textContent = savePlan ? 'Pay first installment' : 'Pay now';
			lockForm(true);
		}

		function payNow() {
			busy(true);
			// Validate the payment details before creating anything in Stripe.
			state.elements.submit().then(function (sub) {
				if (sub.error) { busy(false); showError(sub.error.message); return; }
				// Now — and only now, for a real payment attempt — create the
				// Stripe PaymentIntent for the already-pending registration.
				post('evr_create_intent', { registration_id: state.regId }).then(function (r) {
					if (!r.success) { busy(false); showError(r.data.message); return; }
					var returnUrl = new URL(window.location.href);
					returnUrl.searchParams.set('evr_reg', state.regId);
					state.stripe.confirmPayment({
						elements: state.elements,
						clientSecret: r.data.client_secret,
						confirmParams: { return_url: returnUrl.toString() },
						redirect: 'if_required'
					}).then(function (result) {
						if (result.error) {
							busy(false);
							showError(result.error.message);
							return;
						}
						post('evr_client_confirm', {
							registration_id: state.regId,
							payment_intent: result.paymentIntent.id
						}).then(function () {
							busy(false);
							success();
						});
					});
				});
			});
		}

		function success() {
			root.innerHTML = banner() + '<div class="evr-success">' + esc(cfg.success_message) + '</div>';
			root.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		function lockForm(locked) {
			form.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(function (el) {
				if (!el.closest('.evr-payment-element')) { el.disabled = locked; }
			});
		}

		function busy(on) {
			submitBtn.disabled = on;
			submitBtn.classList.toggle('evr-busy', on);
		}

		function showError(msg) {
			errorEl.textContent = msg || 'Something went wrong. Please try again.';
			errorEl.style.display = '';
		}

		function hideError() {
			errorEl.style.display = 'none';
		}

		function banner() {
			return cfg.test_mode ? '<div class="evr-test-banner">Test mode — no real charges will be made.</div>' : '';
		}

		function priceLabel(t) {
			var label = money(t.current_price_cents, cfg.currency);
			if (t.current_price_cents < t.price_cents) {
				label += ' <s>' + money(t.price_cents, cfg.currency) + '</s>';
			}
			return label;
		}

		function money(cents, currency) {
			var amount = (cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
			var symbols = { usd: '$', cad: '$', aud: '$', eur: '€', gbp: '£' };
			var sign = cents < 0 ? '-' : '';
			return sign + (symbols[(currency || 'usd').toLowerCase()] || '') + amount.replace('-', '');
		}

		function post(action, data, addonKeys) {
			var body = new URLSearchParams();
			body.set('action', action);
			body.set('nonce', cfg.nonce);
			Object.keys(data || {}).forEach(function (k) {
				if (data[k] !== null && data[k] !== undefined) { body.set(k, data[k]); }
			});
			(addonKeys || []).forEach(function (key, i) {
				body.set('addon_keys[' + i + ']', key);
			});
			return fetch(cfg.ajax_url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).then(function (r) { return r.json(); });
		}

		function esc(s) {
			var div = document.createElement('div');
			div.textContent = s == null ? '' : String(s);
			return div.innerHTML;
		}
	}
})();
