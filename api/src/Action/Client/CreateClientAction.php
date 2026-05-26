<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateClientAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClientRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = Validation::client($body);
        if (!empty($errors)) {
            $firstField = array_key_first($errors);
            $firstMsg = $firstField !== null && !empty($errors[$firstField][0])
                ? (string) $errors[$firstField][0]
                : 'Validace selhala';
            return Json::error($response, 'validation_failed', 'Validace selhala: ' . $firstMsg, 400, ['fields' => $errors]);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId === 0) {
            $supplierId = $this->resolveSupplierId($user);
        }
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Nelze vytvořit klienta — chybí supplier kontext.', 400);
        }
        try {
            $id = $this->repo->create($body, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }
        $client = $this->repo->find($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('client.created', $user['id'] ?? null, 'client', $id, [
            'company_name' => $body['company_name'],
            'ic' => $body['ic'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $client, 201);
    }

    private function resolveSupplierId(array $user): int
    {
        $pdo = $this->db->pdo();

        $supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($supplierId > 0) {
            return $supplierId;
        }

        if (($user['role'] ?? '') !== 'admin') {
            return 0;
        }

        try {
            $pdo->beginTransaction();

            $supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier FOR UPDATE')->fetchColumn();
            if ($supplierId > 0) {
                $pdo->commit();
                return $supplierId;
            }

            $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
            if ($countryId === 0) {
                $countryId = (int) $pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn();
            }

            $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
            if ($vatRateId === 0) {
                $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
            }

            if ($countryId === 0 || $vatRateId === 0) {
                $pdo->rollBack();
                return 0;
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            $companyName = trim((string) ($user['name'] ?? 'MyInvoice Supplier'));
            if ($companyName === '') {
                $companyName = 'MyInvoice Supplier';
            }
            $email = trim((string) ($user['email'] ?? 'admin@example.com'));
            if ($email === '') {
                $email = 'admin@example.com';
            }

            $stmt = $pdo->prepare(
                'INSERT INTO supplier
                (company_name, display_name, street, city, zip, country_id, ic, dic, is_vat_payer,
                 email, phone, web, default_currency_id, default_vat_rate_id, default_payment_due_days, default_hourly_rate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
            );
            $stmt->execute([
                $companyName,
                $companyName,
                'Unknown street',
                'Unknown city',
                '00000',
                $countryId,
                null,
                null,
                0,
                $email,
                null,
                null,
                $vatRateId,
                14,
                '0.00',
            ]);

            $supplierId = (int) $pdo->lastInsertId();
            if ($supplierId <= 0) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $pdo->rollBack();
                return 0;
            }

            $insertCur = $pdo->prepare(
                'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
            );
            $insertCur->execute([$supplierId, 'CZK', 'CZK - default', 'Kc', 'Ceska koruna', 'Czech Koruna']);
            $defaultCurrencyId = (int) $pdo->lastInsertId();
            $insertCur->execute([$supplierId, 'EUR', 'EUR - default', 'EUR', 'Euro', 'Euro']);

            if ($defaultCurrencyId > 0) {
                $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
                    ->execute([$defaultCurrencyId, $supplierId]);
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();

            return $supplierId;
        } catch (\Throwable) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return 0;
        }
    }
}
