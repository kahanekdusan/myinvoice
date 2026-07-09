<?php

declare(strict_types=1);

namespace MyInvoice\Service\Quote;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Resolver default hodnot pro novou cenovou nabídku — ze supplier, client, project.
 * Analogie InvoiceDefaults, ale `valid_until` = issue_date + supplier.quote_validity_days
 * (místo splatnosti) a bez daňového data (nabídka není daňový doklad).
 */
final class QuoteDefaults
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    public function resolve(array $data): array
    {
        $pdo = $this->db->pdo();
        $today = date('Y-m-d');

        $clientId = (int) ($data['client_id'] ?? 0);
        $projectId = isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null;

        $client = null;
        if ($clientId) {
            $stmt = $pdo->prepare('SELECT supplier_id, language, currency_default_id, reverse_charge FROM clients WHERE id = ?');
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $project = null;
        if ($projectId) {
            $stmt = $pdo->prepare('SELECT client_id, currency_id FROM projects WHERE id = ?');
            $stmt->execute([$projectId]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($project !== null && (int) $project['client_id'] !== $clientId) {
                throw new \InvalidArgumentException("Zakázka #{$projectId} nepatří klientovi #{$clientId}.");
            }
        }

        $supplier = null;
        $supplierId = (int) ($client['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $stmt = $pdo->prepare('SELECT default_currency_id, quote_validity_days, default_prices_include_vat FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $data['issue_date'] = (string) ($data['issue_date'] ?? $today);

        // Měna: explicitní currency_id > code lookup > project/client/supplier default > CZK fallback.
        if (empty($data['currency_id']) && !empty($data['currency']) && is_string($data['currency']) && $supplierId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
            );
            $stmt->execute([$supplierId, strtoupper($data['currency'])]);
            $found = (int) $stmt->fetchColumn();
            if ($found > 0) {
                $data['currency_id'] = $found;
            }
        }
        if (empty($data['currency_id'])) {
            $data['currency_id'] = (int) (
                $project['currency_id']
                ?? $client['currency_default_id']
                ?? $supplier['default_currency_id']
                ?? 0
            );
            if ($data['currency_id'] <= 0 && $supplierId > 0) {
                $stmt = $pdo->prepare(
                    "SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC LIMIT 1"
                );
                $stmt->execute([$supplierId]);
                $data['currency_id'] = (int) $stmt->fetchColumn();
            }
        }

        // Cross-supplier integrity: currency musí patřit supplier klienta.
        if (!empty($data['currency_id']) && $supplierId > 0) {
            $check = $pdo->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
            $check->execute([(int) $data['currency_id'], $supplierId]);
            if (!$check->fetchColumn()) {
                throw new \InvalidArgumentException(
                    "Měna #{$data['currency_id']} nepatří supplier #{$supplierId} klienta."
                );
            }
        }

        if (empty($data['language'])) {
            $data['language'] = $client['language'] ?? 'cs';
        }

        if (!isset($data['reverse_charge'])) {
            $data['reverse_charge'] = (bool) ($client['reverse_charge'] ?? false);
        }

        if (!isset($data['prices_include_vat'])) {
            $data['prices_include_vat'] = (bool) ($supplier['default_prices_include_vat'] ?? false);
        }

        if (empty($data['valid_until'])) {
            $days = (int) ($supplier['quote_validity_days'] ?? 14);
            if ($days < 1) {
                $days = 14;
            }
            $data['valid_until'] = date('Y-m-d', strtotime($data['issue_date'] . " +{$days} days"));
        }

        return $data;
    }
}
