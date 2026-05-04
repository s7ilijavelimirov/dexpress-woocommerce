<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

final class TransactionRunner
{
    public function __construct(private readonly \wpdb $wpdb) {}

    /**
     * Executes $callback inside a DB transaction.
     * Commits on success, rolls back on any Throwable, then re-throws.
     */
    public function run(callable $callback): mixed
    {
        $this->wpdb->query('START TRANSACTION');

        try {
            $result = $callback();
            $this->wpdb->query('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
