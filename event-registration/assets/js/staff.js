/* Event Registration — staff form (add a registrant without taking payment).
 *
 * The shell is rendered by the shortcode; this fills in the parts that depend
 * on which event was chosen (tickets, add-ons, registration questions) and
 * posts the result. The price shown here is an on-screen estimate from list
 * prices — the amount recorded is always computed on the server, priced
 * exactly as a public registration would be.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-evr-staff]');
	if (!root) { return; }

	var cfg;
	try {
		cfg = JSON.parse(root.getAttribute('data-evr-staff'));
	} catch (e) {
		return;
	}

	var form = root.querySelector('.evr-staff-form');
	if (!form) { return; }

	var els = {
		event: form.querySelector('.evr-staff-event'),
		loading: form.querySelector('.evr-staff-loading'),
		details: form.querySelector('.evr-staff-details'),
		person: form.querySelector('.evr-staff-person'),
		custom: form.querySelector('.evr-staff-custom'),
		payment: form.querySelector('.evr-staff-payment'),
		meta: form.querySelector('.evr-staff-meta'),
		estimate: form.querySelector('.evr-staff-estimate'),
		actions: form.querySelector('.evr-staff-actions'),
		submit: form.querySelector('.evr-staff-submit'),
		message: form.querySelector('.evr-staff-message')
	};

	var current = null; // Config of the event currently selected.

	function post(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce);
		body.set('token', cfg.token);
		Object.keys(data).forEach(function (key) {
			var value = data[key];
			if (Array.isArray(value)) {
				value.forEach(function (v) { body.append(key + '[]', v); });
			} else if (value !== null && typeof value === 'object') {
				Object.keys(value).forEach(function (k) { body.append(key + '[' + k + ']', value[k]); });
			} else if (value !== undefined && value !== null) {
				body.set(key, value);
			}
		});
		return fetch(cfg.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function money(cents, currency) {
		return (currency || 'USD') + ' ' + (cents / 100).toFixed(2);
	}

	function say(text, kind) {
		els.message.textContent = text || '';
		els.message.className = 'evr-staff-message' + (kind ? ' evr-staff-' + kind : '');
	}

	function show(el, on) {
		if (el) { el.hidden = !on; }
	}

	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	/* ---------- Event-specific part of the form ---------- */

	function labelFor(ticket) {
		var label = ticket.label + ' — ' + money(ticket.current_price_cents, current.currency);
		if (ticket.sold_out) { label += ' (sold out)'; }
		if (ticket.inactive) { label += ' (inactive)'; }
		return label;
	}

	function buildDetails(data) {
		els.details.innerHTML = '';
		els.custom.innerHTML = '';
		current = data;

		if (data.test_mode) {
			els.details.appendChild(el('div', { class: 'evr-staff-test' }, 'This event runs in Stripe test mode — registrations are flagged as test data.'));
		}

		var mains = data.tickets.filter(function (t) { return t.type === 'main'; });
		var addons = data.tickets.filter(function (t) { return t.type === 'addon'; });

		if (mains.length) {
			var wrap = el('label', { class: 'evr-staff-field' });
			wrap.appendChild(el('span', {}, 'Ticket'));
			var select = el('select', { name: 'ticket_key', class: 'evr-staff-ticket' });
			select.appendChild(el('option', { value: '' }, '— no ticket —'));
			mains.forEach(function (t) {
				select.appendChild(el('option', { value: t.key, 'data-cents': t.current_price_cents }, labelFor(t)));
			});
			// Pre-select when there is only one choice to make.
			if (mains.length === 1) { select.value = mains[0].key; }
			wrap.appendChild(select);
			els.details.appendChild(wrap);
		}

		if (addons.length) {
			var box = el('div', { class: 'evr-staff-field' });
			box.appendChild(el('span', {}, 'Add-ons'));
			addons.forEach(function (a) {
				var row = el('label', { class: 'evr-staff-check' });
				row.appendChild(el('input', { type: 'checkbox', class: 'evr-staff-addon', value: a.key, 'data-cents': a.current_price_cents }));
				row.appendChild(document.createTextNode(' ' + labelFor(a)));
				box.appendChild(row);
			});
			els.details.appendChild(box);
		}

		// The event's own form questions, shown under the registrant's details.
		if (data.fields.length) {
			var fieldset = el('fieldset', { class: 'evr-staff-group' });
			fieldset.appendChild(el('legend', {}, 'Registration questions'));
			data.fields.forEach(function (f) {
				var wrap = el('label', { class: 'evr-staff-field' });
				wrap.appendChild(el('span', {}, f.label));
				var input;
				if (f.type === 'select' && f.options.length) {
					input = el('select', { 'data-field': f.key });
					input.appendChild(el('option', { value: '' }, '—'));
					f.options.forEach(function (o) { input.appendChild(el('option', { value: o }, o)); });
				} else if (f.type === 'textarea') {
					input = el('textarea', { 'data-field': f.key, rows: '2' });
				} else if (f.type === 'checkbox') {
					input = el('input', { type: 'checkbox', 'data-field': f.key, value: 'Yes' });
				} else {
					input = el('input', { type: f.type === 'date' ? 'date' : 'text', 'data-field': f.key });
				}
				wrap.appendChild(input);
				fieldset.appendChild(wrap);
			});
			fieldset.appendChild(el('p', { class: 'evr-staff-hint' }, 'These feed the event’s GoHighLevel field mappings. None are required here.'));
			els.custom.appendChild(fieldset);
		}

		show(els.details, true);
		show(els.person, true);
		show(els.custom, data.fields.length > 0);
		show(els.payment, true);
		show(els.meta, true);
		show(els.actions, true);
		updateEstimate();
	}

	function selectedTicketCents() {
		var select = form.querySelector('.evr-staff-ticket');
		if (!select || !select.value) { return 0; }
		return parseInt(select.options[select.selectedIndex].getAttribute('data-cents'), 10) || 0;
	}

	function updateEstimate() {
		if (!current) { return; }
		var state = form.querySelector('input[name="payment_state"]:checked');

		if (state && state.value === 'comp') {
			els.estimate.textContent = 'Recorded as ' + money(0, current.currency) + ' (comped).';
			show(els.estimate, true);
			return;
		}

		var cents = selectedTicketCents();
		form.querySelectorAll('.evr-staff-addon:checked').forEach(function (cb) {
			cents += parseInt(cb.getAttribute('data-cents'), 10) || 0;
		});
		els.estimate.textContent = 'About ' + money(cents, current.currency) + ' at list price — the exact figure is worked out on submit, after their membership discount.';
		show(els.estimate, true);
	}

	function resetEvent() {
		current = null;
		els.details.innerHTML = '';
		els.custom.innerHTML = '';
		[els.details, els.person, els.custom, els.payment, els.meta, els.actions, els.estimate].forEach(function (node) { show(node, false); });
	}

	els.event.addEventListener('change', function () {
		say('');
		resetEvent();
		if (!els.event.value) { return; }
		show(els.loading, true);
		post('evr_staff_config', { event_id: els.event.value }).then(function (r) {
			show(els.loading, false);
			if (!r || !r.success) {
				say((r && r.data && r.data.message) || 'Could not load that event.', 'error');
				return;
			}
			buildDetails(r.data);
		}).catch(function () {
			show(els.loading, false);
			say('Could not load that event. Please try again.', 'error');
		});
	});

	form.addEventListener('change', function (e) {
		if (e.target.matches('.evr-staff-ticket, .evr-staff-addon, input[name="payment_state"]')) {
			updateEstimate();
		}
	});

	/* ---------- Submit ---------- */

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		say('');

		var data = {
			event_id: els.event.value,
			first_name: form.querySelector('input[name="first_name"]').value.trim(),
			last_name: form.querySelector('input[name="last_name"]').value.trim(),
			email: form.querySelector('input[name="email"]').value.trim(),
			phone: form.querySelector('input[name="phone"]').value.trim(),
			added_by: form.querySelector('input[name="added_by"]').value.trim(),
			note: form.querySelector('input[name="note"]').value.trim(),
			payment_state: (form.querySelector('input[name="payment_state"]:checked') || {}).value || 'invoiced'
		};

		if (!data.first_name || !data.last_name || !data.email) {
			say('Please fill in the registrant’s name and email address.', 'error');
			return;
		}
		if (!data.added_by) {
			say('Please put your name in, so we know who added this registration.', 'error');
			return;
		}

		var ticket = form.querySelector('.evr-staff-ticket');
		data.ticket_key = ticket ? ticket.value : '';

		data.addon_keys = Array.prototype.map.call(
			form.querySelectorAll('.evr-staff-addon:checked'),
			function (cb) { return cb.value; }
		);

		var fields = {};
		form.querySelectorAll('[data-field]').forEach(function (input) {
			if (input.type === 'checkbox') {
				if (input.checked) { fields[input.getAttribute('data-field')] = input.value; }
			} else if (input.value !== '') {
				fields[input.getAttribute('data-field')] = input.value;
			}
		});
		data.fields = fields;

		els.submit.disabled = true;
		els.submit.textContent = 'Adding…';

		post('evr_staff_register', data).then(function (r) {
			els.submit.disabled = false;
			els.submit.textContent = 'Add registration';
			if (!r || !r.success) {
				say((r && r.data && r.data.message) || 'Something went wrong. Please try again.', 'error');
				return;
			}
			var d = r.data;
			var parts = [d.name + ' is registered for ' + d.event + ' — ' + d.amount + ', ' + d.payment_state.toLowerCase() + '.'];
			if (d.tier) { parts.push('Membership tier recorded: ' + d.tier + '.'); }
			if (d.ghl === 'failed') { parts.push('Note: the GoHighLevel sync failed (' + d.ghl_error + '). The registration is saved — it can be retried from the admin.'); }
			say(parts.join(' '), d.ghl === 'failed' ? 'warn' : 'success');

			// Clear the person but keep the event, so a run of registrations
			// for the same event is quick to enter.
			['first_name', 'last_name', 'email', 'phone', 'note'].forEach(function (name) {
				var input = form.querySelector('[name="' + name + '"]');
				if (input) { input.value = ''; }
			});
			form.querySelectorAll('[data-field]').forEach(function (input) {
				if (input.type === 'checkbox') { input.checked = false; } else { input.value = ''; }
			});
			updateEstimate();
			form.querySelector('input[name="first_name"]').focus();
		}).catch(function () {
			els.submit.disabled = false;
			els.submit.textContent = 'Add registration';
			say('The request failed. Please check your connection and try again.', 'error');
		});
	});
})();
