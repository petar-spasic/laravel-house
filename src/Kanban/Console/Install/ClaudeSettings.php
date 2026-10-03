<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console\Install;

use JsonException;
use PetarSpasic\LaravelHouse\Kanban\Guard\Guard;
use PetarSpasic\LaravelHouse\Kanban\Store\Git\Bootstrap;
use stdClass;

/**
 * Merges `.claude/settings.json`, which is committed: our hooks (identified by the `vendor/bin/kanban` /
 * `kanban-guard` path) are replaced in place and foreign hooks kept; `permissions.allow` gets the kanban CLI; commit/PR
 * attribution is turned off while commit-msg rejects Co-Authored-By trailers. This checkout's absolute rules (main's
 * `vendor/bin/kanban` and, unless `agents.shell` is host, `vendor/bin/kanban-exec`, the commands Guard routes card
 * agents through) go to the uncommitted `.claude/settings.local.json`, and leave `settings.json`.
 */
final class ClaudeSettings extends Step
{
    public const FILE = '.claude/settings.json';

    public const LOCAL = '.claude/settings.local.json';

    /** An absolute kanban or kanban-exec rule, any checkout's. */
    private const ABSOLUTE = "#^Bash\\('?/.*/vendor/bin/kanban(-exec)?'? \\*\\)$#";

    public const PERMISSION = 'Bash(vendor/bin/kanban *)';

    public const GUARD = 'vendor/petar-spasic/laravel-house/bin/kanban-guard';

    public function run(bool $dryRun = false, bool $force = false): array
    {
        return [$this->apply(self::FILE, $this->merge(...), $dryRun), ...$this->local($dryRun)];
    }

    /**
     * Only this checkout's `settings.local.json`: what `attach` gives a fresh clone, whose `settings.json` is committed.
     *
     * @return list<string>
     */
    public function local(bool $dryRun = false): array
    {
        return [$this->apply(self::LOCAL, $this->mergeLocal(...), $dryRun)];
    }

    private function apply(string $file, callable $merge, bool $dryRun): string
    {
        $current = $this->read($file);
        try {
            $settings = $this->decode($current);
        } catch (JsonException $e) {
            return 'skipped '.$file.': not valid JSON ('.$e->getMessage().'); fix it and run again';
        }
        $changes = $merge($settings);
        if ($changes === []) {
            return $file.' ok';
        }
        if ($dryRun) {
            return 'would update '.$file.': '.implode(', ', $changes);
        }
        $this->write($file, self::json($settings, $current));

        return ($current === null ? 'created ' : 'updated ').$file.': '.implode(', ', $changes);
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
        try {
            $local = $this->mergeLocal($this->decode($this->read(self::LOCAL)));
        } catch (JsonException $e) {
            $local = ['is not valid JSON: '.$e->getMessage()];
        }
        foreach ($local as $change) {
            $results[] = ['warn', self::LOCAL.' '.$change.' (run `vendor/bin/kanban doctor --fix`)'];
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

        $changes = [...$changes, ...self::allow($settings, [self::PERMISSION], drop: self::ABSOLUTE)];

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
     * Gives `settings.local.json` this checkout's absolute rules.
     *
     * @return list<string>
     */
    private function mergeLocal(stdClass $settings): array
    {
        $rules = ['Bash('.Guard::kanban(realpath($this->paths->main) ?: $this->paths->main).' *)'];
        if (($this->config['agents']['shell'] ?? null) !== 'host') {
            $rules[] = self::execPermission($this->paths->main);
        }

        return self::allow($settings, $rules);
    }

    /**
     * Adds $rules to `permissions.allow` and removes the other rules matching $drop.
     *
     * @param  list<string>  $rules
     * @return list<string>
     */
    private static function allow(stdClass $settings, array $rules, ?string $drop = null): array
    {
        $permissions = ($settings->permissions ?? null) instanceof stdClass ? $settings->permissions : new stdClass;
        $allow = is_array($permissions->allow ?? null) ? $permissions->allow : [];
        $changes = [];
        foreach ($allow as $i => $rule) {
            if ($drop !== null && is_string($rule) && ! in_array($rule, $rules, true) && preg_match($drop, $rule)) {
                unset($allow[$i]);
                $changes[] = 'permissions.allow -'.$rule;
            }
        }
        foreach ($rules as $rule) {
            if (! in_array($rule, $allow, true)) {
                $allow[] = $rule;
                $changes[] = 'permissions.allow '.$rule;
            }
        }
        if ($changes !== []) {
            $permissions->allow = array_values($allow);
            $settings->permissions = $permissions;
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
