<?php

// PHPUnit puts forced <env> values in $_ENV and putenv() only; Laravel's Env reads $_SERVER first,
// where the container's process env would win. Mirror every forced value there.
require __DIR__.'/../vendor/autoload.php';

$config = collect(['phpunit.xml', 'phpunit.xml.dist'])->map(fn ($f) => __DIR__.'/../'.$f)->first('is_file')
    ?? throw new RuntimeException('tests/bootstrap.php: no phpunit.xml or phpunit.xml.dist to mirror');

foreach (simplexml_load_file($config)->php->env ?? [] as $env) {
    if ((string) $env['force'] === 'true') {
        $name = (string) $env['name'];
        $value = (string) $env['value'];
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("$name=$value");
    }
}
