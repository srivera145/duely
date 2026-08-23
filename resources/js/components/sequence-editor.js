/**
 * Duely — sequence editor.
 *
 * Renders a live preview of each reminder as the user types. The preview goes
 * through the same server-side TemplateRenderer that produces the real email,
 * so what someone approves here is exactly what their client receives — a
 * browser-side reimplementation would eventually drift from it.
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

const flash = (id, message, isError = false) => {
	const box = document.getElementById(id);
	if (!box) return;
	box.textContent = message || '';
	box.classList.toggle('hidden', !message);
	if (message && !isError) {
		window.setTimeout(() => box.classList.add('hidden'), 4000);
	}
};

/** Debounce so typing does not fire a request per keystroke. */
const debounce = (fn, wait) => {
	let timer = null;
	return (...args) => {
		window.clearTimeout(timer);
		timer = window.setTimeout(() => fn(...args), wait);
	};
};

export const initSequenceEditor = () => {
	const form = document.getElementById('sequence-form');
	const restoreButtons = document.querySelectorAll('[data-restore-default]');

	// The index page only needs the restore button.
	restoreButtons.forEach((button) => {
		button.addEventListener('click', async () => {
			button.disabled = true;
			const { ok, body } = await postJson('/api/sequences/restore-default', {});
			button.disabled = false;

			if (ok) {
				window.location.href = `/sequences/${body.id}`;
				return;
			}

			flash('sequence-error', body.error || 'Could not restore the default ladder.', true);
		});
	});

	if (!form) return;

	const stepsContainer = document.getElementById('steps');
	const template = document.getElementById('step-template');

	/** The field the user last touched, so a tag button knows where to insert. */
	let lastFocusedField = null;

	// ------------------------------------------------------------- preview

	const renderPreview = async (step) => {
		const subject = step.querySelector('[name="subject_template"]')?.value || '';
		const body = step.querySelector('[name="body_template"]')?.value || '';

		const { ok, body: result } = await postJson('/api/sequences/preview', {
			subject_template: subject,
			body_template: body,
		});

		if (!ok) return;

		step.querySelector('[data-preview-subject]').textContent = result.subject;

		const target = step.querySelector('[data-preview-body]');
		const mode = step.dataset.previewMode || 'html';

		if (mode === 'html') {
			// The server has already escaped every value and the template's own
			// literal text, so this markup is safe to insert as-is.
			target.innerHTML = result.html;
		} else {
			target.textContent = result.text;
			target.className = 'text-sm text-text whitespace-pre-wrap font-mono';
		}

		if (mode === 'html') {
			target.className = 'text-sm text-text';
		}

		// An unknown tag would render as a hole in a real email; say so here.
		const warning = step.querySelector('[data-tag-warning]');
		if (result.warnings && result.warnings.length > 0) {
			warning.textContent =
				`Duely does not know ${result.warnings.map((t) => `{{${t}}}`).join(', ')} — ` +
				'it will send as empty. Use one of the merge tags above.';
			warning.classList.remove('hidden');
		} else {
			warning.classList.add('hidden');
		}
	};

	const schedulePreview = debounce((step) => renderPreview(step), 300);

	// -------------------------------------------------------------- wiring

	const wireStep = (step) => {
		step.querySelectorAll('[data-template-field]').forEach((field) => {
			field.addEventListener('input', () => schedulePreview(step));
			field.addEventListener('focus', () => {
				lastFocusedField = field;
			});
		});

		step.querySelectorAll('[data-preview-mode]').forEach((button) => {
			button.addEventListener('click', () => {
				step.dataset.previewMode = button.dataset.previewMode;

				step.querySelectorAll('[data-preview-mode]').forEach((other) => {
					const active = other === button;
					other.className = active
						? 'rounded border border-brand px-2 py-1 text-text-strong'
						: 'rounded border border-card-border px-2 py-1 text-text-muted';
				});

				renderPreview(step);
			});
		});

		step.querySelector('[data-remove-step]')?.addEventListener('click', () => {
			if (stepsContainer.querySelectorAll('[data-step]').length <= 1) {
				flash('sequence-error', 'A sequence needs at least one reminder.', true);
				return;
			}

			if (!window.confirm('Remove this reminder from the sequence?')) return;

			step.remove();
			renumberSteps();
		});

		renderPreview(step);
	};

	const renumberSteps = () => {
		stepsContainer.querySelectorAll('[data-step]').forEach((step, index) => {
			step.dataset.step = String(index);
			const number = step.querySelector('[data-step-number]');
			if (number) number.textContent = String(index + 1);
		});
	};

	stepsContainer.querySelectorAll('[data-step]').forEach(wireStep);
	renumberSteps();

	document.getElementById('add-step')?.addEventListener('click', () => {
		const fragment = template.content.cloneNode(true);
		const step = fragment.querySelector('[data-step]');

		stepsContainer.appendChild(fragment);
		renumberSteps();
		wireStep(stepsContainer.lastElementChild);
		stepsContainer.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	});

	// Insert a merge tag at the cursor in whichever field was last focused.
	document.querySelectorAll('[data-insert-tag]').forEach((button) => {
		button.addEventListener('click', () => {
			const field = lastFocusedField;
			if (!field) {
				flash('sequence-error', 'Click into a subject or message first, then pick a tag.', true);
				return;
			}

			const tag = `{{${button.dataset.insertTag}}}`;
			const start = field.selectionStart ?? field.value.length;
			const end = field.selectionEnd ?? field.value.length;

			field.value = field.value.slice(0, start) + tag + field.value.slice(end);
			field.selectionStart = field.selectionEnd = start + tag.length;
			field.focus();

			const step = field.closest('[data-step]');
			if (step) renderPreview(step);
		});
	});

	// ---------------------------------------------------------------- save

	const collectSteps = () =>
		Array.from(stepsContainer.querySelectorAll('[data-step]')).map((step) => ({
			offset_days: Number(step.querySelector('[name="offset_days"]').value || 0),
			tone: step.querySelector('[name="tone"]').value,
			subject_template: step.querySelector('[name="subject_template"]').value,
			body_template: step.querySelector('[name="body_template"]').value,
		}));

	/** Errors arrive keyed as steps.<index>.<field>; place each one in its card. */
	const renderErrors = (errors) => {
		form.querySelectorAll('[data-error-for]').forEach((node) => {
			node.dataset.originalHelp = node.dataset.originalHelp ?? node.textContent;
			node.textContent = node.dataset.originalHelp;
			node.className = 'mt-1 text-xs text-text-muted';
		});

		if (!errors) return;

		Object.entries(errors).forEach(([key, message]) => {
			const stepMatch = key.match(/^steps\.(\d+)\.(.+)$/);

			const node = stepMatch
				? stepsContainer
						.querySelectorAll('[data-step]')
						[Number(stepMatch[1])]?.querySelector(`[data-error-for="${stepMatch[2]}"]`)
				: form.querySelector(`[data-error-for="${key}"]`);

			if (node) {
				node.textContent = message;
				node.className = 'mt-1 text-xs text-danger-text';
			} else {
				flash('sequence-error', message, true);
			}
		});
	};

	form.addEventListener('submit', async (event) => {
		event.preventDefault();

		const button = form.querySelector('button[type="submit"]');
		const idle = button.textContent;
		button.disabled = true;
		button.textContent = 'Saving…';

		flash('sequence-error', '');
		flash('sequence-saved', '');

		const id = form.querySelector('[name="id"]').value;

		const { ok, body } = await postJson(`/api/sequences/${id}`, {
			name: form.querySelector('[name="name"]').value,
			description: form.querySelector('[name="description"]').value,
			send_window_start: form.querySelector('[name="send_window_start"]').value,
			send_window_end: form.querySelector('[name="send_window_end"]').value,
			skip_weekends: form.querySelector('[name="skip_weekends"]').checked,
			is_active: form.querySelector('[name="is_active"]').checked,
			steps: collectSteps(),
		});

		button.disabled = false;
		button.textContent = idle;

		if (!ok) {
			renderErrors(body.errors);
			if (body.error) flash('sequence-error', body.error, true);
			return;
		}

		renderErrors(null);
		flash('sequence-saved', 'Sequence saved.');
	});

	document.getElementById('make-default')?.addEventListener('click', async () => {
		const id = form.querySelector('[name="id"]').value;
		const { ok, body } = await postJson(`/api/sequences/${id}/default`, {});

		if (ok) {
			window.location.reload();
			return;
		}

		flash('sequence-error', body.error || 'Could not make this the default.', true);
	});

	document.getElementById('delete-sequence')?.addEventListener('click', async () => {
		if (!window.confirm('Delete this sequence?')) return;

		const id = form.querySelector('[name="id"]').value;
		const { ok, body } = await postJson(`/api/sequences/${id}/delete`, {});

		if (ok) {
			window.location.href = '/sequences';
			return;
		}

		flash('sequence-error', body.error || 'Could not delete this sequence.', true);
	});
};
