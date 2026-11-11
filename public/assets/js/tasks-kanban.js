(() => {
    'use strict';
    const board = document.querySelector('[data-task-board]');
    const feedback = document.getElementById('task-board-feedback');
    const reload = document.getElementById('task-board-reload');
    if (!board || !feedback) return;
    const storageKey = 'crm.tasks-kanban-feedback';
    let dragged = null;
    let busy = false;
    let blocked = false;
    const announce = (message, error = false) => {
        feedback.textContent = message;
        feedback.className = `alert ${error ? 'alert-warning' : 'alert-success'}`;
        feedback.hidden = false;
        if (reload) reload.hidden = !error;
    };
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        sessionStorage.removeItem(storageKey);
        if (saved && typeof saved.message === 'string') announce(saved.message, !!saved.error);
    } catch (_) { /* Storage is optional; no task data is persisted here. */ }
    const refresh = (message, error = false) => {
        try { sessionStorage.setItem(storageKey, JSON.stringify({ message, error })); } catch (_) { /* Optional feedback only. */ }
        location.reload();
    };
    function reset(card) {
        const select = card?.querySelector('[name=status_id]');
        if (select) select.value = card.dataset.status;
        board.querySelectorAll('.is-drop-target,.is-dragging').forEach(element => element.classList.remove('is-drop-target', 'is-dragging'));
    }
    async function move(card, status) {
        const form = card?.querySelector('.task-move');
        if (busy || blocked || !form || card.draggable !== true) return;
        const select = form.querySelector('[name=status_id]');
        if (![...select.options].some(option => option.value === String(status))) { reset(card); announce('Choose an available status.', true); return; }
        if (String(status) === card.dataset.status) { reset(card); announce('This task is already in that status. Column ordering is automatic.'); return; }
        // Capture before disabling controls. Never change card position before server confirmation.
        const data = new FormData(form);
        data.set('status_id', status);
        const controls = [...board.querySelectorAll('.task-move button,.task-move select')];
        busy = true;
        board.setAttribute('aria-busy', 'true');
        controls.forEach(control => { control.disabled = true; });
        announce('Saving task status…');
        try {
            const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
            if (response.redirected || !(response.headers.get('Content-Type') || '').includes('application/json')) { blocked = true; throw new Error('The session or response changed. Reload the board before trying again.'); }
            const result = await response.json();
            if (typeof result.csrf === 'string') document.querySelectorAll('input[name=my_crm_csrf_token]').forEach(input => { input.value = result.csrf; });
            if (response.status === 409) { reset(card); refresh('This task changed. The board was refreshed; review its latest state before retrying.', true); return; }
            if (!response.ok) {
                blocked = [401,403,404].includes(response.status);
                const errors = Object.values(result.errors || {}).filter(value => typeof value === 'string');
                throw new Error(errors.join(' ') || 'The move was rejected. Reload to check current access and state.');
            }
            refresh('Task status updated. Filters and column pages may hide the moved task.');
        } catch (error) {
            reset(card);
            // A network failure can occur after commit; do not resubmit an uncertain write.
            if (error instanceof TypeError || error instanceof SyntaxError) blocked = true;
            announce(error.message || 'Could not confirm the change. Reload before retrying.', true);
        } finally {
            busy = false;
            board.removeAttribute('aria-busy');
            controls.forEach(control => { control.disabled = blocked; });
        }
    }
    board.addEventListener('submit', event => {
        if (!event.target.matches('.task-move')) return;
        event.preventDefault();
        move(event.target.closest('[data-task]'), event.target.querySelector('[name=status_id]').value);
    });
    board.addEventListener('dragstart', event => {
        const card = event.target.closest('.task-card[draggable=true]');
        if (!card || busy || blocked || event.target.closest('select,button,input,a')) { event.preventDefault(); return; }
        dragged = card;
        card.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.task);
    });
    board.addEventListener('dragover', event => {
        const column = event.target.closest('.kanban-column[data-drop="1"]');
        if (!dragged || busy || blocked || !column) return;
        event.preventDefault();
        board.querySelectorAll('.is-drop-target').forEach(item => item.classList.remove('is-drop-target'));
        column.classList.add('is-drop-target');
        event.dataTransfer.dropEffect = 'move';
    });
    board.addEventListener('dragleave', event => {
        const column = event.target.closest('.kanban-column');
        if (column && !column.contains(event.relatedTarget)) column.classList.remove('is-drop-target');
    });
    board.addEventListener('drop', event => {
        const column = event.target.closest('.kanban-column[data-drop="1"]');
        if (!dragged || !column) return;
        event.preventDefault();
        move(dragged, column.dataset.status);
    });
    board.addEventListener('dragend', () => { reset(dragged); dragged = null; });
})();
