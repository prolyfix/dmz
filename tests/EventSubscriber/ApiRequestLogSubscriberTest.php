<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ApiRequestLogSubscriber;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiRequestLogSubscriberTest extends TestCase
{
    public function testSuccessfulApiRequestIsLoggedAtInfoLevel(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('API request completed.', self::callback(static function (array $context): bool {
                self::assertSame('GET', $context['method']);
                self::assertSame('dmz_public_slots', $context['route']);
                self::assertSame(Response::HTTP_OK, $context['status_code']);
                self::assertIsInt($context['duration_ms']);

                return true;
            }));

        $request = Request::create('/api/public/synstitutes/test-instance/slots');
        $request->attributes->set('_route', 'dmz_public_slots');
        $subscriber = new ApiRequestLogSubscriber($logger);
        $subscriber->onKernelRequest($this->requestEvent($request));
        $subscriber->onKernelResponse($this->responseEvent($request, new Response()));
    }

    public function testApiFailuresAreLoggedWithoutRequestSecrets(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('API request completed.', self::callback(static function (array $context): bool {
                self::assertSame('GET', $context['method']);
                self::assertSame('dmz_bookings_pull', $context['route']);
                self::assertSame(Response::HTTP_UNAUTHORIZED, $context['status_code']);
                self::assertArrayNotHasKey('exception', $context);
                self::assertArrayNotHasKey('path', $context);
                self::assertArrayNotHasKey('headers', $context);
                self::assertArrayNotHasKey('body', $context);

                return true;
            }));

        $request = Request::create(
            '/api/synstitute/bookings?token=query-secret',
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer header-secret'],
            'request-body-secret',
        );
        $request->attributes->set('_route', 'dmz_bookings_pull');
        $subscriber = new ApiRequestLogSubscriber($logger);
        $subscriber->onKernelRequest($this->requestEvent($request));
        $subscriber->onKernelResponse($this->responseEvent($request, new Response('', Response::HTTP_UNAUTHORIZED)));
    }

    public function testApiExceptionsAreLoggedAtErrorLevelByClassOnly(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('API request completed.', self::callback(static function (array $context): bool {
                self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $context['status_code']);
                self::assertSame(\RuntimeException::class, $context['exception']);

                return true;
            }));

        $request = Request::create('/api/synstitute/slots/sync', 'POST');
        $request->attributes->set('_route', 'dmz_slots_sync');
        $subscriber = new ApiRequestLogSubscriber($logger);
        $subscriber->onKernelRequest($this->requestEvent($request));
        $subscriber->onKernelException($this->exceptionEvent($request, new \RuntimeException('private exception detail')));
        $subscriber->onKernelResponse($this->responseEvent($request, new Response('', Response::HTTP_INTERNAL_SERVER_ERROR)));
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(new Kernel('test', false), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function exceptionEvent(Request $request, \Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(new Kernel('test', false), $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(new Kernel('test', false), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
