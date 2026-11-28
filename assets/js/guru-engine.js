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

		// Mobile-only Homepage: merge BROWSE category rows, ensure circle icons & add "See all" links
		function initMobileHomepage() {
			if (!document.body.classList.contains('home') || window.innerWidth > 767) return;

			var row1 = document.querySelector('.elementor-element-f19d57c');
			var row2 = document.querySelector('.elementor-element-13b886e');
			if (row1 && row2 && !row1.dataset.guruMerged) {
				var row2Items = row2.querySelectorAll(':scope > a.elementor-element');
				row2Items.forEach(function (card) {
					row1.appendChild(card);
				});
				row2.style.display = 'none';
				row1.dataset.guruMerged = '1';
			}

			if (row1) {
				var cards = row1.querySelectorAll(':scope > a.elementor-element');
				cards.forEach(function (card) {
					if (card.querySelector('.elementor-icon-wrapper')) return;
					var titleEl = card.querySelector('.elementor-heading-title');
					var titleText = titleEl ? titleEl.textContent.toLowerCase() : '';
					var svgPath = '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>';
					if (titleText.indexOf('hotel') !== -1 || titleText.indexOf('stay') !== -1) {
						svgPath = '<path d="M3 7v11m0-4h18m0 4v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2"/><path d="M7 10V7a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v3"/>';
					} else if (titleText.indexOf('carnival') !== -1) {
						svgPath = '<path d="M12 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4-3.9-3.8 5.4-.8z"/>';
					} else if (titleText.indexOf('experience') !== -1) {
						svgPath = '<circle cx="12" cy="12" r="9"/><polygon points="10 14 14 10 12.5 14.5 9.5 11.5"/>';
					}
					var iconWrap = document.createElement('div');
					iconWrap.className = 'elementor-element elementor-view-default elementor-widget elementor-widget-icon';
					iconWrap.innerHTML =
						'<div class="elementor-icon-wrapper"><div class="elementor-icon">' +
						'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="fill:none !important;stroke:#ffffff !important;" fill="none" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
						svgPath +
						'</svg></div></div>';
					card.insertBefore(iconWrap, card.firstChild);
				});
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

		initMobileHomepage();
		window.addEventListener('resize', initMobileHomepage);
	});
})();
