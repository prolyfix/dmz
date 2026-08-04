<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SynstituteInstanceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: SynstituteInstanceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_instance_identifier', columns: ['identifier'])]
#[ORM\HasLifecycleCallbacks]
class SynstituteInstance implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $identifier;

    #[ORM\Column(length: 255)]
    private string $apiKeyHash;

    #[ORM\Column(length: 255)]
    private string $bookingTargetUrl;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $requireHttps = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getApiKeyHash(): string
    {
        return $this->apiKeyHash;
    }

    public function setApiKeyHash(string $apiKeyHash): self
    {
        $this->apiKeyHash = $apiKeyHash;

        return $this;
    }

    public function getBookingTargetUrl(): string
    {
        return $this->bookingTargetUrl;
    }

    public function setBookingTargetUrl(string $bookingTargetUrl): self
    {
        $this->bookingTargetUrl = $bookingTargetUrl;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function requiresHttps(): bool
    {
        return $this->requireHttps;
    }

    public function setRequireHttps(bool $requireHttps): self
    {
        $this->requireHttps = $requireHttps;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getRoles(): array
    {
        return ['ROLE_INSTANCE'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getPassword(): ?string
    {
        return $this->apiKeyHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable('now');
        $this->createdAt = $this->createdAt ?? $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }
}
