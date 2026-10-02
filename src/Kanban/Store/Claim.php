<?php

namespace PetarSpasic\Kanban\Store;

use PetarSpasic\Kanban\Support\Clock;

final class Claim
{
    public function __construct(public readonly string $by, public readonly ?string $session = null) {}

    /** `user@host` of this machine, with the actor's session. */
    public static function here(Actor $actor): self
    {
        $user = getenv('USER') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'user') : 'user');

        return new self($user.'@'.gethostname(), $actor->session);
    }

    /** @return array{by: string, session: string|null, at: string} */
    public function toArray(): array
    {
        return ['by' => $this->by, 'session' => $this->session, 'at' => Clock::now()];
    }
}
