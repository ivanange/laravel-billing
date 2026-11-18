<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services\Concerns;

use Illuminate\Database\Connection;

/**
 * Billing events announce things that happened: inside a caller's
 * transaction they wait for the outermost commit and never fire if it rolls
 * back. With no transaction open they fire at once.
 *
 * The using class has a `ConnectionInterface $db` property.
 */
trait DispatchesAfterCommit
{
    private function afterCommit(callable $callback): void
    {
        $this->db instanceof Connection ? $this->db->afterCommit($callback) : $callback();
    }
}
