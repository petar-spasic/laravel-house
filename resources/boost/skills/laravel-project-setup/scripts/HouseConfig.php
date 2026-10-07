<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Setup;

use InvalidArgumentException;

/**
 * A project's `config/house.php`, read and checked one way for both engines that render the house rules: install.php
 * (the layer `CLAUDE.md` spans and `.ai/`) and the package's `@houserules` Blade directive (the root rules Boost
 * renders). Plain PHP: install.php runs before the package is installed, and the directive reads the file itself, so a
 * cached config never makes the two disagree. Composer's classmap loads it in a project; install.php requires it.
 */
final class HouseConfig
{
    /** `auth-pages` is a state, not a choice: the htmx auth pages are built and Fortify's views are on. */
    public const MODULES = ['htmx', 'islands', 'spa', 'reverb', 'tenancy', 'auth-pages'];

    /** The values `config/house.php` records: the placeholders of the house files. */
    public const VARS = ['app', 'laravel_version', 'php_version', 'pest_version'];

    /**
     * @param  array<string, string>  $vars
     * @param  list<string>  $modules
     * @param  list<string>  $overrides  paths from the repo root
     */
    public function __construct(public readonly array $vars, public readonly array $modules, public readonly array $overrides) {}

    /** @var array<string, self> each file read once while it stays the same: a render checks it at every gate */
    private static array $read = [];

    /** @throws InvalidArgumentException saying what is wrong with the file */
    public static function read(string $repo): self
    {
        $file = "{$repo}/config/house.php";
        is_file($file) || throw new InvalidArgumentException('no config/house.php: this project is not on the rendered house rules yet (laravel-project-setup, references/adopt.md)');
        clearstatcache(true, $file);

        return self::$read[$file.':'.filemtime($file).':'.filesize($file)] ??= self::parse($file);
    }

    private static function parse(string $file): self
    {
        try {
            $house = (static fn (string $path) => require $path)($file);
        } catch (\Throwable $e) {
            // a syntax slip, or env() outside the app: plain values only
            throw new InvalidArgumentException("config/house.php cannot be read: {$e->getMessage()} (plain values only)");
        }
        is_array($house) || throw new InvalidArgumentException('config/house.php must return an array');
        $vars = [];
        foreach (self::VARS as $key) {
            $value = $house[$key] ?? null;
            // a float would lose digits: 8.10 is 8.1
            is_string($value) || is_int($value)
                || throw new InvalidArgumentException("config/house.php: {$key} ".($value === null ? 'is missing' : 'must be a quoted string'));
            $vars[$key] = (string) $value;
        }
        preg_match('/^[a-z][a-z0-9]*$/', $vars['app'])
            || throw new InvalidArgumentException('config/house.php: app must be lowercase letters and digits (it names the test database and the dev accounts\' email domain)');
        $modules = $house['modules'] ?? [];
        $overrides = $house['overrides'] ?? [];
        foreach (['modules' => $modules, 'overrides' => $overrides] as $key => $list) {
            is_array($list) && array_is_list($list) && array_filter($list, 'is_string') === $list
                || throw new InvalidArgumentException("config/house.php: {$key} must be a list of strings");
        }
        ($why = self::refusal($modules)) === null || throw new InvalidArgumentException("config/house.php: {$why}");

        return new self($vars, $modules, array_map(fn (string $path) => ltrim(preg_replace('#^(\./)+#', '', $path), '/'), $overrides));
    }

    /** @param list<string> $modules  why the house rules cannot be rendered for these modules; null when they can */
    public static function refusal(array $modules): ?string
    {
        if ($unknown = array_diff($modules, self::MODULES)) {
            return 'unknown module(s): '.implode(', ', $unknown).' — known: '.implode(', ', self::MODULES);
        }
        if (in_array('spa', $modules, true) && array_intersect(['htmx', 'islands'], $modules)) {
            return 'spa excludes htmx and islands: its pages are the SvelteKit app in frontend/, Laravel renders none';
        }
        foreach (['islands', 'auth-pages'] as $module) {
            if (in_array($module, $modules, true) && ! in_array('htmx', $modules, true)) {
                return "{$module} requires htmx: drop it from the modules when htmx goes";
            }
        }

        return null;
    }

    /**
     * Whether a `@houserules` gate holds for these modules: no gate holds in every house project, `'a|b'` when one of
     * those modules is on.
     *
     * @param  list<string>  $modules
     */
    public static function holds(?string $gate, array $modules): bool
    {
        return $gate === null || array_intersect(explode('|', $gate), $modules) !== [];
    }
}
