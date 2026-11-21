/**
 * Member login — password reset lightbox.
 */
(function () {
	'use strict';

	var config = window.mmsLogin || {};

	function init() {
		var trigger = document.querySelector('[data-mms-reset-open]');
		var modal = document.getElementById('mms-reset-modal');

		if (!trigger || !modal) {
			return;
		}

		var form = modal.querySelector('.mms-reset-form');
		var email = modal.querySelector('#mms-reset-email');
		var message = modal.querySelector('.mms-reset-message');
		var submit = form ? form.querySelector('button[type="submit"]') : null;
		var label = submit ? submit.textContent : 'Send reset link';
		var lastFocus = null;

		function open(event) {
			if (event) {
				event.preventDefault();
			}

			lastFocus = document.activeElement;
			modal.hidden = false;
			document.documentElement.classList.add('mms-modal-open');

			window.setTimeout(function () {
				if (email) {
					email.focus();
				}
			}, 30);
		}

		function close() {
			modal.hidden = true;
			document.documentElement.classList.remove('mms-modal-open');

			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
		}

		function showMessage(text, ok) {
			message.textContent = text;
			message.className = 'mms-reset-message ' + (ok ? 'is-success' : 'is-error');
			message.hidden = false;
		}

		trigger.addEventListener('click', open);

		Array.prototype.forEach.call(modal.querySelectorAll('[data-mms-reset-close]'), function (el) {
			el.addEventListener('click', close);
		});

		document.addEventListener('keydown', function (event) {
			if (modal.hidden) {
				return;
			}

			if (event.key === 'Escape') {
				close();
				return;
			}

			if (event.key !== 'Tab') {
				return;
			}

			var focusables = modal.querySelectorAll('.mms-reset-dialog button, .mms-reset-dialog input, .mms-reset-dialog a[href]');
			var first = focusables[0];
			var last = focusables[focusables.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});

		if (location.hash === '#reset-password') {
			open();
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			message.hidden = true;

			if (!email.value || !email.checkValidity()) {
				email.reportValidity();
				return;
			}

			submit.disabled = true;
			submit.textContent = config.sending || 'Sending...';

			var data = new FormData(form);
			data.append('action', 'mms_lost_password');
			data.append('nonce', config.nonce || '');

			window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			})
				.then(function (response) {
					return response.json().catch(function () {
						return {};
					});
				})
				.then(function (result) {
					var ok = !!(result && result.success);
					var text = result && result.data && result.data.message
						? result.data.message
						: (ok ? config.success : config.error);

					showMessage(text, ok);

					if (ok) {
						form.reset();
					}
				})
				.catch(function () {
					showMessage(config.error, false);
				})
				.finally(function () {
					submit.disabled = false;
					submit.textContent = label;
				});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
