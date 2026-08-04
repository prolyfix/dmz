<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SynstituteInstance;
use App\Repository\AvailableSlotRepository;
use App\Service\BookingForwarder;
use App\Service\SlotSyncService;
use Doctrine\ORM\EntityManagerInterface;
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
    ) {
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

        $stats = $this->slotSyncService->sync($instance, $slots);

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

        $forward = $this->bookingForwarder->forward($instance, $payload);
        if (!$forward['success']) {
            return $this->json([
                'error' => 'Upstream forwarding failed',
                'upstreamStatus' => $forward['status'],
            ], Response::HTTP_BAD_GATEWAY);
        }

        $slotUid = (string) ($payload['uniqid'] ?? $payload['slotUid'] ?? '');
        if ('' !== $slotUid) {
            $slot = $this->availableSlotRepository->findOneByInstanceAndSlotUid($instance, $slotUid);
            if (null !== $slot) {
                $slot
                    ->setBookedAt(new \DateTimeImmutable('now'))
                    ->setBookedPayload($payload)
                    ->setExportedAt(null)
                    ->touch();
                $this->entityManager->flush();
            }
        }

        return $this->json([
            'status' => 'forwarded',
            'upstreamStatus' => $forward['status'],
        ]);
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
                'appointmentType' => [
                    'string' => $slot->getAppointmentType()->getName(),
                    'duration' => $slot->getAppointmentType()->getDurationMinutes(),
                    'description' => $slot->getAppointmentType()->getDescription(),
                ],
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
