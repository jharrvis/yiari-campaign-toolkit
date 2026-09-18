(function () {
	'use strict';

	var yktAutofillAreas = {};

	function hidePaketAAddressFields() {
		document.querySelectorAll('.ykt-package-a-hidden-address').forEach(function (field) {
			field.required = false;
			field.removeAttribute('required');
			field.setAttribute('aria-required', 'false');

			var row = field.closest('.form-row, .form-group, .wc-block-components-text-input, p');
			if (row) {
				row.hidden = true;
				row.setAttribute('aria-hidden', 'true');
			} else {
				field.hidden = true;
			}
		});
	}

	function getKiriminAjaConfig() {
		var config = window.kiriofBillingAddressConfig || {};
		var kiriofAjax = window.kiriofAjax || {};

		return {
			url: kiriofAjax.ajaxurl || config.ajaxUrl || '',
			nonce: kiriofAjax.nonce || config.nonce || ''
		};
	}

	function firstValue(item, keys) {
		for (var index = 0; index < keys.length; index += 1) {
			if (item && item[keys[index]] !== undefined && item[keys[index]] !== null && String(item[keys[index]]).trim()) {
				return String(item[keys[index]]).trim();
			}
		}

		return '';
	}

	function normalizeArea(item) {
		var area = {
			city: firstValue(item, ['city', 'city_name', 'regency', 'regency_name', 'kabupaten', 'kabupaten_name']),
			state: firstValue(item, ['state', 'state_name', 'province', 'province_name', 'provinsi', 'provinsi_name']),
			postcode: firstValue(item, ['postcode', 'postal_code', 'postalcode', 'zipcode', 'zip'])
		};
		var parts = String(item && item.text ? item.text : '').split(',').map(function (part) {
			return part.trim();
		}).filter(Boolean);

		if (parts.length >= 3) {
			if (!area.postcode && /^\d{5}$/.test(parts[parts.length - 1])) {
				area.postcode = parts[parts.length - 1];
			}
			if (!area.state) {
				area.state = parts[parts.length - (area.postcode ? 2 : 1)];
			}
			if (!area.city) {
				area.city = parts[parts.length - (area.postcode ? 3 : 2)];
			}
		}

		return area;
	}
	function getAddressField(group, field) {
		var selectors = [
			'#' + group + '_' + field,
			'#' + group + '-' + field,
			'[name="' + group + '_' + field + '"]',
			'[name="' + group + '-' + field + '"]'
		];

		return jQuery(selectors.join(',')).first();
	}

	function lockAddressField($field) {
		if (!$field.length) {
			return;
		}

		$field
			.attr('readonly', 'readonly')
			.attr('aria-readonly', 'true')
			.addClass('ykt-autofilled-address');

		if ('SELECT' === $field.prop('tagName')) {
			$field.attr('tabindex', '-1');
		} else {
			$field.prop('readOnly', true);
		}
	}

	function setStateField($field, value) {
		if (!$field.length || !value) {
			return;
		}

		if ('SELECT' === $field.prop('tagName')) {
			var matchedValue = '';
			$field.find('option').each(function () {
				var optionValue = String(jQuery(this).val() || '').trim().toLowerCase();
				var optionText = String(jQuery(this).text() || '').trim().toLowerCase();
				var target = value.toLowerCase();

				if (optionValue === target || optionText === target || optionText.indexOf(target) !== -1 || target.indexOf(optionText) !== -1) {
					matchedValue = jQuery(this).val();
					return false;
				}
			});

			if (!matchedValue) {
				$field.append(new Option(value, value, true, true));
				matchedValue = value;
			}

			$field.val(matchedValue).trigger("change.select2");
		} else {
			$field.val(value);
		}

		lockAddressField($field);
	}

	function applyArea(group, area) {
		yktAutofillAreas[group] = area;
		if (!area.city || !area.state || !area.postcode) {
			return;
		}

		var $city = getAddressField(group, 'city');
		var $state = getAddressField(group, 'state');
		var $postcode = getAddressField(group, 'postcode');

		if ($city.length) {
			$city.val(area.city);
			lockAddressField($city);
		}

		setStateField($state, area.state);

		if ($postcode.length) {
			$postcode.val(area.postcode);
			lockAddressField($postcode);
		}
	}

	function reapplyAutofilledAreas() {
		Object.keys(yktAutofillAreas).forEach(function (group) {
			applyArea(group, yktAutofillAreas[group]);
		});
	}

	function fetchAreaDetails(group, districtId, districtText) {
		var searchTerm = districtText.split(',')[0].trim();
		if (!districtId || !districtText || !window.jQuery) {
			return;
		}

		var parsedArea = normalizeArea( { text: districtText } );
		if ( parsedArea.city && parsedArea.state && parsedArea.postcode ) {
			applyArea( group, parsedArea );
			return;
		}

		var config = getKiriminAjaConfig();
		if (!config.url || !config.nonce) {
			return;
		}

		jQuery.ajax({
			url: config.url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'kiriminaja_subdistrict_search',
				data: {
					term: searchTerm,
					search: searchTerm
				},
				term: searchTerm,
				nonce: config.nonce
			}
		}).done(function (response) {
			var results = response && response.success !== false && Array.isArray(response.data) ? response.data : [];
			var selected = results.find(function (item) {
				return String(item.id || '') === String(districtId);
			});

			if (selected) {
				applyArea(group, normalizeArea(selected));
			}
		});
	}

	function bindAutofill() {
		if (!window.jQuery) {
			return;
		}

		var selector = 'select[name="kiriof_destination_area"], select[name="kiriof_shipping_destination_area"], #kiriof_destination_area, #kiriof_shipping_destination_area';

		jQuery(document.body)
			.off('select2:select.yktAddressAutofill change.yktAddressAutofill', selector)
			.on('select2:select.yktAddressAutofill change.yktAddressAutofill', selector, function (event) {
				var $field = jQuery(this);
				var selected = event.params && event.params.data ? event.params.data : {};
				var districtId = selected.id || $field.val() || '';
				var districtText = selected.text || $field.find('option:selected').text() || '';
				var group = String($field.attr('name') || '').indexOf('shipping_') === 0 || $field.attr('id') === 'kiriof_shipping_destination_area' ? 'shipping' : 'billing';

				fetchAreaDetails(group, districtId, districtText);
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		hidePaketAAddressFields();
		bindAutofill();
	});

	if (window.jQuery) {
		jQuery(document.body).on('updated_checkout updated_wc_div', function () {
			hidePaketAAddressFields();
			bindAutofill();
			[80, 300, 800].forEach(function (delay) {
				window.setTimeout(reapplyAutofilledAreas, delay);
			});
		});
	}
})();
