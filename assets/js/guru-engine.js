(function () {
	'use strict';

	function renderVenueOffers(venue) {
		var container = document.getElementById('guru-offer-options');
		if (!container || !venue) return;

		var html = '';
		var checkedSet = false;

		if (venue.guru_voucher && venue.guru_voucher.available) {
			checkedSet = true;
			html +=
				'<label class="guru-radio-card">' +
				'<input type="radio" name="offer_type" value="guru_funded_voucher" checked />' +
				'<span><strong>Guru-Sponsored Voucher</strong> &mdash; $' +
				Number(venue.guru_voucher.value_usd).toFixed(2) +
				' USD off (Min $' +
				Number(venue.guru_voucher.min_spend_usd).toFixed(2) +
				')</span></label>';
		}

		if (venue.partner_privilege && venue.partner_privilege.available) {
			var attr = checkedSet ? '' : ' checked';
			checkedSet = true;
			html +=
				'<label class="guru-radio-card">' +
				'<input type="radio" name="offer_type" value="partner_perk"' + attr + ' />' +
				'<span><strong>Partner Privilege</strong> &mdash; ' +
				escapeHtml(venue.partner_privilege.description) +
				'</span></label>';
		}

		var stdAttr = checkedSet ? '' : ' checked';
		html +=
			'<label class="guru-radio-card">' +
			'<input type="radio" name="offer_type" value="standard_purchase"' + stdAttr + ' />' +
			'<span><strong>Standard Purchase</strong> &mdash; Earn Guru Points (1 pt / $10 spent)</span></label>';

		container.innerHTML = html;
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function bindRedeemForm(formId, feedbackId) {
		var form = document.getElementById(formId);
		var feedback = document.getElementById(feedbackId);
		if (!form || !feedback || !window.GuruEngineConfig) return;

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			feedback.style.display = 'none';
			feedback.className = 'guru-feedback';

			var btn = form.querySelector('button[type="submit"]');
			if (btn) btn.disabled = true;

			var formData = new FormData(form);
			var payload = {
				card_code: formData.get('card_code'),
				listing_id: Number(formData.get('listing_id')),
				offer_type: formData.get('offer_type'),
				purchase_amount_usd: Number(formData.get('purchase_amount_usd')),
				merchant_pin: formData.get('merchant_pin'),
				redemption_method: formData.get('redemption_method')
			};

			fetch(window.GuruEngineConfig.restUrl + 'redeem', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.GuruEngineConfig.nonce
				},
				body: JSON.stringify(payload)
			})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, data: data };
					});
				})
				.then(function (result) {
					if (btn) btn.disabled = false;
					feedback.style.display = 'block';

					if (result.ok && result.data && result.data.status === 'success') {
						feedback.className = 'guru-feedback is-success';
						feedback.innerHTML =
							'<strong>Redemption Verified!</strong><br>' +
							'Reference Code: <code>' + escapeHtml(result.data.reference_code) + '</code><br>' +
							'Voucher Applied: $' + Number(result.data.voucher_value_usd).toFixed(2) + ' USD<br>' +
							'Points Awarded: +' + Number(result.data.points_awarded) + ' pts (New Balance: ' + Number(result.data.points_balance) + ')';
						form.reset();
					} else {
						feedback.className = 'guru-feedback is-error';
						var msg = (result.data && result.data.message) ? result.data.message : 'Redemption failed.';
						feedback.textContent = msg;
					}
				})
				.catch(function () {
					if (btn) btn.disabled = false;
					feedback.style.display = 'block';
					feedback.className = 'guru-feedback is-error';
					feedback.textContent = 'Network error. Please try again.';
				});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		// /verify/ page venue selector
		var venueSelect = document.getElementById('guru_listing_id');
		if (venueSelect && window.GuruEngineConfig && window.GuruEngineConfig.venues) {
			renderVenueOffers(window.GuruEngineConfig.venues[venueSelect.value]);
			venueSelect.addEventListener('change', function () {
				renderVenueOffers(window.GuruEngineConfig.venues[venueSelect.value]);
			});
		}

		bindRedeemForm('guru-verify-redeem-form', 'guru-redeem-feedback');
		bindRedeemForm('guru-device-redeem-form', 'guru-device-feedback');

		// Single listing fallback toggle
		var toggleBtn = document.getElementById('guru-toggle-device-redeem');
		var devForm = document.getElementById('guru-device-redeem-form');
		if (toggleBtn && devForm) {
			toggleBtn.addEventListener('click', function () {
				devForm.style.display = devForm.style.display === 'none' ? 'block' : 'none';
			});
		}

		// Digital QR code renderer (uses QR Server image with graceful fallback)
		var qrBoxes = document.querySelectorAll('.guru-qr-box[data-qr-url]');
		qrBoxes.forEach(function (box) {
			var url = box.getAttribute('data-qr-url');
			if (!url) return;
			var img = document.createElement('img');
			img.width = 140;
			img.height = 140;
			img.alt = 'Explorer+ QR Code';
			img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' + encodeURIComponent(url);
			box.appendChild(img);
		});

		// Mobile-only Homepage: merge BROWSE category rows & add "See all" links
		if (document.body.classList.contains('home') && window.innerWidth <= 767) {
			var row1 = document.querySelector('.elementor-element-f19d57c');
			var row2 = document.querySelector('.elementor-element-13b886e');
			if (row1 && row2) {
				var row2Items = row2.querySelectorAll(':scope > a.elementor-element');
				row2Items.forEach(function (card) {
					row1.appendChild(card);
				});
				row2.style.display = 'none';
			}

			var sectionLinks = [
				{ id: '67f3ab5', label: 'See all', href: 'explore/?type=place' },
				{ id: 'cfc2bbd', label: 'All events', href: 'explore/?type=event' },
				{ id: '6c381a6', label: 'All properties', href: 'explore/?type=real-estate' }
			];
			sectionLinks.forEach(function (item) {
				var heading = document.querySelector('.elementor-element-' + item.id + ' .elementor-heading-title');
				if (!heading || heading.querySelector('.guru-mobile-see-all')) return;
				var a = document.createElement('a');
				a.className = 'guru-mobile-see-all';
				a.href = item.href;
				a.textContent = item.label;
				heading.appendChild(a);
			});
		}
	});
})();
