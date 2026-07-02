<?php

declare(strict_types=1);

namespace CrmBpSearch\Install;

use CrmBpSearch\Bitrix24\ActivityRegistrar;
use CrmBpSearch\Bitrix24\Client;
use CrmBpSearch\Bitrix24\PortalStorage;
use CrmBpSearch\Bitrix24\RequestParser;

final class AppInstaller
{
    public function __construct(
        private readonly PortalStorage $storage,
        private readonly string $handlerUrl,
        private readonly string $placementUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     */
    public function processEvent(array $request): void
    {
        $event = RequestParser::event($request);
        $auth = RequestParser::extractAuth($request);

        if ($auth === null) {
            $this->respondWithoutAuth($event, $request);
            return;
        }

        $memberId = (string) ($auth['member_id'] ?? $auth['domain'] ?? 'portal');

        if ($event === 'ONAPPUNINSTALL') {
            $this->uninstall($memberId, $auth);
            json_response(['status' => 'uninstalled']);
            return;
        }

        $this->installOrEnsureActivity($memberId, $auth, $event);
    }

    /**
     * @param array<string, mixed> $auth
     * @return array{status: string, error: string|null}
     */
    public function ensureActivityForAuth(array $auth): array
    {
        $memberId = (string) ($auth['member_id'] ?? $auth['domain'] ?? 'portal');

        $this->storage->save($memberId, $auth, [
            'handler_url' => $this->handlerUrl,
            'placement_url' => $this->placementUrl,
        ]);

        $client = Client::fromAuth($auth, $this->storage);
        $registrar = new ActivityRegistrar($client, $this->handlerUrl, $this->placementUrl);

        try {
            $registrar->update();
            return ['status' => 'updated', 'error' => null];
        } catch (\Throwable) {
            // The activity may not exist yet on this portal.
        }

        try {
            $registrar->register();
            return ['status' => 'registered', 'error' => null];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function installOrEnsureActivity(string $memberId, array $auth, string $event): void
    {
        $this->storage->save($memberId, $auth, [
            'handler_url' => $this->handlerUrl,
            'placement_url' => $this->placementUrl,
        ]);

        $client = Client::fromAuth($auth, $this->storage);
        $registrar = new ActivityRegistrar($client, $this->handlerUrl, $this->placementUrl);

        $status = 'installed';
        $error = null;

        try {
            $registrar->delete();
        } catch (\Throwable) {
            // activity may not exist yet
        }

        try {
            $registrar->register();
            $status = $event === 'ONAPPUPDATE' ? 'updated' : 'installed';
        } catch (\Throwable $e) {
            $status = 'error';
            $error = $e->getMessage();
        }

        $activities = [];
        try {
            $activities = $registrar->listRegistered();
        } catch (\Throwable $e) {
            $error = ($error ?? '') . ' / list: ' . $e->getMessage();
        }

        if ($this->wantsHtmlResponse($event)) {
            AppPage::renderInstallResult($status, $error, $activities);
            return;
        }

        json_response([
            'status' => $status,
            'event' => $event,
            'error' => $error,
            'activities' => $activities,
        ], $status === 'error' ? 500 : 200);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function respondWithoutAuth(string $event, array $request): void
    {
        if ($event === 'ONAPPINSTALL') {
            json_response([
                'error' => 'Missing auth in install request',
                'hint' => 'Bitrix must POST AUTH_ID or auth[access_token] to install.php',
                'received_keys' => array_keys($request),
            ], 400);
            return;
        }

        AppPage::render(
            'CRM BP Search',
            "Приложение доступно.\n\n"
            . "Настройка блока — в дизайнере БП (кнопка настроек на блоке откроет визуальный редактор).\n\n"
            . "Если блок не появился — переустановите приложение.\n"
            . "Права: crm + bizproc",
            true,
        );
    }

    private function wantsHtmlResponse(string $event): bool
    {
        return $event === 'ONAPPINSTALL' || $event === 'ONAPPUPDATE';
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function uninstall(string $memberId, array $auth): void
    {
        try {
            $client = Client::fromAuth($auth, $this->storage);
            (new ActivityRegistrar($client, $this->handlerUrl, $this->placementUrl))->delete();
        } catch (\Throwable) {
            // ignore on uninstall
        }

        $this->storage->delete($memberId);
    }
}
