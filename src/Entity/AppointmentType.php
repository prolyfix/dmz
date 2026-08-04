<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentTypeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_appt_type_per_instance', columns: ['synstitute_instance_id', 'name', 'duration_minutes'])]
class AppointmentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SynstituteInstance $synstituteInstance;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private int $durationMinutes;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

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
