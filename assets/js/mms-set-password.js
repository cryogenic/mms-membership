(function () {
	'use strict';

	function init() {
		var form = document.querySelector('.mms-setpw-form');
		if (!form) {
			return;
		}

		var password = form.querySelector('#mms-password');
		var confirm = form.querySelector('#mms-password-confirm');
		var list = form.querySelector('[data-mms-rules]');
		var ruleLength = list ? list.querySelector('[data-rule="length"]') : null;
		var ruleMatch = list ? list.querySelector('[data-rule="match"]') : null;

		function mark(el, met) {
			if (el) {
				el.classList.toggle('is-met', met);
			}
		}

		function refresh() {
			var value = password ? password.value : '';
			var again = confirm ? confirm.value : '';
			var min = password ? Math.max(1, password.minLength) : 8;

			mark(ruleLength, value.length >= min);
			mark(ruleMatch, again.length > 0 && value === again);
		}

		Array.prototype.forEach.call(form.querySelectorAll('[data-mms-toggle]'), function (button) {
			button.addEventListener('click', function () {
				var input = document.getElementById(button.getAttribute('data-mms-toggle'));
				if (!input) {
					return;
				}

				var show = input.type === 'password';
				var label = show ? button.getAttribute('data-label-hide') : button.getAttribute('data-label-show');

				input.type = show ? 'text' : 'password';
				button.textContent = label;
				button.setAttribute('aria-label', label);
				button.setAttribute('aria-pressed', show ? 'true' : 'false');
				input.focus();
			});
		});

		Array.prototype.forEach.call(form.querySelectorAll('[data-mms-pw]'), function (input) {
			input.addEventListener('input', refresh);
			input.addEventListener('blur', refresh);
		});

		refresh();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
