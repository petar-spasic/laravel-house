<?php

namespace PetarSpasic\Kanban\Console;

/**
 * Extension point of `kanban:install`: bind implementations and tag them `InstallStep::TAG` in a service
 * provider; each runs after the board part (Claude settings, agents, Boost, …), in tag order.
 */
interface InstallStep
{
    public const TAG = 'kanban.install';

    /** @return list<string> lines to print, one fact each */
    public function install(InstallCommand $command, bool $dryRun, bool $force): array;
}
