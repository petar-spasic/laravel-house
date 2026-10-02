<?php

// PHPUnit puts forced <env> values in $_ENV and putenv() only; Laravel's Env reads $_SERVER first,
// where the container's process env would win. Mirror every forced value there.
require __DIR__.'/../vendor/autoload.php';

$config = collect(['phpunit.xml', 'phpunit.xml.dist'])->map(fn ($f) => __DIR__.'/../'.$f)->first(fn ($f) => is_file($f))
    ?? throw new RuntimeException('tests/bootstrap.php: no phpunit.xml or phpunit.xml.dist to mirror');

foreach (simplexml_load_file($config)->php->env ?? [] as $env) {
    if ((string) $env['force'] === 'true') {
        $name = (string) $env['name'];
        $value = (string) $env['value'];
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("$name=$value");
    }
}
# if:spa

// {{app}}_test has one user at a time. docker/e2e.sh holds this lock alone; test processes (parallel workers too)
// share it. In the checkout, so a run on the host and one in the container see each other. The handle stays in
// $GLOBALS, so the lock is held until the process exits.
$lockFile = __DIR__.'/../storage/framework/testing/db.lock';
is_dir(dirname($lockFile)) || mkdir(dirname($lockFile), 0775, true);
$GLOBALS['testDatabaseLock'] = fopen($lockFile, 'c');
if (! flock($GLOBALS['testDatabaseLock'], LOCK_SH | LOCK_NB)) {
    fwrite(STDERR, "The test database is in use by an e2e run (docker/e2e.sh); run the tests when it ends.\n");
    exit(1);
}
# endif
