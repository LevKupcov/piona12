<?php

declare(strict_types=1);

namespace CrmBpSearch\Bitrix24;

final class RequestParser
{
    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $request = array_merge($_GET, $_POST);

        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $request = array_merge($request, $json);
            }
        }

        if (isset($request['auth']) && is_string($request['auth'])) {
            $decoded = json_decode($request['auth'], true);
            if (is_array($decoded)) {
                $request['auth'] = $decoded;
            }
        }

        if (isset($request['data']) && is_string($request['data'])) {
            $decoded = json_decode($request['data'], true);
            if (is_array($decoded)) {
                $request['data'] = $decoded;
            }
        }

        return $request;
    }

    /**
     * Нормализует auth из формата REST-приложения и локального приложения портала.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    public static function extractAuth(array $request): ?array
    {
        $auth = $request['auth'] ?? null;
        if (!is_array($auth)) {
            $auth = [];
        }

        if (!empty($request['AUTH_ID']) && empty($auth['access_token'])) {
            $auth['access_token'] = (string) $request['AUTH_ID'];
        }
        if (!empty($request['REFRESH_ID']) && empty($auth['refresh_token'])) {
            $auth['refresh_token'] = (string) $request['REFRESH_ID'];
        }
        if (!empty($request['AUTH_EXPIRES']) && empty($auth['expires_in'])) {
            $auth['expires_in'] = (string) $request['AUTH_EXPIRES'];
        }
        if (!empty($request['DOMAIN']) && empty($auth['domain'])) {
            $auth['domain'] = (string) $request['DOMAIN'];
        }
        if (!empty($request['member_id']) && empty($auth['member_id'])) {
            $auth['member_id'] = (string) $request['member_id'];
        }
        if (!empty($request['application_token']) && empty($auth['application_token'])) {
            $auth['application_token'] = (string) $request['application_token'];
        }
        if (!empty($request['APP_SID']) && empty($auth['application_token'])) {
            $auth['application_token'] = (string) $request['APP_SID'];
        }

        $accessToken = trim((string) ($auth['access_token'] ?? ''));
        if ($accessToken === '') {
            return null;
        }

        $auth['access_token'] = $accessToken;

        return $auth;
    }

    public static function event(array $request): string
    {
        return strtoupper(trim((string) ($request['event'] ?? '')));
    }
}
