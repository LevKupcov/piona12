<?php

declare(strict_types=1);

namespace CrmBpSearch\Bitrix24;

final class ActivityRegistrar
{
    public function __construct(
        private readonly Client $client,
        private readonly string $handlerUrl,
        private readonly ?string $placementUrl = null,
    ) {
    }

    public function register(): array
    {
        $response = $this->client->call('bizproc.activity.add', $this->buildPayload());
        return self::normalizeResult($response['result'] ?? null, 'bizproc.activity.add');
    }

    public function update(): array
    {
        $config = activity_config();
        $response = $this->client->call('bizproc.activity.update', [
            'CODE' => $config['CODE'],
            'FIELDS' => $this->buildPayload(excludeCode: true),
        ]);

        return self::normalizeResult($response['result'] ?? null, 'bizproc.activity.update');
    }

    public function delete(): array
    {
        $code = activity_config()['CODE'];
        $response = $this->client->call('bizproc.activity.delete', ['CODE' => $code]);
        return self::normalizeResult($response['result'] ?? null, 'bizproc.activity.delete');
    }

    public function listRegistered(): array
    {
        $response = $this->client->call('bizproc.activity.list');
        $result = $response['result'] ?? [];

        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(bool $excludeCode = false): array
    {
        $config = activity_config();
        $payload = [
            'HANDLER' => $this->handlerUrl,
            'AUTH_USER_ID' => $config['AUTH_USER_ID'],
            'USE_SUBSCRIPTION' => $config['USE_SUBSCRIPTION'],
            'NAME' => $config['NAME'],
            'DESCRIPTION' => $config['DESCRIPTION'],
            'PROPERTIES' => $config['PROPERTIES'],
            'RETURN_PROPERTIES' => $config['RETURN_PROPERTIES'],
        ];

        if (!$excludeCode) {
            $payload['CODE'] = $config['CODE'];
        }

        if (!empty($config['FILTER'])) {
            $payload['FILTER'] = $config['FILTER'];
        }

        $payload['USE_PLACEMENT'] = ($config['USE_PLACEMENT'] ?? 'N') === 'Y' ? 'Y' : 'N';

        if ($payload['USE_PLACEMENT'] === 'Y' && $this->placementUrl !== null && $this->placementUrl !== '') {
            $payload['PLACEMENT_HANDLER'] = $this->placementUrl;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeResult(mixed $result, string $method): array
    {
        if (is_array($result)) {
            return $result;
        }

        if ($result === true || $result === 1 || $result === 'true') {
            return ['success' => true, 'method' => $method];
        }

        if ($result === false || $result === null) {
            return ['success' => false, 'method' => $method];
        }

        return ['success' => true, 'method' => $method, 'value' => $result];
    }
}
