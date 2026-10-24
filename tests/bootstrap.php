<?php

// Mirror the real entrypoints (public/index.php and artisan): the stable
// dependency-free launcher is loaded exactly once, before the application.
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../bootstrap/runtime-release.php';
