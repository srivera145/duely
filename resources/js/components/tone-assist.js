/**
 * Duely — writing assistant.
 *
 * Every draft is shown as a proposed diff against what the user already wrote.
 * Nothing is applied until they press Accept, and even then it only fills the
 * editor fields — saving is still the ordinary sequence save, which validates
 * merge tags and offsets the same way it does for hand-written copy.
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
		body = { error: 'The assistant returned an unexpected response.' };
	}

	return { ok: response.ok, body };
};

/**
 * A word-level diff, rendered as two columns.
 *
 * Deliberately simple: the point is to show the user what changed so they can
 * judge it, not to be a merge tool.
 */
const diffWords = (before, after) => {
	const a = before.split(/(\s+)/);
	const b = after.split(/(\s+)/);

	// Longest common subsequence over tokens.
	const lengths = Array.from({ length: a.length + 1 }, () => new Array(b.length + 1).fill(0));

	for (let i = a.length - 1; i >= 0; i--) {
		for (let j = b.length - 1; j >= 0; j--) {
			lengths[i][j] = a[i] === b[j]
				? lengths[i + 1][j + 1] + 1
				: Math.max(lengths[i + 1][j], lengths[i][j + 1]);
		}
	}

	const parts = [];
	let i = 0;
	let j = 0;

	while (i < a.length && j < b.length) {
		if (a[i] === b[j]) {
			parts.push({ type: 'same', text: a[i] });
			i++;
			j++;
		} else if (lengths[i + 1][j] >= lengths[i][j + 1]) {
			parts.push({ type: 'removed', text: a[i] });
			i++;
		} else {
			parts.push({ type: 'added', text: b[j] });
			j++;
		}
	}

	while (i < a.length) parts.push({ type: 'removed', text: a[i++] });
	while (j < b.length) parts.push({ type: 'added', text: b[j++] });

	return parts;
};

const renderDiff = (container, before, after) => {
	container.textContent = '';

	diffWords(before, after).forEach((part) => {
		if (part.type === 'same' && part.text.trim() === '') {
			container.appendChild(document.createTextNode(part.text));
			return;
		}

		const span = document.createElement('span');
		span.textContent = part.text;

		if (part.type === 'removed') {
			span.className = 'bg-red-500/15 text-red-400 line-through';
		} else if (part.type === 'added') {
			span.className = 'bg-emerald-500/15 text-emerald-400';
		}

		container.appendChild(span);
	});
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

export const initToneAssist = () => {
	const panel = document.getElementById('tone-assist');
	if (!panel) return;

	const stepsContainer = document.getElementById('steps');
	const allowanceLabel = panel.querySelector('[data-assist-allowance]');
	const errorBox = panel.querySelector('[data-assist-error]');
	const proposalPanel = document.getElementById('assist-proposal');

	/** The draft currently on offer, and where it came from. */
	let pending = null;

	const showError = (message) => {
		errorBox.textContent = message || '';
		errorBox.classList.toggle('hidden', !message);
	};

	const showAllowance = (allowance) => {
		if (!allowance || !allowanceLabel) return;
		allowanceLabel.textContent = `${allowance.used} of ${allowance.limit} writing requests used today`;
	};

	/** Say plainly what was stripped before anything was sent. */
	const showRedactions = (redactions) => {
		const note = panel.querySelector('[data-assist-redactions]');
		if (!note) return;

		const kinds = Object.entries(redactions || {});

		if (kinds.length === 0) {
			note.classList.add('hidden');
			return;
		}

		const described = kinds
			.map(([kind, count]) => `${count} ${kind}${count === 1 ? '' : 's'}`)
			.join(', ');

		note.textContent =
			`Before sending, Duely replaced ${described} in your text with merge tags, ` +
			'so no client details left the app.';
		note.classList.remove('hidden');
	};

	// ------------------------------------------------------ rewrite one step

	document.querySelectorAll('[data-assist-rewrite]').forEach((button) => {
		button.addEventListener('click', async () => {
			const step = button.closest('[data-step]');
			if (!step) return;

			const subject = step.querySelector('[name="subject_template"]').value;
			const body = step.querySelector('[name="body_template"]').value;
			const tone = step.querySelector('[name="tone"]').value;

			showError('');
			setBusy(button, true, 'Drafting…');

			const { ok, body: result } = await postJson('/api/tone-assist/rewrite', {
				subject_template: subject,
				body_template: body,
				tone,
				instruction: panel.querySelector('[data-assist-instruction]')?.value || '',
			});

			setBusy(button, false);
			showAllowance(result.allowance);

			if (!ok) {
				showError(result.error || 'That draft could not be produced.');
				return;
			}

			showRedactions(result.redactions);

			pending = { kind: 'step', step, proposal: result.proposal, original: result.original };
			renderStepProposal(result.original, result.proposal);
		});
	});

	const renderStepProposal = (original, proposal) => {
		proposalPanel.querySelector('[data-proposal-title]').textContent = 'Proposed rewrite';

		const subjectDiff = proposalPanel.querySelector('[data-proposal-subject]');
		const bodyDiff = proposalPanel.querySelector('[data-proposal-body]');

		renderDiff(subjectDiff, original.subject, proposal.subject);
		renderDiff(bodyDiff, original.body, proposal.body);

		proposalPanel.querySelector('[data-proposal-steps]').classList.add('hidden');
		proposalPanel.querySelector('[data-proposal-single]').classList.remove('hidden');
		proposalPanel.classList.remove('hidden');
		proposalPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	};

	// ------------------------------------------------- generate a whole ladder

	panel.querySelector('[data-assist-sequence]')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const description = panel.querySelector('[data-assist-description]')?.value || '';

		if (description.trim().length < 20) {
			showError('Tell Duely a little more about your work first — a sentence or two is plenty.');
			return;
		}

		showError('');
		setBusy(button, true, 'Drafting…');

		const { ok, body: result } = await postJson('/api/tone-assist/sequence', { description });

		setBusy(button, false);
		showAllowance(result.allowance);

		if (!ok) {
			showError(result.error || 'That sequence could not be drafted.');
			return;
		}

		showRedactions(result.redactions);

		pending = { kind: 'sequence', proposal: result.proposal };
		renderSequenceProposal(result.proposal);
	});

	const renderSequenceProposal = (proposal) => {
		proposalPanel.querySelector('[data-proposal-title]').textContent =
			`Proposed ladder — ${proposal.steps.length} reminders`;

		const list = proposalPanel.querySelector('[data-proposal-steps]');
		list.textContent = '';

		proposal.steps.forEach((step) => {
			const card = document.createElement('div');
			card.className = 'rounded-lg border border-card-border bg-surface-muted p-4';

			const heading = document.createElement('p');
			heading.className = 'text-xs uppercase tracking-wide text-text-muted';
			heading.textContent = `Day ${step.offset_days} · ${step.tone}`;
			card.appendChild(heading);

			const subject = document.createElement('p');
			subject.className = 'mt-2 font-medium text-text-strong';
			subject.textContent = step.subject;
			card.appendChild(subject);

			const body = document.createElement('pre');
			body.className = 'mt-2 whitespace-pre-wrap font-sans text-sm text-text-muted';
			body.textContent = step.body;
			card.appendChild(body);

			list.appendChild(card);
		});

		proposalPanel.querySelector('[data-proposal-single]').classList.add('hidden');
		list.classList.remove('hidden');
		proposalPanel.classList.remove('hidden');
		proposalPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	};

	// --------------------------------------------------- accept or discard

	proposalPanel.querySelector('[data-proposal-accept]')?.addEventListener('click', () => {
		if (!pending) return;

		if (pending.kind === 'step') {
			// Fill the editor only. The user still has to save.
			pending.step.querySelector('[name="subject_template"]').value = pending.proposal.subject;
			pending.step.querySelector('[name="body_template"]').value = pending.proposal.body;

			// Nudge the live preview to re-render.
			pending.step
				.querySelectorAll('[data-template-field]')
				.forEach((field) => field.dispatchEvent(new Event('input', { bubbles: true })));
		} else {
			applySequenceToEditor(pending.proposal);
		}

		pending = null;
		proposalPanel.classList.add('hidden');

		const reminder = panel.querySelector('[data-assist-unsaved]');
		if (reminder) reminder.classList.remove('hidden');
	});

	proposalPanel.querySelector('[data-proposal-discard]')?.addEventListener('click', () => {
		pending = null;
		proposalPanel.classList.add('hidden');
	});

	/**
	 * Load a drafted ladder into the editor's step cards, adding or removing
	 * cards so the count matches. Still unsaved.
	 */
	const applySequenceToEditor = (proposal) => {
		if (!stepsContainer) return;

		const addButton = document.getElementById('add-step');

		while (stepsContainer.querySelectorAll('[data-step]').length < proposal.steps.length) {
			addButton?.click();
		}

		const cards = stepsContainer.querySelectorAll('[data-step]');

		cards.forEach((card, index) => {
			const step = proposal.steps[index];

			if (!step) {
				card.remove();
				return;
			}

			card.querySelector('[name="offset_days"]').value = step.offset_days;
			card.querySelector('[name="tone"]').value = step.tone;
			card.querySelector('[name="subject_template"]').value = step.subject;
			card.querySelector('[name="body_template"]').value = step.body;

			card.querySelectorAll('[data-template-field]').forEach((field) =>
				field.dispatchEvent(new Event('input', { bubbles: true }))
			);
		});
	};
};
