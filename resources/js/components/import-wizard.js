/**
 * Duely — CSV import wizard.
 *
 * Drives the five steps. The important invariant is that only the confirm
 * button on step 3 sends `confirmed`, so no other interaction — including
 * uploading — can write an invoice.
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

	return { ok: response.ok, body };
};

const postFile = async (url, file) => {
	const form = new FormData();
	form.append('file', file);
	form.append('_csrf', csrfToken());

	const response = await fetch(url, {
		method: 'POST',
		headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
		body: form,
	});

	let body = {};
	try {
		body = await response.json();
	} catch {
		body = { error: 'The server returned an unexpected response.' };
	}

	return { ok: response.ok, body };
};

const text = (tag, value, className = '') => {
	const node = document.createElement(tag);
	node.textContent = value == null ? '' : String(value);
	if (className) node.className = className;
	return node;
};

export const initImportWizard = () => {
	const dropZone = document.getElementById('drop-zone');
	if (!dropZone) return;

	const fileInput = document.getElementById('csv-file');
	const fields = window.duelyImportFields || {};

	/** Everything the wizard knows about the file in progress. */
	const state = {
		token: null,
		headers: [],
		mapping: {},
		hasHeaderRow: true,
		locale: 'auto',
		validCount: 0,
	};

	// ---------------------------------------------------------------- steps

	const showStep = (step) => {
		document.querySelectorAll('[data-step-panel]').forEach((panel) => {
			panel.classList.toggle('hidden', Number(panel.dataset.stepPanel) !== step);
		});

		document.querySelectorAll('[data-step-indicator]').forEach((indicator) => {
			const number = Number(indicator.dataset.stepIndicator);
			const bubble = indicator.querySelector('[data-step-number]');
			const label = indicator.querySelector('[data-step-label]');
			const done = number < step;
			const current = number === step;

			bubble.className = current
				? 'flex h-6 w-6 items-center justify-center rounded-full bg-brand text-xs font-semibold text-brand-contrast'
				: done
					? 'flex h-6 w-6 items-center justify-center rounded-full border border-emerald-500/40 text-xs text-emerald-400'
					: 'flex h-6 w-6 items-center justify-center rounded-full border border-card-border text-xs text-text-muted';

			bubble.textContent = done ? '✓' : String(number);
			label.className = current ? 'font-medium text-text-strong' : 'text-text-muted';
		});

		window.scrollTo({ top: 0, behavior: 'smooth' });
	};

	const showError = (id, message) => {
		const box = document.getElementById(id);
		if (!box) return;
		box.textContent = message || '';
		box.classList.toggle('hidden', !message);
	};

	// -------------------------------------------------------------- step 1

	const handleFile = async (file) => {
		if (!file) return;
		showError('upload-error', '');

		dropZone.classList.add('opacity-60');
		const { ok, body } = await postFile('/api/invoices/import/upload', file);
		dropZone.classList.remove('opacity-60');

		if (!ok) {
			showError('upload-error', body.error || 'That file could not be read.');
			return;
		}

		state.token = body.token;
		state.headers = body.headers || [];
		state.mapping = body.mapping || {};
		state.hasHeaderRow = body.has_header_row;
		state.locale = body.detected_locale === 'auto' ? 'mdy' : body.detected_locale;

		renderPreview(body);
		renderMappingControls();
		renderLocalePanel(body);
		showStep(2);
	};

	dropZone.addEventListener('click', () => fileInput.click());
	fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));

	['dragenter', 'dragover'].forEach((event) => {
		dropZone.addEventListener(event, (e) => {
			e.preventDefault();
			dropZone.classList.add('border-brand');
		});
	});
	['dragleave', 'drop'].forEach((event) => {
		dropZone.addEventListener(event, (e) => {
			e.preventDefault();
			dropZone.classList.remove('border-brand');
		});
	});
	dropZone.addEventListener('drop', (e) => handleFile(e.dataTransfer?.files?.[0]));

	// -------------------------------------------------------------- step 2

	const renderPreview = (body) => {
		document.querySelector('[data-file-name]').textContent = body.original_name || 'your file';
		document.querySelector('[data-total-rows]').textContent = body.total_rows;

		const headerRow = document.querySelector('[data-preview-headers]');
		headerRow.textContent = '';
		state.headers.forEach((header) => headerRow.appendChild(text('th', header)));

		const tbody = document.querySelector('[data-preview-rows]');
		tbody.textContent = '';

		(body.rows || []).forEach((row) => {
			const tr = document.createElement('tr');
			state.headers.forEach((_, index) => {
				tr.appendChild(text('td', row[index] ?? '', 'whitespace-nowrap text-text-muted'));
			});
			tbody.appendChild(tr);
		});

		const warning = document.getElementById('truncation-warning');
		if (body.truncated) {
			warning.textContent =
				'This file is larger than we import in one go. The first 5,000 rows will be used — import the rest in a second file.';
			warning.classList.remove('hidden');
		} else {
			warning.classList.add('hidden');
		}
	};

	/** One select per importable field, listing every column in the file. */
	const renderMappingControls = () => {
		const container = document.querySelector('[data-mapping-fields]');
		container.textContent = '';

		Object.entries(fields).forEach(([field, definition]) => {
			const wrapper = document.createElement('div');

			const label = document.createElement('label');
			label.className = 'block text-sm font-medium text-text';
			label.setAttribute('for', `map-${field}`);
			label.textContent = definition.label;

			if (definition.required) {
				label.appendChild(text('span', ' *', 'text-brand'));
			}

			const select = document.createElement('select');
			select.className = 'form-input mt-1 w-full';
			select.id = `map-${field}`;
			select.dataset.mapField = field;

			const none = document.createElement('option');
			none.value = '-1';
			none.textContent = definition.required ? '— choose a column —' : '— not in my file —';
			select.appendChild(none);

			state.headers.forEach((header, index) => {
				const option = document.createElement('option');
				option.value = String(index);
				option.textContent = header;
				if (state.mapping[field] === index) option.selected = true;
				select.appendChild(option);
			});

			select.addEventListener('change', () => {
				const value = Number(select.value);
				state.mapping[field] = value >= 0 ? value : null;
			});

			wrapper.appendChild(label);
			wrapper.appendChild(select);

			if (definition.help) {
				wrapper.appendChild(text('p', definition.help, 'mt-1 text-xs text-text-muted'));
			}

			container.appendChild(wrapper);
		});
	};

	/** Only ask about date order when the file actually contains ambiguity. */
	const renderLocalePanel = (body) => {
		const panel = document.getElementById('locale-panel');
		if (!body.ambiguous_dates) {
			panel.classList.add('hidden');
			return;
		}

		const dueIndex = state.mapping.due_date;
		const example = (body.rows || [])
			.map((row) => row[dueIndex])
			.find((value) => value && /^\d{1,2}[/\-.]\d{1,2}[/\-.]\d{2,4}$/.test(value));

		panel.querySelector('[data-ambiguous-example]').textContent = example || '03/04/2026';

		panel.querySelectorAll('input[name="date_locale"]').forEach((radio) => {
			radio.checked = radio.value === state.locale;
			radio.addEventListener('change', () => {
				if (radio.checked) state.locale = radio.value;
			});
		});

		panel.classList.remove('hidden');
	};

	document.getElementById('validate-button')?.addEventListener('click', async (event) => {
		showError('mapping-error', '');
		const button = event.currentTarget;
		button.disabled = true;
		button.textContent = 'Checking…';

		const { ok, body } = await postJson('/api/invoices/import/validate', {
			token: state.token,
			mapping: state.mapping,
			locale: state.locale,
			has_header_row: state.hasHeaderRow,
		});

		button.disabled = false;
		button.textContent = 'Check my file';

		if (!ok) {
			showError('mapping-error', body.error || 'Those columns could not be checked.');
			return;
		}

		renderValidation(body);
		showStep(3);
	});

	// -------------------------------------------------------------- step 3

	const renderValidation = (body) => {
		const summary = body.summary;
		state.validCount = summary.valid;

		const tiles = document.querySelector('[data-summary-tiles]');
		tiles.textContent = '';

		[
			['Will import', summary.valid, 'text-emerald-400'],
			['New invoices', summary.new_invoices, 'text-text-strong'],
			['Will update', summary.updated_invoices, 'text-text-strong'],
			['Cannot import', summary.invalid, summary.invalid > 0 ? 'text-amber-400' : 'text-text-muted'],
		].forEach(([label, value, className]) => {
			const tile = document.createElement('div');
			tile.className = 'rounded-lg border border-card-border bg-surface-muted p-4';
			tile.appendChild(text('p', label, 'text-xs text-text-muted'));
			tile.appendChild(text('p', value, `mt-1 text-2xl font-semibold ${className}`));
			tiles.appendChild(tile);
		});

		const clients = document.createElement('p');
		clients.className = 'mt-4 text-sm text-text-muted';
		clients.textContent = `${summary.new_clients} new client${summary.new_clients === 1 ? '' : 's'} will be created; ${summary.matched_clients} row${summary.matched_clients === 1 ? '' : 's'} matched a client you already have.`;
		tiles.parentElement.appendChild(clients);

		// Sample of parsed rows, so a locale mistake is visible before commit.
		const sampleBody = document.querySelector('[data-sample-rows]');
		sampleBody.textContent = '';
		(body.sample || []).forEach((row) => {
			const tr = document.createElement('tr');
			tr.appendChild(text('td', row.line, 'text-text-muted'));
			tr.appendChild(text('td', row.number, 'font-mono text-text'));
			tr.appendChild(text('td', row.client_name, 'text-text'));
			tr.appendChild(text('td', row.amount_formatted, 'text-right font-mono text-text'));
			tr.appendChild(text('td', row.due_date, 'font-mono text-text-muted'));
			tr.appendChild(
				text('td', row.action === 'update' ? 'Update' : 'Create', 'text-text-muted')
			);
			sampleBody.appendChild(tr);
		});

		renderErrorTable(body.errors || [], 'errors-panel', '[data-error-rows]', '[data-error-count]', true);

		document.querySelector('[data-commit-count]').textContent = summary.valid;
		document.getElementById('commit-button').disabled = summary.valid === 0;
	};

	const renderErrorTable = (errors, panelId, rowsSelector, countSelector, includeValues) => {
		const panel = document.getElementById(panelId);
		panel.classList.toggle('hidden', errors.length === 0);

		if (errors.length === 0) return;

		document.querySelector(countSelector).textContent = errors.length;

		const tbody = document.querySelector(rowsSelector);
		tbody.textContent = '';

		errors.forEach((error) => {
			const tr = document.createElement('tr');
			tr.appendChild(text('td', error.line, 'font-mono text-text-muted'));
			tr.appendChild(text('td', error.reason, 'text-text'));

			if (includeValues) {
				const values = Object.values(error.values || {}).filter(Boolean).join(' · ');
				tr.appendChild(text('td', values, 'font-mono text-xs text-text-muted'));
			}

			tbody.appendChild(tr);
		});
	};

	document.getElementById('commit-button')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		button.disabled = true;
		button.textContent = 'Importing…';

		const { ok, body } = await postJson('/api/invoices/import/commit', {
			token: state.token,
			mapping: state.mapping,
			locale: state.locale,
			has_header_row: state.hasHeaderRow,
			// The only place this flag is ever sent.
			confirmed: true,
		});

		button.disabled = false;
		button.textContent = `Import ${state.validCount} invoices`;

		if (!ok) {
			showError('mapping-error', body.error || 'The import could not be completed.');
			showStep(2);
			return;
		}

		renderResult(body);
		showStep(4);
	});

	document.querySelector('[data-back-to-mapping]')?.addEventListener('click', () => showStep(2));

	// -------------------------------------------------------------- step 4

	const renderResult = (body) => {
		const created = body.created;
		const updated = body.updated;

		document.querySelector('[data-result-headline]').textContent =
			`${body.imported} invoice${body.imported === 1 ? '' : 's'} imported`;

		const parts = [];
		if (created) parts.push(`${created} new`);
		if (updated) parts.push(`${updated} updated`);
		if (body.clients_created) {
			parts.push(`${body.clients_created} new client${body.clients_created === 1 ? '' : 's'}`);
		}

		document.querySelector('[data-result-detail]').textContent = parts.join(' · ');

		renderErrorTable(
			body.errors || [],
			'result-errors',
			'[data-result-error-rows]',
			'[data-result-error-count]',
			false
		);
	};

	// --------------------------------------------------------------- cancel

	document.querySelectorAll('[data-cancel-import]').forEach((button) => {
		button.addEventListener('click', async () => {
			if (state.token) {
				await postJson('/api/invoices/import/cancel', { token: state.token });
			}
			window.location.reload();
		});
	});
};
