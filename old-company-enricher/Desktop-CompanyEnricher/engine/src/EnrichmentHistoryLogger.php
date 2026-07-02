<?php

declare(strict_types=1);

/** Дозапись строк JSON (jsonl) для истории обогащений — один объект на строку. */
final class EnrichmentHistoryLogger
{
    private string $logFilePath;

    public function __construct(string $logFilePath)
    {
        $this->logFilePath = $logFilePath;
    }

    public function write(array $entry): void
    {
        $directory = dirname($this->logFilePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }

        file_put_contents($this->logFilePath, $line . PHP_EOL, FILE_APPEND);
    }
}
