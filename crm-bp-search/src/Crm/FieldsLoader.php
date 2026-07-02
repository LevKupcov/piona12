<?php

declare(strict_types=1);

namespace CrmBpSearch\Crm;

use CrmBpSearch\Bitrix24\Client;

final class FieldsLoader
{
    private const LEGACY_METHODS = [
        'lead' => 'crm.lead.fields',
        'deal' => 'crm.deal.fields',
        'contact' => 'crm.contact.fields',
        'company' => 'crm.company.fields',
    ];

    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * @return list<array{code: string, title: string, type: string}>
     */
    public function load(string $entity, int $smartTypeId = 0): array
    {
        $raw = $this->fetchRaw($entity, $smartTypeId);
        $fields = [];

        foreach ($raw as $code => $meta) {
            if (!is_array($meta) || !is_string($code) || $code === '') {
                continue;
            }

            if ($this->isServiceField($code, $meta)) {
                continue;
            }

            $title = (string) ($meta['title'] ?? $meta['listLabel'] ?? $meta['formLabel'] ?? $code);
            $type = strtolower((string) ($meta['type'] ?? 'string'));

            $fields[] = [
                'code' => $code,
                'title' => $title,
                'type' => $type,
            ];
        }

        usort($fields, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRaw(string $entity, int $smartTypeId): array
    {
        if ($entity === 'smart') {
            if ($smartTypeId <= 0) {
                throw new \InvalidArgumentException('SMART_TYPE_ID is required');
            }

            $response = $this->client->call('crm.item.fields', [
                'entityTypeId' => $smartTypeId,
            ]);
        } else {
            $method = self::LEGACY_METHODS[$entity] ?? null;
            if ($method === null) {
                throw new \InvalidArgumentException('Unsupported entity: ' . $entity);
            }

            $response = $this->client->call($method);
        }

        $result = $response['result'] ?? [];
        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function isServiceField(string $code, array $meta): bool
    {
        if (str_starts_with($code, 'UF_CRM_') && ($meta['isDynamic'] ?? false)) {
            return false;
        }

        $skip = ['LINK', 'ORIGINATOR_ID', 'ORIGIN_ID', 'ORIGIN_VERSION', 'FACE_ID', 'UTM_SOURCE', 'UTM_MEDIUM', 'UTM_CAMPAIGN', 'UTM_CONTENT', 'UTM_TERM'];
        if (in_array($code, $skip, true)) {
            return true;
        }

        $type = strtolower((string) ($meta['type'] ?? ''));
        if (in_array($type, ['file', 'resourcebooking', 'employee', 'crm_multifield'], true)) {
            return true;
        }

        return false;
    }
}
