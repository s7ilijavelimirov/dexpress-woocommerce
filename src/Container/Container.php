<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Container;

/**
 * Lightweight PSR-11-compatible DI container.
 *
 * Supports:
 * - bind(id, factory): register a factory closure; called fresh each time
 * - singleton(id, factory): register a singleton; factory called once, result cached
 * - instance(id, value): store an already-constructed value
 * - get(id): resolve a binding; throws NotFoundException if not registered
 * - has(id): check whether a binding exists
 */
final class Container
{
    /** @var array<string, callable> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $singletons = [];

    public function bind(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
        $this->singletons[$id] = true;
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->bindings[$id])) {
            throw new NotFoundException("Service not found in container: {$id}");
        }

        $resolved = ($this->bindings[$id])($this);

        if (isset($this->singletons[$id])) {
            $this->instances[$id] = $resolved;
        }

        return $resolved;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }
}
