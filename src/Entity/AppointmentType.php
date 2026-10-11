<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentTypeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_appt_category_per_instance', columns: ['synstitute_instance_id', 'source_id'])]
#[ORM\Index(name: 'idx_appt_type_name_duration', columns: ['synstitute_instance_id', 'name', 'duration_minutes'])]
class AppointmentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SynstituteInstance $synstituteInstance;

    #[ORM\Column(nullable: true)]
    private ?int $sourceId = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private int $durationMinutes;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $forBooking = null;

    public function getSourceId(): ?int
    {
        return $this->sourceId;
    }

    public function setSourceId(?int $sourceId): self
    {
        $this->sourceId = $sourceId;

        return $this;
    }

    public function getForBooking(): ?string
    {
        return $this->forBooking;
    }

    public function setForBooking(?string $forBooking): self
    {
        $this->forBooking = $forBooking;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'string' => $this->name,
            'duration' => $this->durationMinutes,
            'description' => $this->description,
        ];
        if (null !== $this->sourceId) {
            $data += [
                'id' => $this->sourceId,
                'name' => $this->name,
                'durationMinutes' => $this->durationMinutes,
                'forBooking' => $this->forBooking,
            ];
        }

        return $data;
    }

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): self
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }
}
