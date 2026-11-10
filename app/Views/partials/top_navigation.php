<header class="topbar">
    <div class="topbar-left"><button class="icon-button" id="sidebar-toggle" aria-label="Toggle sidebar" aria-controls="app-sidebar" aria-expanded="true"><?= crm_icon('menu') ?></button><div class="breadcrumb-line"><span>Workspace</span><?= crm_icon('chevron') ?><strong><?= esc($title) ?></strong></div></div>
    <div class="topbar-actions">
        <button class="search-trigger" data-bs-toggle="modal" data-bs-target="#workspace-search" aria-label="Search navigation"><?= crm_icon('search') ?><span>Search workspace</span><kbd>Ctrl K</kbd></button>
        <div class="dropdown"><button class="icon-button notification-button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open notifications"><?= crm_icon('bell') ?></button><div class="dropdown-menu dropdown-menu-end notification-menu"><h2>Notifications</h2><div class="notification-empty"><?= crm_icon('bell') ?><strong>You're all caught up</strong><p>Notifications will appear here when business modules are connected.</p></div></div></div>
        <span class="topbar-divider"></span>
        <?= view('partials/account_menu') ?>
    </div>
</header>
