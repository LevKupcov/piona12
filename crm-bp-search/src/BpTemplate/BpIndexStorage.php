<?php

declare(strict_types=1);

namespace CrmBpSearch\BpTemplate;

final class BpIndexStorage
{
    private string $dataDir;

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? PROJECT_ROOT . '/data/bp-index';
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * @return array{templates: array<string, array<string, mixed>>, synced_at: ?string}
     */
    public function load(string $memberId): array
    {
        $file = $this->path($memberId);
        if (!is_file($file)) {
            return ['templates' => [], 'synced_at' => null];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return ['templates' => [], 'synced_at' => null];
        }

        return [
            'templates' => is_array($data['templates'] ?? null) ? $data['templates'] : [],
            'synced_at' => $data['synced_at'] ?? null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $templates
     */
    public function save(string $memberId, array $templates, ?string $syncedAt = null): void
    {
        $payload = [
            'member_id' => $memberId,
            'synced_at' => $syncedAt ?? date('c'),
            'templates' => $templates,
        ];

        file_put_contents(
            $this->path($memberId),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function upsertMeta(string $memberId, string $templateId, array $meta): void
    {
        $index = $this->load($memberId);
        $templates = $index['templates'];
        $existing = is_array($templates[$templateId] ?? null) ? $templates[$templateId] : [];

        $templates[$templateId] = array_merge($existing, $meta, [
            'updated_at' => date('c'),
        ]);

        $this->save($memberId, $templates, $index['synced_at']);
    }

    private function path(string $memberId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $memberId) ?: 'unknown';
        return $this->dataDir . '/' . $safe . '.json';
    }
}
