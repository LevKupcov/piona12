<?php

declare(strict_types=1);

namespace CrmBpSearch\Bitrix24;

final class TokenRefresher
{
    public function refresh(array $auth): array
    {
        $refreshToken = trim((string) ($auth['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new \RuntimeException('No refresh_token available');
        }

        $clientId = app_config('APP_CLIENT_ID', '');
        $clientSecret = app_config('APP_CLIENT_SECRET', '');
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('APP_CLIENT_ID and APP_CLIENT_SECRET required for token refresh');
        }

        $domain = $this->resolveDomain($auth);
        $endpoints = [
            'https://' . $domain . '/oauth/token/',
            'https://oauth.bitrix.info/oauth/token/',
        ];

        $lastError = 'refresh failed';
        foreach ($endpoints as $url) {
            try {
                return $this->request($url, [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ]);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function resolveDomain(array $auth): string
    {
        $domain = (string) ($auth['domain'] ?? '');
        if ($domain === '' && !empty($auth['client_endpoint'])) {
            $host = parse_url((string) $auth['client_endpoint'], PHP_URL_HOST);
            $domain = is_string($host) ? $host : '';
        }

        return rtrim(str_replace(['https://', 'http://'], '', $domain), '/');
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function request(string $url, array $params): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new \RuntimeException('Token refresh HTTP error: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid token refresh response');
        }

        if (isset($decoded['error'])) {
            throw new \RuntimeException('Token refresh error: ' . (string) ($decoded['error_description'] ?? $decoded['error']));
        }

        if (empty($decoded['access_token'])) {
            throw new \RuntimeException('Token refresh returned no access_token');
        }

        return $decoded;
    }
}
