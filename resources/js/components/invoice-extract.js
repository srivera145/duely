/**
 * Reading an invoice document.
 *
 * Two jobs: record consent, and hand a document to the server. What comes back
 * is a draft, and the only thing this does with it is put it in the ordinary
 * new-invoice form. Nothing here saves anything -- the user reviews the fields
 * and presses save like they would for an invoice typed by hand.
 */

const csrfToken = () =>
	document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

const show = (id, message) => {
	const el = document.getElementById(id);
	if (!el) return;
	el.textContent = message || '';
	el.classList.toggle('hidden', !message);
};

const busy = (on) => {
	const el = document.getElementById('doc-busy');
	if (el) el.classList.toggle('hidden', !on);
};

const consent = async () => {
	const button = document.getElementById('extraction-consent');
	if (!button) return;

	button.disabled = true;
	button.textContent = 'Turning on…';

	const response = await fetch('/api/invoices/extraction/consent', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'X-CSRF-Token': csrfToken(),
		},
		body: JSON.stringify({ enabled: true, _csrf: csrfToken() }),
	});

	if (!response.ok) {
		button.disabled = false;
		button.textContent = 'Turn this on';
		show('doc-error', 'That could not be saved. Try again.');
		return;
	}

	// Reload so the drop zone replaces the consent panel, rather than building
	// the same markup twice in two places.
	window.location.reload();
};

const send = async (file) => {
	show('doc-error', '');
	busy(true);

	const form = new FormData();
	form.append('file', file);
	form.append('_csrf', csrfToken());

	try {
		const response = await fetch('/api/invoices/extract', {
			method: 'POST',
			headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
			body: form,
		});

		const body = await response.json().catch(() => ({}));

		if (!response.ok) {
			show('doc-error', body.error || 'That document could not be read.');
			return;
		}

		// Everything the reader found travels in the URL to the ordinary new
		// invoice form, where the user checks it. Nothing has been written.
		const params = new URLSearchParams();
		Object.entries(body.draft || {}).forEach(([key, value]) => {
			if (value) params.set(key, value);
		});
		if (body.confidence) params.set('confidence', body.confidence);
		if (body.notes) params.set('notes', body.notes);
		(body.warnings || []).forEach((w) => params.append('warning', w));

		window.location.href = '/invoices/new?' + params.toString();
	} catch {
		show('doc-error', 'The server could not be reached. Try again.');
	} finally {
		busy(false);
	}
};

export const initInvoiceExtract = () => {
	document.getElementById('extraction-consent')?.addEventListener('click', consent);

	const zone = document.getElementById('doc-zone');
	const input = document.getElementById('doc-file');
	if (!zone || !input) return;

	zone.addEventListener('click', () => input.click());
	input.addEventListener('change', () => {
		if (input.files?.[0]) send(input.files[0]);
	});

	['dragenter', 'dragover'].forEach((name) =>
		zone.addEventListener(name, (event) => {
			event.preventDefault();
			zone.classList.add('border-brand');
		}),
	);

	['dragleave', 'drop'].forEach((name) =>
		zone.addEventListener(name, (event) => {
			event.preventDefault();
			zone.classList.remove('border-brand');
		}),
	);

	zone.addEventListener('drop', (event) => {
		const file = event.dataTransfer?.files?.[0];
		if (file) send(file);
	});
};
