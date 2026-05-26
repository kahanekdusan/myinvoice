<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailTemplateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Wrapper nad Symfony Mailer + Twig pro renderování šablon.
 *
 * Použití:
 *   $mailer->sendTemplate('password_reset', 'cs', ['user@example.com'], ['name' => 'Jan Novák', 'resetLink' => '...']);
 *
 * Šablony jsou v api/templates/email/<code>.<lang>.{html,txt}.twig.
 */
final class Mailer
{
    private ?SymfonyMailer $mailer = null;
    private mixed $transport = null;
    private ?Environment $twig = null;
    private ?array $supplierFooter = null;
    private ?string $oauthAccessToken = null;
    private int $oauthAccessTokenExpiresAt = 0;
    private string $oauthAccessTokenScope = '';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Connection $db,
        private readonly EmailTemplateRepository $templates,
    ) {}

    /**
     * @param string[]      $to
     * @param array<string,mixed> $vars
     * @param string[]      $cc
     * @param string[]      $bcc
     * @param array<int,array{path:string,name:string,contentType:string}> $attachments
     * @return string Krátký SMTP server response z poslední odpovědi (např.
     *               „250 2.0.0 Ok: queued as ABCDEF"). Plný transcript jde
     *               do log/myinvoice-*.log na úrovni info.
     */
    public function sendTemplate(
        string $code,
        string $locale,
        array $to,
        array $vars,
        ?string $subjectOverride = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
    ): string {
        $twig = $this->twig();

        $vars['locale'] = $locale;
        if (!isset($vars['supplier'])) {
            $vars['supplier'] = $this->loadSupplierFooter();
        }
        // Pre-compute display dimensions pro logo (HTML width/height attributy
        // — email klienti respektují líp než CSS max-height, viz Outlook).
        if (is_array($vars['supplier'] ?? null)) {
            $vars['supplier'] = $this->addLogoDisplaySize($vars['supplier']);
        }

        // Pokud je v DB override, vyrenderuj přímo ze stringu (vyšší priorita než file).
        $dbTpl = $this->templates->find($code, $locale)
              ?? $this->templates->find($code, 'cs');

        if ($dbTpl !== null) {
            // DB šablona je editovatelná adminem — sandboxujeme proti SSTI
            $sandbox = $this->sandboxedTwig();
            $vars['subject'] = $subjectOverride ?? $dbTpl['subject'];
            $html = $sandbox->createTemplate($dbTpl['body_html'])->render($vars);
            $text = $sandbox->createTemplate($dbTpl['body_text'])->render($vars);
        } else {
            $htmlTemplate = "{$code}.{$locale}.html.twig";
            $textTemplate = "{$code}.{$locale}.txt.twig";
            if (!$twig->getLoader()->exists($htmlTemplate)) {
                $htmlTemplate = "{$code}.cs.html.twig";
                $textTemplate = "{$code}.cs.txt.twig";
            }
            if (!isset($vars['subject'])) {
                $vars['subject'] = $subjectOverride ?? $this->defaultSubject($code, $locale);
            }
            $html = $twig->render($htmlTemplate, $vars);
            $text = $twig->render($textTemplate, $vars);
        }

        // From: per-supplier override (vars['supplier'].email + display_name) > globální cfg
        $globalFromEmail = (string) $this->config->get('smtp.from_email');
        $globalFromName  = (string) $this->config->get('smtp.from_name');
        $supplier = is_array($vars['supplier'] ?? null) ? $vars['supplier'] : null;
        $fromName = $globalFromName;
        if ($supplier !== null) {
            $supName = (string) ($supplier['display_name'] ?? $supplier['company_name'] ?? '');
            if ($supName !== '') $fromName = $supName;
        }

        $email = (new Email())
            ->from(new Address($globalFromEmail, $fromName))
            ->subject((string) $vars['subject'])
            ->html($html)
            ->text($text);

        // Per-supplier branding logo jako CID inline image — je-li `email_branding_enabled`
        // a logo soubor existuje. Twig používá `cid:supplier_logo` jako image src.
        $inlineAttachments = [];
        if ($supplier !== null
            && !empty($supplier['email_branding_enabled'])
            && !empty($supplier['logo_path'])
            && !empty($supplier['id'])
        ) {
            // SafeLogoPath: defense-in-depth proti LFI přes podstrčený logo_path
            // (security report @andrejtomci #2). Resolve vrátí null pokud cesta
            // neukazuje do storage/supplier-logos/sup-{id}.{png|svg|...}.
            $logoAbs = SafeLogoPath::resolve((string) $supplier['logo_path'], (int) $supplier['id']);
            if ($logoAbs !== null) {
                $inlineAttachments[] = [
                    'path' => $logoAbs,
                    'name' => 'supplier-logo.png',
                    'contentType' => 'image/png',
                    'contentId' => 'supplier_logo',
                ];
                $email->embedFromPath($logoAbs, 'supplier_logo', 'image/png');
            }
        }

        foreach ($to as $addr)  $email->addTo($addr);
        foreach ($cc as $addr)  $email->addCc($addr);
        foreach ($bcc as $addr) $email->addBcc($addr);

        // Reply-To: per-supplier override (supplier.email) > globální cfg.smtp.reply_to_email
        $replyEmail = '';
        $replyName  = '';
        if ($supplier !== null && !empty($supplier['email']) && filter_var($supplier['email'], FILTER_VALIDATE_EMAIL)) {
            $replyEmail = (string) $supplier['email'];
            $replyName  = (string) ($supplier['display_name'] ?? $supplier['company_name'] ?? '');
        } else {
            $replyEmail = (string) $this->config->get('smtp.reply_to_email', '');
            $replyName  = (string) $this->config->get('smtp.reply_to_name', '');
        }
        if ($replyEmail !== '') {
            $email->replyTo(new Address($replyEmail, $replyName));
        }

        foreach ($attachments as $att) {
            $email->attachFromPath($att['path'], $att['name'], $att['contentType']);
        }

        $transportMode = strtolower((string) $this->config->get('smtp.transport', 'smtp'));
        if ($transportMode === 'graph') {
            $graphResponse = $this->sendViaMicrosoftGraph(
                (string) $vars['subject'],
                $html,
                $text,
                $to,
                $cc,
                $bcc,
                $replyEmail,
                $replyName,
                $attachments,
                $inlineAttachments,
            );

            $this->logger->info('mail.sent', [
                'template'      => $code,
                'locale'        => $locale,
                'to'            => $to,
                'cc'            => $cc,
                'bcc'           => $bcc,
                'attachments'   => count($attachments) + count($inlineAttachments),
                'provider'      => 'graph',
                'smtp_response' => $graphResponse,
                'smtp_debug'    => '',
            ]);

            return $graphResponse;
        }

        // DKIM signer
        if ($this->config->get('smtp.dkim.enabled', false)) {
            $keyPath = (string) $this->config->get('smtp.dkim.private_key_path', '');
            if (is_file($keyPath)) {
                $signer = new DkimSigner(
                    'file://' . $keyPath,
                    (string) $this->config->get('smtp.dkim.domain'),
                    (string) $this->config->get('smtp.dkim.selector'),
                    [],
                    (string) $this->config->get('smtp.dkim.passphrase', ''),
                );
                $email = $signer->sign($email);
            } else {
                $this->logger->warning('DKIM enabled, ale private key neexistuje: ' . $keyPath);
            }
        }

        // POZOR: high-level `Symfony\Component\Mailer\Mailer::send()` vrací void
        // (od 5.x). Pro získání SentMessage s debug transcriptem musíme volat
        // transport->send() napřímo. Stejný transport instance jako $this->mailer().
        $sent = $this->transport()->send($email);
        $debug = $sent !== null ? $sent->getDebug() : '';
        $smtpResponse = $this->extractLastServerResponse($debug);

        $this->logger->info('mail.sent', [
            'template'      => $code,
            'locale'        => $locale,
            'to'            => $to,
            'cc'            => $cc,
            'bcc'           => $bcc,
            'attachments'   => count($attachments),
            'smtp_response' => $smtpResponse,
            // Plný SMTP transcript — užitečný pro debugging delivery problémů.
            // Pokud je log moc velký, dá se filtrovat na úrovni Monolog handleru.
            'smtp_debug'    => $debug,
        ]);

        return $smtpResponse;
    }

    /**
     * Vytáhne z SMTP transcriptu poslední řádek odpovědi serveru (`<<< 250 …`).
     * Používá se pro logování do activity_log payload — uživatel vidí, co
     * SMTP server poslední řekl, a pozná, jestli zpráva byla přijata
     * (`2xx`), odmítnuta (`5xx`) nebo dočasně failnula (`4xx`).
     */
    private function extractLastServerResponse(string $debug): string
    {
        if ($debug === '') return '';
        // Symfony Mailer 8.x prefixuje řádky timestampem `[YYYY-MM-DDTHH:MM:SS] < …`.
        // Server odpovědi používají `< ` (s mezerou) nebo `<<<` (starší verze).
        // Najdeme poslední match přes celý transcript.
        $lines = preg_split('/\r?\n/', $debug) ?: [];
        $last = '';
        foreach ($lines as $line) {
            // Strip timestamp prefix `[2026-05-07T11:43:39.349662+02:00] `
            $stripped = (string) preg_replace('/^\[[^\]]+\]\s*/', '', $line);
            $stripped = trim($stripped);
            if ($stripped === '') continue;
            if (str_starts_with($stripped, '< ') || str_starts_with($stripped, '<<<')) {
                $last = $stripped;
            }
        }
        $last = (string) preg_replace('/^(?:<<<\s*|<\s+)/', '', $last);
        return $last !== '' ? $last : '(no SMTP debug — possibly non-SMTP transport)';
    }

    private function mailer(): SymfonyMailer
    {
        if ($this->mailer === null) {
            $this->mailer = new SymfonyMailer($this->transport());
        }
        return $this->mailer;
    }

    private function transport(): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        if ($this->transport === null) {
            $this->transport = Transport::fromDsn($this->buildDsn());
        }
        return $this->transport;
    }

    private function buildDsn(): string
    {
        $host = (string) $this->config->get('smtp.host');
        $port = (int) $this->config->get('smtp.port', 25);
        $authEnabled = (bool) $this->config->get('smtp.auth_enabled', false);
        $authType = strtolower((string) $this->config->get('smtp.auth_type', 'password'));
        $user = (string) $this->config->get('smtp.user', '');
        $pass = (string) $this->config->get('smtp.pass', '');
        $encryption = (string) $this->config->get('smtp.encryption', '');
        $verifyPeer = (bool) $this->config->get('smtp.verify_peer', true);

        $userPart = '';
        if ($authEnabled && $user !== '') {
            if ($authType === 'xoauth2') {
                $oauthToken = $this->resolveOauthAccessToken();
                if ($oauthToken !== '') {
                    $pass = $oauthToken;
                }
            }
            $userPart = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
        }

        $params = [];
        // encryption: ssl (port 465 implicit TLS), tls (STARTTLS), '' = plain
        if ($encryption === 'tls') {
            // STARTTLS — Symfony to defaultně udělá pro port 587
        }
        if ($encryption === '') {
            // Plain — disable peer verify implicitly
            $verifyPeer = false;
        }
        if ($authEnabled && $authType === 'xoauth2') {
            $params[] = 'auth_mode=xoauth2';
        }
        if (!$verifyPeer) {
            $params[] = 'verify_peer=0';
        }

        $query = $params ? '?' . implode('&', $params) : '';

        return sprintf('smtp://%s%s:%d%s', $userPart, $host, $port, $query);
    }

    private function resolveOauthAccessToken(?string $forcedScope = null): string
    {
        $now = time();
        $cacheScope = $forcedScope ?? '__default__';
        if (
            $this->oauthAccessToken !== null
            && $this->oauthAccessTokenExpiresAt > ($now + 60)
            && $this->oauthAccessTokenScope === $cacheScope
        ) {
            return $this->oauthAccessToken;
        }

        $provider = strtolower((string) $this->config->get('smtp.oauth.provider', 'microsoft'));
        if ($provider !== 'microsoft') {
            $this->logger->warning('smtp.oauth.provider is not supported', ['provider' => $provider]);
            return '';
        }

        $tenant = (string) $this->config->get('smtp.oauth.microsoft.tenant_id', '');
        $clientId = (string) $this->config->get('smtp.oauth.microsoft.client_id', '');
        $clientSecret = (string) $this->config->get('smtp.oauth.microsoft.client_secret', '');
        $refreshToken = (string) $this->config->get('smtp.oauth.microsoft.refresh_token', '');
        // Backward compatibility: support older flat oauth keys from legacy cfg samples.
        if ($clientId === '') {
            $clientId = (string) $this->config->get('smtp.oauth.client_id', '');
        }
        if ($clientSecret === '') {
            $clientSecret = (string) $this->config->get('smtp.oauth.client_secret', '');
        }
        if ($refreshToken === '') {
            $refreshToken = (string) $this->config->get('smtp.oauth.refresh_token', '');
        }
        if ($refreshToken === '') {
            // cfg.local.php may be updated at runtime by OAuth callback while current
            // request still holds an older Config snapshot; pull token from disk.
            $refreshToken = $this->readRefreshTokenFromLocalConfig();
        }

        $grantType = strtolower((string) $this->config->get('smtp.oauth.microsoft.grant_type', ''));
        if ($grantType === '') {
            $grantType = $refreshToken !== '' ? 'refresh_token' : 'client_credentials';
        }

        $fallbackToClientCredentials = false;
        if ($grantType === 'refresh_token' && $refreshToken === '') {
            $this->logger->warning('Missing Microsoft OAuth refresh_token, falling back to client_credentials grant.');
            $grantType = 'client_credentials';
            $fallbackToClientCredentials = true;
        }

        $scopeDefault = $grantType === 'refresh_token'
            ? 'https://outlook.office365.com/SMTP.Send offline_access'
            : 'https://outlook.office365.com/.default';
        $scope = $forcedScope !== null
            ? $forcedScope
            : (string) $this->config->get('smtp.oauth.microsoft.scope', $scopeDefault);
        if ($fallbackToClientCredentials && $forcedScope !== null && !str_contains($scope, '.default')) {
            $scope = str_contains($scope, 'graph.microsoft.com')
                ? 'https://graph.microsoft.com/.default'
                : 'https://outlook.office365.com/.default';
        }
        $tokenUrl = (string) $this->config->get('smtp.oauth.microsoft.token_url', '');
        if ($tenant === '' && $tokenUrl === '') {
            $this->logger->warning('Missing Microsoft OAuth tenant_id (or explicit token_url).');
            return '';
        }
        if ($clientId === '' || $clientSecret === '') {
            $this->logger->warning('Missing Microsoft OAuth SMTP credentials.');
            return '';
        }

        if ($tokenUrl === '') {
            $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
        }

        $postData = [
            'grant_type' => $grantType,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $scope,
        ];
        if ($grantType === 'refresh_token') {
            $postData['refresh_token'] = $refreshToken;
        } elseif ($grantType !== 'client_credentials') {
            $this->logger->warning('Unsupported Microsoft OAuth grant_type for SMTP.', ['grant_type' => $grantType]);
            return '';
        }

        $postFields = http_build_query($postData);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $httpCode < 200 || $httpCode >= 300) {
            $this->logger->warning('Failed to fetch SMTP OAuth token', [
                'http_code' => $httpCode,
                'error' => $curlErr,
            ]);
            return '';
        }

        $json = json_decode($raw, true);
        $accessToken = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
        $expiresIn = is_array($json) ? (int) ($json['expires_in'] ?? 0) : 0;
        if ($accessToken === '' || $expiresIn <= 0) {
            $this->logger->warning('SMTP OAuth response missing token.', ['response' => $json]);
            return '';
        }

        $this->oauthAccessToken = $accessToken;
        $this->oauthAccessTokenExpiresAt = time() + $expiresIn;
        $this->oauthAccessTokenScope = $cacheScope;
        return $accessToken;
    }

    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     * @param array<int,array{path:string,name:string,contentType:string}> $attachments
     * @param array<int,array{path:string,name:string,contentType:string,contentId:string}> $inlineAttachments
     */
    private function sendViaMicrosoftGraph(
        string $subject,
        string $html,
        string $text,
        array $to,
        array $cc,
        array $bcc,
        string $replyEmail,
        string $replyName,
        array $attachments,
        array $inlineAttachments,
    ): string {
        $grantType = strtolower((string) $this->config->get('smtp.oauth.microsoft.grant_type', ''));
        $refreshToken = (string) $this->config->get('smtp.oauth.microsoft.refresh_token', '');
        if ($refreshToken === '') {
            $refreshToken = (string) $this->config->get('smtp.oauth.refresh_token', '');
        }
        if ($refreshToken === '') {
            $refreshToken = $this->readRefreshTokenFromLocalConfig();
        }
        if ($grantType === '') {
            $grantType = $refreshToken !== '' ? 'refresh_token' : 'client_credentials';
        } elseif ($grantType === 'refresh_token' && $refreshToken === '') {
            $grantType = 'client_credentials';
        }

        $graphScopeDefault = $grantType === 'refresh_token'
            ? 'https://graph.microsoft.com/Mail.Send offline_access'
            : 'https://graph.microsoft.com/.default';
        $graphScope = (string) $this->config->get('smtp.oauth.microsoft.graph_scope', $graphScopeDefault);
        $accessToken = $this->resolveOauthAccessToken($graphScope);
        if ($accessToken === '') {
            throw new \RuntimeException('Microsoft Graph token nebyl získán. Zkontroluj oauth scope a refresh token.');
        }

        $fromUser = trim((string) $this->config->get('smtp.user', ''));
        $sendUrl = trim((string) $this->config->get('smtp.oauth.microsoft.graph_send_url', ''));
        if ($sendUrl === '') {
            if ($grantType === 'refresh_token') {
                // Delegated token: prefer explicit mailbox when configured.
                // This is important when OAuth consent was done by one account but
                // sending should happen from another mailbox with SendAs/SendOnBehalf rights.
                $sendUrl = $fromUser !== ''
                    ? 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($fromUser) . '/sendMail'
                    : 'https://graph.microsoft.com/v1.0/me/sendMail';
            } else {
                // App-only token: requires explicit mailbox address + Mail.Send application permission.
                if ($fromUser === '') {
                    throw new \RuntimeException('Pro Graph app-only režim nastav smtp.user (mailbox adresa).');
                }
                $sendUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($fromUser) . '/sendMail';
            }
        }

        $message = [
            'subject' => $subject,
            'body' => [
                'contentType' => $html !== '' ? 'HTML' : 'Text',
                'content' => $html !== '' ? $html : $text,
            ],
            'toRecipients' => array_map(fn (string $addr) => [
                'emailAddress' => ['address' => $addr],
            ], $to),
        ];
        if (!empty($cc)) {
            $message['ccRecipients'] = array_map(fn (string $addr) => [
                'emailAddress' => ['address' => $addr],
            ], $cc);
        }
        if (!empty($bcc)) {
            $message['bccRecipients'] = array_map(fn (string $addr) => [
                'emailAddress' => ['address' => $addr],
            ], $bcc);
        }
        if ($replyEmail !== '') {
            $message['replyTo'] = [[
                'emailAddress' => ['address' => $replyEmail, 'name' => $replyName],
            ]];
        }

        $graphAttachments = [];
        foreach ($attachments as $att) {
            if (!is_file($att['path'])) {
                continue;
            }
            $bytes = file_get_contents($att['path']);
            if ($bytes === false) {
                continue;
            }
            $graphAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $att['name'],
                'contentType' => $att['contentType'],
                'contentBytes' => base64_encode($bytes),
            ];
        }
        foreach ($inlineAttachments as $att) {
            if (!is_file($att['path'])) {
                continue;
            }
            $bytes = file_get_contents($att['path']);
            if ($bytes === false) {
                continue;
            }
            $graphAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $att['name'],
                'contentType' => $att['contentType'],
                'contentBytes' => base64_encode($bytes),
                'isInline' => true,
                'contentId' => $att['contentId'],
            ];
        }
        if (!empty($graphAttachments)) {
            $message['attachments'] = $graphAttachments;
        }

        $payload = json_encode([
            'message' => $message,
            'saveToSentItems' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw new \RuntimeException('Nepodařilo se serializovat payload pro Graph.');
        }

        $ch = curl_init($sendUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException('Graph send selhal: ' . $curlErr);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $detail = 'HTTP ' . $httpCode;
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json)) {
                $errCode = (string) ($json['error']['code'] ?? '');
                $msg = (string) (($json['error']['message'] ?? $json['message'] ?? ''));
                if ($errCode !== '') {
                    $detail .= ' [' . $errCode . ']';
                }
                if ($msg !== '') {
                    $detail .= ' - ' . $msg;
                }
            }
            throw new \RuntimeException('Graph send selhal (' . $grantType . '): ' . $detail);
        }

        return 'Graph send accepted (HTTP ' . $httpCode . ')';
    }

    private function readRefreshTokenFromLocalConfig(): string
    {
        $candidates = [
            '/data/cfg.local.php',
            dirname(__DIR__, 4) . '/cfg.local.php',
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }

            $data = @include $path;
            if (!is_array($data)) {
                continue;
            }

            $token = trim((string) ($data['smtp']['oauth']['microsoft']['refresh_token'] ?? ''));
            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email');
            $this->twig = new Environment($loader, [
                'autoescape' => 'html',
                'cache'      => false,
                'strict_variables' => false,
            ]);
        }
        return $this->twig;
    }

    private ?Environment $sandboxTwig = null;

    /**
     * Validuje user-editovanou šablonu proti sandbox policy (stejnou jakou
     * používá `sendTemplate` pro DB override). Vrací `null` pokud šablona
     * projde, jinak human-readable chybovou hlášku v češtině pro UI toast.
     *
     * Volá se z `EmailTemplateAction::put` před `$repo->save()` — zachytíme
     * neplatné tagy/filtry/syntax dřív, než user pošle email a uvidí ošklivý
     * runtime crash. Issue #25 follow-up.
     *
     * @return array{field:string,message:string}|null
     */
    public function validateUserTemplate(string $bodyHtml, string $bodyText): ?array
    {
        $sandbox = $this->sandboxedTwig();
        foreach (['body_html' => $bodyHtml, 'body_text' => $bodyText] as $field => $body) {
            if ($body === '') continue;
            try {
                // Trial render s prázdnými vars stačí — SecurityNotAllowed* errors
                // sandbox hlásí už při kompilaci/první návštěvě AST node.
                // strict_variables=false → undefined refs neselžou.
                $sandbox->createTemplate($body)->render([]);
            } catch (\Twig\Sandbox\SecurityNotAllowedTagError $e) {
                return ['field' => $field, 'message' => sprintf(
                    'Tag „%s" není v šabloně povolený. Sandbox povoluje pouze: if/for/set/spaceless/extends/block/use.',
                    $e->getTagName()
                )];
            } catch (\Twig\Sandbox\SecurityNotAllowedFilterError $e) {
                return ['field' => $field, 'message' => sprintf(
                    'Filtr „|%s" není povolený. Povolené filtry: escape, default, date, number_format, replace, upper, lower, trim, length, first, last, join, split, nl2br, abs, round, format aj.',
                    $e->getFilterName()
                )];
            } catch (\Twig\Sandbox\SecurityNotAllowedFunctionError $e) {
                return ['field' => $field, 'message' => sprintf(
                    'Funkce „%s()" není povolená. Povolené: date(), min(), max().',
                    $e->getFunctionName()
                )];
            } catch (\Twig\Sandbox\SecurityNotAllowedMethodError $e) {
                return ['field' => $field, 'message' => 'Volání metod na objektech není povolené.'];
            } catch (\Twig\Sandbox\SecurityNotAllowedPropertyError $e) {
                return ['field' => $field, 'message' => 'Přístup k property není povolený — použij array notaci `{{ var.klic }}`.'];
            } catch (\Twig\Error\SyntaxError $e) {
                // Strip filename z message (je to interní `__string_template__…`).
                $msg = (string) preg_replace('/ in ".*?"/', '', $e->getRawMessage());
                return ['field' => $field, 'message' => sprintf('Chyba syntaxe (řádek %d): %s', $e->getTemplateLine(), $msg)];
            } catch (\Twig\Error\RuntimeError $e) {
                // Runtime chyby (undefined property atd.) ignorujeme — závisí na reálných
                // datech, které tady nemáme. Reálný render má všechny vars naplněné.
                continue;
            } catch (\Throwable $e) {
                return ['field' => $field, 'message' => 'Neočekávaná chyba při validaci šablony: ' . $e->getMessage()];
            }
        }
        return null;
    }

    /**
     * Sandboxovaný Twig pro renderování DB šablon — chrání proti SSTI:
     * povoleny jen základní tagy, filtry a accessory na safe variables.
     * Bez funkcí (range, dump, attribute) a bez method calls mimo allow-list.
     */
    private function sandboxedTwig(): Environment
    {
        if ($this->sandboxTwig === null) {
            // `extends`/`block`/`use` musí být povoleny — uložená DB šablona dědí
            // z `_layout.html.twig` (viz EmailTemplateAction::loadDefaults, který
            // vrátí celé tělo včetně `{% extends %}{% block content %}`).
            // Tyto tagy jsou čistě strukturální (nespouští PHP) a FilesystemLoader
            // je rooted v `templates/email/`, takže nelze přes ně načíst soubor mimo.
            // Issue #25 — bez `block` selže render po každé editaci šablony.
            $allowedTags = ['if', 'for', 'set', 'spaceless', 'extends', 'block', 'use'];
            $allowedFilters = [
                'escape', 'e', 'raw', 'default', 'date', 'number_format',
                'upper', 'lower', 'capitalize', 'title', 'trim', 'replace',
                'length', 'first', 'last', 'join', 'split', 'nl2br',
                'abs', 'round', 'format',
            ];
            $allowedFunctions = ['date', 'min', 'max'];
            $allowedMethods = []; // žádné method calls na objektech
            $allowedProperties = []; // všechny array klíče OK, jen property accesy zakázané
            $policy = new SecurityPolicy($allowedTags, $allowedFilters, $allowedMethods, $allowedProperties, $allowedFunctions);

            $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email');
            $this->sandboxTwig = new Environment($loader, [
                'autoescape' => 'html',
                'cache'      => false,
                'strict_variables' => false,
            ]);
            $this->sandboxTwig->addExtension(new SandboxExtension($policy, true)); // sandboxed=true
        }
        return $this->sandboxTwig;
    }

    /**
     * Načte data pro patičku emailu — fallback pro non-invoice templates (password_reset apod).
     * Použije MIN(id) supplier — primární / „system default" branding.
     *
     * Pro invoice/reminder emaily caller (InvoiceEmailVarsBuilder) předává
     * `vars['supplier']` z konkrétní faktury (přes invoice.supplier_id) — Mailer pak nevolá tuto metodu.
     * Cached na instance lifetime.
     */
    private function loadSupplierFooter(): ?array
    {
        if ($this->supplierFooter !== null) {
            return $this->supplierFooter ?: null;
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT s.id, s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                        s.email, s.phone, s.web,
                        s.email_branding_enabled, s.email_accent_color, s.logo_path,
                        co.name_cs AS country
                   FROM supplier s
              LEFT JOIN countries co ON co.id = s.country_id
                  WHERE s.id = (SELECT MIN(id) FROM supplier)'
            );
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                $row['email_branding_enabled'] = (bool) ($row['email_branding_enabled'] ?? false);
                $row['email_accent_color']     = (string) ($row['email_accent_color'] ?: '#3B2D83');
            }
            $this->supplierFooter = $row !== false ? $row : [];
            return $this->supplierFooter ?: null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load supplier footer: ' . $e->getMessage());
            $this->supplierFooter = [];
            return null;
        }
    }

    /**
     * Spočítá display rozměry loga pro 48px display height (HTML width/height
     * atributy v `<img>` tagu — respektovány všemi email klienty na rozdíl
     * od CSS max-height, které Outlook a další ignorují).
     *
     * Doplní do $supplier klíče `logo_display_width`, `logo_display_height`.
     * Pokud logo neexistuje nebo branding je vypnutý, klíče zůstanou null.
     */
    private function addLogoDisplaySize(array $supplier): array
    {
        $supplier['logo_display_width']  = null;
        $supplier['logo_display_height'] = null;

        if (empty($supplier['email_branding_enabled']) || empty($supplier['logo_path']) || empty($supplier['id'])) {
            return $supplier;
        }
        // SafeLogoPath: viz security report @andrejtomci #2
        $abs = SafeLogoPath::resolve((string) $supplier['logo_path'], (int) $supplier['id']);
        if ($abs === null) return $supplier;

        $info = @getimagesize($abs);
        if ($info === false || (int) $info[1] === 0) return $supplier;

        $targetH = 48;
        $w = (int) $info[0];
        $h = (int) $info[1];
        $supplier['logo_display_height'] = $targetH;
        $supplier['logo_display_width']  = max(1, (int) round($w * $targetH / $h));
        return $supplier;
    }

    private function defaultSubject(string $code, string $locale): string
    {
        $subjects = [
            'cs' => [
                'password_reset'    => 'Obnova hesla — MyInvoice.cz',
                'login_otp'         => 'Ověřovací kód pro přihlášení — MyInvoice.cz',
                'invoice_send'      => 'Faktura — MyInvoice.cz',
                'invoice_reminder'  => 'Upomínka — MyInvoice.cz',
                'proforma_reminder' => 'Připomínka zálohy — MyInvoice.cz',
                'recurring_draft_reminder' => 'Koncept pravidelné faktury se brzy vystaví — MyInvoice.cz',
            ],
            'en' => [
                'password_reset'    => 'Password reset — MyInvoice.cz',
                'login_otp'         => 'Sign-in verification code — MyInvoice.cz',
                'invoice_send'      => 'Invoice — MyInvoice.cz',
                'invoice_reminder'  => 'Reminder — MyInvoice.cz',
                'proforma_reminder' => 'Advance payment reminder — MyInvoice.cz',
                'recurring_draft_reminder' => 'Recurring invoice draft will be issued soon — MyInvoice.cz',
            ],
        ];
        return $subjects[$locale][$code] ?? ($subjects['cs'][$code] ?? 'MyInvoice.cz');
    }
}
