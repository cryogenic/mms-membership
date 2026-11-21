/**
 * Public registration form: step navigation, dynamic document list,
 * review summary and local draft autosave.
 */
(function () {
	'use strict';

	var DRAFT_KEY = 'mms_registration_draft_v1';

	function text(value) {
		return value == null ? '' : String(value);
	}

	function init(root) {
		var form = root.querySelector('.mms-form');
		if (!form) {
			return;
		}

		var steps = Array.prototype.slice.call(form.querySelectorAll('.mms-step'));
		var indicators = Array.prototype.slice.call(form.querySelectorAll('[data-step-indicator]'));
		var docRows = Array.prototype.slice.call(form.querySelectorAll('.mms-doc-row'));
		var reviewBox = form.querySelector('[data-mms-review]');
		var idleTimer = null;
		var current = 0;

		root.classList.remove('mms-no-js');

		function typeInput() {
			return form.querySelector('input[name="membership_type"]:checked');
		}

		/* ---------- step navigation ---------- */

		function showStep(index) {
			current = Math.max(0, Math.min(index, steps.length - 1));

			steps.forEach(function (step, i) {
				var active = i === current;
				step.classList.toggle('is-active', active);
				if (active) {
					step.removeAttribute('hidden');
				} else {
					step.setAttribute('hidden', 'hidden');
				}
			});

			indicators.forEach(function (item, i) {
				item.classList.toggle('is-active', i === current);
				item.classList.toggle('is-done', i < current);
			});

			if (current === steps.length - 1) {
				buildReview();
			}

			saveDraft();

			var top = root.getBoundingClientRect().top + window.pageYOffset - 40;
			if (window.pageYOffset > top) {
				window.scrollTo({ top: top, behavior: 'smooth' });
			}
		}

		function stepFields(index) {
			return Array.prototype.slice.call(
				steps[index].querySelectorAll('input, select, textarea')
			);
		}

		function validateStep(index) {
			var valid = true;
			var firstInvalid = null;

			stepFields(index).forEach(function (field) {
				if (field.type === 'file' || field.type === 'submit' || field.type === 'button') {
					return;
				}
				if (!field.checkValidity()) {
					valid = false;
					field.classList.add('mms-invalid');
					if (!firstInvalid) {
						firstInvalid = field;
					}
				} else {
					field.classList.remove('mms-invalid');
				}
			});

			if (!valid && firstInvalid) {
				firstInvalid.focus();
				if (typeof firstInvalid.reportValidity === 'function') {
					firstInvalid.reportValidity();
				}
			}

			return valid;
		}

		form.addEventListener('click', function (event) {
			var next = event.target.closest('.mms-next');
			var prev = event.target.closest('.mms-prev');

			if (next) {
				event.preventDefault();
				if (validateStep(current)) {
					showStep(current + 1);
				}
				return;
			}

			if (prev) {
				event.preventDefault();
				showStep(current - 1);
			}
		});

		/* ---------- membership type / documents ---------- */

		function updateDocuments() {
			var selected = typeInput();
			var slug = selected ? selected.value : '';
			var cards = form.querySelectorAll('.mms-type-card');

			Array.prototype.forEach.call(cards, function (card) {
				var input = card.querySelector('input[name="membership_type"]');
				card.classList.toggle('is-selected', !!input && input.checked);
			});

			docRows.forEach(function (row) {
				var requiredIn = text(row.getAttribute('data-required-in')).split(',');
				var optionalIn = text(row.getAttribute('data-optional-in')).split(',');
				var isRequired = slug !== '' && requiredIn.indexOf(slug) !== -1;
				var isOptional = slug !== '' && optionalIn.indexOf(slug) !== -1;
				var visible = isRequired || isOptional;
				var badge = row.querySelector('[data-badge]');
				var file = row.querySelector('input[type="file"]');

				row.hidden = !visible;
				row.classList.toggle('is-hidden', !visible);

				if (file) {
					file.required = isRequired;
				}

				if (badge) {
					badge.textContent = isRequired
						? badge.getAttribute('data-required-label') || 'Required'
						: badge.getAttribute('data-optional-label') || 'Optional';
					badge.classList.toggle('is-required', isRequired);
				}
			});

			return slug;
		}

		form.addEventListener('change', function (event) {
			if (event.target.name === 'membership_type') {
				updateDocuments();
				saveDraft();
			}
		});

		/* ---------- review summary ---------- */

		function buildReview() {
			if (!reviewBox) {
				return;
			}

			var selected = typeInput();
			var card = selected ? selected.closest('.mms-type-card') : null;
			var rows = [];

			rows.push([
				'Membership type',
				card
					? text(card.querySelector('.mms-type-name') && card.querySelector('.mms-type-name').textContent) +
							' — ' +
							text(card.querySelector('.mms-type-fee') && card.querySelector('.mms-type-fee').textContent)
					: '—'
			]);

			var labels = {
				company_name: 'Company name',
				company_reg_no: 'Registration number',
				company_address: 'Address',
				company_phone: 'Company phone',
				company_email: 'Company email',
				company_website: 'Website',
				pic_name: 'PIC name',
				pic_ic: 'PIC IC',
				pic_position: 'PIC position',
				pic_email: 'PIC email',
				pic_phone: 'PIC phone'
			};

			Object.keys(labels).forEach(function (name) {
				var field = form.querySelector('[name="' + name + '"]');
				rows.push([labels[name], field ? text(field.value).trim() : '']);
			});

			var files = [];
			docRows.forEach(function (row) {
				if (row.hidden) {
					return;
				}
				var field = row.querySelector('input[type="file"]');
				if (field && field.files && field.files.length) {
					files.push(field.files[0].name);
				}
			});
			rows.push(['Documents', files.length ? files.join(', ') : 'None']);

			var html = '<table class="mms-review-table"><tbody>';
			rows.forEach(function (pair) {
				html +=
					'<tr><th>' +
					escapeHtml(pair[0]) +
					'</th><td>' +
					escapeHtml(pair[1] === '' ? '—' : pair[1]) +
					'</td></tr>';
			});
			html += '</tbody></table>';

			reviewBox.innerHTML = html;
		}

		function escapeHtml(value) {
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(value));
			return div.innerHTML;
		}

		/* ---------- draft autosave ---------- */

		function saveDraft() {
			window.clearTimeout(idleTimer);
			idleTimer = window.setTimeout(function () {
				try {
					var draft = { step: current, fields: {} };
					Array.prototype.forEach.call(form.elements, function (field) {
						if (!field.name || field.type === 'file' || field.type === 'hidden') {
							return;
						}
						if (field.type === 'checkbox' || field.type === 'radio') {
							draft.fields[field.name] = draft.fields[field.name] || [];
							if (field.checked) {
								draft.fields[field.name].push(field.value);
							}
							return;
						}
						if (field.type === 'submit' || field.type === 'button') {
							return;
						}
						draft.fields[field.name] = field.value;
					});
					window.localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
				} catch (err) {
					/* localStorage may be unavailable; the form still works. */
				}
			}, 400);
		}

		function hasValue(name) {
			var field = form.querySelector('[name="' + name + '"]');
			return !!field && text(field.value).trim() !== '';
		}

		function restoreDraft() {
			var raw = null;
			try {
				raw = window.localStorage.getItem(DRAFT_KEY);
			} catch (err) {
				return;
			}

			if (!raw) {
				return;
			}

			var draft;
			try {
				draft = JSON.parse(raw);
			} catch (err) {
				return;
			}

			if (!draft || !draft.fields) {
				return;
			}

			if (hasValue('company_name') || hasValue('pic_name') || typeInput()) {
				return;
			}

			var restored = false;

			Object.keys(draft.fields).forEach(function (name) {
				var fields = form.querySelectorAll('[name="' + name + '"]');
				Array.prototype.forEach.call(fields, function (field) {
					var saved = draft.fields[name];
					if (Array.isArray(saved)) {
						field.checked = saved.indexOf(field.value) !== -1;
						restored = restored || field.checked;
						return;
					}
					if (field.type === 'file') {
						return;
					}
					field.value = saved;
					restored = restored || text(saved).trim() !== '';
				});
			});

			updateDocuments();

			if (restored) {
				var notice = document.createElement('p');
				notice.className = 'mms-notice mms-notice-info';
				notice.innerHTML =
					'Your saved draft was restored. <button type="button" class="mms-button-link mms-discard-draft">Start over</button>';
				root.insertBefore(notice, form);

				notice.querySelector('.mms-discard-draft').addEventListener('click', function () {
					try {
						window.localStorage.removeItem(DRAFT_KEY);
					} catch (err) {
						/* ignore */
					}
					window.location.reload();
				});
			}
		}

		form.addEventListener('input', saveDraft);
		form.addEventListener('change', saveDraft);

		form.addEventListener('submit', function () {
			if (!validateStep(0) || !validateStep(1)) {
				return;
			}
			Array.prototype.forEach.call(form.querySelectorAll('.mms-step'), function (step) {
				step.removeAttribute('hidden');
			});
			try {
				window.localStorage.removeItem(DRAFT_KEY);
			} catch (err) {
				/* ignore */
			}
		});

		/* ---------- start ---------- */

		docRows.forEach(function (row) {
			var badge = row.querySelector('[data-badge]');
			if (badge) {
				badge.setAttribute('data-required-label', 'Required');
				badge.setAttribute('data-optional-label', 'Optional');
			}
		});

		updateDocuments();
		restoreDraft();

		if (form.querySelector('.mms-notice-error')) {
			showStep(0);
		} else if (typeInput() && form.querySelector('.mms-notice')) {
			showStep(1);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('.mms-registration'), init);
	});
})();
