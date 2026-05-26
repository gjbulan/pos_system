<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../auth/session.php';
require_login();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= app_url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body>
<div class="mobile-overlay" id="mobileOverlay" aria-hidden="true"></div>
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container-fluid page-container py-4">
