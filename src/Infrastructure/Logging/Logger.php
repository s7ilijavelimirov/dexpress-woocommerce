<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Logging;

use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'none' => 99];

    public function __construct(private readonly OptionsRepository $options) {}

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function getLogDirectory(): string
    {
        return $this->logDir();
    }

    public function purgeOldLogs(): void
    {
        $days   = $this->options->getInt('logging.retention_days', 30);
        $logDir = $this->logDir();

        if (!is_dir($logDir)) {
            return;
        }

        $cutoff = time() - ($days * DAY_IN_SECONDS);
        $files  = glob($logDir . 'dexpress-*.log') ?: [];

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
    }

    private function log(string $level, string $message, array $context): void
    {
        $configured = $this->options->getString('logging.level', 'info');

        if ($configured === 'none') {
            return;
        }

        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$configured] ?? 3)) {
            return;
        }

        $logDir = $this->logDir();

        if (!is_dir($logDir)) {
            wp_mkdir_p($logDir);
            // Prevent direct web access to log files.
            file_put_contents($logDir . '.htaccess', "Options -Indexes\ndeny from all\n");
        }

        $file = $logDir . 'dexpress-' . gmdate('Y-m-d') . '.log';
        $ts   = current_time('Y-m-d H:i:s');
        $ctx  = empty($context) ? '' : ' ' . wp_json_encode($context);
        $line = "[{$ts}] [" . strtoupper($level) . "] {$message}{$ctx}" . PHP_EOL;

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    private function logDir(): string
    {
        return wp_upload_dir()['basedir'] . '/dexpress-logs/';
    }
}
