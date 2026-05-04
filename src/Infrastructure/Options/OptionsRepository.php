<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Options;

/**
 * Single source of truth for all plugin settings.
 *
 * All settings live in one wp_options row keyed 'dexpress_settings'.
 * Dot-notation keys navigate nested arrays: 'api.username', 'sync.last_towns'.
 *
 * Usage pattern:
 *   $options->set('api.username', 'foo');
 *   $options->save();          // persists the whole array in one update_option call
 */
final class OptionsRepository
{
    private const OPTION_KEY = 'dexpress_settings';

    private array $data;

    public function __construct()
    {
        $this->data = (array) get_option(self::OPTION_KEY, []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->resolve($key);

        return $value !== null ? $value : $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->resolve($key);

        return is_string($value) ? $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->resolve($key);

        if ($value === null) {
            return $default;
        }

        return (bool) $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->resolve($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Sets a value at the given dot-notation key.
     * Intermediate array levels are created automatically.
     * Call save() to persist.
     */
    public function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $last  = array_pop($parts);
        $node  = &$this->data;

        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }

        $node[$last] = $value;
        unset($node);
    }

    /**
     * Persists the in-memory settings array to wp_options.
     *
     * Returns true if the value was updated, false if it was identical to the stored value.
     */
    public function save(): bool
    {
        return (bool) update_option(self::OPTION_KEY, $this->data);
    }

    /**
     * Returns the full settings array.
     * Intended only for SettingsSaveHandler which needs to merge partial tab data.
     */
    public function all(): array
    {
        return $this->data;
    }

    private function resolve(string $key): mixed
    {
        $parts   = explode('.', $key);
        $current = $this->data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }
}
