<aside class="sidebar" id="app-sidebar" aria-label="Workspace navigation">
    <div class="sidebar-brand"><a href="<?= site_url('dashboard') ?>" class="brand"><span class="brand-mark"><?= crm_icon('layers') ?></span><span class="brand-text">my<span>crm</span><small>WORKSPACE</small></span></a><button class="icon-button mobile-sidebar-close" data-sidebar-close aria-label="Close navigation"><?= crm_icon('close') ?></button></div>
    <div class="workspace-switch"><span class="workspace-avatar">M</span><span class="sidebar-label"><strong>My workspace</strong><small>Local development</small></span><span class="workspace-indicator"></span></div>
    <nav class="sidebar-nav" aria-label="Main navigation">
        <?php foreach ($navigation as $section => $items): ?>
            <div class="nav-section"><h2><?= esc($section) ?></h2>
                <?php foreach ($items as $item): $selected = $active === $item['slug']; ?>
                    <div class="nav-entry">
                        <a class="nav-link <?= $selected ? 'is-active' : '' ?>" href="<?= esc($item['url']) ?>" <?= $current === $item['slug'] ? 'aria-current="page"' : '' ?> title="<?= esc($item['label']) ?><?= $item['comingSoon'] ? ' — Coming Soon' : '' ?>">
                            <?= crm_icon($item['icon']) ?><span class="sidebar-label"><?= esc($item['label']) ?></span><?php if ($item['comingSoon'] && ! $item['children']): ?><small class="nav-soon">Soon</small><?php endif ?>
                        </a>
                        <?php if ($item['children']): ?>
                            <button class="nav-group-toggle <?= $selected ? '' : 'collapsed' ?>" data-bs-toggle="collapse" data-bs-target="#nav-<?= esc($item['slug']) ?>" aria-expanded="<?= $selected ? 'true' : 'false' ?>" aria-controls="nav-<?= esc($item['slug']) ?>" aria-label="Toggle <?= esc($item['label']) ?> navigation"><?= crm_icon('chevron') ?></button>
                            <div class="collapse nav-children <?= $selected ? 'show' : '' ?>" id="nav-<?= esc($item['slug']) ?>">
                                <?php foreach ($item['children'] as $child): ?><a href="<?= esc($child['url']) ?>" <?= $current === $child['slug'] ? 'aria-current="page"' : '' ?>><?= esc($child['label']) ?><small>Soon</small></a><?php endforeach ?>
                            </div>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endforeach ?>
    </nav>
    <div class="sidebar-bottom"><span class="status-dot"></span><span class="sidebar-label">Foundation ready</span><small class="sidebar-label">v0.3</small></div>
</aside>
<button class="sidebar-backdrop" data-sidebar-close aria-label="Close navigation" tabindex="-1"></button>
