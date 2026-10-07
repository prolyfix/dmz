<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AdminPasswordHashCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminPasswordHashCommandTest extends TestCase
{
    public function testGeneratesHashWithoutDisplayingPassword(): void
    {
        $tester = new CommandTester(new AdminPasswordHashCommand());
        $tester->setInputs(['test-only-password-12345', 'test-only-password-12345']);
        $tester->execute([], ['interactive' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, preg_match("/ADMIN_PASSWORD_HASH='([^']+)'/", $tester->getDisplay(), $matches));
        self::assertTrue(password_verify('test-only-password-12345', $matches[1]));
        self::assertStringNotContainsString('test-only-password-12345', $tester->getDisplay());
    }

    public function testRejectsUnconfirmedPassword(): void
    {
        $tester = new CommandTester(new AdminPasswordHashCommand());
        $tester->setInputs(['test-only-password-12345', 'different-password-12345']);
        $tester->execute([], ['interactive' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('ADMIN_PASSWORD_HASH=', $tester->getDisplay());
    }

    public function testRejectsNonInteractiveInvocation(): void
    {
        $tester = new CommandTester(new AdminPasswordHashCommand());
        $tester->execute([], ['interactive' => false]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
