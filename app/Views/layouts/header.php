<?php requireBootstrapped(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
<meta name="theme-color" content="#8b1621">
<link rel="manifest" href="../manifest.json">
<link rel="apple-touch-icon" href="assets/images/pwa-icon-apple-touch.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MARS DC">
<script>window.PWA_BASE_PREFIX = '../';</script>

<title>MARS COLLECTION AND DELIVERY SYSTEM</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css">
<link rel="stylesheet" href="<?= adminAsset('assets/leaflet.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/dashboard.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/dashboard_card.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/CustomerTab.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/delivery-collection.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/user-management.css') ?>">
<link rel="stylesheet" href="<?= adminAsset('assets/red-theme.css') ?>">
<link rel="shortcut icon" href="assets/images/favicon2.ico" />
</head>
<body>
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
