<?php

declare(strict_types=1);

namespace CrmBpSearch\BpTemplate;

final class BpTemplateSearch
{
    public function __construct(
        private readonly BpIndexStorage $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function search(string $memberId, array $filters): array
    {
        $index = $this->storage->load($memberId);
        $templates = $index['templates'];
        $results = [];

        $category = trim((string) ($filters['category'] ?? ''));
        $entity = trim((string) ($filters['entity'] ?? ''));
        $autoExecute = $filters['auto_execute'] ?? '';
        $keyword = mb_strtolower(trim((string) ($filters['keyword'] ?? '')));
        $criteria = mb_strtolower(trim((string) ($filters['criteria'] ?? '')));
        $tag = mb_strtolower(trim((string) ($filters['tag'] ?? '')));

        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }

            if ($category !== '' && (string) ($template['category'] ?? '') !== $category) {
                continue;
            }

            if ($entity !== '' && (string) ($template['entity_code'] ?? '') !== $entity) {
                continue;
            }

            if ($autoExecute !== '' && (string) ($template['auto_execute'] ?? '') !== (string) $autoExecute) {
                continue;
            }

            if ($keyword !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($template['name'] ?? ''),
                    (string) ($template['keywords'] ?? ''),
                    (string) ($template['description'] ?? ''),
                ]));
                if (!str_contains($haystack, $keyword)) {
                    continue;
                }
            }

            if ($criteria !== '') {
                $criteriaList = array_map(
                    static fn ($c): string => mb_strtolower((string) $c),
                    is_array($template['criteria'] ?? null) ? $template['criteria'] : [],
                );
                $matched = false;
                foreach ($criteriaList as $item) {
                    if (str_contains($item, $criteria)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }

            if ($tag !== '') {
                $tags = array_map(
                    static fn ($t): string => mb_strtolower((string) $t),
                    is_array($template['tags'] ?? null) ? $template['tags'] : [],
                );
                if (!in_array($tag, $tags, true) && !str_contains(implode(' ', $tags), $tag)) {
                    continue;
                }
            }

            $results[] = $template;
        }

        usort($results, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $results;
    }
}
