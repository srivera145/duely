/**
 * Duely — email account settings.
 *
 * Does the client-side lifting for the connect flow: prefilling server settings
 * from the address, mirroring sign-in details between SMTP and IMAP, running
 * live connection tests, and rendering per-channel results.
 *
 * A password only leaves this page inside a submit to our own origin. Nothing
 * is written to storage, and the masked placeholder is submitted unchanged when
 * the user has not typed a replacement — that is what tells the server to keep
 * the credential it already holds.
 */

const FIELDS = [
	'account_id', 'provider', 'from_name', 'from_email', 'reply_to',
	'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
	'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
	'imap_folder',
];

const csrfToken = () =>
	document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

const post = async (url, payload) => {
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

const collect = (form) => {
	const payload = {};
	FIELDS.forEach((field) => {
		const input = form.querySelector(`[name="${field}"]`);
		if (input) {
			payload[field] = input.value;
		}
	});
	return payload;
};

const setBusy = (button, busy, busyLabel) => {
	if (!button) return;
	if (busy) {
		button.dataset.idleLabel = button.textContent;
		button.textContent = busyLabel;
		button.disabled = true;
	} else {
		button.textContent = button.dataset.idleLabel || button.textContent;
		button.disabled = false;
	}
};

/** Render one channel's outcome into its result panel. */
const renderResult = (channel, result) => {
	const panel = document.querySelector(`[data-result="${channel}"]`);
	if (!panel || !result) return;

	const good = result.ok;
	panel.className = good
		? 'mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-400'
		: 'mt-4 rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text';

	panel.textContent = '';

	const heading = document.createElement('p');
	heading.className = 'font-semibold';
	heading.textContent = `${good ? '✓' : '✗'} ${result.message}`;
	panel.appendChild(heading);

	if (result.hint) {
		const hint = document.createElement('p');
		hint.className = 'mt-1 text-text-muted';
		hint.textContent = result.hint;
		panel.appendChild(hint);
	}

	// The scrubbed server transcript stays collapsed — useful for support,
	// noise for everyone else.
	if (result.detail) {
		const details = document.createElement('details');
		details.className = 'mt-2';

		const summary = document.createElement('summary');
		summary.className = 'cursor-pointer text-xs text-text-muted';
		summary.textContent = 'Server response';
		details.appendChild(summary);

		const pre = document.createElement('pre');
		pre.className = 'mt-1 overflow-x-auto whitespace-pre-wrap text-xs text-text-muted';
		pre.textContent = result.detail;
		details.appendChild(pre);

		panel.appendChild(details);
	}
};

/** Inline app-password instructions — the highest-drop-off moment. */
const renderGuidance = (guidance) => {
	const panel = document.getElementById('test-guidance');
	if (!panel) return;

	if (!guidance) {
		panel.classList.add('hidden');
		return;
	}

	panel.querySelector('[data-guidance-title]').textContent = guidance.title;
	panel.querySelector('[data-guidance-summary]').textContent = guidance.summary;

	const steps = panel.querySelector('[data-guidance-steps]');
	steps.textContent = '';
	(guidance.steps || []).forEach((step) => {
		const li = document.createElement('li');
		li.textContent = step;
		steps.appendChild(li);
	});

	const link = panel.querySelector('[data-guidance-link]');
	link.href = guidance.link_url;
	link.textContent = guidance.link_label;

	const footnote = panel.querySelector('[data-guidance-footnote]');
	footnote.textContent = guidance.footnote || '';
	footnote.classList.toggle('hidden', !guidance.footnote);

	panel.classList.remove('hidden');
	panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
};

const showError = (message) => {
	const box = document.getElementById('form-error');
	if (!box) return;

	if (!message) {
		box.classList.add('hidden');
		return;
	}

	box.textContent = message;
	box.classList.remove('hidden');
};

const updateStatusBadge = (status) => {
	const badge = document.querySelector('[data-status-badge]');
	if (!badge) return;

	const styles = {
		active: ['Connected', 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400'],
		needs_reauth: ['Needs attention', 'border-amber-500/30 bg-amber-500/10 text-amber-400'],
		disabled: ['Disabled', 'border-gray-500/30 bg-gray-500/10 text-gray-400'],
		unverified: ['Not connected', 'border-gray-500/30 bg-gray-500/10 text-gray-400'],
	};

	const [label, classes] = styles[status] || styles.unverified;
	badge.textContent = label;
	badge.className = `rounded-full border px-3 py-1 text-xs font-semibold ${classes}`;
};

/** Prefill host/port fields from the typed address. */
const applyPreset = (preset, { force = false } = {}) => {
	const map = {
		smtp_host: preset.smtp_host,
		smtp_port: preset.smtp_port,
		smtp_encryption: preset.smtp_encryption,
		imap_host: preset.imap_host,
		imap_port: preset.imap_port,
		imap_encryption: preset.imap_encryption,
	};

	Object.entries(map).forEach(([name, value]) => {
		const input = document.querySelector(`[name="${name}"]`);
		if (!input) return;
		// Never clobber something the user typed themselves.
		if (force || !input.value || input.dataset.autofilled === 'true') {
			input.value = value;
			input.dataset.autofilled = 'true';
		}
	});

	const provider = document.getElementById('provider');
	if (provider) provider.value = preset.provider;

	const usernames = ['smtp_username', 'imap_username'];
	const email = document.getElementById('from_email')?.value || '';
	usernames.forEach((name) => {
		const input = document.querySelector(`[name="${name}"]`);
		if (input && (!input.value || input.dataset.autofilled === 'true')) {
			input.value = email;
			input.dataset.autofilled = 'true';
		}
	});

	renderPreflightNotice(preset.app_password_notice, preset.note, preset.confident);
};

const renderPreflightNotice = (notice, note, confident) => {
	const panel = document.getElementById('app-password-notice');
	if (!panel) return;

	if (notice) {
		panel.querySelector('[data-notice-title]').textContent = notice.title;
		panel.querySelector('[data-notice-body]').textContent = notice.body;
		const link = panel.querySelector('[data-notice-link]');
		link.href = notice.link_url;
		link.textContent = notice.link_label;
		link.classList.remove('hidden');
		panel.classList.remove('hidden');
		return;
	}

	// Not an app-password provider, but we may still be guessing at the hosts.
	if (!confident && note) {
		panel.querySelector('[data-notice-title]').textContent = 'Check these settings with your host';
		panel.querySelector('[data-notice-body]').textContent = note;
		panel.querySelector('[data-notice-link]').classList.add('hidden');
		panel.classList.remove('hidden');
		return;
	}

	panel.classList.add('hidden');
};

export const initEmailAccount = () => {
	const form = document.getElementById('email-account-form');
	if (!form) return;

	const masked = window.duelyEmailAccount?.masked || '';
	const fromEmail = document.getElementById('from_email');
	const sameCredentials = document.getElementById('same-credentials');

	// Mirror sending sign-in into receiving while the boxes are linked.
	const mirror = () => {
		if (!sameCredentials?.checked) return;
		const username = form.querySelector('[name="smtp_username"]');
		const password = form.querySelector('[name="smtp_password"]');
		const imapUsername = form.querySelector('[name="imap_username"]');
		const imapPassword = form.querySelector('[name="imap_password"]');

		if (username && imapUsername) imapUsername.value = username.value;
		if (password && imapPassword) imapPassword.value = password.value;
	};

	['smtp_username', 'smtp_password'].forEach((name) => {
		form.querySelector(`[name="${name}"]`)?.addEventListener('input', mirror);
	});
	sameCredentials?.addEventListener('change', mirror);

	// Autodetect provider settings once the address looks complete.
	let presetTimer = null;
	fromEmail?.addEventListener('input', () => {
		window.clearTimeout(presetTimer);
		const value = fromEmail.value.trim();
		if (!value.includes('@') || value.endsWith('@')) return;

		presetTimer = window.setTimeout(async () => {
			const { ok, body } = await post('/api/email-account/preset', { email: value });
			if (ok && body.preset) {
				applyPreset(body.preset);
			}
		}, 400);
	});

	const clearResults = () => {
		document.querySelectorAll('[data-result]').forEach((panel) => panel.classList.add('hidden'));
		renderGuidance(null);
		showError('');
	};

	const handleOutcome = (body) => {
		renderResult('smtp', body.smtp);
		renderResult('imap', body.imap);
		renderGuidance(body.guidance);

		if (body.error) showError(body.error);
	};

	document.getElementById('test-button')?.addEventListener('click', async (event) => {
		clearResults();
		mirror();
		const button = event.currentTarget;
		setBusy(button, true, 'Testing…');

		const { body } = await post('/api/email-account/test', collect(form));
		handleOutcome(body);

		setBusy(button, false);
	});

	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		clearResults();
		mirror();

		const button = document.getElementById('save-button');
		setBusy(button, true, 'Testing and saving…');

		const { body } = await post('/api/email-account/save', collect(form));
		handleOutcome(body);

		if (body.saved) {
			updateStatusBadge('active');
			// Re-mask both password fields: the value is stored now, and the
			// plaintext should not linger in the DOM.
			['smtp_password', 'imap_password'].forEach((name) => {
				const input = form.querySelector(`[name="${name}"]`);
				if (input) input.value = masked;
			});
			const accountId = form.querySelector('[name="account_id"]');
			if (accountId && body.account_id) accountId.value = body.account_id;

			window.location.reload();
		}

		setBusy(button, false);
	});

	document.getElementById('send-test-button')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		setBusy(button, true, 'Sending…');
		showError('');

		const accountId = form.querySelector('[name="account_id"]')?.value || '';
		const { body } = await post('/api/email-account/send-test', { account_id: accountId });

		if (body.result) {
			renderResult('smtp', body.result);
		}
		if (body.error) showError(body.error);

		setBusy(button, false);
	});

	document.getElementById('delete-button')?.addEventListener('click', async (event) => {
		if (!window.confirm('Disconnect this mailbox? Scheduled reminders will stop until you connect another.')) {
			return;
		}

		const button = event.currentTarget;
		setBusy(button, true, 'Disconnecting…');

		const accountId = form.querySelector('[name="account_id"]')?.value || '';
		const { body } = await post('/api/email-account/delete', { account_id: accountId });

		if (body.deleted) {
			window.location.reload();
			return;
		}

		showError(body.error || 'Could not disconnect this mailbox.');
		setBusy(button, false);
	});
};
