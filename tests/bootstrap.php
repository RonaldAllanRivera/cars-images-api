<?php

/*
|--------------------------------------------------------------------------
| PHPUnit bootstrap
|--------------------------------------------------------------------------
|
| The suite needs an APP_KEY: Laravel throws MissingAppKeyException the
| moment anything touches encryption, sessions, or cookies. Committing a
| literal key to `phpunit.xml` solves that, but puts a credential-shaped
| string in the repository — GitGuardian flags it, and a reader may copy
| it into a real environment, where it would be public from day one.
|
| So the key is generated here instead, per run, and never written to
| disk. Nothing key-shaped is committed, CI needs no secret, and a fresh
| clone runs the suite with no setup.
|
| An APP_KEY supplied by the environment always wins, so a CI job or a
| developer can still pin one deliberately.
|
*/

require __DIR__.'/../vendor/autoload.php';

$suppliedKey = $_SERVER['APP_KEY'] ?? $_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: null;

if (! is_string($suppliedKey) || trim($suppliedKey) === '') {
    // 32 random bytes: the length AES-256-CBC requires.
    $key = 'base64:'.base64_encode(random_bytes(32));

    // Cover every adapter Laravel's Env repository reads from.
    putenv("APP_KEY={$key}");
    $_ENV['APP_KEY'] = $key;
    $_SERVER['APP_KEY'] = $key;
}
