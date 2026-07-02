<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\Client;
use CrmBpSearch\Bitrix24\RequestParser;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);

if ($auth === null) {
    json_response(['error' => 'Missing auth'], 401);
}

$templateId = (int) ($request['template_id'] ?? 0);
$entity = strtolower(trim((string) ($request['entity'] ?? 'deal')));
$documentId = (int) ($request['document_id'] ?? 0);
$conditionsRaw = $request['conditions'] ?? '[]';
$logic = strtoupper((string) ($request['logic'] ?? 'AND')) === 'OR' ? 'OR' : 'AND';

if ($templateId <= 0) {
    json_response(['error' => 'template_id required'], 400);
}

if ($documentId <= 0) {
    json_response(['error' => 'document_id required'], 400);
}

$conditions = json_decode(is_string($conditionsRaw) ? $conditionsRaw : json_encode($conditionsRaw), true);
if (!is_array($conditions) || $conditions === []) {
    json_response(['error' => 'At least one condition required'], 400);
}

$documentIdArr = match ($entity) {
    'lead' => ['crm', 'CCrmDocumentLead', 'LEAD_' . $documentId],
    'deal' => ['crm', 'CCrmDocumentDeal', 'DEAL_' . $documentId],
    'contact' => ['crm', 'CCrmDocumentContact', 'CONTACT_' . $documentId],
    'company' => ['crm', 'CCrmDocumentCompany', 'COMPANY_' . $documentId],
    default => ['crm', 'CCrmDocumentDeal', 'DEAL_' . $documentId],
};

$parameter1 = '';
foreach ($conditions as $cond) {
    if (!is_array($cond)) {
        continue;
    }
    if (strtoupper((string) ($cond['field'] ?? '')) === 'TITLE'
        && strtolower((string) ($cond['operator'] ?? '')) === 'contains') {
        $parameter1 = (string) ($cond['value'] ?? '');
        break;
    }
}

$parameters = [
    'Parameter1' => $parameter1,
    'Parameter2' => match ($entity) {
        'lead' => 'lead|Лид',
        'contact' => 'contact|Контакт',
        'company' => 'company|Компания',
        default => 'deal|Сделка',
    },
    'Parameter3' => json_encode($conditions, JSON_UNESCAPED_UNICODE),
];

try {
    $client = Client::fromAuth($auth);
    $result = $client->call('bizproc.workflow.start', [
        'TEMPLATE_ID' => $templateId,
        'DOCUMENT_ID' => $documentIdArr,
        'PARAMETERS' => $parameters,
    ]);

    json_response([
        'ok' => true,
        'workflow_id' => $result['result'] ?? $result,
        'parameters' => $parameters,
    ]);
} catch (\Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
