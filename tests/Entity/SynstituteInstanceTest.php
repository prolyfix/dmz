<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SynstituteInstance;
use PHPUnit\Framework\TestCase;

final class SynstituteInstanceTest extends TestCase
{
    public function testLifecycleAndSecurityMethods(): void
    {
        $instance = new SynstituteInstance();
        $instance
            ->setIdentifier('instance-42')
            ->setApiKeyHash('hashed-key')
            ->setBookingTargetUrl('https://booking.example.test');

        self::assertSame('instance-42', $instance->getIdentifier());
        self::assertSame('instance-42', $instance->getUserIdentifier());
        self::assertSame('hashed-key', $instance->getPassword());
        self::assertSame(['ROLE_INSTANCE'], $instance->getRoles());
        self::assertTrue($instance->isActive());
        self::assertTrue($instance->requiresHttps());

        $instance->onPrePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $instance->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $instance->getUpdatedAt());

        $createdAt = $instance->getCreatedAt();
        $instance->onPreUpdate();
        self::assertTrue($instance->getUpdatedAt() >= $createdAt);
    }
}
