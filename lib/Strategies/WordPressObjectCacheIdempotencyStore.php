<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Tasks\Interfaces\IdempotencyStore;
use PHPNomad\Tasks\Interfaces\IsIdempotent;
use PHPNomad\Tasks\Interfaces\Task;

final class WordPressObjectCacheIdempotencyStore implements IdempotencyStore
{
    public const GROUP  = 'phpnomad_idempotency';
    public const PREFIX = 'phpnomad';

    public function acquire(IsIdempotent $task, int $lockTtlSeconds): bool
    {
        $ttl = max(1, $lockTtlSeconds);
        $key = $this->lockKey($task);

        return wp_cache_add($key, 1, self::GROUP, $ttl);
    }

    public function markDone(IsIdempotent $task, int $doneTtlSeconds): void
    {
        $ttl = max(1, $doneTtlSeconds);
        $key = $this->doneKey($task);

        wp_cache_set($key, 1, self::GROUP, $ttl);
    }

    public function isDone(IsIdempotent $task): bool
    {
        $key = $this->doneKey($task);

        return wp_cache_get($key, self::GROUP) !== false;
    }

    public function release(IsIdempotent $task): void
    {
        wp_cache_delete($this->lockKey($task), self::GROUP);
    }

    private function lockKey(IsIdempotent $task): string
    {
        return $this->baseKey($task) . ':lock';
    }

    private function doneKey(IsIdempotent $task): string
    {
        return $this->baseKey($task) . ':done';
    }

    private function baseKey(IsIdempotent $task): string
    {
        /** @var Task $task */
        $taskId = $task::getId();

        // Stable + short: safe for cache backends.
        $hash = substr(hash('sha256', $taskId . '|' . $task->idempotencyKey()), 0, 40);

        return self::PREFIX . ':' . $taskId . ':' . $hash;
    }
}
