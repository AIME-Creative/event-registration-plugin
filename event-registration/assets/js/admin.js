/* Event Registration — event editor */
(function ($) {
	'use strict';

	// Initialize WP color pickers on any EVR admin page (settings + event editor).
	$(function () {
		if ($.fn.wpColorPicker) {
			$('.evr-color').wpColorPicker();
		}
	});

	if (typeof window.EVR_EVENT_CONFIG === 'undefined') {
		// Not on the event editor; wire the registrations-page buttons only.
		$(document).on('click', '.evr-retry-ghl', retryGhl);
		$(document).on('click', '.evr-portal-link', portalLink);
		$(document).on('click', '.evr-send-payoff', sendPayoff);
		$(document).on('click', '.evr-cancel-inst', cancelInstallment);
		$(document).on('click', '.evr-cancel-remaining', cancelRemaining);
		$(document).on('click', '.evr-mark-paid', markManualPaid);
		wireManualKey();
		return;
	}

	var cfg = window.EVR_EVENT_CONFIG;
	var stripeProducts = { test: [], live: [] };

	function uid(prefix) {
		return prefix + Math.random().toString(36).slice(2, 10);
	}

	function dollars(cents) {
		return cents ? (cents / 100).toFixed(2) : '';
	}

	/* ---------- Tickets ---------- */

	function productSelect(selected, mode) {
		var $sel = $('<select class="evr-t-product"><option value="">— product —</option></select>').attr('data-mode', mode);
		stripeProducts[mode].forEach(function (p) {
			var label = p.name + ' ($' + (p.price_cents / 100).toFixed(2) + ')';
			$sel.append($('<option>').val(p.id).text(label).attr('data-cents', p.price_cents));
		});
		if (selected && !$sel.find('option[value="' + selected + '"]').length) {
			$sel.append($('<option>').val(selected).text(selected + ' (saved)'));
		}
		$sel.val(selected || '');
		return $sel;
	}

	function addTicketRow(t) {
		t = t || {};
		var $tr = $('<tr class="evr-ticket-row">').data('key', t.key || uid('tk_'));
		$tr.append($('<td>').append(
			$('<select class="evr-t-type"><option value="main">Main ticket</option><option value="addon">Add-on</option></select>').val(t.type || 'main')
		));
		$tr.append($('<td>').append($('<input type="text" class="evr-t-label" placeholder="e.g. General Admission">').val(t.label || '')));
		// Legacy tickets had one product ID, picked under the event's mode
		// at the time — seed it into that mode's slot.
		var legacyMode = cfg.stripe_mode || EVR_ADMIN.mode;
		var testId = t.stripe_product_id_test || (legacyMode === 'test' ? t.stripe_product_id : '') || '';
		var liveId = t.stripe_product_id_live || (legacyMode === 'live' ? t.stripe_product_id : '') || '';
		$tr.append($('<td class="evr-t-product-cell" data-mode="test">').append(productSelect(testId, 'test')));
		$tr.append($('<td class="evr-t-product-cell" data-mode="live">').append(productSelect(liveId, 'live')));
		$tr.append($('<td>').append($('<input type="number" step="0.01" min="0" class="evr-t-price small-text">').val(dollars(t.price_cents))));
		$tr.append($('<td>').append($('<input type="number" min="0" class="evr-t-cap small-text" placeholder="∞">').val(t.capacity || '')));
		$tr.append($('<td>').append($('<input type="checkbox" class="evr-t-active">').prop('checked', t.active !== false)));
		$tr.append($('<td>').append($('<button type="button" class="button-link-delete evr-remove">Remove</button>')));
		$('#evr-tickets-table tbody').append($tr);
		refreshPeriodTicketSelects();
	}

	/* ---------- Pricing periods ---------- */

	function fillTicketSelect($sel, selected) {
		var current = selected !== undefined ? selected : $sel.val();
		$sel.empty();
		$('.evr-ticket-row').each(function () {
			var label = $(this).find('.evr-t-label').val() || '(untitled ticket)';
			$sel.append($('<option>').val($(this).data('key')).text(label));
		});
		if (current) { $sel.val(current); }
		return $sel;
	}

	function refreshPeriodTicketSelects() {
		$('.evr-period-row .evr-pp-ticket').each(function () {
			fillTicketSelect($(this));
		});
	}

	function addPeriodRow(p) {
		p = p || {};
		var $tr = $('<tr class="evr-period-row">');
		$tr.append($('<td>').append(fillTicketSelect($('<select class="evr-pp-ticket">'), p.ticket_key || '')));
		$tr.append($('<td>').append($('<input type="text" class="evr-pp-label" placeholder="e.g. Early Bird 1">').val(p.label || '')));
		$tr.append($('<td>').append($('<input type="number" step="0.01" min="0" class="evr-pp-price small-text">').val(dollars(p.price_cents))));
		$tr.append($('<td>').append($('<input type="datetime-local" class="evr-pp-ends">').val(p.ends || '')));
		$tr.append($('<td>').append($('<input type="text" class="evr-pp-total-label" placeholder="e.g. Total Early-Bird Price">').val(p.total_label || '')));
		$tr.append($('<td>').append($('<button type="button" class="button-link-delete evr-remove">Remove</button>')));
		$('#evr-periods-table tbody').append($tr);
	}

	// Keep the period ticket pickers in sync with ticket label edits/removals.
	$(document).on('input', '.evr-t-label', refreshPeriodTicketSelects);

	// When a Stripe product is picked, prefill the price from its default price.
	$(document).on('change', '.evr-t-product', function () {
		var cents = $(this).find('option:selected').data('cents');
		if (cents) {
			$(this).closest('tr').find('.evr-t-price').val((cents / 100).toFixed(2));
		}
	});

	/* ---------- Discounts ---------- */

	function renderDiscounts() {
		var $tbody = $('#evr-discounts');
		['premium', 'elite', 'vip'].forEach(function (tier) {
			var d = (cfg.discounts && cfg.discounts[tier]) || { type: 'fixed', amount: 0 };
			var $tr = $('<tr>').attr('data-tier', tier);
			$tr.append($('<td>').text(tier.charAt(0).toUpperCase() + tier.slice(1) + ' (+ Processor)'));
			$tr.append($('<td>').append(
				$('<select class="evr-d-type"><option value="fixed">$ off</option><option value="percent">% off</option></select>').val(d.type || 'fixed')
			));
			$tr.append($('<td>').append($('<input type="number" step="0.01" min="0" class="evr-d-amount small-text">').val(d.amount || '')));
			$tbody.append($tr);
		});
	}

	/* ---------- Promo codes ---------- */

	function addPromoRow(p) {
		p = p || {};
		var $tr = $('<tr class="evr-promo-row">');
		$tr.append($('<td>').append($('<input type="text" class="evr-p-code" placeholder="SPEAKER25">').val(p.code || '')));
		$tr.append($('<td>').append(
			$('<select class="evr-p-type"><option value="fixed">$ off</option><option value="percent">% off</option></select>').val(p.type || 'fixed')
		));
		$tr.append($('<td>').append($('<input type="number" step="0.01" min="0" class="evr-p-amount small-text">').val(p.amount || '')));
		$tr.append($('<td>').append($('<input type="number" min="0" class="evr-p-max small-text" placeholder="∞">').val(p.max_uses || '')));
		$tr.append($('<td class="evr-p-used">').text(p.used || 0));
		$tr.append($('<td>').append($('<input type="datetime-local" class="evr-p-expires">').val(p.expires || '')));
		$tr.append($('<td>').append($('<button type="button" class="button-link-delete evr-remove">Remove</button>')));
		$('#evr-promos-table tbody').append($tr);
	}

	/* ---------- Custom fields ---------- */

	var ghlFields = [];

	var GHL_STANDARD_CONTACT = [
		['contact.companyName', 'Company name'],
		['contact.address1', 'Street address'],
		['contact.city', 'City'],
		['contact.state', 'State'],
		['contact.postalCode', 'Postal code'],
		['contact.country', 'Country'],
		['contact.website', 'Website'],
		['contact.dateOfBirth', 'Date of birth'],
		['contact.gender', 'Gender'],
		['contact.timezone', 'Timezone'],
		['contact.source', 'Contact source']
	];
	var GHL_STANDARD_OPP = [
		['opportunity.source', 'Opportunity source']
	];

	function ghlFieldSelect(selected) {
		selected = selected || '';
		var $wrap = $('<span class="evr-ghl-map">');
		var $sel = $('<select class="evr-f-ghl-select"><option value="">— not mapped —</option></select>');

		function group(label, pairs) {
			if (!pairs.length) { return; }
			var $g = $('<optgroup>').attr('label', label);
			pairs.forEach(function (p) { $g.append($('<option>').val(p[0]).text(p[1])); });
			$sel.append($g);
		}

		function cfLabel(f) {
			return f.name + (f.options && f.options.length ? ' (' + f.options.length + ' options)' : '');
		}
		group('Contact — standard fields', GHL_STANDARD_CONTACT);
		group('Contact — custom fields', ghlFields.filter(function (f) { return f.model === 'contact'; })
			.map(function (f) { return ['cf_contact:' + f.id, cfLabel(f)]; }));
		group('Opportunity — standard fields', GHL_STANDARD_OPP);
		group('Opportunity — custom fields', ghlFields.filter(function (f) { return f.model === 'opportunity'; })
			.map(function (f) { return ['cf_opportunity:' + f.id, cfLabel(f)]; }));
		$sel.append($('<option>').val('__manual').text('Enter field ID manually…'));

		var $manual = $('<input type="text" class="evr-f-ghl-id" placeholder="GHL field ID" style="display:none">');

		// Resolve the saved mapping to an option; legacy bare IDs were
		// contact custom fields.
		var value = selected;
		if (value && !$sel.find('option[value="' + value.replace(/"/g, '\\"') + '"]').length) {
			if ($sel.find('option[value="cf_contact:' + value + '"]').length) {
				value = 'cf_contact:' + value;
			} else {
				$manual.val(selected).show();
				value = '__manual';
			}
		}
		$sel.val(value);
		return $wrap.append($sel).append(' ').append($manual);
	}

	$(document).on('change', '.evr-f-ghl-select', function () {
		$(this).siblings('.evr-f-ghl-id').toggle($(this).val() === '__manual');

		// If the mapped GHL field is a dropdown/radio/multi-select, offer
		// to pull its option list into this form field.
		var val = $(this).val() || '';
		var m = val.match(/^cf_(?:contact|opportunity):(.+)$/);
		if (!m) { return; }
		var ghlField = null;
		ghlFields.forEach(function (f) { if (f.id === m[1]) { ghlField = f; } });
		if (!ghlField || !ghlField.options || !ghlField.options.length) { return; }

		var $row = $(this).closest('tr');
		var $options = $row.find('.evr-f-options');
		var joined = ghlField.options.join(', ');
		if ($options.val() && $options.val() !== joined &&
			!confirm('Replace this field’s options with the ' + ghlField.options.length + ' options from GHL ("' + ghlField.name + '")?')) {
			return;
		}
		$options.val(joined);
		$row.find('.evr-f-type').val('select');
	});

	function mappingValue($row) {
		var v = $row.find('.evr-f-ghl-select').val();
		return v === '__manual' ? $row.find('.evr-f-ghl-id').val() : v;
	}

	/* ---------- Conditional visibility ("Show when") ---------- */

	// Build one condition row: [controlling field] [operator] [value].
	function conditionRow(c) {
		c = c || {};
		var $row = $('<div class="evr-cond-row">').data('selectedField', c.field || '');
		$row.append($('<select class="evr-f-cond-field"><option value="">— field —</option></select>'));
		$row.append($('<select class="evr-f-cond-op">' +
			'<option value="equals">equals</option>' +
			'<option value="not_equals">does not equal</option>' +
			'<option value="one_of">is one of</option>' +
			'<option value="not_empty">is not empty / checked</option>' +
			'</select>').val(c.operator || 'equals'));
		var $val = $('<input type="text" class="evr-f-cond-value" placeholder="value (or A, B, C)">').val(c.value || '');
		if ('not_empty' === (c.operator || 'equals')) { $val.hide(); }
		$row.append($val);
		$row.append($('<button type="button" class="button-link-delete evr-remove-cond" title="Remove condition">&times;</button>'));
		return $row;
	}

	// Only Dropdown/Checkbox fields can control visibility.
	function controllerFields() {
		var list = [];
		$('.evr-field-row').each(function () {
			var type = $(this).find('.evr-f-type').val();
			if ('select' === type || 'checkbox' === type) {
				list.push({ key: $(this).data('key'), label: $(this).find('.evr-f-label').val() || '(untitled field)' });
			}
		});
		return list;
	}

	// Repopulate every condition's field dropdown (used when fields are
	// added/removed/retyped/relabelled). Keeps current selections.
	function refreshConditionControllers() {
		var fields = controllerFields();
		$('.evr-cond-row').each(function () {
			var $sel = $(this).find('.evr-f-cond-field');
			var ownerKey = $(this).closest('.evr-field-row').data('key');
			var current = $sel.val() || $(this).data('selectedField') || '';
			$sel.empty().append($('<option value="">— field —</option>'));
			fields.forEach(function (o) {
				if (o.key === ownerKey) { return; } // a field can't depend on itself
				$sel.append($('<option>').val(o.key).text(o.label));
			});
			$sel.val(current);
		});
	}

	// Show/hide the conditions builder depending on the logic selector.
	function updateVisibilityCell($tr) {
		var logic = $tr.find('.evr-f-cond-logic').val();
		var $body = $tr.find('.evr-f-cond-body');
		$body.toggle(!!logic);
		// Starting to use conditions with none yet — seed one empty row.
		if (logic && !$tr.find('.evr-cond-row').length) {
			$tr.find('.evr-f-conditions').append(conditionRow());
			refreshConditionControllers();
		}
	}

	function addFieldRow(f) {
		f = f || {};
		var $tr = $('<tr class="evr-field-row">').data('key', f.key || uid('fld_'));
		$tr.append($('<td class="evr-drag-handle" title="Drag to reorder">').append($('<span class="dashicons dashicons-menu">')));
		$tr.append($('<td>').append($('<input type="text" class="evr-f-label" placeholder="e.g. Company name">').val(f.label || '')));
		$tr.append($('<td>').append(
			$('<select class="evr-f-type"><option value="text">Text</option><option value="textarea">Textarea</option><option value="select">Dropdown</option><option value="checkbox">Checkbox</option><option value="date">Date</option></select>').val(f.type || 'text')
		));
		$tr.append($('<td>').append($('<input type="text" class="evr-f-placeholder" placeholder="(optional)">').val(f.placeholder || '')));
		$tr.append($('<td>').append($('<input type="text" class="evr-f-options" placeholder="Option A, Option B">').val(f.options || '')));
		$tr.append($('<td>').append($('<input type="checkbox" class="evr-f-required">').prop('checked', !!f.required)));
		$tr.append($('<td>').append(ghlFieldSelect(f.ghl_field_id)));

		// "Show when" cell: logic selector + a list of condition rows.
		var hasConds = f.conditions && f.conditions.length;
		var $vis = $('<td class="evr-f-visibility">');
		$vis.append($('<select class="evr-f-cond-logic">' +
			'<option value="">Always show</option>' +
			'<option value="and">Show when ALL match</option>' +
			'<option value="or">Show when ANY match</option>' +
			'</select>').val(hasConds ? (f.cond_logic || 'and') : ''));
		var $body = $('<div class="evr-f-cond-body">');
		var $conds = $('<div class="evr-f-conditions">');
		(f.conditions || []).forEach(function (c) { $conds.append(conditionRow(c)); });
		$body.append($conds).append($('<button type="button" class="button button-small evr-add-cond">+ condition</button>'));
		if (!hasConds) { $body.hide(); }
		$vis.append($body);
		$tr.append($vis);

		$tr.append($('<td>').append($('<button type="button" class="button-link-delete evr-remove">Remove</button>')));
		$('#evr-fields-table tbody').append($tr);
		refreshConditionControllers();
	}

	function refreshGhlSelects() {
		$('.evr-field-row, .evr-utm-row').each(function () {
			$(this).find('.evr-ghl-map').replaceWith(ghlFieldSelect(mappingValue($(this))));
		});
	}

	/* ---------- UTM mapping rows ---------- */

	var UTM_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

	function renderUtmRows(saved) {
		saved = saved || {};
		UTM_PARAMS.forEach(function (param) {
			var $tr = $('<tr class="evr-utm-row">').attr('data-param', param);
			$tr.append($('<td>').append($('<code>').text(param)));
			$tr.append($('<td>').append(ghlFieldSelect(saved[param] || '')));
			$('#evr-utm-table tbody').append($tr);
		});
	}

	/* ---------- AJAX helpers ---------- */

	function post(action, data) {
		return $.post(EVR_ADMIN.ajax_url, $.extend({ action: action, nonce: EVR_ADMIN.nonce }, data || {}));
	}

	$('#evr-load-products').on('click', function () {
		$('#evr-products-status').text('Loading test + live catalogs…');
		var pending = 2;
		var errors = [];
		['test', 'live'].forEach(function (mode) {
			post('evr_stripe_products', { mode: mode }).done(function (r) {
				if (r.success) { stripeProducts[mode] = r.data.products; }
				else { errors.push(mode + ': ' + r.data.message); }
			}).fail(function () {
				errors.push(mode + ': request failed');
			}).always(function () {
				pending--;
				if (pending > 0) { return; }
				$('#evr-products-status').text(errors.length
					? errors.join(' — ')
					: stripeProducts.test.length + ' test / ' + stripeProducts.live.length + ' live products loaded.');
				$('.evr-ticket-row .evr-t-product-cell').each(function () {
					var cellMode = $(this).data('mode');
					var current = $(this).find('.evr-t-product').val();
					$(this).empty().append(productSelect(current, cellMode));
				});
			});
		});
	});

	$('#evr-load-pipelines').on('click', function () {
		var loc = $('#evr-ghl-location').val();
		if (!loc) { $('#evr-pipelines-status').text('Enter a Location ID first.'); return; }
		$('#evr-pipelines-status').text('Loading…');
		post('evr_ghl_pipelines', { location_id: loc, token: $('#evr-ghl-token').val() }).done(function (r) {
			if (!r.success) { $('#evr-pipelines-status').text(r.data.message); return; }
			var $p = $('#evr-ghl-pipeline').empty().show();
			r.data.pipelines.forEach(function (pl) {
				$p.append($('<option>').val(pl.id).text(pl.name).data('stages', pl.stages));
			});
			$('#evr-ghl-pipeline-manual').hide();
			var savedPipeline = $('#evr-ghl-pipeline-id').val();
			if (savedPipeline) { $p.val(savedPipeline); }
			$p.trigger('change');

			// Waitlist pipeline picker gets the same list, plus a
			// "same as main" option.
			var $wp = $('#evr-wl-pipeline').empty().show();
			$wp.append($('<option>').val('').text('— same pipeline as event —'));
			r.data.pipelines.forEach(function (pl) {
				$wp.append($('<option>').val(pl.id).text(pl.name).data('stages', pl.stages));
			});
			$('#evr-wl-manual').hide();
			var savedWlPipeline = $('#evr-wl-pipeline-id').val();
			if (savedWlPipeline) { $wp.val(savedWlPipeline); }
			$wp.trigger('change');

			$('#evr-pipelines-status').text('');
		}).fail(function () { $('#evr-pipelines-status').text('Request failed.'); });
	});

	$('#evr-ghl-pipeline').on('change', function () {
		var stages = $(this).find('option:selected').data('stages') || [];
		var $s = $('#evr-ghl-stage').empty().show();
		stages.forEach(function (st) { $s.append($('<option>').val(st.id).text(st.name)); });
		var savedStage = $('#evr-ghl-stage-id').val();
		if (savedStage) { $s.val(savedStage); }

		// Abandoned-cart stage uses the same pipeline's stages (optional).
		var $a = $('#evr-ghl-abandoned-stage').empty().show();
		$a.append($('<option>').val('').text('— none (disabled) —'));
		stages.forEach(function (st) { $a.append($('<option>').val(st.id).text(st.name)); });
		$('#evr-ghl-abandoned-manual').hide();
		var savedAb = $('#evr-ghl-abandoned-stage-id').val();
		if (savedAb) { $a.val(savedAb); }

		// Payment-plan Outstanding + Fully-paid stages (same pipeline, optional).
		[
			['#evr-ghl-outstanding-stage', '#evr-ghl-outstanding-manual', '#evr-ghl-outstanding-stage-id', '— use registered stage —'],
			['#evr-ghl-fullypaid-stage', '#evr-ghl-fullypaid-manual', '#evr-ghl-fullypaid-stage-id', '— none (disabled) —']
		].forEach(function (ids) {
			var $sel = $(ids[0]).empty().show();
			$sel.append($('<option>').val('').text(ids[3]));
			stages.forEach(function (st) { $sel.append($('<option>').val(st.id).text(st.name)); });
			$(ids[1]).hide();
			var saved = $(ids[2]).val();
			if (saved) { $sel.val(saved); }
		});
		// "Same as main" waitlist stages depend on the main pipeline.
		if ($('#evr-wl-pipeline').is(':visible') && !$('#evr-wl-pipeline').val()) {
			$('#evr-wl-pipeline').trigger('change');
		}
	});

	$('#evr-wl-pipeline').on('change', function () {
		// Blank value = same pipeline as the event; use its stages.
		var stages = $(this).val()
			? ($(this).find('option:selected').data('stages') || [])
			: ($('#evr-ghl-pipeline').find('option:selected').data('stages') || []);
		var $s = $('#evr-wl-stage').empty().show();
		$s.append($('<option>').val('').text('— select stage —'));
		stages.forEach(function (st) { $s.append($('<option>').val(st.id).text(st.name)); });
		var savedStage = $('#evr-wl-stage-id').val();
		if (savedStage) { $s.val(savedStage); }
	});

	$('#evr-load-ghl-fields').on('click', function () {
		var loc = $('#evr-ghl-location').val();
		if (!loc) { $('#evr-ghl-fields-status').text('Enter a Location ID first.'); return; }
		$('#evr-ghl-fields-status').text('Loading…');
		post('evr_ghl_fields', { location_id: loc, token: $('#evr-ghl-token').val() }).done(function (r) {
			if (!r.success) { $('#evr-ghl-fields-status').text(r.data.message); return; }
			ghlFields = r.data.fields;
			$('#evr-ghl-fields-status').text(ghlFields.length + ' fields loaded.');
			refreshGhlSelects();
		}).fail(function () { $('#evr-ghl-fields-status').text('Request failed.'); });
	});

	// A manually added, separately invoiced registrant has now paid: record it
	// and let the server re-sync GHL out of the Outstanding-payment stage.
	function markManualPaid() {
		var $btn = $(this);
		if (!confirm('Mark this registration as paid? It records the full amount as collected and moves the GoHighLevel opportunity out of the Outstanding-payment stage.')) { return; }
		$btn.prop('disabled', true).text('Saving…');
		post('evr_mark_manual_paid', { registration_id: $btn.data('reg') }).done(function (r) {
			if (r.success) { location.reload(); }
			else {
				$btn.prop('disabled', false).text('Mark paid');
				$btn.siblings('.evr-mark-paid-result').text(r.data.message);
			}
		}).fail(function () {
			$btn.prop('disabled', false).text('Mark paid');
			$btn.siblings('.evr-mark-paid-result').text('Request failed.');
		});
	}

	// Settings: generate a shared key for the manual-registration endpoint.
	function wireManualKey() {
		$(document).on('click', '#evr-gen-manual-key', function () {
			var bytes = new Uint8Array(24);
			(window.crypto || window.msCrypto).getRandomValues(bytes);
			var key = Array.prototype.map.call(bytes, function (b) {
				return ('0' + b.toString(16)).slice(-2);
			}).join('');
			$('#evr-manual-key').val(key);
		});
	}

	function retryGhl() {
		var $btn = $(this).prop('disabled', true).text('Retrying…');
		post('evr_retry_ghl', { registration_id: $btn.data('reg') }).done(function (r) {
			if (r.success) { location.reload(); }
			else { $btn.prop('disabled', false).text('Retry'); alert(r.data.message); }
		});
	}

	// Generate a Stripe Customer Portal link (single-use) so the team can update
	// the card for a plan's upcoming installment invoices.
	function portalLink() {
		var $btn = $(this).prop('disabled', true).text('Generating…');
		var $out = $btn.siblings('.evr-portal-result');
		post('evr_portal_link', { registration_id: $btn.data('reg') }).done(function (r) {
			$btn.prop('disabled', false).text('Manage card');
			if (!r.success) { $out.text(r.data.message); return; }
			$out.html(' <a href="' + r.data.url + '" target="_blank" rel="noopener">Open card-update portal ↗</a> <em>(single-use)</em>');
		}).fail(function () {
			$btn.prop('disabled', false).text('Manage card');
			$out.text('Request failed.');
		});
	}
	// Email the customer an invoice for their full remaining plan balance.
	// Errors from either cancel button share one slot under the schedule.
	function cancelResult($btn) {
		return $btn.closest('.evr-plan-detail').find('.evr-cancel-result');
	}

	function cancelInstallment() {
		var $btn = $(this);
		var n = $btn.data('index') + 1;
		if (!confirm('Cancel payment ' + n + '? It will never be charged, and any Stripe invoice already out for it is voided. Payments scheduled after it still run. This cannot be undone.')) { return; }
		$btn.prop('disabled', true).text('Cancelling…');
		post('evr_cancel_installment', { registration_id: $btn.data('reg'), index: $btn.data('index') }).done(function (r) {
			if (r.success) { location.reload(); }
			else { $btn.prop('disabled', false).text('Cancel'); cancelResult($btn).text(r.data.message); }
		}).fail(function () {
			$btn.prop('disabled', false).text('Cancel');
			cancelResult($btn).text('Request failed.');
		});
	}

	function cancelRemaining() {
		if (!confirm('Cancel every payment on this plan that has not been collected yet? Nothing further is charged automatically, any Stripe invoice already out is voided, and the balance has to be collected another way. This cannot be undone.')) { return; }
		var $btn = $(this).prop('disabled', true).text('Cancelling…');
		post('evr_cancel_remaining', { registration_id: $btn.data('reg') }).done(function (r) {
			if (r.success) { location.reload(); }
			else { $btn.prop('disabled', false).text('Cancel remaining payments'); cancelResult($btn).text(r.data.message); }
		}).fail(function () {
			$btn.prop('disabled', false).text('Cancel remaining payments');
			cancelResult($btn).text('Request failed.');
		});
	}

	function sendPayoff() {
		if (!confirm('Email the customer an invoice for their entire remaining balance? Their scheduled auto-charges pause until it is paid or voided.')) { return; }
		var $btn = $(this).prop('disabled', true).text('Sending…');
		var $out = $btn.siblings('.evr-payoff-result');
		post('evr_send_payoff', { registration_id: $btn.data('reg') }).done(function (r) {
			if (r.success) { location.reload(); }
			else { $btn.prop('disabled', false).text('Send invoice for remaining balance'); $out.text(r.data.message); }
		}).fail(function () {
			$btn.prop('disabled', false).text('Send invoice for remaining balance');
			$out.text('Request failed.');
		});
	}

	$(document).on('click', '.evr-retry-ghl', retryGhl);
	$(document).on('click', '.evr-portal-link', portalLink);
	$(document).on('click', '.evr-send-payoff', sendPayoff);
	$(document).on('click', '.evr-cancel-inst', cancelInstallment);
	$(document).on('click', '.evr-cancel-remaining', cancelRemaining);
	$(document).on('click', '.evr-mark-paid', markManualPaid);

	/* ---------- Add/remove rows ---------- */

	$(document).on('click', '[data-evr-add]', function () {
		var kind = $(this).data('evr-add');
		if (kind === 'ticket') { addTicketRow({ type: 'main', active: true }); }
		if (kind === 'addon') { addTicketRow({ type: 'addon', active: true }); }
		if (kind === 'period') { addPeriodRow(); }
		if (kind === 'promo') { addPromoRow(); }
		if (kind === 'field') { addFieldRow(); }
	});
	$(document).on('click', '.evr-remove', function () {
		var $tr = $(this).closest('tr');
		var wasTicket = $tr.hasClass('evr-ticket-row');
		var wasField = $tr.hasClass('evr-field-row');
		$tr.remove();
		if (wasTicket) { refreshPeriodTicketSelects(); }
		if (wasField) { refreshConditionControllers(); }
	});

	/* ---------- Conditional visibility builder ---------- */

	$(document).on('change', '.evr-f-cond-logic', function () {
		updateVisibilityCell($(this).closest('.evr-field-row'));
	});
	$(document).on('click', '.evr-add-cond', function () {
		$(this).closest('.evr-field-row').find('.evr-f-conditions').append(conditionRow());
		refreshConditionControllers();
	});
	$(document).on('click', '.evr-remove-cond', function () {
		$(this).closest('.evr-cond-row').remove();
	});
	$(document).on('change', '.evr-f-cond-op', function () {
		$(this).closest('.evr-cond-row').find('.evr-f-cond-value').toggle($(this).val() !== 'not_empty');
	});
	// A field's label/type feeds the controller dropdowns.
	$(document).on('input', '.evr-f-label', refreshConditionControllers);
	$(document).on('change', '.evr-f-type', refreshConditionControllers);

	/* ---------- Serialize on submit ---------- */

	function cents(val) {
		var n = parseFloat(val);
		return isNaN(n) ? 0 : Math.round(n * 100);
	}

	$('#evr-event-form').on('submit', function () {
		var out = {
			stripe_mode: $('#evr-stripe-mode').val(),
			reg_open: $('#evr-reg-open').val(),
			reg_close: $('#evr-reg-close').val(),
			currency: cfg.currency || 'usd',
			allow_duplicates: $('#evr-allow-duplicates').prop('checked'),
			collect_phone: $('#evr-collect-phone').prop('checked'),
			early_bird_overrides_discounts: $('#evr-eb-override').prop('checked'),
			success_message: $('#evr-success-message').val(),
			consent_text: $('#evr-consent-text').val(),
			email_note: $('#evr-email-note').val(),
			member_signup_url: $('#evr-member-url').val(),
			placeholders: {
				first_name: $('#evr-ph-first').val(),
				last_name: $('#evr-ph-last').val(),
				phone: $('#evr-ph-phone').val(),
				email: $('#evr-ph-email').val()
			},
			tickets: [],
			discounts: {},
			promo_codes: [],
			ghl: {
				location_id: $('#evr-ghl-location').val(),
				pipeline_id: $('#evr-ghl-pipeline').is(':visible') ? $('#evr-ghl-pipeline').val() : $('#evr-ghl-pipeline-id').val(),
				stage_id: $('#evr-ghl-stage').is(':visible') ? $('#evr-ghl-stage').val() : $('#evr-ghl-stage-id').val(),
				abandoned_stage_id: $('#evr-ghl-abandoned-stage').is(':visible') ? $('#evr-ghl-abandoned-stage').val() : $('#evr-ghl-abandoned-stage-id').val(),
				outstanding_stage_id: $('#evr-ghl-outstanding-stage').is(':visible') ? $('#evr-ghl-outstanding-stage').val() : $('#evr-ghl-outstanding-stage-id').val(),
				fully_paid_stage_id: $('#evr-ghl-fullypaid-stage').is(':visible') ? $('#evr-ghl-fullypaid-stage').val() : $('#evr-ghl-fullypaid-stage-id').val(),
				token_override: $('#evr-ghl-token').val(),
				tags: $('#evr-ghl-tags').val()
			},
			waitlist: {
				manual: $('#evr-wl-manual').prop('checked'),
				before_open: $('#evr-wl-before').prop('checked'),
				on_capacity: $('#evr-wl-capacity').prop('checked'),
				pipeline_id: $('#evr-wl-pipeline').is(':visible') ? $('#evr-wl-pipeline').val() : $('#evr-wl-pipeline-id').val(),
				stage_id: $('#evr-wl-stage').is(':visible') ? $('#evr-wl-stage').val() : $('#evr-wl-stage-id').val(),
				message: $('#evr-wl-message').val()
			},
			appearance: {
				font: $('#evr-ap-font').val(),
				button_bg: $('#evr-ap-button-bg').val(),
				button_text: $('#evr-ap-button-text').val(),
				text: $('#evr-ap-text').val(),
				border: $('#evr-ap-border').val(),
				accent: $('#evr-ap-accent').val()
			},
			payment_plan: {
				enabled: $('#evr-pp-enabled').prop('checked'),
				count: parseInt($('#evr-pp-count').val(), 10) || 2,
				interval_unit: $('#evr-pp-interval-unit').val(),
				interval_count: parseInt($('#evr-pp-interval-count').val(), 10) || 1,
				min_total_cents: cents($('#evr-pp-min-total').val()),
				final_due: $('#evr-pp-final-due').val(),
				label: $('#evr-pp-label').val()
			},
			fields: []
		};

		$('.evr-ticket-row').each(function () {
			var $r = $(this);
			out.tickets.push({
				key: $r.data('key'),
				type: $r.find('.evr-t-type').val(),
				label: $r.find('.evr-t-label').val(),
				stripe_product_id_test: $r.find('.evr-t-product[data-mode=test]').val(),
				stripe_product_id_live: $r.find('.evr-t-product[data-mode=live]').val(),
				price_cents: cents($r.find('.evr-t-price').val()),
				capacity: parseInt($r.find('.evr-t-cap').val(), 10) || 0,
				active: $r.find('.evr-t-active').prop('checked')
			});
		});

		out.pricing_periods = [];
		$('.evr-period-row').each(function () {
			var $r = $(this);
			out.pricing_periods.push({
				ticket_key: $r.find('.evr-pp-ticket').val(),
				label: $r.find('.evr-pp-label').val(),
				price_cents: cents($r.find('.evr-pp-price').val()),
				ends: $r.find('.evr-pp-ends').val(),
				total_label: $r.find('.evr-pp-total-label').val()
			});
		});

		$('#evr-discounts tr').each(function () {
			out.discounts[$(this).data('tier')] = {
				type: $(this).find('.evr-d-type').val(),
				amount: parseFloat($(this).find('.evr-d-amount').val()) || 0
			};
		});

		$('.evr-promo-row').each(function () {
			var $r = $(this);
			out.promo_codes.push({
				code: $r.find('.evr-p-code').val(),
				type: $r.find('.evr-p-type').val(),
				amount: parseFloat($r.find('.evr-p-amount').val()) || 0,
				max_uses: parseInt($r.find('.evr-p-max').val(), 10) || 0,
				used: parseInt($r.find('.evr-p-used').text(), 10) || 0,
				expires: $r.find('.evr-p-expires').val()
			});
		});

		out.utm_mappings = {};
		$('.evr-utm-row').each(function () {
			out.utm_mappings[$(this).data('param')] = mappingValue($(this));
		});

		$('.evr-field-row').each(function () {
			var $r = $(this);
			var label = $r.find('.evr-f-label').val();
			var key = $r.data('key') || label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
			var condLogic = $r.find('.evr-f-cond-logic').val();
			var conditions = [];
			if (condLogic) {
				$r.find('.evr-cond-row').each(function () {
					var cf = $(this).find('.evr-f-cond-field').val();
					if (!cf) { return; }
					conditions.push({
						field: cf,
						operator: $(this).find('.evr-f-cond-op').val(),
						value: $(this).find('.evr-f-cond-value').val()
					});
				});
			}
			out.fields.push({
				key: key,
				label: label,
				type: $r.find('.evr-f-type').val(),
				placeholder: $r.find('.evr-f-placeholder').val(),
				options: $r.find('.evr-f-options').val(),
				required: $r.find('.evr-f-required').prop('checked'),
				ghl_field_id: mappingValue($r),
				cond_logic: condLogic || 'and',
				conditions: conditions
			});
		});

		$('#evr-config-json').val(JSON.stringify(out));
	});

	/* ---------- Init from saved config ---------- */

	$(function () {
		(cfg.tickets || []).forEach(addTicketRow);
		(cfg.pricing_periods || []).forEach(addPeriodRow);
		renderDiscounts();
		(cfg.promo_codes || []).forEach(addPromoRow);
		(cfg.fields || []).forEach(addFieldRow);
		refreshConditionControllers();
		renderUtmRows(cfg.utm_mappings || {});
		$('#evr-eb-override').prop('checked', !!cfg.early_bird_overrides_discounts);
		if (cfg.ghl) {
			$('#evr-ghl-location').val(cfg.ghl.location_id || '');
			$('#evr-ghl-token').val(cfg.ghl.token_override || '');
			$('#evr-ghl-pipeline-id').val(cfg.ghl.pipeline_id || '');
			$('#evr-ghl-stage-id').val(cfg.ghl.stage_id || '');
			$('#evr-ghl-abandoned-stage-id').val(cfg.ghl.abandoned_stage_id || '');
			$('#evr-ghl-outstanding-stage-id').val(cfg.ghl.outstanding_stage_id || '');
			$('#evr-ghl-fullypaid-stage-id').val(cfg.ghl.fully_paid_stage_id || '');
			$('#evr-ghl-tags').val(cfg.ghl.tags || '');
		}
		if (cfg.waitlist) {
			$('#evr-wl-manual').prop('checked', !!cfg.waitlist.manual);
			$('#evr-wl-before').prop('checked', !!cfg.waitlist.before_open);
			$('#evr-wl-capacity').prop('checked', !!cfg.waitlist.on_capacity);
			$('#evr-wl-pipeline-id').val(cfg.waitlist.pipeline_id || '');
			$('#evr-wl-stage-id').val(cfg.waitlist.stage_id || '');
			$('#evr-wl-message').val(cfg.waitlist.message || '');
		}

		// Drag-to-reorder custom form fields. The submit handler reads rows
		// in DOM order, so reordering here changes the saved (and front-end) order.
		if ($.fn.sortable) {
			$('#evr-fields-table tbody').sortable({
				handle: '.evr-drag-handle',
				axis: 'y',
				containment: '#evr-fields-table',
				cursor: 'grabbing',
				// Keep column widths stable while a row is being dragged.
				helper: function (e, $tr) {
					var $originals = $tr.children();
					var $helper = $tr.clone();
					$helper.children().each(function (i) { $(this).width($originals.eq(i).width()); });
					return $helper;
				}
			});
		}
	});
})(jQuery);
