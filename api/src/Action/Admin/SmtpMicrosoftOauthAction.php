<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Config\CfgLocalWriter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin flow pro získání Microsoft delegated refresh tokenu přímo v appce
 * (bez Postman callback URI).
 *
 * GET /api/admin/smtp/oauth/microsoft/start
 *   -> 302 redirect na Microsoft authorize endpoint.
 *
 * GET /api/admin/smtp/oauth/microsoft/callback?code=...&state=...
 *   -> exchange authorization_code, uloží refresh_token do cfg.local.php.
 */
final class SmtpMicrosoftOauthAction
{
    public function __construct(
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function start(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $tenant = $this->cfg('smtp.oauth.microsoft.tenant_id', '');
        $clientId = $this->cfg('smtp.oauth.microsoft.client_id', $this->cfg('smtp.oauth.client_id', ''));
        if ($tenant === '' || $clientId === '') {
            return Json::error($response, 'smtp_oauth_config_missing', 'Chybí tenant_id nebo client_id v smtp.oauth.microsoft.', 400);
        }

        $authorizeUrl = $this->cfg(
            'smtp.oauth.microsoft.authorize_url',
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize'
        );
        $redirectUri = $this->resolveRedirectUri();
        $scope = $this->cfg('smtp.oauth.microsoft.scope', $this->defaultOauthScope());
        $prompt = $this->cfg('smtp.oauth.microsoft.prompt', 'consent');
        $loginHint = $this->cfg('smtp.user', '');

        $state = $this->createState((int) ($user['id'] ?? 0), $redirectUri);

        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'response_mode' => 'query',
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
        ];
        if ($prompt !== '') {
            $params['prompt'] = $prompt;
        }
        if ($loginHint !== '' && filter_var($loginHint, FILTER_VALIDATE_EMAIL)) {
            $params['login_hint'] = $loginHint;
        }

        $location = $authorizeUrl . (str_contains($authorizeUrl, '?') ? '&' : '?') . http_build_query($params);
        return $response->withStatus(302)->withHeader('Location', $location);
    }

    public function status(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $refreshToken = trim($this->cfg('smtp.oauth.microsoft.refresh_token', $this->cfg('smtp.oauth.refresh_token', '')));

        return Json::ok($response, [
            'configured' => $refreshToken !== '',
            'refresh_token_present' => $refreshToken !== '',
            'transport' => strtolower($this->cfg('smtp.transport', 'smtp')),
            'auth_type' => strtoupper($this->cfg('smtp.auth_type', '')),
            'from_user' => $this->cfg('smtp.user', ''),
            'app_url' => rtrim($this->cfg('app.url', ''), '/'),
            'redirect_uri' => $this->resolveRedirectUri(),
            'scope' => $this->cfg('smtp.oauth.microsoft.scope', $this->defaultOauthScope()),
            'tenant_id_present' => $this->cfg('smtp.oauth.microsoft.tenant_id', '') !== '',
            'client_id_present' => $this->cfg('smtp.oauth.microsoft.client_id', $this->cfg('smtp.oauth.client_id', '')) !== '',
            'start_path' => '/api/admin/smtp/oauth/microsoft/start',
        ]);
    }

    public function disconnect(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        try {
            CfgLocalWriter::setKeys(CfgLocalWriter::resolveTargetDir(Bootstrap::rootDir()), [
                'smtp.oauth.microsoft.refresh_token' => '',
            ]);
        } catch (\Throwable $e) {
            return Json::error($response, 'config_write_failed', 'Odpojení selhalo: ' . $e->getMessage(), 500);
        }

        $currentUserId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('smtp.oauth.microsoft_refresh_token_deleted', $currentUserId, null, null, [], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true, 'disconnected' => true]);
    }

    public function callback(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $q = $request->getQueryParams();
        $oauthError = trim((string) ($q['error'] ?? ''));
        if ($oauthError !== '') {
            $desc = trim((string) ($q['error_description'] ?? '')); // provider text
            return $this->html($response, 400, 'Microsoft OAuth selhal',
                'Poskytovatel vrátil chybu: ' . htmlspecialchars($oauthError . ($desc !== '' ? ' - ' . $desc : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $state = trim((string) ($q['state'] ?? ''));
        $code = trim((string) ($q['code'] ?? ''));
        if ($state === '' || $code === '') {
            return $this->html($response, 400, 'Neplatný callback', 'Chybí parametr code nebo state.');
        }

        $decoded = $this->verifyState($state);
        if ($decoded === null) {
            return $this->html($response, 400, 'Neplatný state', 'State je neplatný nebo expirovaný. Spusť OAuth znovu.');
        }

        $currentUserId = (int) ($user['id'] ?? 0);
        if ((int) ($decoded['uid'] ?? 0) !== $currentUserId) {
            return $this->html($response, 403, 'Neplatný uživatel', 'OAuth callback nepatří aktuálně přihlášenému adminovi.');
        }

        $redirectUri = (string) ($decoded['redirect_uri'] ?? '');
        if ($redirectUri === '') {
            return $this->html($response, 400, 'Neplatný callback', 'State neobsahuje redirect_uri.');
        }

        $tenant = $this->cfg('smtp.oauth.microsoft.tenant_id', '');
        $clientId = $this->cfg('smtp.oauth.microsoft.client_id', $this->cfg('smtp.oauth.client_id', ''));
        $clientSecret = $this->cfg('smtp.oauth.microsoft.client_secret', $this->cfg('smtp.oauth.client_secret', ''));
        if ($tenant === '' || $clientId === '' || $clientSecret === '') {
            return $this->html($response, 400, 'Chybí SMTP OAuth konfigurace', 'Doplň tenant_id, client_id a client_secret do konfigurace.');
        }

        $tokenUrl = $this->cfg(
            'smtp.oauth.microsoft.token_url',
            'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token'
        );
        $scope = $this->cfg('smtp.oauth.microsoft.scope', $this->defaultOauthScope());

        $postFields = http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
        ]);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $httpCode < 200 || $httpCode >= 300) {
            $detail = $curlErr !== '' ? $curlErr : 'HTTP ' . $httpCode;
            return $this->html($response, 502, 'Token exchange selhal', 'Nepodařilo se získat token od Microsoftu: ' . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $json = json_decode($raw, true);
        $refreshToken = is_array($json) ? trim((string) ($json['refresh_token'] ?? '')) : '';
        $accessToken = is_array($json) ? trim((string) ($json['access_token'] ?? '')) : '';
        if ($refreshToken === '' || $accessToken === '') {
            return $this->html(
                $response,
                502,
                'Chybí refresh token',
                'Microsoft odpověď neobsahuje refresh_token. Zkontroluj scope offline_access a prompt=consent.'
            );
        }

        try {
            CfgLocalWriter::setKeys(CfgLocalWriter::resolveTargetDir(Bootstrap::rootDir()), [
                'smtp.auth_enabled' => true,
                'smtp.auth_type' => 'XOAUTH2',
                'smtp.oauth.provider' => 'microsoft',
                'smtp.oauth.microsoft.grant_type' => 'refresh_token',
                'smtp.oauth.microsoft.refresh_token' => $refreshToken,
            ]);
        } catch (\Throwable $e) {
            return $this->html($response, 500, 'Uložení konfigurace selhalo',
                'Refresh token získán, ale nepodařilo se uložit do cfg.local.php: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('smtp.oauth.microsoft_refresh_token_saved', $currentUserId, null, null, [
            'grant_type' => 'refresh_token',
            'scope' => $scope,
            'expires_in' => (int) (is_array($json) ? ($json['expires_in'] ?? 0) : 0),
            'token_type' => is_array($json) ? (string) ($json['token_type'] ?? '') : '',
        ], $ip, $request->getHeaderLine('User-Agent'));

        return $this->html($response, 200, 'Microsoft OAuth dokončen',
            'Refresh token byl úspěšně uložen do cfg.local.php. Můžeš zavřít toto okno a otestovat SMTP odeslání v aplikaci.');
    }

    private function createState(int $userId, string $redirectUri): string
    {
        $payload = [
            'uid' => $userId,
            'exp' => time() + 600,
            'nonce' => bin2hex(random_bytes(16)),
            'redirect_uri' => $redirectUri,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', $json, $this->stateSigningKey(), true);
        return $this->b64urlEncode($json) . '.' . $this->b64urlEncode($sig);
    }

    /** @return array<string,mixed>|null */
    private function verifyState(string $state): ?array
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) return null;

        $json = $this->b64urlDecode($parts[0]);
        $sig = $this->b64urlDecode($parts[1]);
        if ($json === null || $sig === null) return null;

        $expected = hash_hmac('sha256', $json, $this->stateSigningKey(), true);
        if (!hash_equals($expected, $sig)) return null;

        $payload = json_decode($json, true);
        if (!is_array($payload)) return null;
        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp <= 0 || $exp < time()) return null;

        return $payload;
    }

    private function stateSigningKey(): string
    {
        $k = $this->cfg('app.secret_encryption_key', '');
        if ($k !== '') return 'smtp-oauth-state:' . $k;
        return 'smtp-oauth-state:' . $this->cfg('app.pepper', 'myinvoice-state');
    }

    private function resolveRedirectUri(): string
    {
        $configured = rtrim($this->cfg('smtp.oauth.microsoft.redirect_uri', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $appUrl = rtrim($this->cfg('app.url', ''), '/');
        return $appUrl . '/api/admin/smtp/oauth/microsoft/callback';
    }

    private function cfg(string $path, string $default): string
    {
        $value = (string) $this->config->get($path, $default);
        return trim($value);
    }

    private function defaultOauthScope(): string
    {
        $transport = strtolower($this->cfg('smtp.transport', 'smtp'));
        if ($transport === 'graph') {
            return 'https://graph.microsoft.com/Mail.Send offline_access';
        }
        return 'https://outlook.office365.com/SMTP.Send offline_access';
    }

    private function b64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private function html(Response $response, int $status, string $title, string $message): Response
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $response->getBody()->write('<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . $safeTitle . '</title></head><body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;line-height:1.5">'
            . '<h1 style="font-size:22px;margin:0 0 12px">' . $safeTitle . '</h1>'
            . '<p style="margin:0 0 16px">' . $safeMessage . '</p>'
            . '<p style="color:#666;margin:0">MyInvoice SMTP OAuth</p>'
            . '</body></html>');

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
