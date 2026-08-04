<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AvailableSlotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AvailableSlotRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_slot_per_instance', columns: ['synstitute_instance_id', 'slot_uid'])]
#[ORM\Index(name: 'idx_booked_exported', columns: ['booked_at', 'exported_at'])]
class AvailableSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SynstituteInstance $synstituteInstance;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AppointmentType $appointmentType;

    #[ORM\Column(length: 128)]
    private string $slotUid;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $slotDate;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bookedPayload = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $bookedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $exportedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSynstituteInstance(): SynstituteInstance
    {
        return $this->synstituteInstance;
    }

    public function setSynstituteInstance(SynstituteInstance $synstituteInstance): self
    {
        $this->synstituteInstance = $synstituteInstance;

        return $this;
    }

    public function getAppointmentType(): AppointmentType
    {
        return $this->appointmentType;
    }

    public function setAppointmentType(AppointmentType $appointmentType): self
    {
        $this->appointmentType = $appointmentType;

        return $this;
    }

    public function getSlotUid(): string
    {
        return $this->slotUid;
    }

    public function setSlotUid(string $slotUid): self
    {
        $this->slotUid = $slotUid;

        return $this;
    }

    public function getSlotDate(): \DateTimeImmutable
    {
        return $this->slotDate;
    }

    public function setSlotDate(\DateTimeImmutable $slotDate): self
    {
        $this->slotDate = $slotDate;

        return $this;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): self
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): \DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): self
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function getBookedPayload(): ?array
    {
        return $this->bookedPayload;
    }

    public function setBookedPayload(?array $bookedPayload): self
    {
        $this->bookedPayload = $bookedPayload;

        return $this;
    }

    public function getBookedAt(): ?\DateTimeImmutable
    {
        return $this->bookedAt;
    }

    public function setBookedAt(?\DateTimeImmutable $bookedAt): self
    {
        $this->bookedAt = $bookedAt;

        return $this;
    }

    public function getExportedAt(): ?\DateTimeImmutable
    {
        return $this->exportedAt;
    }

    public function setExportedAt(?\DateTimeImmutable $exportedAt): self
    {
        $this->exportedAt = $exportedAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now');

        return $this;
    }
}
