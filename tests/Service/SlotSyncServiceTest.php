<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppointmentType;
use App\Entity\AvailableSlot;
use App\Entity\SynstituteInstance;
use App\Repository\AppointmentTypeRepository;
use App\Repository\AvailableSlotRepository;
use App\Service\SlotSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SlotSyncServiceTest extends TestCase
{
    public function testSyncCreatesNewAppointmentTypeAndSlot(): void
    {
        $instance = new SynstituteInstance();
        $instance->setIdentifier('instance-a')->setApiKeyHash('hash')->setBookingTargetUrl('https://booking.example.test');

        $appointmentTypeRepository = $this->createMock(AppointmentTypeRepository::class);
        $availableSlotRepository = $this->createMock(AvailableSlotRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $appointmentTypeRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'synstituteInstance' => $instance,
                'name' => 'Initial consultation',
                'durationMinutes' => 30,
            ])
            ->willReturn(null);

        $availableSlotRepository->expects($this->once())
            ->method('findOneByInstanceAndSlotUid')
            ->with($instance, 'slot-123')
            ->willReturn(null);

        $entityManager->expects($this->exactly(2))
            ->method('persist')
            ->with($this->callback(static fn (object $value): bool => $value instanceof AppointmentType || $value instanceof AvailableSlot));

        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use ($instance, $query) {
                if ('instance' === $name) {
                    self::assertSame($instance, $value);
                }

                if ('uids' === $name) {
                    self::assertSame(['slot-123'], $value);
                }

                return $query;
            });
        $query->expects($this->once())
            ->method('execute')
            ->willReturn(1);

        $entityManager->expects($this->once())
            ->method('createQuery')
            ->with($this->stringContains('DELETE FROM App\\Entity\\AvailableSlot'))
            ->willReturn($query);

        $entityManager->expects($this->once())
            ->method('flush');

        $service = new SlotSyncService($entityManager, $appointmentTypeRepository, $availableSlotRepository);

        $result = $service->sync($instance, [[
            'uniqid' => 'slot-123',
            'date' => '2026-08-03',
            'startAt' => '09:00',
            'endAt' => '09:30',
            'appointmentType' => [
                'string' => 'Initial consultation',
                'duration' => 30,
                'description' => 'First patient visit',
            ],
        ]]);

        self::assertSame([
            'received' => 1,
            'inserted' => 1,
            'updated' => 0,
        ], $result);
    }

    public function testSyncUpdatesExistingSlotAndAppointmentType(): void
    {
        $instance = new SynstituteInstance();
        $instance->setIdentifier('instance-b')->setApiKeyHash('hash')->setBookingTargetUrl('https://booking.example.test');

        $existingType = (new AppointmentType())
            ->setSynstituteInstance($instance)
            ->setName('Follow-up consultation')
            ->setDurationMinutes(60)
            ->setDescription('Old summary');

        $existingSlot = (new AvailableSlot())
            ->setSynstituteInstance($instance)
            ->setAppointmentType($existingType)
            ->setSlotUid('slot-456')
            ->setSlotDate(new \DateTimeImmutable('2026-08-01'))
            ->setStartAt(new \DateTimeImmutable('2026-08-01 10:00:00'))
            ->setEndAt(new \DateTimeImmutable('2026-08-01 11:00:00'))
            ->touch();

        $appointmentTypeRepository = $this->createMock(AppointmentTypeRepository::class);
        $availableSlotRepository = $this->createMock(AvailableSlotRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $appointmentTypeRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'synstituteInstance' => $instance,
                'name' => 'Follow-up consultation',
                'durationMinutes' => 60,
            ])
            ->willReturn($existingType);

        $availableSlotRepository->expects($this->once())
            ->method('findOneByInstanceAndSlotUid')
            ->with($instance, 'slot-456')
            ->willReturn($existingSlot);

        $entityManager->expects($this->never())->method('persist');

        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use ($instance, $query) {
                if ('instance' === $name) {
                    self::assertSame($instance, $value);
                }

                if ('uids' === $name) {
                    self::assertSame(['slot-456'], $value);
                }

                return $query;
            });
        $query->expects($this->once())
            ->method('execute')
            ->willReturn(1);

        $entityManager->expects($this->once())
            ->method('createQuery')
            ->with($this->stringContains('DELETE FROM App\\Entity\\AvailableSlot'))
            ->willReturn($query);

        $entityManager->expects($this->once())
            ->method('flush');

        $service = new SlotSyncService($entityManager, $appointmentTypeRepository, $availableSlotRepository);

        $result = $service->sync($instance, [[
            'uniqid' => 'slot-456',
            'date' => '2026-08-04',
            'startAt' => '11:00',
            'endAt' => '12:00',
            'appointmentType' => [
                'string' => 'Follow-up consultation',
                'duration' => 60,
                'description' => 'Updated summary',
            ],
        ]]);

        self::assertSame([
            'received' => 1,
            'inserted' => 0,
            'updated' => 1,
        ], $result);
        self::assertSame('Updated summary', $existingType->getDescription());
        self::assertSame('2026-08-04', $existingSlot->getSlotDate()->format('Y-m-d'));
    }
}
