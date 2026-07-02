<?php

declare(strict_types=1);

namespace CrmBpSearch\Crm;

use CrmBpSearch\Bitrix24\Client;

final class SearchService
{
    public function __construct(
        private readonly Client $client,
        private readonly FilterBuilder $filterBuilder = new FilterBuilder(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param list<string> $extraSelect
     * @return array{ids: list<int>, titles: list<string>, rows: list<array<string, mixed>>}
     */
    public function search(
        string $entity,
        array $conditions,
        string $logic,
        int $limit,
        int $smartTypeId = 0,
        array $extraSelect = [],
    ): array {
        $map = EntityMap::get($entity);
        $filter = $this->filterBuilder->build($conditions, $logic);

        $select = array_values(array_unique(array_merge(
            [$map['id_field'], $map['title_field']],
            $extraSelect,
        )));

        $params = [
            'filter' => $filter,
            'select' => $select,
            'order' => [$map['id_field'] => 'ASC'],
        ];

        if ($entity === 'smart') {
            if ($smartTypeId <= 0) {
                throw new \InvalidArgumentException('SMART_TYPE_ID is required for smart process search');
            }
            $params['entityTypeId'] = $smartTypeId;
        }

        $rows = $this->client->callListAll($map['method'], $params, $limit);
        $ids = [];
        $titles = [];
        $normalizedRows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $row[$map['id_field']] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            $intId = (int) $id;
            if (in_array($intId, $ids, true)) {
                continue;
            }

            $ids[] = $intId;
            $titles[] = trim((string) ($row[$map['title_field']] ?? ''));
            $normalizedRows[] = $row;
        }

        return [
            'ids' => $ids,
            'titles' => $titles,
            'rows' => $normalizedRows,
        ];
    }
}
