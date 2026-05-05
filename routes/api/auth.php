<?php

use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    require __DIR__ . '/auth/common.php';
    require __DIR__ . '/auth/google.php';
    require __DIR__ . '/auth/github.php';
    require __DIR__ . '/auth/gitlab.php';
    require __DIR__ . '/auth/discord.php';
});