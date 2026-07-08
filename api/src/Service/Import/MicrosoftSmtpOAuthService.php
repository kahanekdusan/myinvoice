<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;

/**
 * Microsoft OAuth2 (Graph Mail.Send) connection flow.
 *
 * Varianta A scope:
 * - uložení per-supplier OAuth credentials/tokens,
 * - start URL pro admin connect,
 * - callback exchange authorization_code -> refresh token,
 * - status + disconnect endpointy.
 */
final class MicrosoftSmtpOAuthService
{
    private const DEFAULT_TENANT = 'common';
    private const STATE_MAX_AGE_SECONDS = 900;
    private const SCOPES = [
        'offline_access',
        'https://graph.microsoft.com/Mail.Send',
        'https://graph.microsoft.com/User.Read',
    ];

    private Client $http;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly SecretEncryption $secrets,
    ) {
        $this->http = new Client([
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * @return array{
     *   configured:bool,
     *   connected:bool,
     *   tenant_id:string,
     *   client_id:?string,
     *   mailbox:?string,
     *   connected_at:?string,
     *   token_expires_at:?string,
     *   redirect_uri:string,
     *   scopes:list<string>
     * }
     */
    public function getStatus(int $supplierId): array
    {
        $row = $this->findRow($supplierId);

        $cfgTenantId = $this->configTenantId();
        $cfgClientId = $this->configClientId();
        $cfgClientSecret = $this->configClientSecret();
        $cfgMailbox = $this->configMailbox();

        $tenantId = trim((string) ($row['tenant_id'] ?? ''));
        if ($tenantId === '') {
            $tenantId = $cfgTenantId;
        }
        if ($tenantId === '') {
            $tenantId = self::DEFAULT_TENANT;
        }

        $clientId = trim((string) ($row['client_id'] ?? ''));
        if ($clientId === '') {
            $clientId = $cfgClientId;
        }

        $mailbox = trim((string) ($row['mailbox'] ?? ''));
        if ($mailbox === '') {
            $mailbox = (string) ($cfgMailbox ?? '');
        }

        $configured = ($row !== null
                && !empty($row['client_id'])
                && !empty($row['client_secret_enc']))
            || ($cfgClientId !== '' && $cfgClientSecret !== '');

        return [
            'configured' => $configured,
            'connected' => $row !== null
                && !empty($row['refresh_token_enc'])
                && !empty($row['connected_at']),
            'tenant_id' => $tenantId,
            'client_id' => $clientId !== '' ? $clientId : null,
            'mailbox' => $mailbox !== '' ? $mailbox : null,
            'connected_at' => $row['connected_at'] ?? null,
            'token_expires_at' => $row['access_token_expires_at'] ?? null,
            'redirect_uri' => $this->redirectUri(),
            'scopes' => self::SCOPES,
        ];
    }

    /**
     * @return array{authorize_url:string, redirect_uri:string}
     */
    public function beginAuthorization(
        int $supplierId,
        int $userId,
        string $tenantId,
        string $clientId,
        ?string $clientSecret,
        ?string $mailbox,
    ): array {
        $tenantId = trim($tenantId);
        $clientId = trim($clientId);
        $current = $this->findRow($supplierId);

        if ($tenantId === '' && $current !== null && !empty($current['tenant_id'])) {
            $tenantId = trim((string) $current['tenant_id']);
        }
        if ($tenantId === '') {
            $tenantId = $this->configTenantId();
        }
        if ($tenantId === '') {
            $tenantId = self::DEFAULT_TENANT;
        }

        if ($clientId === '' && $current !== null && !empty($current['client_id'])) {
            $clientId = trim((string) $current['client_id']);
        }
        if ($clientId === '') {
            $clientId = $this->configClientId();
        }
        if ($clientId === '') {
            throw new \RuntimeException('Microsoft OAuth Client ID chybí. Nastavte ho v cfg smtp.oauth.microsoft.client_id.');
        }

        $secretEnc = null;
        if ($clientSecret !== null && $clientSecret !== '') {
            $secretEnc = $this->secrets->encrypt($clientSecret);
        } elseif ($current !== null && !empty($current['client_secret_enc'])) {
            $secretEnc = (string) $current['client_secret_enc'];
        } else {
            $cfgSecret = $this->configClientSecret();
            if ($cfgSecret !== '') {
                $secretEnc = $this->secrets->encrypt($cfgSecret);
            }
        }

        if ($secretEnc === null) {
            throw new \RuntimeException('Microsoft OAuth Client Secret chybí. Nastavte ho v cfg smtp.oauth.microsoft.client_secret.');
        }

        $mailbox = trim((string) $mailbox);
        if ($mailbox === '' && $current !== null && !empty($current['mailbox'])) {
            $mailbox = (string) $current['mailbox'];
        }
        if ($mailbox === '') {
            $mailbox = (string) ($this->configMailbox() ?? '');
        }

        $scope = implode(' ', self::SCOPES);

        $this->db->pdo()->prepare(
            'INSERT INTO smtp_oauth_connections
                (supplier_id, provider, tenant_id, client_id, client_secret_enc, mailbox, scope, created_by)
             VALUES (?, "microsoft", ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                tenant_id = VALUES(tenant_id),
                client_id = VALUES(client_id),
                client_secret_enc = VALUES(client_secret_enc),
                mailbox = VALUES(mailbox),
                scope = VALUES(scope),
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            $supplierId,
            $tenantId,
            $clientId,
            $secretEnc,
            $mailbox !== '' ? $mailbox : null,
            $scope,
            $userId > 0 ? $userId : null,
        ]);

        $state = $this->encodeState($supplierId, $userId);
        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => $scope,
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorize_url' => 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/authorize?' . $params,
            'redirect_uri' => $this->redirectUri(),
        ];
    }

    /**
     * Zpracuje callback z Microsoft OAuth autorizaèního endpointu.
     *
     * @return array{supplier_id:int, user_id:int, mailbox:?string, token_expires_at:?string}
     */
    public function handleCallback(
        string $state,
        ?string $code,
        ?string $error,
        ?string $errorDescription,
    ): array {
        $stateData = $this->decodeState($state);
        $supplierId = (int) $stateData['supplier_id'];

        if ($error !== null && $error !== '') {
            $msg = trim((string) $errorDescription);
            throw new \RuntimeException($msg !== '' ? $msg : 'Autorizace byla zamítnuta (' . $error . ').');
        }

        $authCode = trim((string) $code);
        if ($authCode === '') {
            throw new \RuntimeException('Chybí authorization code.');
        }

        $row = $this->findRow($supplierId);
        if ($row === null) {
            throw new \RuntimeException('Microsoft OAuth konfigurace nebyla nalezena.');
        }

        $tenantId = trim((string) ($row['tenant_id'] ?? ''));
        if ($tenantId === '') {
            $tenantId = $this->configTenantId();
        }
        if ($tenantId === '') {
            $tenantId = self::DEFAULT_TENANT;
        }

        $clientId = trim((string) ($row['client_id'] ?? ''));
        if ($clientId === '') {
            $clientId = $this->configClientId();
        }
        if ($clientId === '') {
            throw new \RuntimeException('Microsoft OAuth Client ID chybí.');
        }

        $clientSecret = '';
        if (!empty($row['client_secret_enc'])) {
            $clientSecret = $this->secrets->decrypt((string) $row['client_secret_enc']);
        }
        if ($clientSecret === '') {
            $clientSecret = $this->configClientSecret();
        }
        if ($clientSecret === '') {
            throw new \RuntimeException('Microsoft OAuth Client Secret chybí.');
        }

        $tokenResp = $this->http->post(
            'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token',
            [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => $authCode,
                    'redirect_uri' => $this->redirectUri(),
                    'scope' => implode(' ', self::SCOPES),
                ],
            ]
        );

        $status = $tokenResp->getStatusCode();
        $body = (string) $tokenResp->getBody();
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            $detail = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? '') : '';
            throw new \RuntimeException('OAuth token exchange selhal.' . ($detail !== '' ? ' ' . trim($detail) : ''));
        }

        $accessToken = (string) $data['access_token'];
        $refreshToken = (string) ($data['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new \RuntimeException('OAuth odpovìï neobsahuje refresh token.');
        }

        $expiresIn = max(60, (int) ($data['expires_in'] ?? 3600));
        $expiresAt = (new \DateTimeImmutable('+' . $expiresIn . ' seconds'))->format('Y-m-d H:i:s');

        $mailbox = trim((string) ($row['mailbox'] ?? ''));
        if ($mailbox === '') {
            $mailbox = (string) ($this->configMailbox() ?? '');
        }
        if ($mailbox === '') {
            $mailbox = $this->resolveMailboxFromGraph($accessToken) ?? '';
        }

        $this->db->pdo()->prepare(
            'UPDATE smtp_oauth_connections
                SET refresh_token_enc = ?,
                    access_token_enc = ?,
                    access_token_expires_at = ?,
                    connected_at = NOW(),
                    mailbox = ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND provider = "microsoft"'
        )->execute([
            $this->secrets->encrypt($refreshToken),
            $this->secrets->encrypt($accessToken),
            $expiresAt,
            $mailbox !== '' ? $mailbox : null,
            $supplierId,
        ]);

        return [
            'supplier_id' => $supplierId,
            'user_id' => (int) $stateData['user_id'],
            'mailbox' => $mailbox !== '' ? $mailbox : null,
            'token_expires_at' => $expiresAt,
        ];
    }

    /**
     * True pokud má dodavatel aktivní (pøipojené) Microsoft Graph napojení.
     */
    public function isConnected(int $supplierId): bool
    {
        $row = $this->findRow($supplierId);

        return $row !== null
            && !empty($row['refresh_token_enc'])
            && !empty($row['connected_at']);
    }

    /**
     * Vrátí platný Graph access token — z DB cache, nebo obnovený pøes refresh_token.
     * Rotované tokeny persistuje zpìt do DB.
     *
     * @return array{access_token:string, mailbox:?string}
     */
    public function getGraphAccessToken(int $supplierId): array
    {
        $row = $this->findRow($supplierId);
        if ($row === null || empty($row['refresh_token_enc'])) {
            throw new \RuntimeException('Microsoft úèet není pøipojen.');
        }

        // Reuse cached access tokenu, dokud je platný (>60 s rezerva).
        $expiresAt = $row['access_token_expires_at'] ?? null;
        if (!empty($row['access_token_enc']) && $expiresAt !== null) {
            $ts = strtotime((string) $expiresAt);
            if ($ts !== false && $ts > time() + 60) {
                return [
                    'access_token' => $this->secrets->decrypt((string) $row['access_token_enc']),
                    'mailbox' => $row['mailbox'] ?? null,
                ];
            }
        }

        $tenantId = trim((string) ($row['tenant_id'] ?? ''));
        if ($tenantId === '') {
            $tenantId = $this->configTenantId();
        }
        if ($tenantId === '') {
            $tenantId = self::DEFAULT_TENANT;
        }

        $clientId = trim((string) ($row['client_id'] ?? ''));
        if ($clientId === '') {
            $clientId = $this->configClientId();
        }

        $clientSecret = '';
        if (!empty($row['client_secret_enc'])) {
            $clientSecret = $this->secrets->decrypt((string) $row['client_secret_enc']);
        }
        if ($clientSecret === '') {
            $clientSecret = $this->configClientSecret();
        }

        $refreshToken = $this->secrets->decrypt((string) $row['refresh_token_enc']);
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new \RuntimeException('Microsoft OAuth konfigurace je neúplná.');
        }

        $resp = $this->http->post(
            'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token',
            [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'scope' => implode(' ', self::SCOPES),
                ],
            ]
        );

        $status = $resp->getStatusCode();
        $data = json_decode((string) $resp->getBody(), true);
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            $detail = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? '') : '';
            throw new \RuntimeException('Obnova Microsoft Graph tokenu selhala.' . ($detail !== '' ? ' ' . trim($detail) : ''));
        }

        $accessToken = (string) $data['access_token'];
        $newRefresh = (string) ($data['refresh_token'] ?? '');
        $expiresIn = max(60, (int) ($data['expires_in'] ?? 3600));
        $newExpiresAt = (new \DateTimeImmutable('+' . $expiresIn . ' seconds'))->format('Y-m-d H:i:s');

        $this->db->pdo()->prepare(
            'UPDATE smtp_oauth_connections
                SET access_token_enc = ?,
                    access_token_expires_at = ?,
                    refresh_token_enc = COALESCE(?, refresh_token_enc),
                    updated_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND provider = "microsoft"'
        )->execute([
            $this->secrets->encrypt($accessToken),
            $newExpiresAt,
            $newRefresh !== '' ? $this->secrets->encrypt($newRefresh) : null,
            $supplierId,
        ]);

        return [
            'access_token' => $accessToken,
            'mailbox' => $row['mailbox'] ?? null,
        ];
    }

    /**
     * Odešle e-mail pøes Microsoft Graph `sendMail`.
     *
     * @param array{
     *   subject:string,
     *   html:string,
     *   text:string,
     *   to:list<string>,
     *   cc:list<string>,
     *   bcc:list<string>,
     *   reply_email:string,
     *   reply_name:string,
     *   attachments:list<array{name:string,contentType:string,bytes:string}>,
     *   inline:list<array{name:string,contentType:string,bytes:string,contentId:string}>
     * } $parts
     */
    public function sendGraphMessage(int $supplierId, array $parts): string
    {
        $tokenInfo = $this->getGraphAccessToken($supplierId);
        $accessToken = $tokenInfo['access_token'];
        $mailbox = trim((string) ($tokenInfo['mailbox'] ?? ''));

        $sendUrl = $mailbox !== ''
            ? 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($mailbox) . '/sendMail'
            : 'https://graph.microsoft.com/v1.0/me/sendMail';

        $html = (string) ($parts['html'] ?? '');
        $text = (string) ($parts['text'] ?? '');
        $to = array_values($parts['to'] ?? []);
        $cc = array_values($parts['cc'] ?? []);
        $bcc = array_values($parts['bcc'] ?? []);

        $message = [
            'subject' => (string) ($parts['subject'] ?? ''),
            'body' => [
                'contentType' => $html !== '' ? 'HTML' : 'Text',
                'content' => $html !== '' ? $html : $text,
            ],
            'toRecipients' => array_map(
                static fn (string $addr) => ['emailAddress' => ['address' => $addr]],
                $to,
            ),
        ];
        if ($cc !== []) {
            $message['ccRecipients'] = array_map(
                static fn (string $addr) => ['emailAddress' => ['address' => $addr]],
                $cc,
            );
        }
        if ($bcc !== []) {
            $message['bccRecipients'] = array_map(
                static fn (string $addr) => ['emailAddress' => ['address' => $addr]],
                $bcc,
            );
        }
        $replyEmail = trim((string) ($parts['reply_email'] ?? ''));
        if ($replyEmail !== '') {
            $message['replyTo'] = [[
                'emailAddress' => ['address' => $replyEmail, 'name' => (string) ($parts['reply_name'] ?? '')],
            ]];
        }

        $graphAttachments = [];
        foreach (($parts['attachments'] ?? []) as $att) {
            $graphAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => (string) $att['name'],
                'contentType' => (string) $att['contentType'],
                'contentBytes' => base64_encode((string) $att['bytes']),
            ];
        }
        foreach (($parts['inline'] ?? []) as $att) {
            $graphAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => (string) $att['name'],
                'contentType' => (string) $att['contentType'],
                'contentBytes' => base64_encode((string) $att['bytes']),
                'isInline' => true,
                'contentId' => (string) $att['contentId'],
            ];
        }
        if ($graphAttachments !== []) {
            $message['attachments'] = $graphAttachments;
        }

        $payload = json_encode([
            'message' => $message,
            'saveToSentItems' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) {
            throw new \RuntimeException('Nepodaøilo se serializovat payload pro Microsoft Graph.');
        }

        $resp = $this->http->post($sendUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'body' => $payload,
        ]);

        $httpCode = $resp->getStatusCode();
        if ($httpCode < 200 || $httpCode >= 300) {
            $raw = (string) $resp->getBody();
            $json = json_decode($raw, true);
            $detail = 'HTTP ' . $httpCode;
            if (is_array($json)) {
                $errCode = (string) ($json['error']['code'] ?? '');
                $msg = (string) ($json['error']['message'] ?? $json['message'] ?? '');
                if ($errCode !== '') {
                    $detail .= ' [' . $errCode . ']';
                }
                if ($msg !== '') {
                    $detail .= ' - ' . $msg;
                }
            }
            throw new \RuntimeException('Microsoft Graph odeslání selhalo: ' . $detail);
        }

        return 'Graph send accepted (HTTP ' . $httpCode . ')';
    }

    public function disconnect(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE smtp_oauth_connections
                SET refresh_token_enc = NULL,
                    access_token_enc = NULL,
                    access_token_expires_at = NULL,
                    connected_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND provider = "microsoft"'
        )->execute([$supplierId]);
    }

    private function redirectUri(): string
    {
        $override = trim((string) $this->config->get('smtp.oauth.microsoft.redirect_uri', ''));
        if ($override === '') {
            $override = trim((string) $this->config->get('smtp.oauth.redirect_uri', ''));
        }
        if ($override !== '') {
            return $override;
        }

        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        if ($base === '') {
            throw new \RuntimeException('cfg.app.url není nastaveno.');
        }
        return $base . '/api/admin/smtp/oauth/microsoft/callback';
    }

    private function configTenantId(): string
    {
        $tenant = $this->sanitizeConfigValue((string) $this->config->get('smtp.oauth.microsoft.tenant_id', ''));
        if ($tenant === '') {
            $tenant = $this->sanitizeConfigValue((string) $this->config->get('smtp.oauth.tenant_id', ''));
        }

        return $tenant;
    }

    private function configClientId(): string
    {
        $clientId = $this->sanitizeConfigValue((string) $this->config->get('smtp.oauth.microsoft.client_id', ''));
        if ($clientId === '') {
            $clientId = $this->sanitizeConfigValue((string) $this->config->get('smtp.oauth.client_id', ''));
        }

        return $clientId;
    }

    private function configClientSecret(): string
    {
        $secret = $this->sanitizeConfigValue((string) $this->config->get('smtp.oauth.microsoft.client_secret', ''));
        if ($secret === '') {
            $secret = $this->sanitizeConfigValue((string) $this->config->get('smtp.oauth.client_secret', ''));
        }

        return $secret;
    }

    private function configMailbox(): ?string
    {
        $mailbox = $this->sanitizeConfigValue((string) $this->config->get('smtp.oauth.microsoft.mailbox', ''));
        if ($mailbox === '') {
            $mailbox = $this->sanitizeConfigValue((string) $this->config->get('smtp.user', ''));
        }
        if ($mailbox !== '' && filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            return $mailbox;
        }

        return null;
    }

    private function sanitizeConfigValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^CHANGE[\s_-]*ME$/i', $value)) {
            return '';
        }

        return $value;
    }

    private function encodeState(int $supplierId, int $userId): string
    {
        $payload = [
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'ts' => time(),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadB64 = $this->b64UrlEncode($payloadJson);
        $signature = hash_hmac('sha256', $payloadB64, $this->stateKey(), true);

        return $payloadB64 . '.' . $this->b64UrlEncode($signature);
    }

    /**
     * @return array{supplier_id:int,user_id:int,ts:int,nonce:string}
     */
    private function decodeState(string $state): array
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Neplatný OAuth state.');
        }

        [$payloadB64, $sigB64] = $parts;
        $expected = hash_hmac('sha256', $payloadB64, $this->stateKey(), true);
        $actual = $this->b64UrlDecode($sigB64);
        if ($actual === null || !hash_equals($expected, $actual)) {
            throw new \RuntimeException('OAuth state signature nesouhlasí.');
        }

        $payloadRaw = $this->b64UrlDecode($payloadB64);
        if ($payloadRaw === null) {
            throw new \RuntimeException('Neplatný OAuth state payload.');
        }

        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Neplatný OAuth state payload.');
        }

        $supplierId = (int) ($payload['supplier_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);
        $ts = (int) ($payload['ts'] ?? 0);
        $nonce = (string) ($payload['nonce'] ?? '');
        if ($supplierId <= 0 || $userId <= 0 || $ts <= 0 || $nonce === '') {
            throw new \RuntimeException('Neplatný OAuth state payload.');
        }

        if (abs(time() - $ts) > self::STATE_MAX_AGE_SECONDS) {
            throw new \RuntimeException('OAuth state expiroval. Zkuste pøipojení znovu.');
        }

        return [
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'ts' => $ts,
            'nonce' => $nonce,
        ];
    }

    private function stateKey(): string
    {
        $encKey = (string) $this->config->get('app.secret_encryption_key', '');
        if ($encKey !== '') {
            $raw = base64_decode($encKey, true);
            if ($raw !== false && strlen($raw) === 32) {
                return $raw;
            }
        }

        $pepper = (string) $this->config->get('app.pepper', '');
        if ($pepper === '') {
            throw new \RuntimeException('Chybí app.pepper pro OAuth state podpis.');
        }

        return hash_hmac('sha256', 'microsoft-oauth-state', $pepper, true);
    }

    private function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $encoded): ?string
    {
        $padded = strtr($encoded, '-_', '+/');
        $padLen = strlen($padded) % 4;
        if ($padLen > 0) {
            $padded .= str_repeat('=', 4 - $padLen);
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    private function resolveMailboxFromGraph(string $accessToken): ?string
    {
        $resp = $this->http->get('https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
        ]);
        if ($resp->getStatusCode() !== 200) {
            return null;
        }

        $data = json_decode((string) $resp->getBody(), true);
        if (!is_array($data)) {
            return null;
        }

        $mail = trim((string) ($data['mail'] ?? ''));
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            return $mail;
        }

        $upn = trim((string) ($data['userPrincipalName'] ?? ''));
        if ($upn !== '' && filter_var($upn, FILTER_VALIDATE_EMAIL)) {
            return $upn;
        }

        return null;
    }

    private function findRow(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, tenant_id, client_id, client_secret_enc,
                    refresh_token_enc, access_token_enc, access_token_expires_at,
                    mailbox, scope, connected_at
               FROM smtp_oauth_connections
              WHERE supplier_id = ? AND provider = "microsoft"
              LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }
}
