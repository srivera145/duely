/**
 * The public site's script.
 *
 * Deliberately tiny. The only thing here is the waitlist form, and it is an
 * enhancement rather than the mechanism: the form is a real form with a real
 * action, so a visitor whose script never arrives still joins the list — they
 * just get a page load instead of an inline message.
 *
 * The CSS comes in through this entry so the marketing pages ship one
 * stylesheet and no application bundle.
 */
import '../css/app.css';

const csrfToken = () =>
	document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

const setMessage = (form, text, ok) => {
	const target = form.querySelector('[data-waitlist-message]');
	if (!target) return;

	target.textContent = text;
	target.classList.remove('hidden', 'text-brand', 'text-danger-text');
	target.classList.add(ok ? 'text-brand' : 'text-danger-text');
};

const submit = async (form) => {
	const button = form.querySelector('button[type="submit"]');
	const original = button?.textContent;

	if (button) {
		button.disabled = true;
		button.textContent = 'Sending…';
	}

	try {
		const response = await fetch(form.action, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'X-CSRF-Token': csrfToken(),
			},
			body: new FormData(form),
		});

		const body = await response.json().catch(() => ({}));

		if (!response.ok) {
			setMessage(form, body.error || 'Something went wrong. Try again in a moment.', false);
			return;
		}

		setMessage(form, body.message || 'Check your inbox.', true);

		// The address has been accepted; leaving it in the field invites a
		// second submission that does nothing.
		form.querySelector('input[type="email"]').value = '';
	} catch {
		setMessage(form, 'We could not reach the server. Check your connection and try again.', false);
	} finally {
		if (button) {
			button.disabled = false;
			button.textContent = original;
		}
	}
};

const init = () => {
	document.querySelectorAll('[data-waitlist-form]').forEach((form) => {
		form.addEventListener('submit', (event) => {
			event.preventDefault();
			submit(form);
		});
	});
};

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
