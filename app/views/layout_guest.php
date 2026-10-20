<?php /** @var \Sabadgardan\View\View $v */ ?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e($title ?? '') ?> · <?= $v->e($siteName) ?></title>
<link rel="stylesheet" href="<?= $v->asset('app.css') ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Cpath d='M7 22l6-7 5 4 7-9' stroke='white' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
</head>
<body>
<div class="guest"><?= $content ?></div>
</body>
</html>
