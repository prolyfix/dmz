<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:admin:password-hash', description: 'Generate a password hash for the instance administrator')]
class AdminPasswordHashCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Run this command interactively to avoid exposing a password in command history.');

            return Command::FAILURE;
        }

        $password = $io->askHidden('Administrator password (at least 16 characters)', static function (?string $value): string {
            if (null === $value || strlen($value) < 16) {
                throw new \InvalidArgumentException('Use a password of at least 16 characters.');
            }

            return $value;
        });
        $confirmation = $io->askHidden('Confirm password');
        if ($password !== $confirmation) {
            $io->error('Passwords do not match.');

            return Command::FAILURE;
        }

        $io->writeln('Store the following in your server environment or untracked .env.local:');
        $io->writeln("ADMIN_PASSWORD_HASH='" . password_hash($password, PASSWORD_DEFAULT) . "'");

        return Command::SUCCESS;
    }
}
