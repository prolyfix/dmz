<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SynstituteInstance;
use App\Repository\AvailableSlotRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BookingViewController extends AbstractController
{
    public function __construct(
        private readonly AvailableSlotRepository $availableSlotRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
    }

    #[Route('/view/bookings/{id}', name: 'dmz_bookings_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(Request $request, int $id): Response
    {
        $allowInsecure = 'dev' === $this->appEnv && filter_var($_ENV['APP_ALLOW_INSECURE'] ?? '0', FILTER_VALIDATE_BOOL);
        if (!$allowInsecure && !$request->isSecure()) {
            return new Response('HTTPS is required.', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain']);
        }

        $instance = $this->getUser();
        // An instance may only view its own bookings.
        if (!$instance instanceof SynstituteInstance || !$instance->isActive() || $instance->getId() !== $id) {
            throw $this->createAccessDeniedException();
        }

        $rows = '';
        foreach ($this->availableSlotRepository->findBookedForInstance($instance) as $slot) {
            $type = $slot->getAppointmentType();
            $payload = $slot->getBookedPayload();
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s – %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><pre>%s</pre></td></tr>',
                $this->e($slot->getSlotDate()->format('Y-m-d')),
                $this->e($slot->getStartAt()->format('H:i')),
                $this->e($slot->getEndAt()->format('H:i')),
                $this->e($type->getName()),
                $this->e($slot->getSlotUid()),
                $this->e($slot->getBookedAt()?->format('Y-m-d H:i') ?? ''),
                $this->e($slot->getExportedAt()?->format('Y-m-d H:i') ?? '–'),
                $this->e(null === $payload ? '' : (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            );
        }

        if ('' === $rows) {
            $rows = '<tr><td colspan="7">No bookings found.</td></tr>';
        }

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <title>Bookings – {$this->e($instance->getIdentifier())}</title>
            <style>
            body{font-family:system-ui,sans-serif;margin:2rem;color:#222}
            table{border-collapse:collapse;width:100%}
            th,td{border:1px solid #ccc;padding:.4rem .6rem;text-align:left;vertical-align:top}
            th{background:#f3f3f3}
            pre{margin:0;font-size:.8rem;white-space:pre-wrap}
            </style>
            </head>
            <body>
            <h1>Bookings – {$this->e($instance->getIdentifier())}</h1>
            <table>
            <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Slot UID</th><th>Booked at</th><th>Exported at</th><th>Payload</th></tr></thead>
            <tbody>{$rows}</tbody>
            </table>
            </body>
            </html>
            HTML;

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
