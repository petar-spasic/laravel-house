<?php

namespace PetarSpasic\LaravelHouse\Kanban\Upstream;

use PetarSpasic\LaravelHouse\Kanban\Protocol\Staged;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;

/**
 * Findings about the house package that agents flag with `--upstream`: an `upstream` entry in the card's log, pending
 * until an `upstream_filed` or `upstream_dismissed` entry names it.
 */
final class Findings
{
    public const BODY = 4000;

    public const REPO = 'petar-spasic/laravel-house';

    /** @param  array<string, mixed>  $config  the `kanban` config */
    public static function enabled(array $config): bool
    {
        return filter_var($config['upstream']['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * `HOST/OWNER/REPO`: without a host gh would use its default one, which may be an enterprise server.
     *
     * @param  array<string, mixed>  $config
     */
    public static function repo(array $config): string
    {
        $repo = trim((string) ($config['upstream']['repo'] ?? self::REPO), '/');

        return substr_count($repo, '/') === 1 ? 'github.com/'.$repo : $repo;
    }

    /** @param  array<string, mixed>  $config */
    public static function host(array $config): string
    {
        return strtok(self::repo($config), '/');
    }

    /**
     * `Title — body` → {title, body}, scrubbed.
     *
     * @param  list<string>  $texts
     * @return list<array{title: string, body: string}>
     */
    public static function stage(array $texts, Scrubber $scrubber, string $what = '--upstream'): array
    {
        return array_map(function (string $text) use ($scrubber, $what) {
            [$title, $body] = Staged::split($text);
            if ($title === '' || mb_strlen($title) > 120) {
                throw new Invalid("{$what}: the title must be 1-120 characters (\"Title — body\")");
            }
            if (mb_strlen($body) > self::BODY) {
                throw new Invalid("{$what}: the body must be at most ".self::BODY.' characters');
            }
            $scrubber->check($title."\n".$body, $what);

            return ['title' => $title, 'body' => $body];
        }, array_values($texts));
    }

    /**
     * @return list<array{card: Card, entry: array<string, mixed>}>
     */
    public static function pending(Snapshot $snapshot): array
    {
        $out = [];
        foreach ($snapshot->cards as $card) {
            $settled = self::settled($card);
            foreach ($card->log() as $entry) {
                if (($entry['event'] ?? null) === 'upstream' && ! isset($settled[$entry['id']])) {
                    $out[] = ['card' => $card, 'entry' => $entry];
                }
            }
        }

        return $out;
    }

    /** @return array<string, true> ids of the card's findings already filed or dismissed */
    public static function settled(Card $card): array
    {
        $settled = [];
        foreach ($card->log() as $entry) {
            if (in_array($entry['event'] ?? null, ['upstream_filed', 'upstream_dismissed'], true) && is_string($entry['finding'] ?? null)) {
                $settled[$entry['finding']] = true;
            }
        }

        return $settled;
    }

    /** The words of a title worth searching open issues for. */
    public static function query(string $title): string
    {
        $words = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice(array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) >= 4))), 0, 6));
    }
}
