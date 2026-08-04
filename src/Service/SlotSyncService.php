<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppointmentType;
use App\Entity\AvailableSlot;
use App\Entity\SynstituteInstance;
use App\Repository\AppointmentTypeRepository;
use App\Repository\AvailableSlotRepository;
use Doctrine\ORM\EntityManagerInterface;

class SlotSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppointmentTypeRepository $appointmentTypeRepository,
        private readonly AvailableSlotRepository $availableSlotRepository,
    ) {
    }

    /**
     * @param array<int, mixed> $rawSlots
     */
    public function sync(SynstituteInstance $instance, array $rawSlots): array
    {
        $seenUids = [];
        $inserted = 0;
        $updated = 0;

        foreach ($rawSlots as $rawSlot) {
            if (!is_array($rawSlot)) {
                continue;
            }

            $normalized = $this->normalizeSlot($rawSlot);
            if (null === $normalized) {
                continue;
            }

            [$slotUid, $date, $startAt, $endAt, $typeName, $typeDuration, $typeDescription] = $normalized;
            $seenUids[] = $slotUid;

            $appointmentType = $this->appointmentTypeRepository->findOneBy([
                'synstituteInstance' => $instance,
                'name' => $typeName,
                'durationMinutes' => $typeDuration,
            ]);

            if (null === $appointmentType) {
                $appointmentType = (new AppointmentType())
                    ->setSynstituteInstance($instance)
                    ->setName($typeName)
                    ->setDurationMinutes($typeDuration)
                    ->setDescription($typeDescription);
                $this->entityManager->persist($appointmentType);
            } elseif ($appointmentType->getDescription() !== $typeDescription) {
                $appointmentType->setDescription($typeDescription);
            }

            $slot = $this->availableSlotRepository->findOneByInstanceAndSlotUid($instance, $slotUid);
            if (null === $slot) {
                $slot = (new AvailableSlot())
                    ->setSynstituteInstance($instance)
                    ->setSlotUid($slotUid);
                $inserted++;
                $this->entityManager->persist($slot);
            } else {
                $updated++;
            }

            $slot
                ->setAppointmentType($appointmentType)
                ->setSlotDate($date)
                ->setStartAt($startAt)
                ->setEndAt($endAt)
                ->touch();
        }

        if ([] !== $seenUids) {
            $this->entityManager->createQuery(
                'DELETE FROM App\\Entity\\AvailableSlot s
                WHERE s.synstituteInstance = :instance
                AND s.slotUid NOT IN (:uids)
                AND s.bookedAt IS NULL'
            )
                ->setParameter('instance', $instance)
                ->setParameter('uids', $seenUids)
                ->execute();
        }

        $this->entityManager->flush();

        return [
            'received' => count($rawSlots),
            'inserted' => $inserted,
            'updated' => $updated,
        ];
    }

    /**
     * @param array<string, mixed> $rawSlot
     *
     * @return array{string, \DateTimeImmutable, \DateTimeImmutable, \DateTimeImmutable, string, int, ?string}|null
     */
    private function normalizeSlot(array $rawSlot): ?array
    {
        $slotUid = (string) ($rawSlot['uniqid'] ?? $rawSlot['slotUid'] ?? '');
        $dateRaw = (string) ($rawSlot['date'] ?? '');
        $startRaw = (string) ($rawSlot['startAt'] ?? '');
        $endRaw = (string) ($rawSlot['endAt'] ?? '');

        $appt = $rawSlot['appointmentType'] ?? null;
        $name = '';
        $duration = 0;
        $description = null;

        if (is_string($appt)) {
            $name = trim($appt);
            $duration = (int) ($rawSlot['duration'] ?? 0);
            $description = isset($rawSlot['description']) ? (string) $rawSlot['description'] : null;
        } elseif (is_array($appt)) {
            $name = trim((string) ($appt['string'] ?? $appt['name'] ?? ''));
            $duration = (int) ($appt['duration'] ?? 0);
            $description = isset($appt['description']) ? (string) $appt['description'] : null;
        }

        if ('' === $slotUid || '' === $dateRaw || '' === $startRaw || '' === $endRaw || '' === $name || $duration <= 0) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw);
        $startAt = \DateTimeImmutable::createFromFormat('H:i', $startRaw);
        $endAt = \DateTimeImmutable::createFromFormat('H:i', $endRaw);

        if (false === $date || false === $startAt || false === $endAt) {
            return null;
        }

        return [$slotUid, $date, $startAt, $endAt, $name, $duration, $description];
    }
}
