<?php

declare(strict_types=1);

namespace CrmBpSearch\Bitrix24;

final class PortalStorage
{
    private string $dataDir;

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? PROJECT_ROOT . '/data/portals';
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function save(string $memberId, array $auth, array $extra = []): void
    {
        $payload = array_merge($extra, [
            'member_id' => $memberId,
            'auth' => $auth,
            'updated_at' => date('c'),
        ]);

        file_put_contents(
            $this->path($memberId),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX,
        );
    }

    public function load(string $memberId): ?array
    {
        $file = $this->path($memberId);
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function delete(string $memberId): void
    {
        $file = $this->path($memberId);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function path(string $memberId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $memberId) ?: 'unknown';
        return $this->dataDir . '/' . $safe . '.json';
    }
}
