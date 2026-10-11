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

    /** @param array<int, mixed> $categories */
    public function syncCategories(SynstituteInstance $instance, array $categories): array
    {
        $normalized = [];
        foreach ($categories as $category) {
            if (!is_array($category) || null === ($type = $this->normalizeType($category)) || null === $type['id']) {
                throw new \InvalidArgumentException('Each appointment category requires a positive integer id, a name and a positive durationMinutes.');
            }
            $normalized[] = $type;
        }

        $typesByKey = [];
        foreach ($normalized as $type) {
            $this->upsertType($instance, $type, $typesByKey);
        }
        $this->entityManager->flush();

        return ['received' => count($categories), 'synchronized' => count($typesByKey)];
    }

    /**
     * @param array<int, mixed> $rawSlots
     */
    public function sync(SynstituteInstance $instance, array $rawSlots): array
    {
        $seenUids = [];
        $inserted = 0;
        $updated = 0;
        // Repository lookups don't see entities persisted but not yet flushed in this run.
        /** @var array<string, AppointmentType> $typesByKey */
        $typesByKey = [];
        /** @var array<string, AvailableSlot> $slotsByUid */
        $slotsByUid = [];

        foreach ($rawSlots as $rawSlot) {
            if (!is_array($rawSlot)) {
                continue;
            }

            $normalized = $this->normalizeSlot($rawSlot, $instance, $typesByKey);
            if (null === $normalized) {
                continue;
            }

            [$slotUid, $date, $startAt, $endAt, $type] = $normalized;
            $appointmentType = $type instanceof AppointmentType ? $type : $this->upsertType($instance, $type, $typesByKey);

            if (isset($slotsByUid[$slotUid])) {
                $slot = $slotsByUid[$slotUid];
                $updated++;
            } else {
                $seenUids[] = $slotUid;
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
                $slotsByUid[$slotUid] = $slot;
            }

            if (null === $slot->getBookedAt()) {
                $slot
                    ->setAppointmentType($appointmentType)
                    ->setSlotDate($date)
                    ->setStartAt($startAt)
                    ->setEndAt($endAt)
                    ->touch();
            }
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
     * @return array{string, \DateTimeImmutable, \DateTimeImmutable, \DateTimeImmutable, array|AppointmentType}|null
     */
    private function normalizeSlot(array $rawSlot, SynstituteInstance $instance, array $typesByKey): ?array
    {
        $slotUid = (string) ($rawSlot['uniqid'] ?? $rawSlot['slotUid'] ?? '');
        $dateRaw = (string) ($rawSlot['date'] ?? '');
        $startRaw = (string) ($rawSlot['startAt'] ?? '');
        $endRaw = (string) ($rawSlot['endAt'] ?? '');

        $appt = $rawSlot['appointmentType'] ?? null;
        if (is_string($appt)) {
            $type = $this->normalizeType([
                'name' => $appt,
                'duration' => $rawSlot['duration'] ?? 0,
                'description' => $rawSlot['description'] ?? null,
            ]);
        } elseif (is_array($appt)) {
            $type = $this->normalizeType($appt);
            if (null === $type && isset($appt['id']) && !isset($appt['duration']) && !isset($appt['durationMinutes'])) {
                $type = $this->findSourceType($instance, $appt['id'], $typesByKey);
            }
        } else {
            $type = $this->findSourceType($instance, $appt, $typesByKey);
        }

        if ('' === $slotUid || '' === $dateRaw || '' === $startRaw || '' === $endRaw || null === $type) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw);
        $startAt = \DateTimeImmutable::createFromFormat('H:i', $startRaw);
        $endAt = \DateTimeImmutable::createFromFormat('H:i', $endRaw);

        if (false === $date || false === $startAt || false === $endAt) {
            return null;
        }

        return [$slotUid, $date, $startAt, $endAt, $type];
    }

    private function findSourceType(SynstituteInstance $instance, mixed $id, array $typesByKey): ?AppointmentType
    {
        if (!is_int($id) || $id <= 0) {
            return null;
        }

        return $typesByKey['id:' . $id] ?? $this->appointmentTypeRepository->findOneBy(['synstituteInstance' => $instance, 'sourceId' => $id]);
    }

    /** @return array{id: ?int, name: string, duration: int, description?: ?string, forBooking?: ?string}|null */
    private function normalizeType(array $raw): ?array
    {
        $id = $raw['id'] ?? null;
        $name = $raw['name'] ?? $raw['string'] ?? null;
        $duration = $raw['durationMinutes'] ?? $raw['duration'] ?? null;
        if (is_string($duration) && ctype_digit($duration)) {
            $duration = (int) $duration;
        }
        $description = $raw['description'] ?? null;
        $forBooking = $raw['forBooking'] ?? null;
        if (
            (null !== $id && (!is_int($id) || $id <= 0))
            || !is_string($name) || '' === trim($name) || mb_strlen($name) > 255
            || !is_int($duration) || $duration <= 0
            || (null !== $description && !is_string($description))
            || (null !== $forBooking && !is_string($forBooking))
        ) {
            return null;
        }

        $data = ['id' => $id, 'name' => trim($name), 'duration' => $duration];
        if (array_key_exists('description', $raw)) {
            $data['description'] = $description;
        }
        if (array_key_exists('forBooking', $raw)) {
            $data['forBooking'] = $forBooking;
        }

        return $data;
    }

    /**
     * @param array{id: ?int, name: string, duration: int, description?: ?string, forBooking?: ?string} $data
     * @param array<string, AppointmentType> $typesByKey
     */
    private function upsertType(SynstituteInstance $instance, array $data, array &$typesByKey): AppointmentType
    {
        $key = null !== $data['id'] ? 'id:' . $data['id'] : 'legacy:' . $data['name'] . '|' . $data['duration'];
        $criteria = ['synstituteInstance' => $instance] + (null !== $data['id']
            ? ['sourceId' => $data['id']]
            : ['name' => $data['name'], 'durationMinutes' => $data['duration'], 'sourceId' => null]);
        $type = $typesByKey[$key] ?? $this->appointmentTypeRepository->findOneBy($criteria);
        if (null === $type) {
            $type = (new AppointmentType())->setSynstituteInstance($instance);
            $this->entityManager->persist($type);
        }
        $type
            ->setSourceId($data['id'])
            ->setName($data['name'])
            ->setDurationMinutes($data['duration']);
        if (array_key_exists('description', $data) || null === $data['id']) {
            $type->setDescription($data['description'] ?? null);
        }
        if (array_key_exists('forBooking', $data) || null === $data['id']) {
            $type->setForBooking($data['forBooking'] ?? null);
        }
        $typesByKey[$key] = $type;

        return $type;
    }
}
