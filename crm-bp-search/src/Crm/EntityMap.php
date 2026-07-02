<?php

declare(strict_types=1);

namespace CrmBpSearch\Crm;

final class EntityMap
{
    /** @var array<string, array{method: string, id_field: string, title_field: string}> */
    private const MAP = [
        'lead' => ['method' => 'crm.lead.list', 'id_field' => 'ID', 'title_field' => 'TITLE'],
        'deal' => ['method' => 'crm.deal.list', 'id_field' => 'ID', 'title_field' => 'TITLE'],
        'contact' => ['method' => 'crm.contact.list', 'id_field' => 'ID', 'title_field' => 'NAME'],
        'company' => ['method' => 'crm.company.list', 'id_field' => 'ID', 'title_field' => 'TITLE'],
        'smart' => ['method' => 'crm.item.list', 'id_field' => 'id', 'title_field' => 'title'],
    ];

    public static function isSupported(string $entity): bool
    {
        return isset(self::MAP[$entity]);
    }

    /**
     * @return array{method: string, id_field: string, title_field: string}
     */
    public static function get(string $entity): array
    {
        if (!self::isSupported($entity)) {
            throw new \InvalidArgumentException('Unsupported entity: ' . $entity);
        }

        return self::MAP[$entity];
    }
}
