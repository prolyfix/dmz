<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailableSlot;

class BookingPayloadFactory
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function forSlot(AvailableSlot $slot, array $payload): array
    {
        $type = $slot->getAppointmentType();
        if (array_key_exists('appointmentType', $payload)) {
            $requested = $payload['appointmentType'];
            $id = is_array($requested) ? ($requested['id'] ?? null) : $requested;
            $matches = is_int($id) && null !== $type->getSourceId() && $id === $type->getSourceId();
            if (null === $type->getSourceId()) {
                $matches = $requested === $type->getName() || (
                    is_array($requested)
                    && !array_key_exists('id', $requested)
                    && ($requested['string'] ?? $requested['name'] ?? null) === $type->getName()
                    && ($requested['duration'] ?? $requested['durationMinutes'] ?? null) === $type->getDurationMinutes()
                );
            }
            if (!$matches) {
                throw new \InvalidArgumentException('appointmentType must identify the appointment category of the selected slot.');
            }
        }

        $payload['appointmentType'] = $type->toArray();

        return $payload;
    }
}
