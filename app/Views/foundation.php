<?= $this->extend('auth/layout') ?>
<?= $this->section('title') ?><?= esc($title) ?><?= $this->endSection() ?>
<?= $this->section('main') ?>
<div class="card mx-auto my-5" style="max-width: 42rem">
    <div class="card-body p-4">
        <h1 class="h3"><?= esc($title) ?></h1>
        <p>Signed in as <?= esc(auth()->user()->username ?? 'User') ?>.</p>
        <p>The independent application foundation is ready.</p>
        <?php if (auth()->user()->inGroup('admin')): ?>
            <a href="<?= site_url('admin') ?>" class="btn btn-outline-primary mb-3">Verify administrator access</a>
        <?php endif ?>
        <form action="<?= url_to('logout') ?>" method="post">
            <?= csrf_field() ?>
            <button class="btn btn-secondary" type="submit">Sign out</button>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
