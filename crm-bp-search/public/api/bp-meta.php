<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\BpTemplate\BpIndexStorage;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);

if ($auth === null) {
    json_response(['error' => 'Missing auth'], 401);
    return;
}

$memberId = (string) ($auth['member_id'] ?? $auth['domain'] ?? 'portal');
$templateId = trim((string) ($request['template_id'] ?? ''));

if ($templateId === '') {
    json_response(['error' => 'template_id required'], 400);
    return;
}

$tagsRaw = trim((string) ($request['tags'] ?? ''));
$tags = $tagsRaw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));

$meta = [
    'category' => trim((string) ($request['category'] ?? '')),
    'keywords' => trim((string) ($request['keywords'] ?? '')),
    'description' => trim((string) ($request['description'] ?? '')),
    'tags' => $tags,
];

if ($meta['category'] !== '') {
    $meta['criteria'] = array_values(array_unique(array_merge(
        is_array($meta['tags']) ? array_map(static fn ($t) => 'Тег: ' . $t, $tags) : [],
        ['Категория: ' . $meta['category']],
    )));
}

try {
    (new BpIndexStorage())->upsertMeta($memberId, $templateId, array_filter($meta, static fn ($v) => $v !== '' && $v !== []));
    json_response(['ok' => true, 'template_id' => $templateId]);
} catch (\Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}
