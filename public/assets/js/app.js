(() => {
    'use strict';
    const toggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('app-sidebar');
    const mobile = window.matchMedia('(max-width: 991.98px)');
    const search = document.getElementById('workspace-search');
    const input = document.getElementById('navigation-search');
    const resultItems = Array.from(document.querySelectorAll('[data-search-label]'));
    let previousFocus = null;
    try { if (localStorage.getItem('my-crm.sidebar-collapsed') === 'true') document.body.classList.add('sidebar-collapsed'); } catch (_) { /* Optional preference. */ }
    const updateExpanded = () => {
        toggle?.setAttribute('aria-expanded', String(mobile.matches ? document.body.classList.contains('sidebar-open') : !document.body.classList.contains('sidebar-collapsed')));
        const isMobileOpen = mobile.matches && document.body.classList.contains('sidebar-open');
        if (sidebar) {
            sidebar.inert = mobile.matches && !isMobileOpen;
            if (isMobileOpen) { sidebar.setAttribute('role', 'dialog'); sidebar.setAttribute('aria-modal', 'true'); }
            else { sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal'); }
        }
        document.querySelector('.main-shell').inert = isMobileOpen;
    };
    const closeSidebar = () => {
        const wasOpen = document.body.classList.contains('sidebar-open');
        document.body.classList.remove('sidebar-open');
        updateExpanded();
        if (wasOpen) (previousFocus || toggle)?.focus();
    };
    toggle?.addEventListener('click', () => {
        if (mobile.matches) {
            previousFocus = toggle;
            document.body.classList.toggle('sidebar-open');
            updateExpanded();
            if (document.body.classList.contains('sidebar-open')) sidebar.querySelector('[data-sidebar-close]')?.focus();
        } else {
            document.body.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('my-crm.sidebar-collapsed', String(document.body.classList.contains('sidebar-collapsed'))); } catch (_) { /* Optional preference. */ }
            updateExpanded();
        }
    });
    document.querySelectorAll('[data-sidebar-close]').forEach(button => button.addEventListener('click', closeSidebar));
    mobile.addEventListener('change', closeSidebar);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeSidebar();
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault(); closeSidebar();
            window.bootstrap?.Modal.getOrCreateInstance(search).show();
        }
        if (event.key === 'Tab' && mobile.matches && document.body.classList.contains('sidebar-open')) {
            const focusable = Array.from(sidebar.querySelectorAll('a,button')).filter(el => el.getClientRects().length && !el.disabled);
            const first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    const filterResults = () => {
        const query = input.value.trim().toLocaleLowerCase(); let count = 0;
        resultItems.forEach(item => { item.hidden = !item.dataset.searchLabel.includes(query); if (!item.hidden) count++; });
        document.querySelector('.search-empty').hidden = count !== 0;
        document.getElementById('search-status').textContent = `${count} destination${count === 1 ? '' : 's'} found`;
    };
    input?.addEventListener('input', filterResults);
    input?.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') { event.preventDefault(); resultItems.find(item => !item.hidden)?.querySelector('a').focus(); }
        if (event.key === 'Enter') { const visible = resultItems.filter(item => !item.hidden); if (visible.length === 1) visible[0].querySelector('a').click(); }
    });
    search?.addEventListener('shown.bs.modal', () => { input.value = ''; filterResults(); input.focus(); });
    updateExpanded();
})();
