/**
 * Duely — first-run wizard and trial start.
 *
 * The wizard is a guide, not a gate: every button here either records progress
 * or starts a trial, and none of them block the rest of the app.
 */

const csrfToken = () =>
	document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

const postJson = async (url, payload = {}) => {
	const response = await fetch(url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'X-CSRF-Token': csrfToken(),
		},
		body: JSON.stringify({ ...payload, _csrf: csrfToken() }),
	});

	let body = {};
	try {
		body = await response.json();
	} catch {
		body = { error: 'The server returned an unexpected response.' };
	}

	return { ok: response.ok, body };
};

const showError = (message) => {
	const box = document.getElementById('onboarding-error');
	if (!box) return;
	box.textContent = message || '';
	box.classList.toggle('hidden', !message);
};

export const initOnboarding = () => {
	const panel = document.getElementById('onboarding');
	if (!panel) return;

	document.getElementById('accept-sequence')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		button.disabled = true;

		const { ok, body } = await postJson('/api/onboarding/reviewed');

		if (!ok) {
			button.disabled = false;
			showError(body.error || 'That could not be recorded.');
			return;
		}

		window.location.reload();
	});

	document.getElementById('skip-onboarding')?.addEventListener('click', async () => {
		const { ok, body } = await postJson('/api/onboarding/skip');

		if (!ok) {
			showError(body.error || 'That could not be recorded.');
			return;
		}

		window.location.href = '/dashboard';
	});

	document.getElementById('start-trial')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		button.disabled = true;
		button.textContent = 'Starting…';

		const { ok, body } = await postJson('/api/billing/trial', { plan: 'solo' });

		if (!ok) {
			button.disabled = false;
			button.textContent = 'Start the free trial';
			showError(body.error || 'The trial could not be started.');
			return;
		}

		window.location.reload();
	});
};
