<?php

declare(strict_types=1);

/**
 * Тонкая обёртка над Bitrix24 REST: один метод → JSON-ответ (ошибки через исключение).
 */
final class BitrixRestClient
{
    public function call(string $portalDomain, string $authToken, string $method, array $params = []): array
    {
        $cleanDomain = preg_replace('#^https?://#i', '', trim($portalDomain)) ?? '';
        $cleanDomain = trim($cleanDomain, "/ \t\n\r\0\x0B");
        if ($cleanDomain === '') {
            throw new RuntimeException('Invalid portal domain');
        }

        $baseUrl = sprintf('https://%s/rest/%s.json?auth=%s', $cleanDomain, $method, urlencode($authToken));
        $requestParams = $params;
        $requestParams['auth'] = $authToken;

        $ch = curl_init($baseUrl);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($requestParams),
            CURLOPT_TIMEOUT => 15,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Bitrix24 REST request failed: ' . $curlError);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid REST response: ' . $raw);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Bitrix24 REST HTTP error: ' . $httpCode);
        }

        if (isset($decoded['error'])) {
            throw new RuntimeException('Bitrix24 REST error: ' . $decoded['error']);
        }

        return $decoded;
    }
}
