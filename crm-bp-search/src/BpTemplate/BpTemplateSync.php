<?php

declare(strict_types=1);

namespace CrmBpSearch\BpTemplate;

use CrmBpSearch\Bitrix24\Client;

final class BpTemplateSync
{
    private const SELECT = [
        'ID', 'NAME', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE',
        'AUTO_EXECUTE', 'PARAMETERS', 'VARIABLES', 'MODIFIED', 'USER_ID',
    ];

    public function __construct(
        private readonly Client $client,
        private readonly BpIndexStorage $storage,
    ) {
    }

    /**
     * @return array{count: int, synced_at: string}
     */
    public function sync(string $memberId): array
    {
        $rows = $this->client->callListAll('bizproc.workflow.template.list', [
            'select' => self::SELECT,
            'filter' => ['MODULE_ID' => 'crm'],
            'order' => ['NAME' => 'ASC'],
        ], 500);

        $existing = $this->storage->load($memberId)['templates'];
        $templates = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (string) ($row['ID'] ?? '');
            if ($id === '') {
                continue;
            }

            $documentType = $row['DOCUMENT_TYPE'] ?? [];
            $autoExecute = (int) ($row['AUTO_EXECUTE'] ?? 0);
            $parameters = $this->extractKeys($row['PARAMETERS'] ?? []);
            $variables = $this->extractKeys($row['VARIABLES'] ?? []);

            $autoCriteria = [BpCategoryMap::autoExecuteLabel($autoExecute)];
            $paramCriteria = array_map(
                static fn (string $key): string => 'Параметр: ' . $key,
                $parameters,
            );

            $merged = array_merge($autoCriteria, $paramCriteria);
            $prev = is_array($existing[$id] ?? null) ? $existing[$id] : [];

            $templates[$id] = array_merge($prev, [
                'id' => $id,
                'name' => (string) ($row['NAME'] ?? ''),
                'module_id' => (string) ($row['MODULE_ID'] ?? 'crm'),
                'entity' => (string) ($row['ENTITY'] ?? ''),
                'document_type' => $documentType,
                'entity_code' => BpCategoryMap::entityCodeFromDocumentType($documentType),
                'category' => $prev['category'] ?? BpCategoryMap::categoryFromDocumentType($documentType),
                'auto_execute' => $autoExecute,
                'auto_execute_label' => BpCategoryMap::autoExecuteLabel($autoExecute),
                'parameters' => $parameters,
                'variables' => $variables,
                'criteria' => array_values(array_unique(array_merge(
                    $merged,
                    is_array($prev['criteria'] ?? null) ? $prev['criteria'] : [],
                    is_array($prev['tags'] ?? null) ? $prev['tags'] : [],
                ))),
                'tags' => is_array($prev['tags'] ?? null) ? $prev['tags'] : [],
                'keywords' => (string) ($prev['keywords'] ?? ''),
                'description' => (string) ($prev['description'] ?? ''),
                'modified' => (string) ($row['MODIFIED'] ?? ''),
                'user_id' => (int) ($row['USER_ID'] ?? 0),
            ]);
        }

        $syncedAt = date('c');
        $this->storage->save($memberId, $templates, $syncedAt);

        return ['count' => count($templates), 'synced_at' => $syncedAt];
    }

    /**
     * @return list<string>
     */
    private function extractKeys(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $keys = [];
        foreach ($data as $key => $meta) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        sort($keys);
        return $keys;
    }
}
