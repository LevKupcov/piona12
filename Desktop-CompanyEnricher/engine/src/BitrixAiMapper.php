<?php

declare(strict_types=1);

/**
 * Сопоставление полей CRM через настроенный Bitrix AI (rest-метод из config).
 */
final class BitrixAiMapper
{
    private BitrixRestClient $restClient;

    public function __construct(?BitrixRestClient $restClient = null)
    {
        $this->restClient = $restClient ?? new BitrixRestClient();
    }

    public function mapFields(
        string $portalDomain,
        string $authToken,
        array $siteFacts,
        array $preMappedFields,
        array $config
    ): ?array {
        $method = trim((string)($config['ai']['bitrix_method'] ?? ''));
        if ($method === '') {
            return null;
        }

        $prompt = $this->buildPrompt($siteFacts, $preMappedFields);
        $response = $this->restClient->call(
            $portalDomain,
            $authToken,
            $method,
            [
                'prompt' => $prompt,
                'input' => $prompt,
            ]
        );

        $raw = $this->extractTextPayload($response);
        if ($raw === '') {
            return null;
        }

        $json = $this->extractJsonObject($raw);
        if ($json === null || !is_array($json)) {
            return null;
        }

        return $json;
    }

    private function buildPrompt(array $siteFacts, array $preMappedFields): string
    {
        $payload = [
            'siteFacts' => $siteFacts,
            'preMappedFields' => $preMappedFields,
            'instruction' => 'Return ONLY valid JSON object with CRM field keys (TITLE, WEB, EMAIL, PHONE, INDUSTRY, ADDRESS_CITY, COMMENTS, UF_CRM_*).',
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function extractTextPayload(array $response): string
    {
        $candidates = [
            $response['result'] ?? null,
            $response['answer'] ?? null,
            $response['data'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_array($candidate)) {
                $encoded = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) {
                    return $encoded;
                }
            }
        }

        $encodedResponse = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encodedResponse) ? $encodedResponse : '';
    }

    private function extractJsonObject(string $text): ?array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/su', $text, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
