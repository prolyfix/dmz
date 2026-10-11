<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SynstituteInstance;
use App\Repository\AvailableSlotRepository;
use App\Service\BookingForwarder;
use App\Service\BookingPayloadFactory;
use App\Service\SlotSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ApiController extends AbstractController
{
    private const MAX_JSON_BYTES = 1048576;

    public function __construct(
        private readonly SlotSyncService $slotSyncService,
        private readonly AvailableSlotRepository $availableSlotRepository,
        private readonly BookingForwarder $bookingForwarder,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly BookingPayloadFactory $bookingPayloadFactory,
    ) {
    }

    #[Route('/api/synstitute/appointment-categories/sync', name: 'dmz_appointment_categories_sync', methods: ['POST'])]
    public function syncAppointmentCategories(Request $request): JsonResponse
    {
        $instance = $this->requireInstance();
        if ($instance instanceof JsonResponse) {
            return $instance;
        }
        $payload = $this->readJsonPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }
        $categories = $payload['appointmentCategories'] ?? $payload;
        if (!is_array($categories) || !array_is_list($categories)) {
            return $this->json(['error' => 'appointmentCategories must be an array'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $stats = $this->slotSyncService->syncCategories($instance, $categories);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['status' => 'ok', 'stats' => $stats]);
    }

    #[Route('/health', name: 'dmz_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'time' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
        ]);
    }

    #[Route('/api/synstitute/slots/sync', name: 'dmz_slots_sync', methods: ['POST'])]
    public function syncSlots(Request $request): JsonResponse
    {
        $instance = $this->requireInstance();
        if ($instance instanceof JsonResponse) {
            return $instance;
        }

        $payload = $this->readJsonPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $slots = $payload['slots'] ?? $payload;
        if (!is_array($slots)) {
            return $this->json(['error' => 'slots payload must be an array'], Response::HTTP_BAD_REQUEST);
        }

        $categoryStats = null;
        if (array_key_exists('appointmentCategories', $payload)) {
            if (!is_array($payload['appointmentCategories']) || !array_is_list($payload['appointmentCategories'])) {
                return $this->json(['error' => 'appointmentCategories must be an array'], Response::HTTP_BAD_REQUEST);
            }
            try {
                $categoryStats = $this->slotSyncService->syncCategories($instance, $payload['appointmentCategories']);
            } catch (\InvalidArgumentException $exception) {
                return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
            }
        }
        $stats = $this->slotSyncService->sync($instance, $slots);
        if (null !== $categoryStats) {
            $stats['appointmentCategories'] = $categoryStats;
        }

        return $this->json([
            'status' => 'ok',
            'stats' => $stats,
        ]);
    }

    #[Route('/api/bookings', name: 'dmz_booking_forward', methods: ['POST'])]
    public function forwardBooking(Request $request): JsonResponse
    {
        $instance = $this->requireInstance();
        if ($instance instanceof JsonResponse) {
            return $instance;
        }

        $payload = $this->readJsonPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $slotUid = (string) ($payload['uniqid'] ?? $payload['slotUid'] ?? '');
        $slot = '' === $slotUid ? null : $this->availableSlotRepository->findOneByInstanceAndSlotUid($instance, $slotUid);
        if (null !== $slot) {
            try {
                $payload = $this->bookingPayloadFactory->forSlot($slot, $payload);
            } catch (\InvalidArgumentException $exception) {
                return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
            }
        } elseif (array_key_exists('appointmentType', $payload)) {
            return $this->json(['error' => 'A known slot is required to validate appointmentType'], Response::HTTP_BAD_REQUEST);
        }
        $forward = $this->bookingForwarder->forward($instance, $payload);
        if (!$forward['success']) {
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
                $this->logger->error('Booking forwarding failed; no available slot to queue.', [
                    'identifier' => $instance->getIdentifier(),
                    'upstreamStatus' => $forward['status'],
                ]);

                return $this->json([
                    'error' => 'Upstream forwarding failed',
                    'upstreamStatus' => $forward['status'],
                ], Response::HTTP_BAD_GATEWAY);
            }
        }

        if (null !== $slot) {
            $slot
                ->setBookedAt(new \DateTimeImmutable('now'))
                ->setBookedPayload($payload)
                ->setExportedAt(null)
                ->touch();
            $this->entityManager->flush();
        }

        if (!$forward['success']) {
            $this->logger->warning('Booking forwarding failed; booking queued for Synstitute pickup.', [
                'identifier' => $instance->getIdentifier(),
                'upstreamStatus' => $forward['status'],
            ]);
        }

        return $this->json([
            'status' => $forward['success'] ? 'forwarded' : 'queued',
            'upstreamStatus' => $forward['status'],
        ], $forward['success'] ? Response::HTTP_OK : Response::HTTP_ACCEPTED);
    }

    #[Route('/api/synstitute/bookings', name: 'dmz_bookings_pull', methods: ['GET'])]
    public function pullBookedSlots(): JsonResponse
    {
        $instance = $this->requireInstance();
        if ($instance instanceof JsonResponse) {
            return $instance;
        }

        $slots = $this->availableSlotRepository->findBookedNotExportedForInstance($instance, 500);
        $now = new \DateTimeImmutable('now');
        $response = [];

        foreach ($slots as $slot) {
            $slot->setExportedAt($now)->touch();
            $response[] = [
                'uniqid' => $slot->getSlotUid(),
                'date' => $slot->getSlotDate()->format('Y-m-d'),
                'startAt' => $slot->getStartAt()->format('H:i'),
                'endAt' => $slot->getEndAt()->format('H:i'),
                'appointmentType' => $slot->getBookedAppointmentTypeData(),
                'bookedAt' => $slot->getBookedAt()?->format(DATE_ATOM),
                'payload' => $slot->getBookedPayload(),
            ];
        }

        $this->entityManager->flush();

        return $this->json([
            'bookings' => $response,
            'count' => count($response),
        ]);
    }

    private function requireInstance(): SynstituteInstance|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof SynstituteInstance) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $user;
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

        if (!is_array($decoded)) {
            return $this->json(['error' => 'JSON root must be an object or an array'], Response::HTTP_BAD_REQUEST);
        }

        return $decoded;
    }
}
