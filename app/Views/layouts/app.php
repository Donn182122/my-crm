<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark"><title><?= esc($title) ?> | My CRM</title>
    <link rel="stylesheet" href="<?= base_url('assets/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <?php if (in_array($active, ['clients', 'leads', 'projects', 'tasks'], true)): ?><link rel="stylesheet" href="<?= base_url('assets/css/clients.css') ?>"><!-- Clients stylesheet --><?php endif ?>
    <?php if ($active === 'leads'): ?><link rel="stylesheet" href="<?= base_url('assets/css/pipeline.css') ?>"><?php endif ?>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="app-shell">
    <?= view('partials/sidebar') ?>
    <div class="main-shell">
        <?= view('partials/top_navigation') ?>
        <main id="main-content" class="workspace" tabindex="-1"><?= $this->renderSection('content') ?></main>
        <footer class="workspace-footer"><span>My CRM <span class="footer-dot">/</span> Your work, in focus.</span><span>Independent workspace <span class="status-dot"></span></span></footer>
    </div>
</div>
<?= view('partials/search') ?>
<script src="<?= base_url('assets/bootstrap/bootstrap.bundle.min.js') ?>" defer></script>
<script src="<?= base_url('assets/js/app.js') ?>" defer></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
