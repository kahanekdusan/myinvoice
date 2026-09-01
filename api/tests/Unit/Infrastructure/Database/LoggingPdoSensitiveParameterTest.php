<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\LoggingPdo;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

final class LoggingPdoSensitiveParameterTest extends TestCase
{
    public function testConstructorRedactsPasswordFromStackTraces(): void
    {
        $constructor = new ReflectionMethod(LoggingPdo::class, '__construct');
        $password = $constructor->getParameters()[2];

        self::assertSame('password', $password->getName());
        self::assertCount(1, $password->getAttributes(SensitiveParameter::class));
    }
}
