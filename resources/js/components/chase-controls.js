/**
 * Duely — manual chase controls and the mark-paid undo.
 *
 * Shared by the dashboard and the invoice timeline, because the buttons mean
 * the same thing in both places.
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

	return { ok: response.ok, status: response.status, body };
};

const showError = (message) => {
	const box = document.getElementById('dashboard-error');
	if (!box) return;

	box.textContent = message || '';
	box.classList.toggle('hidden', !message);

	if (message) {
		box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}
};

const setBusy = (button, busy, label) => {
	if (!button) return;

	if (busy) {
		button.dataset.idleLabel = button.textContent;
		button.textContent = label;
		button.disabled = true;
	} else {
		button.textContent = button.dataset.idleLabel || button.textContent;
		button.disabled = false;
	}
};

/**
 * The undo toast.
 *
 * The window is short and the countdown is visible, because an undo that has
 * silently expired is worse than no undo at all.
 */
const undoToast = (() => {
	let timer = null;

	const hide = () => {
		const toast = document.getElementById('undo-toast');
		if (!toast) return;
		toast.classList.add('hidden');
		toast.classList.remove('flex');
		window.clearInterval(timer);
	};

	const show = (message, token, seconds, onUndone) => {
		const toast = document.getElementById('undo-toast');
		if (!toast) return;

		toast.querySelector('[data-undo-message]').textContent = message;
		const countdown = toast.querySelector('[data-undo-countdown]');
		const button = toast.querySelector('[data-undo-button]');

		let remaining = seconds;
		countdown.textContent = String(remaining);

		toast.classList.remove('hidden');
		toast.classList.add('flex');

		window.clearInterval(timer);
		timer = window.setInterval(() => {
			remaining -= 1;
			countdown.textContent = String(Math.max(0, remaining));

			if (remaining <= 0) {
				hide();
			}
		}, 1000);

		button.onclick = async () => {
			setBusy(button, true, 'Undoing…');
			const { ok, body } = await postJson('/api/invoices/undo', { undo_token: token });
			setBusy(button, false);
			hide();

			if (!ok) {
				showError(body.error || 'That could not be undone.');
				return;
			}

			onUndone?.();
		};
	};

	return { show, hide };
})();

/** Reload, but only once the user has stopped being able to undo. */
const refresh = () => window.location.reload();

export const initChaseControls = () => {
	const actionButtons = document.querySelectorAll('[data-chase-action]');
	const markPaidButtons = document.querySelectorAll('[data-mark-paid]');
	const startButtons = document.querySelectorAll('[data-start-chase]');

	if (!actionButtons.length && !markPaidButtons.length && !startButtons.length) {
		return;
	}

	const labels = {
		pause: ['Pausing…', 'Reminders paused.'],
		resume: ['Resuming…', 'Reminders resumed.'],
		stop: ['Stopping…', 'Reminders stopped.'],
		'send-now': ['Sending…', 'Reminder sent.'],
	};

	actionButtons.forEach((button) => {
		button.addEventListener('click', async () => {
			const action = button.dataset.chaseAction;
			const chaseId = button.dataset.chaseId;
			const [busyLabel] = labels[action] || ['Working…'];

			if (action === 'stop' && !window.confirm('Stop chasing this invoice? Reminders will not resume on their own.')) {
				return;
			}

			if (action === 'send-now' && !window.confirm('Send the next reminder now, outside the usual sending hours?')) {
				return;
			}

			showError('');
			setBusy(button, true, busyLabel);

			const { ok, body } = await postJson(`/api/chases/${chaseId}/${action}`);

			setBusy(button, false);

			if (!ok) {
				showError(body.error || body.reason || 'That did not work.');
				return;
			}

			refresh();
		});
	});

	markPaidButtons.forEach((button) => {
		button.addEventListener('click', async () => {
			const invoiceId = button.dataset.markPaid;

			showError('');
			setBusy(button, true, 'Marking…');

			const { ok, body } = await postJson(`/api/invoices/${invoiceId}/mark-paid`);

			setBusy(button, false);

			if (!ok) {
				showError(body.error || 'That invoice could not be marked paid.');
				return;
			}

			// Hide the row straight away so the screen matches reality, but hold
			// off reloading until the undo window has closed.
			const row = button.closest('[data-chase-row]');
			row?.classList.add('opacity-40');

			undoToast.show(
				'Marked paid. Reminders stopped.',
				body.undo_token,
				body.undo_expires_in,
				refresh
			);

			window.setTimeout(refresh, (body.undo_expires_in + 1) * 1000);
		});
	});

	startButtons.forEach((button) => {
		button.addEventListener('click', async () => {
			showError('');
			setBusy(button, true, 'Starting…');

			const { ok, body } = await postJson(`/api/invoices/${button.dataset.startChase}/start-chase`);

			setBusy(button, false);

			if (!ok) {
				showError(body.error || 'Chasing could not be started.');
				return;
			}

			refresh();
		});
	});
};
