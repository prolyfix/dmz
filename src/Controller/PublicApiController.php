<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SynstituteInstance;
use App\Repository\AvailableSlotRepository;
use App\Repository\AppointmentTypeRepository;
use App\Repository\SynstituteInstanceRepository;
use App\Service\BookingForwarder;
use App\Service\BookingPayloadFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicApiController extends AbstractController
{
    private const MAX_JSON_BYTES = 1048576;

    public function __construct(
        private readonly SynstituteInstanceRepository $instanceRepository,
        private readonly AvailableSlotRepository $availableSlotRepository,
        private readonly BookingForwarder $bookingForwarder,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
        private readonly AppointmentTypeRepository $appointmentTypeRepository,
        private readonly BookingPayloadFactory $bookingPayloadFactory,
    ) {
    }

    #[Route('/api/public/synstitutes/{identifier}/appointment-types', name: 'dmz_public_appointment_types', requirements: ['identifier' => '[A-Za-z0-9._-]{3,64}'], methods: ['GET'])]
    public function appointmentTypes(Request $request, string $identifier): JsonResponse
    {
        $httpsError = $this->requireHttps($request);
        if (null !== $httpsError) {
            return $httpsError;
        }
        $instance = $this->instanceRepository->findActiveByIdentifier($identifier);
        if (null === $instance) {
            return $this->json(['error' => 'Synstitute not found'], Response::HTTP_NOT_FOUND);
        }
        $types = [];
        foreach ($this->appointmentTypeRepository->findBy(['synstituteInstance' => $instance], ['name' => 'ASC', 'id' => 'ASC']) as $type) {
            if (null !== $type->getSourceId()) {
                $types[] = $type->toArray();
            }
        }

        return $this->json(['appointmentTypes' => $types, 'count' => count($types)], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/public/synstitutes/{identifier}/slots', name: 'dmz_public_slots', requirements: ['identifier' => '[A-Za-z0-9._-]{3,64}'], methods: ['GET'])]
    public function availableSlots(Request $request, string $identifier): JsonResponse
    {
        $httpsError = $this->requireHttps($request);
        if (null !== $httpsError) {
            return $httpsError;
        }

        $instance = $this->instanceRepository->findActiveByIdentifier($identifier);
        if (null === $instance) {
            return $this->json(['error' => 'Synstitute not found'], Response::HTTP_NOT_FOUND);
        }

        $response = [];
        foreach ($this->availableSlotRepository->findAvailableForInstance($instance) as $slot) {
            $type = $slot->getAppointmentType();
            $response[] = [
                'uniqid' => $slot->getSlotUid(),
                'date' => $slot->getSlotDate()->format('Y-m-d'),
                'startAt' => $slot->getStartAt()->format('H:i'),
                'endAt' => $slot->getEndAt()->format('H:i'),
                'appointmentType' => $type->toArray(),
            ];
        }

        return $this->json([
            'slots' => $response,
            'count' => count($response),
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/public/synstitutes/{identifier}/bookings', name: 'dmz_public_booking', requirements: ['identifier' => '[A-Za-z0-9._-]{3,64}'], methods: ['POST'])]
    public function createBooking(Request $request, string $identifier): JsonResponse
    {
        $httpsError = $this->requireHttps($request);
        if (null !== $httpsError) {
            return $httpsError;
        }

        $instance = $this->instanceRepository->findActiveByIdentifier($identifier);
        if (null === $instance) {
            return $this->json(['error' => 'Synstitute not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->readJsonPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $slotUid = trim((string) ($payload['uniqid'] ?? $payload['slotUid'] ?? ''));
        if ('' === $slotUid || strlen($slotUid) > 128) {
            return $this->json(['error' => 'A valid uniqid or slotUid is required'], Response::HTTP_BAD_REQUEST);
        }

        $slot = $this->availableSlotRepository->findOneByInstanceAndSlotUid($instance, $slotUid);
        $now = new \DateTimeImmutable('now');
        if (
            null === $slot
            || null !== $slot->getBookedAt()
            || $slot->getSlotDate()->format('Y-m-d') < $now->format('Y-m-d')
            || (
                $slot->getSlotDate()->format('Y-m-d') === $now->format('Y-m-d')
                && $slot->getStartAt()->format('H:i:s') < $now->format('H:i:s')
            )
        ) {
            return $this->json(['error' => 'Slot is no longer available'], Response::HTTP_CONFLICT);
        }

        try {
            $payload = $this->bookingPayloadFactory->forSlot($slot, $payload);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $forward = $this->bookingForwarder->forward($instance, $payload);
        $slot
            ->setBookedAt(new \DateTimeImmutable('now'))
            ->setBookedPayload($payload)
            ->setExportedAt(null)
            ->touch();
        $this->entityManager->flush();

        if (!$forward['success']) {
            $this->logger->warning('Booking forwarding failed; booking queued for Synstitute pickup.', [
                'identifier' => $instance->getIdentifier(),
                'upstreamStatus' => $forward['status'],
            ]);
        }

        return $this->json([
            'status' => $forward['success'] ? 'forwarded' : 'queued',
            'upstreamStatus' => $forward['status'],
        ], Response::HTTP_CREATED);
    }

    private function requireHttps(Request $request): ?JsonResponse
    {
        $allowInsecure = 'dev' === $this->appEnv && filter_var($_ENV['APP_ALLOW_INSECURE'] ?? '0', FILTER_VALIDATE_BOOL);
        if (!$allowInsecure && !$request->isSecure()) {
            return $this->json(['error' => 'HTTPS is required'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /** @return array<string, mixed>|JsonResponse */
    private function readJsonPayload(Request $request): array|JsonResponse
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (!str_starts_with($contentType, 'application/json')) {
            return $this->json(['error' => 'Content-Type must be application/json'], Response::HTTP_BAD_REQUEST);
        }

        $content = $request->getContent();
        if ('' === trim($content)) {
            return $this->json(['error' => 'JSON body is required'], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($content) > self::MAX_JSON_BYTES) {
            return $this->json(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return $this->json(['error' => 'JSON root must be an object'], Response::HTTP_BAD_REQUEST);
        }

        return $decoded;
    }
}
