<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BookingTargetUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingTargetUrlValidatorTest extends TestCase
{
    #[DataProvider('invalidUrls')]
    public function testRejectsUnsafeTargets(string $url): void
    {
        self::assertFalse((new BookingTargetUrlValidator())->isAllowed($url));
    }

    public static function invalidUrls(): iterable
    {
        yield ['http://93.184.216.34/bookings'];
        yield ['https://127.0.0.1/bookings'];
        yield ['https://10.0.0.1/bookings'];
        yield ['https://169.254.169.254/'];
        yield ['https://100.64.0.1/'];
        yield ['https://[::1]/'];
        yield ['https://[::ffff:127.0.0.1]/'];
        yield ['https://[fd00::1]/'];
        yield ['https://user:password@93.184.216.34/bookings'];
        yield ['https://93.184.216.34:8443/bookings'];
        yield ['https://93.184.216.34/bookings#fragment'];
        yield ['https://93.184.216.34/' . str_repeat('a', 256)];
        yield ['not a url'];
    }

    public function testAcceptsPublicHttpsTargetWithoutMakingNetworkRequests(): void
    {
        self::assertTrue((new BookingTargetUrlValidator())->isAllowed('https://93.184.216.34:443/bookings'));
    }
}
