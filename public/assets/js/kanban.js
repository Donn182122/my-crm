(() => {
    'use strict';
    const board = document.querySelector('.kanban-board');
    const feedback = document.getElementById('pipeline-feedback');
    if (!board || !feedback) return;
    let dragged = null;
    let busy = false;
    const announce = (text, error = false) => {
        feedback.textContent = text;
        feedback.className = `alert ${error ? 'alert-warning' : 'alert-success'}`;
        feedback.hidden = false;
    };
    try { const previous = sessionStorage.getItem('crm.pipeline-feedback'); if (previous) { sessionStorage.removeItem('crm.pipeline-feedback'); announce(previous, previous.includes('changed')); } } catch (_) { /* Optional feedback persistence. */ }
    const refresh = text => { try { sessionStorage.setItem('crm.pipeline-feedback', text); } catch (_) { /* No business state is stored. */ } location.reload(); };
    async function move(card, status) {
        if (busy || !card || card.draggable !== true) return;
        const form = card.querySelector('.kanban-move');
        if (!form) return;
        busy = true; board.setAttribute('aria-busy', 'true');
        const data = new FormData(form); data.set('status_id', status);
        try {
            const reply = await fetch(card.dataset.stageUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            const result = await reply.json().catch(() => ({}));
            if (result.csrf) document.querySelectorAll('input[name=my_crm_csrf_token]').forEach(input => { input.value = result.csrf; });
            if (reply.status === 409) { refresh('This lead changed. The board was refreshed; review its latest state before retrying.'); return; }
            if (!reply.ok) throw new Error(Object.values(result.errors || {}).join(' ') || 'The move was rejected. Reload the board to check your access and current data.');
            refresh('Pipeline stage updated.');
        } catch (error) { announce(error.message || 'The move could not be saved. Reload the board before retrying.', true); }
        finally { busy = false; board.removeAttribute('aria-busy'); }
    }
    board.addEventListener('submit', event => {
        if (!event.target.matches('.kanban-move')) return;
        event.preventDefault(); move(event.target.closest('.kanban-card'), event.target.querySelector('[name=status_id]').value);
    });
    board.addEventListener('dragstart', event => {
        const card = event.target.closest('.kanban-card[draggable=true]');
        if (!card || busy || event.target.closest('input,select,button')) { event.preventDefault(); return; }
        dragged = card; card.classList.add('is-dragging'); event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', card.dataset.lead);
    });
    board.addEventListener('dragover', event => { const column = event.target.closest('.kanban-column[data-drop="1"]'); if (dragged && column) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; } });
    board.addEventListener('drop', event => { const column = event.target.closest('.kanban-column[data-drop="1"]'); if (!dragged || !column) return; event.preventDefault(); move(dragged, column.dataset.status); });
    board.addEventListener('dragend', () => { dragged?.classList.remove('is-dragging'); dragged = null; });
})();
