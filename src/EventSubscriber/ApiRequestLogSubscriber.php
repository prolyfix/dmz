<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ApiRequestLogSubscriber implements EventSubscriberInterface
{
    private const START_TIME_ATTRIBUTE = '_api_request_log_start_time';
    private const EXCEPTION_ATTRIBUTE = '_api_request_log_exception';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
            KernelEvents::EXCEPTION => 'onKernelException',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isApiRequest($request->getPathInfo())) {
            return;
        }

        $request->attributes->set(self::START_TIME_ATTRIBUTE, microtime(true));
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && $request->attributes->has(self::START_TIME_ATTRIBUTE)) {
            $request->attributes->set(self::EXCEPTION_ATTRIBUTE, $event->getThrowable()::class);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (
            !$event->isMainRequest()
            || !$request->attributes->has(self::START_TIME_ATTRIBUTE)
        ) {
            return;
        }

        $statusCode = $event->getResponse()->getStatusCode();
        $context = [
            'method' => $request->getMethod(),
            'route' => $request->attributes->get('_route', 'unmatched'),
            'status_code' => $statusCode,
            'duration_ms' => (int) round((microtime(true) - $request->attributes->get(self::START_TIME_ATTRIBUTE)) * 1000),
        ];

        $exception = $request->attributes->get(self::EXCEPTION_ATTRIBUTE);
        if (is_string($exception)) {
            $context['exception'] = $exception;
        }

        if ($statusCode >= 500) {
            $this->logger->error('API request completed.', $context);
        } elseif ($statusCode >= 400) {
            $this->logger->warning('API request completed.', $context);
        } else {
            $this->logger->info('API request completed.', $context);
        }
    }

    private function isApiRequest(string $path): bool
    {
        return '/api' === $path || str_starts_with($path, '/api/');
    }
}
