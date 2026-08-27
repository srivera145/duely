/**
 * Duely — invoice and client editors.
 *
 * Both forms post JSON and render field-level errors in place, so a validation
 * failure never loses what the user typed.
 */

const csrfToken = () =>
	document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

const postJson = async (url, payload) => {
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
	new FormData(form).forEach((value, key) => {
		payload[key] = value;
	});
	return payload;
};

/** Put messages next to the fields they belong to, not in one lump at the top. */
const renderFieldErrors = (form, errors) => {
	form.querySelectorAll('[data-error-for]').forEach((node) => {
		const field = node.dataset.errorFor;

		if (errors && errors[field]) {
			node.dataset.originalHelp = node.dataset.originalHelp ?? node.innerHTML;
			node.textContent = errors[field];
			node.className = 'mt-1 text-xs text-danger-text';
			form.querySelector(`[name="${field}"]`)?.classList.add('border-danger-border');
		} else {
			if (node.dataset.originalHelp !== undefined) {
				node.innerHTML = node.dataset.originalHelp;
			}
			node.className = 'mt-1 text-xs text-text-muted';
			form.querySelector(`[name="${field}"]`)?.classList.remove('border-danger-border');
		}
	});
};

const flash = (id, message, isError = false) => {
	const box = document.getElementById(id);
	if (!box) return;
	box.textContent = message || '';
	box.classList.toggle('hidden', !message);
	if (message && !isError) {
		window.setTimeout(() => box.classList.add('hidden'), 4000);
	}
};

const bindForm = ({ formId, endpoint, errorBox, savedBox, redirect, savedMessage, redirectOnSave = false }) => {
	const form = document.getElementById(formId);
	if (!form) return;

	form.addEventListener('submit', async (event) => {
		event.preventDefault();

		const button = form.querySelector('button[type="submit"]');
		const idleLabel = button?.textContent;
		if (button) {
			button.disabled = true;
			button.textContent = 'Saving…';
		}

		flash(errorBox, '');
		flash(savedBox, '');

		const { ok, body } = await postJson(endpoint, collect(form));

		if (button) {
			button.disabled = false;
			button.textContent = idleLabel;
		}

		if (!ok) {
			renderFieldErrors(form, body.errors);
			if (body.error || !body.errors) {
				flash(errorBox, body.error || 'That could not be saved.', true);
			}
			return;
		}

		renderFieldErrors(form, null);

		const wasNew = !form.querySelector('[name="id"]')?.value;

		if (body.id) {
			form.querySelector('[name="id"]').value = body.id;
		}

		// The server may have merged into an existing record on email; say so
		// rather than letting the user wonder where their new client went.
		flash(savedBox, body.message || savedMessage);

		// A new record always goes to its own page. An existing one does too
		// when the form asks for it: the invoice form is reached *from* the
		// invoice, so leaving the user on the form after saving strands them
		// one click from where they started.
		if (body.id && (wasNew || redirectOnSave)) {
			window.location.href = `${redirect}/${body.id}`;
		}
	});
};

export const initRecords = () => {
	bindForm({
		formId: 'invoice-form',
		endpoint: '/api/invoices',
		errorBox: 'invoice-error',
		savedBox: 'invoice-saved',
		redirect: '/invoices',
		savedMessage: 'Invoice saved.',
		// Back to the invoice, not left sitting on the form.
		redirectOnSave: true,
	});

	bindForm({
		formId: 'client-form',
		endpoint: '/api/clients',
		errorBox: 'client-error',
		savedBox: 'client-saved',
		redirect: '/clients',
		savedMessage: 'Client saved.',
	});

	document.getElementById('delete-invoice')?.addEventListener('click', async () => {
		const id = document.querySelector('#invoice-form [name="id"]')?.value;
		if (!id) return;

		if (!window.confirm('Delete this invoice? Any chase running against it stops too.')) {
			return;
		}

		const { ok, body } = await postJson(`/api/invoices/${id}/delete`, {});
		if (ok) {
			window.location.href = '/invoices';
			return;
		}
		flash('invoice-error', body.error || 'Could not delete that invoice.', true);
	});

	document.getElementById('delete-client')?.addEventListener('click', async () => {
		const id = document.querySelector('#client-form [name="id"]')?.value;
		if (!id) return;

		if (!window.confirm('Delete this client?')) return;

		let result = await postJson(`/api/clients/${id}/delete`, {});

		// The server refuses first when invoices would be removed as well, so
		// the cascade is a deliberate second decision rather than a surprise.
		if (result.status === 409 && result.body.requires_confirmation) {
			if (!window.confirm(`${result.body.error}\n\nDelete them anyway?`)) {
				return;
			}
			result = await postJson(`/api/clients/${id}/delete`, { confirm_cascade: true });
		}

		if (result.ok) {
			window.location.href = '/clients';
			return;
		}

		flash('client-error', result.body.error || 'Could not delete that client.', true);
	});
};
