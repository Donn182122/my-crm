<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->renderSection('title') ?> | My CRM</title>
    <link href="<?= base_url('assets/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
    <?= $this->renderSection('pageStyles') ?>
</head>
<body class="bg-light">
    <main class="container py-4"><?= $this->renderSection('main') ?></main>
    <script src="<?= base_url('assets/bootstrap/bootstrap.bundle.min.js') ?>" defer></script>
    <?= $this->renderSection('pageScripts') ?>
</body>
</html>
