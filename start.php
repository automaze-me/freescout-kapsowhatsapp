<?php

// Guarded: Laravel re-bootstraps a fresh application (and re-includes every
// module's start.php) per test method within the same PHP process, but PHP
// constants are process-global and never get undefined between tests. An
// unguarded define() here would emit "already defined" on the second and
// later test methods; FreeScout's modules.register_error handler swallows
// that error in a CLI/test context (empty($_POST)), which aborts the rest
// of this file — silently skipping the routes require below for every test
// after the first one in the process.
if (!defined('KAPSO_WHATSAPP_MODULE')) {
    define('KAPSO_WHATSAPP_MODULE', 'kapsowhatsapp');
}

if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
