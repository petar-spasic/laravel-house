<?php

namespace PetarSpasic\LaravelHouse\Kanban\Upstream;

use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Support\DotEnv;
use PetarSpasic\LaravelHouse\Kanban\Support\Git;
use PetarSpasic\LaravelHouse\Kanban\Support\Paths;

/**
 * Refuses text bound for the public package repository when it names this project or machine. It names the kind of
 * match, never the text. Only project-unique tokens count: framework and package vocabulary, and words under 4
 * characters, are allowed, so a stock `APP_NAME=Laravel` refuses nothing.
 */
final class Scrubber
{
    private const ALLOW = ['laravel', 'laravel/laravel', 'laravel-house', 'petar-spasic/laravel-house', 'app', 'acme', 'localhost'];

    /** RFC 5737 documentation ranges and loopback: [network, prefix length]. */
    private const ALLOWED_NETWORKS = [['192.0.2.0', 24], ['198.51.100.0', 24], ['203.0.113.0', 24], ['127.0.0.0', 8]];

    /** @var array<string, list<string>> kind => regexes */
    private array $patterns = [];

    /**
     * @param  array<string, list<string|null>>  $terms  kind => project values (a value is matched whole, its words joined by any of space . _ -)
     * @param  list<string>  $allow  more allowed vocabulary (package names)
     */
    public function __construct(string $key, array $terms, array $allow = [])
    {
        $allowed = array_flip(array_map('mb_strtolower', [...self::ALLOW, ...$allow]));
        $this->patterns['a card id'] = ['/(?<![A-Za-z0-9])'.preg_quote($key, '/').'-[0-9A-HJKMNP-TV-Z]{4,12}(?![A-Za-z0-9])/i'];
        if (strlen($key) >= 4 && ! isset($allowed[mb_strtolower($key)])) {
            $this->patterns['the board key'] = ['/(?<![A-Za-z0-9])'.preg_quote($key, '/').'(?![A-Za-z0-9])/'];
        }
        foreach ($terms as $kind => $values) {
            foreach ($values as $value) {
                $value = trim((string) $value);
                $words = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (mb_strlen($value) < 4 || isset($allowed[mb_strtolower($value)]) || $words === []) {
                    continue;
                }
                $this->patterns[$kind][] = '/(?<![\p{L}\p{N}])'.implode('[\s._-]*', array_map(fn (string $w) => preg_quote($w, '/'), $words)).'(?![\p{L}\p{N}])/iu';
            }
        }
        $this->patterns['an email address'] = ['/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/'];
        $this->patterns['a home path'] = ['#/home/|/Users/|[A-Za-z]:\\\\Users\\\\|(?<![\w.])~/#i'];
    }

    /** The scrubber of the project at $paths: its .env, composer files, git remote and user, checkout, and this machine. */
    public static function forProject(Paths $paths, string $key, string $remote = 'origin'): self
    {
        $env = DotEnv::parse($paths->main.'/.env');
        $composer = self::json($paths->main.'/composer.json');
        $name = is_string($composer['name'] ?? null) ? $composer['name'] : null;
        $git = new Git($paths->main);
        $remoteUrl = (string) $git->line(['config', '--get', "remote.{$remote}.url"]);
        $owner = preg_match('#^(?:[a-z][a-z0-9+.-]*://[^/]+/|[^@/\s]+@[^:/\s]+:)(?:.*/)?([^/]+)/([^/]+?)(?:\.git)?/?$#i', $remoteUrl, $m) === 1 ? [$m[1], $m[2]] : [];

        return new self($key, [
            'APP_NAME' => [$env['APP_NAME'] ?? null],
            'the APP_URL host' => [self::host($env['APP_URL'] ?? null)],
            'the LOCAL_APP_URL host' => [self::host($env['LOCAL_APP_URL'] ?? null)],
            'the composer name' => $name === null ? [] : [$name, ...explode('/', $name)],
            'the git remote' => $owner,
            'the checkout directory' => [basename($paths->main)],
            'the hostname' => [gethostname() ?: null],
            'the git user' => [$git->line(['config', '--get', 'user.name'])],
        ], self::packages($paths->main));
    }

    /**
     * The kinds of project specifics in $text, empty when it is clean.
     *
     * @return list<string>
     */
    public function kinds(string $text): array
    {
        $kinds = [];
        foreach ($this->patterns as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $kinds[] = $kind;
                    break;
                }
            }
        }
        if ($this->foreignAddress($text)) {
            $kinds[] = 'an IPv4 address outside RFC 5737';
        }

        return $kinds;
    }

    public function check(string $text, string $what): void
    {
        if (($kinds = $this->kinds($text)) !== []) {
            throw new Invalid("{$what} names ".implode(', ', $kinds).': restate it in generic terms');
        }
    }

    private function foreignAddress(string $text): bool
    {
        preg_match_all('/(?<![\d.])\d{1,3}(?:\.\d{1,3}){3}(?!\.?\d)/', $text, $m);
        foreach ($m[0] as $candidate) {
            $ip = ip2long($candidate);
            if ($ip === false) {
                continue;
            }
            $documented = false;
            foreach (self::ALLOWED_NETWORKS as [$network, $bits]) {
                $mask = -1 << (32 - $bits) & 0xFFFFFFFF;
                $documented = $documented || ($ip & $mask) === (ip2long($network) & $mask);
            }
            if (! $documented) {
                return true;
            }
        }

        return false;
    }

    /** A URL's host, unless it is an IP literal (the address rule covers those). */
    private static function host(?string $url): ?string
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false ? $host : null;
    }

    /**
     * Composer and npm package names in the lockfiles, and their vendor and package parts.
     *
     * @return list<string>
     */
    private static function packages(string $root): array
    {
        $lock = self::json($root.'/composer.lock');
        $names = array_column([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])], 'name');
        $npm = self::json($root.'/package-lock.json');
        foreach ([...array_keys((array) ($npm['packages'] ?? [])), ...array_keys((array) ($npm['dependencies'] ?? []))] as $path) {
            $name = preg_replace('#^.*node_modules/#', '', (string) $path);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        $out = [];
        foreach (array_filter($names, 'is_string') as $name) {
            array_push($out, $name, ...explode('/', ltrim($name, '@')));
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }
}
