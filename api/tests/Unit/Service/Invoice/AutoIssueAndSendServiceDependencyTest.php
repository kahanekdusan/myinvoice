<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Mail\RecipientResolver;
use PHPUnit\Framework\TestCase;

final class AutoIssueAndSendServiceDependencyTest extends TestCase
{
    public function testConstructorDependenciesUseResolvableTypes(): void
    {
        $constructor = (new \ReflectionClass(AutoIssueAndSendService::class))->getConstructor();

        self::assertNotNull($constructor);

        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            $types[$parameter->getName()] = $type->getName();
        }

        self::assertSame(Config::class, $types['config'] ?? null);
        self::assertSame(RecipientResolver::class, $types['recipients'] ?? null);
    }
}
