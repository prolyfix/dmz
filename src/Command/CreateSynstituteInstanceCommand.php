<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SynstituteInstance;
use App\Repository\SynstituteInstanceRepository;
use App\Service\BookingTargetUrlValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:instance:create', description: 'Create a Synstitute instance and output a new API key')]
class CreateSynstituteInstanceCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynstituteInstanceRepository $instanceRepository,
        private readonly BookingTargetUrlValidator $targetUrlValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'Unique Synstitute instance identifier')
            ->addArgument('bookingTargetUrl', InputArgument::REQUIRED, 'HTTPS target URL for forwarded bookings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = (string) $input->getArgument('identifier');
        $targetUrl = (string) $input->getArgument('bookingTargetUrl');

        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/D', $identifier)) {
            $io->error('Use an identifier of 3 to 64 letters, digits, dots, underscores or hyphens.');

            return Command::FAILURE;
        }

        if ($this->instanceRepository->findOneBy(['identifier' => $identifier])) {
            $io->error('Instance identifier already exists.');

            return Command::FAILURE;
        }

        if (!$this->targetUrlValidator->isAllowed($targetUrl)) {
            $io->error('bookingTargetUrl must be a public HTTPS URL on port 443, without credentials or a fragment.');

            return Command::FAILURE;
        }

        $apiKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $instance = (new SynstituteInstance())
            ->setIdentifier($identifier)
            ->setApiKeyHash(password_hash($apiKey, PASSWORD_DEFAULT))
            ->setBookingTargetUrl($targetUrl)
            ->setIsActive(true)
            ->setRequireHttps(true);

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $io->success('Instance created. Save this API key securely; it will not be shown again.');
        $io->writeln('instance_id: ' . $identifier);
        $io->writeln('api_key: ' . $apiKey);

        return Command::SUCCESS;
    }
}
