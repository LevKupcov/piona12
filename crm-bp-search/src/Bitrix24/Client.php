<?php

declare(strict_types=1);

namespace CrmBpSearch\Bitrix24;

final class Client
{
    private ?array $authContext = null;

    public function __construct(
        private readonly string $domain,
        private string $accessToken,
        private readonly ?PortalStorage $storage = null,
        private readonly ?string $memberId = null,
    ) {
    }

    public static function fromAuth(array $auth, ?PortalStorage $storage = null): self
    {
        $domain = (string) ($auth['domain'] ?? '');
        if ($domain === '' && !empty($auth['client_endpoint'])) {
            $host = parse_url((string) $auth['client_endpoint'], PHP_URL_HOST);
            $domain = is_string($host) ? $host : '';
        }

        $domain = rtrim(str_replace(['https://', 'http://'], '', $domain), '/');
        if (str_contains($domain, '/')) {
            $parsed = parse_url('https://' . ltrim($domain, '/'), PHP_URL_HOST);
            $domain = is_string($parsed) ? $parsed : $domain;
        }

        if ($domain === '') {
            throw new \InvalidArgumentException('Cannot detect portal domain from auth');
        }

        $client = new self(
            $domain,
            (string) ($auth['access_token'] ?? ''),
            $storage,
            (string) ($auth['member_id'] ?? ''),
        );
        $client->authContext = $auth;

        return $client;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        try {
            return $this->request($method, $params);
        } catch (\RuntimeException $e) {
            if ($this->canRefresh($e)) {
                $this->refreshAccessToken();
                return $this->request($method, $params);
            }

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callListAll(string $method, array $params, int $maxItems): array
    {
        $items = [];
        $start = 0;

        do {
            $pageParams = $params;
            if ($start > 0) {
                $pageParams['start'] = $start;
            }

            $response = $this->call($method, $pageParams);
            $chunk = $response['result'] ?? [];
            if (!is_array($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
                if (count($items) >= $maxItems) {
                    return $items;
                }
            }

            $start = isset($response['next']) ? (int) $response['next'] : 0;
        } while ($start > 0 && count($items) < $maxItems);

        return $items;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(string $method, array $params): array
    {
        $url = 'https://' . $this->domain . '/rest/' . $method . '.json';
        $params['auth'] = $this->accessToken;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new \RuntimeException('REST request failed: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid REST response');
        }

        if (isset($decoded['error'])) {
            $code = (string) $decoded['error'];
            $desc = (string) ($decoded['error_description'] ?? $code);
            throw new \RuntimeException('Bitrix REST error [' . $code . ']: ' . $desc);
        }

        return $decoded;
    }

    private function canRefresh(\RuntimeException $e): bool
    {
        if ($this->authContext === null) {
            return false;
        }

        $message = $e->getMessage();
        return str_contains($message, 'expired')
            || str_contains($message, 'invalid_token')
            || str_contains($message, 'ERROR_OAUTH');
    }

    private function refreshAccessToken(): void
    {
        if ($this->authContext === null) {
            throw new \RuntimeException('Cannot refresh token without auth context');
        }

        $refresher = new TokenRefresher();
        $newTokens = $refresher->refresh($this->authContext);

        $this->accessToken = (string) $newTokens['access_token'];
        $this->authContext['access_token'] = $this->accessToken;

        if (!empty($newTokens['refresh_token'])) {
            $this->authContext['refresh_token'] = (string) $newTokens['refresh_token'];
        }

        if ($this->storage !== null && $this->memberId !== null && $this->memberId !== '') {
            $this->storage->save($this->memberId, $this->authContext);
        }
    }
}
