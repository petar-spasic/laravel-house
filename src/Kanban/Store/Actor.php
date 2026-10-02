<?php

namespace PetarSpasic\Kanban\Store;

use PetarSpasic\Kanban\Store\Exceptions\Invalid;

final class Actor
{
    public const ROLES = ['owner', 'main', 'worker', 'evaluator', 'hook', 'import'];

    public function __construct(public readonly string $role, public readonly ?string $session = null)
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new Invalid("unknown actor '{$role}'");
        }
    }

    /** `main` when KANBAN_SESSION is set (the orchestrating session), otherwise `owner`. */
    public static function fromEnvironment(): self
    {
        $session = getenv('KANBAN_SESSION');

        return is_string($session) && $session !== '' ? new self('main', $session) : new self('owner');
    }

    public static function owner(): self
    {
        return new self('owner');
    }

    public function isMain(): bool
    {
        return $this->role === 'main';
    }

    public function canForce(): bool
    {
        return $this->role === 'main';
    }
}
