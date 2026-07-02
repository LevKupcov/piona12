<?php

declare(strict_types=1);

namespace CrmBpSearch\Bizproc;

use CrmBpSearch\Bitrix24\Client;
use CrmBpSearch\Crm\ConditionsResolver;
use CrmBpSearch\Crm\CrmUrlBuilder;
use CrmBpSearch\Crm\SearchService;

final class Handler
{
    public function handle(array $request): void
    {
        $auth = \CrmBpSearch\Bitrix24\RequestParser::extractAuth($request);
        if ($auth === null) {
            http_response_code(400);
            echo 'Missing auth';
            return;
        }

        $eventToken = (string) ($request['event_token'] ?? '');
        if ($eventToken === '') {
            http_response_code(400);
            echo 'Missing event_token';
            return;
        }

        $properties = $request['properties'] ?? $request['PROPERTY_VALUES'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        $client = Client::fromAuth($auth);
        $returnValues = [
            'FOUND_IDS' => [],
            'FOUND_COUNT' => 0,
            'FOUND_TITLES' => [],
            'FOUND_LINKS' => [],
            'FIRST_FOUND_ID' => 0,
            'FIRST_FOUND_LINK' => '',
            'FIRST_FOUND_TITLE' => '',
            'FOUND_SUMMARY' => '',
            'FOUND_ITEMS' => '',
            'ERROR_MESSAGE' => '',
        ];

        try {
            $entity = CrmUrlBuilder::normalizeEntity((string) ($properties['ENTITY'] ?? 'deal'));
            $logic = (string) ($properties['LOGIC'] ?? 'AND');
            $limit = max(1, min(500, (int) ($properties['LIMIT'] ?? 50)));
            $smartTypeId = (int) ($properties['SMART_TYPE_ID'] ?? 0);
            $conditions = (new ConditionsResolver())->resolve($properties);
            $extraSelect = $this->parseSelectFields((string) ($properties['SELECT_FIELDS'] ?? ''));

            $result = (new SearchService($client))->search(
                $entity,
                $conditions,
                $logic,
                $limit,
                $smartTypeId,
                $extraSelect,
            );

            $returnValues['FOUND_IDS'] = $result['ids'];
            $returnValues['FOUND_COUNT'] = count($result['ids']);
            $returnValues['FOUND_TITLES'] = $result['titles'];
            $returnValues['FIRST_FOUND_ID'] = $result['ids'][0] ?? 0;
            $returnValues['FIRST_FOUND_TITLE'] = $result['titles'][0] ?? '';

            $domain = (string) ($auth['domain'] ?? '');
            if ($domain === '' && !empty($auth['client_endpoint'])) {
                $host = parse_url((string) $auth['client_endpoint'], PHP_URL_HOST);
                $domain = is_string($host) ? $host : '';
            }

            $text = CrmUrlBuilder::buildResultText(
                $domain,
                $entity,
                $result['ids'],
                $result['titles'],
                $smartTypeId,
            );
            $returnValues['FOUND_LINKS'] = $text['links'];
            $returnValues['FOUND_SUMMARY'] = $text['summary'];
            $returnValues['FIRST_FOUND_LINK'] = $text['links'][0] ?? '';

            $returnValues['FOUND_ITEMS'] = json_encode(
                $result['rows'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable $e) {
            $returnValues['ERROR_MESSAGE'] = $e->getMessage();
        }

        $client->call('bizproc.event.send', [
            'event_token' => $eventToken,
            'return_values' => $returnValues,
        ]);

        echo 'OK';
    }

    /**
     * @return list<string>
     */
    private function parseSelectFields(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $fields = [];

        foreach ($parts as $part) {
            $field = trim($part);
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
