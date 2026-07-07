<?php

declare(strict_types=1);

namespace MyInvoice\Action\System;

use MyInvoice\Http\Json;
use MyInvoice\Service\Update\VersionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/version — public endpoint pro footer / about / health probe.
 *
 * Vrací jen `current` + cached `latest` + `has_update` flag. Bez release
 * notes / detailů (ty má /api/admin/update/status, kde admin může číst
 * markdown v UI). Cached, žádný blocking síťový call.
 */
final class VersionAction
{
    public function __construct(private readonly VersionService $version) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $s = $this->version->getStatus();
        return Json::ok($response, [
            'current'     => $s['current'],
            'latest'      => $s['latest'],
            'has_update'  => $s['has_update'],
            'release_url' => $s['release_url'],
        ]);
    }
}
