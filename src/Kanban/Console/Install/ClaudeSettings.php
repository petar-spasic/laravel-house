<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use JsonException;
use PetarSpasic\LaravelHouse\Kanban\Guard\Guard;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use stdClass;

/**
 * Merges `.claude/settings.json`: our hooks (identified by the `vendor/bin/kanban` / `kanban-guard` path) are replaced
 * in place and foreign hooks kept; `permissions.allow` gets the kanban CLI and, unless `agents.shell` is host, this
 * checkout's absolute `vendor/bin/kanban-exec`, which Guard routes card agents' shells through; commit/PR attribution
 * is turned off while commit-msg rejects Co-Authored-By trailers.
 */
final class ClaudeSettings extends Step
{
    public const FILE = '.claude/settings.json';

    public const PERMISSION = 'Bash(vendor/bin/kanban *)';

    public const GUARD = 'vendor/petar-spasic/laravel-house/bin/kanban-guard';

    public function run(bool $dryRun = false, bool $force = false): array
    {
        $current = $this->read(self::FILE);
        try {
            $settings = $this->decode($current);
        } catch (JsonException $e) {
            return ['skipped '.self::FILE.': not valid JSON ('.$e->getMessage().'); fix it and run again'];
        }
        $changes = $this->merge($settings);
        if ($changes === []) {
            return [self::FILE.' ok'];
        }
        if ($dryRun) {
            return ['would update '.self::FILE.': '.implode(', ', $changes)];
        }
        $this->write(self::FILE, self::json($settings, $current));

        return [($current === null ? 'created ' : 'updated ').self::FILE.': '.implode(', ', $changes)];
    }

    public function check(): array
    {
        $current = $this->read(self::FILE);
        if ($current === null) {
            return [['fail', self::FILE.' missing (run `vendor/bin/kanban doctor --fix`)']];
        }
        try {
            $settings = $this->decode($current);
        } catch (JsonException $e) {
            return [['fail', self::FILE.' is not valid JSON: '.$e->getMessage()]];
        }
        $changes = $this->merge($settings);
        $hooks = array_values(array_filter($changes, fn (string $c) => str_starts_with($c, 'hooks.')));
        $results = [$hooks === []
            ? ['ok', self::FILE.' hooks']
            : ['fail', self::FILE.' hooks missing or outdated: '.implode(', ', $hooks).' (run `vendor/bin/kanban doctor --fix`)']];
        foreach (array_diff($changes, $hooks) as $change) {
            $results[] = ['warn', self::FILE.' '.$change.' not set'];
        }
        foreach (['vendor/bin/kanban', 'vendor/bin/kanban-exec', self::GUARD] as $handler) {
            $results[] = is_executable($this->path($handler))
                ? ['ok', "{$handler} executable"]
                : ['fail', "{$handler} missing or not executable (composer install)"];
        }

        return $results;
    }

    /** `Bash(<main>/vendor/bin/kanban-exec *)`: the command prefix Guard writes. */
    public static function execPermission(string $main): string
    {
        return 'Bash('.Guard::exec(realpath($main) ?: $main).' *)';
    }

    /** @throws JsonException */
    private function decode(?string $json): stdClass
    {
        if ($json === null || trim($json) === '') {
            return new stdClass;
        }
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (! $data instanceof stdClass) {
            throw new JsonException('the top level is not an object');
        }

        return $data;
    }

    /**
     * Applies our settings to $settings; returns what changed (empty = already current).
     *
     * @return list<string>
     */
    private function merge(stdClass $settings): array
    {
        $stub = json_decode(self::stub('claude/settings.hooks.json'), false, 512, JSON_THROW_ON_ERROR);
        $changes = [];

        $hooks = ($settings->hooks ?? null) instanceof stdClass ? $settings->hooks : new stdClass;
        foreach ($stub->hooks as $event => $ours) {
            $existing = is_array($hooks->{$event} ?? null) ? $hooks->{$event} : [];
            $merged = self::replaceOurs($existing, $ours);
            if ($merged != $existing) {
                $hooks->{$event} = $merged;
                $changes[] = "hooks.{$event}";
            }
        }
        $settings->hooks = $hooks;

        $permissions = ($settings->permissions ?? null) instanceof stdClass ? $settings->permissions : new stdClass;
        $allow = is_array($permissions->allow ?? null) ? $permissions->allow : [];
        $rules = [self::PERMISSION];
        if (($this->config['agents']['shell'] ?? null) !== 'host') {
            $rules[] = self::execPermission($this->paths->main);
        }
        foreach ($rules as $rule) {
            if (! in_array($rule, $allow, true)) {
                $allow[] = $rule;
                $permissions->allow = $allow;
                $settings->permissions = $permissions;
                $changes[] = 'permissions.allow '.$rule;
            }
        }

        if (Bootstrap::rejectsCoAuthored($this->config) && ($settings->attribution ?? null) !== false) {
            $attribution = ($settings->attribution ?? null) instanceof stdClass ? $settings->attribution : new stdClass;
            $before = clone $attribution;
            foreach ((array) $stub->attribution as $key => $value) {
                $attribution->{$key} = $value;
            }
            if ($attribution != $before || ! isset($settings->attribution)) {
                $settings->attribution = $attribution;
                $changes[] = 'attribution off';
            }
        }

        return $changes;
    }

    /**
     * Our handlers leave their groups (a group left empty goes); our groups take the place of the first one found.
     *
     * @param  list<mixed>  $groups
     * @param  list<stdClass>  $ours
     * @return list<mixed>
     */
    private static function replaceOurs(array $groups, array $ours): array
    {
        $kept = [];
        $at = null;
        foreach ($groups as $group) {
            $handlers = $group instanceof stdClass && is_array($group->hooks ?? null) ? $group->hooks : null;
            if ($handlers === null) {
                $kept[] = $group;

                continue;
            }
            $foreign = array_values(array_filter($handlers, fn (mixed $h) => ! self::ours($h)));
            if (count($foreign) === count($handlers)) {
                $kept[] = $group;

                continue;
            }
            $at ??= count($kept);
            if ($foreign !== []) {
                $group = clone $group;
                $group->hooks = $foreign;
                $kept[] = $group;
            }
        }
        array_splice($kept, $at ?? count($kept), 0, $ours);

        return $kept;
    }

    private static function ours(mixed $handler): bool
    {
        if (! $handler instanceof stdClass) {
            return false;
        }
        $text = (is_string($handler->command ?? null) ? $handler->command : '').' '
            .implode(' ', array_filter((array) ($handler->args ?? []), 'is_string'));

        return str_contains($text, 'vendor/bin/kanban') || str_contains($text, '/bin/kanban-guard');
    }
}
