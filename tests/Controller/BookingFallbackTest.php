<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AppointmentType;
use App\Entity\AvailableSlot;
use App\Entity\SynstituteInstance;
use App\Kernel;
use App\Service\BookingForwarder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BookingFallbackTest extends WebTestCase
{
    private const API_KEY = 'test-only-booking-key-12345678';
    private KernelBrowser $client;
    private AvailableSlot $slot;
    private array $previousDatabaseUrl;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDatabaseUrl = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient(['environment' => 'test'], ['HTTPS' => 'on']);
        $this->client->disableReboot();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $instance = (new SynstituteInstance())
            ->setIdentifier('fallback-test')
            ->setApiKeyHash(password_hash(self::API_KEY, PASSWORD_DEFAULT))
            ->setBookingTargetUrl('https://booking.example.test');
        $type = (new AppointmentType())
            ->setSynstituteInstance($instance)
            ->setName('Consultation')
            ->setDurationMinutes(30);
        $this->slot = (new AvailableSlot())
            ->setSynstituteInstance($instance)
            ->setAppointmentType($type)
            ->setSlotUid('fallback-slot')
            ->setSlotDate(new \DateTimeImmutable('tomorrow'))
            ->setStartAt(new \DateTimeImmutable('tomorrow 09:00'))
            ->setEndAt(new \DateTimeImmutable('tomorrow 09:30'))
            ->touch();
        foreach ([$instance, $type, $this->slot] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        [$env, $server] = $this->previousDatabaseUrl;
        if (null === $env) {
            unset($_ENV['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $env;
        }
        if (null === $server) {
            unset($_SERVER['DATABASE_URL']);
        } else {
            $_SERVER['DATABASE_URL'] = $server;
        }
    }

    public static function forwardingOutcomes(): iterable
    {
        foreach ([true, false] as $authenticated) {
            foreach ([0, 404, 405, 500, 201] as $status) {
                yield ($authenticated ? 'authenticated' : 'public') . '-' . $status => [$authenticated, $status];
            }
        }
    }

    #[DataProvider('forwardingOutcomes')]
    public function testBookingIsPersistedAndCanBePulled(bool $authenticated, int $upstreamStatus): void
    {
        $success = 201 === $upstreamStatus;
        $payload = ['uniqid' => 'fallback-slot', 'customer' => ['name' => 'Test customer']];
        $forwardedPayload = $payload + ['appointmentType' => $this->slot->getAppointmentType()->toArray()];
        $forwarder = $this->createMock(BookingForwarder::class);
        $forwarder->expects(self::once())->method('forward')
            ->with(self::isInstanceOf(SynstituteInstance::class), $forwardedPayload)
            ->willReturn(['success' => $success, 'status' => $upstreamStatus, 'body' => '']);
        self::getContainer()->set(BookingForwarder::class, $forwarder);

        $this->client->request(
            'POST',
            $authenticated ? '/api/bookings' : '/api/public/synstitutes/fallback-test/bookings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'] + ($authenticated ? $this->authHeaders() : []),
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame($authenticated ? ($success ? 200 : 202) : 201);
        self::assertSame(
            ['status' => $success ? 'forwarded' : 'queued', 'upstreamStatus' => $upstreamStatus],
            json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $persistedSlot = $entityManager->find(AvailableSlot::class, $this->slot->getId());
        self::assertNotNull($persistedSlot->getBookedAt());
        self::assertSame($forwardedPayload, $persistedSlot->getBookedPayload());
        self::assertNull($persistedSlot->getExportedAt());

        $this->client->request('GET', '/api/public/synstitutes/fallback-test/slots');
        self::assertResponseIsSuccessful();
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['count']);

        $this->client->request('POST', '/api/public/synstitutes/fallback-test/bookings', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);

        $this->client->request('GET', '/api/synstitute/bookings', [], [], $this->authHeaders());
        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $response['count']);
        self::assertSame('fallback-slot', $response['bookings'][0]['uniqid']);
        self::assertSame($forwardedPayload, $response['bookings'][0]['payload']);

        $this->client->request('GET', '/api/synstitute/bookings', [], [], $this->authHeaders());
        self::assertResponseIsSuccessful();
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['count']);
    }

    public static function unavailableSlots(): iterable
    {
        yield 'unknown' => ['unknown'];
        yield 'booked' => ['booked'];
        yield 'past' => ['past'];
    }

    #[DataProvider('unavailableSlots')]
    public function testPublicBookingRejectsUnavailableSlotWithoutForwarding(string $state): void
    {
        if ('booked' === $state) {
            $this->slot->setBookedAt(new \DateTimeImmutable());
        } elseif ('past' === $state) {
            $this->slot->setSlotDate(new \DateTimeImmutable('yesterday'));
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $forwarder = $this->createMock(BookingForwarder::class);
        $forwarder->expects(self::never())->method('forward');
        self::getContainer()->set(BookingForwarder::class, $forwarder);

        $this->client->request('POST', '/api/public/synstitutes/fallback-test/bookings', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'uniqid' => 'unknown' === $state ? 'unknown-slot' : 'fallback-slot',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testSuccessfulAuthenticatedForwardStillAllowsUnknownSlot(): void
    {
        $forwarder = $this->createMock(BookingForwarder::class);
        $forwarder->expects(self::once())->method('forward')
            ->willReturn(['success' => true, 'status' => 200, 'body' => '']);
        self::getContainer()->set(BookingForwarder::class, $forwarder);

        $this->client->request('POST', '/api/bookings', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + $this->authHeaders(), '{"uniqid":"unknown-slot"}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['status' => 'forwarded', 'upstreamStatus' => 200],
            json_decode($this->client->getResponse()->getContent(), true),
        );
        $this->client->request('GET', '/api/synstitute/bookings', [], [], $this->authHeaders());
        self::assertResponseIsSuccessful();
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['count']);
    }

    #[DataProvider('unavailableSlots')]
    public function testAuthenticatedFallbackCannotQueueUnavailableSlot(string $state): void
    {
        if ('booked' === $state) {
            $this->slot->setBookedAt(new \DateTimeImmutable())->setBookedPayload(['original' => true]);
        } elseif ('past' === $state) {
            $this->slot->setSlotDate(new \DateTimeImmutable('yesterday'));
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $forwarder = $this->createMock(BookingForwarder::class);
        $forwarder->expects(self::once())->method('forward')
            ->willReturn(['success' => false, 'status' => 405, 'body' => '']);
        self::getContainer()->set(BookingForwarder::class, $forwarder);

        $this->client->request('POST', '/api/bookings', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + $this->authHeaders(), json_encode([
            'uniqid' => 'unknown' === $state ? 'unknown-slot' : 'fallback-slot',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(502);
        self::assertSame(
            ['error' => 'Upstream forwarding failed', 'upstreamStatus' => 405],
            json_decode($this->client->getResponse()->getContent(), true),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $persistedSlot = $entityManager->find(AvailableSlot::class, $this->slot->getId());
        self::assertSame('booked' === $state ? ['original' => true] : null, $persistedSlot->getBookedPayload());
    }

    private function authHeaders(): array
    {
        return [
            'HTTP_X_INSTANCE_ID' => 'fallback-test',
            'HTTP_X_API_KEY' => self::API_KEY,
            'HTTP_X_REQUEST_TIMESTAMP' => (string) time(),
            'HTTP_X_REQUEST_ID' => bin2hex(random_bytes(16)),
        ];
    }

    public function testCategoryCatalogueKeepsSourceIdsAndIsIsolatedByInstance(): void
    {
        $categories = [
            ['id' => 41, 'name' => 'Consultation', 'durationMinutes' => 30, 'description' => 'First visit', 'forBooking' => 'Bring your referral'],
            ['id' => 42, 'name' => 'Consultation', 'durationMinutes' => 30, 'description' => null, 'forBooking' => null],
        ];
        $this->syncCategories($categories);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $otherInstance = (new SynstituteInstance())
            ->setIdentifier('other-instance')
            ->setApiKeyHash('unused-hash')
            ->setBookingTargetUrl('https://booking.example.test');
        $otherType = (new AppointmentType())
            ->setSynstituteInstance($otherInstance)
            ->setSourceId(41)
            ->setName('Private to another instance')
            ->setDurationMinutes(60);
        $entityManager->persist($otherInstance);
        $entityManager->persist($otherType);
        $entityManager->flush();
        $entityManager->clear();

        $this->client->request('GET', '/api/public/synstitutes/fallback-test/appointment-types');
        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $response['count']);
        self::assertSame([41, 42], array_column($response['appointmentTypes'], 'id'));
        self::assertSame('Bring your referral', $response['appointmentTypes'][0]['forBooking']);
        self::assertSame('First visit', $response['appointmentTypes'][0]['description']);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $categories[0]['name'] = 'Renamed consultation';
        $this->syncCategories($categories);
        $this->client->request('GET', '/api/public/synstitutes/fallback-test/appointment-types');
        $response = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $response['count']);
        self::assertSame('Renamed consultation', $response['appointmentTypes'][1]['name']);
    }

    public function testCombinedSyncAcceptsCategoryReferences(): void
    {
        $this->client->request('POST', '/api/synstitute/slots/sync', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + $this->authHeaders(), json_encode([
            'appointmentCategories' => [['id' => 41, 'name' => 'Consultation', 'durationMinutes' => 30]],
            'slots' => [[
                'uniqid' => 'fallback-slot',
                'date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
                'startAt' => '09:00',
                'endAt' => '09:30',
                'appointmentType' => ['id' => 41],
            ]],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/public/synstitutes/fallback-test/slots');
        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(41, $response['slots'][0]['appointmentType']['id']);
        self::assertSame(30, $response['slots'][0]['appointmentType']['durationMinutes']);
    }

    public function testInlineSlotDoesNotEraseOmittedCategoryBookingInformation(): void
    {
        $this->syncCategories([[
            'id' => 41, 'name' => 'Consultation', 'durationMinutes' => 30,
            'description' => 'First visit', 'forBooking' => 'Bring your referral',
        ]]);
        $this->client->request('POST', '/api/synstitute/slots/sync', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + $this->authHeaders(), json_encode(['slots' => [[
            'uniqid' => 'fallback-slot',
            'date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            'startAt' => '09:00', 'endAt' => '09:30',
            'appointmentType' => ['id' => 41, 'name' => 'Consultation', 'durationMinutes' => 30],
        ]]], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/public/synstitutes/fallback-test/slots');
        $type = json_decode($this->client->getResponse()->getContent(), true)['slots'][0]['appointmentType'];
        self::assertSame('First visit', $type['description']);
        self::assertSame('Bring your referral', $type['forBooking']);
    }

    public function testInvalidCategoryBatchIsRejectedWithoutPartialWrites(): void
    {
        $this->client->request('POST', '/api/synstitute/appointment-categories/sync', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + $this->authHeaders(), json_encode([
            'appointmentCategories' => [
                ['id' => 41, 'name' => 'Valid', 'durationMinutes' => 30],
                ['id' => 42, 'name' => 'Invalid', 'durationMinutes' => 0],
            ],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/public/synstitutes/fallback-test/appointment-types');
        self::assertResponseIsSuccessful();
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['count']);
    }

    public function testInlineCategoryAndReferenceAreReusedWithinOneSync(): void
    {
        $base = [
            'date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            'startAt' => '09:00',
            'endAt' => '09:30',
        ];
        $this->client->request('POST', '/api/synstitute/slots/sync', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + $this->authHeaders(), json_encode(['slots' => [
            $base + ['uniqid' => 'first-slot', 'appointmentType' => [
                'id' => 41, 'name' => 'Inline category', 'durationMinutes' => 30, 'forBooking' => 'Instructions',
            ]],
            $base + ['uniqid' => 'second-slot', 'appointmentType' => 41],
        ]], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $stats = json_decode($this->client->getResponse()->getContent(), true)['stats'];
        self::assertSame(2, $stats['inserted']);
        $this->client->request('GET', '/api/public/synstitutes/fallback-test/slots');
        $slots = json_decode($this->client->getResponse()->getContent(), true)['slots'];
        self::assertCount(2, $slots);
        self::assertSame($slots[0]['appointmentType'], $slots[1]['appointmentType']);
        self::assertSame(41, $slots[0]['appointmentType']['id']);
        $this->client->request('GET', '/api/public/synstitutes/fallback-test/appointment-types');
        self::assertSame(1, json_decode($this->client->getResponse()->getContent(), true)['count']);
    }

    #[DataProvider('forwardingOutcomes')]
    public function testSelectedCategoryIsForwardedAndSnapshotSurvivesResync(bool $authenticated, int $upstreamStatus): void
    {
        $this->slot->getAppointmentType()->setSourceId(41)->setForBooking('Original booking instructions');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $snapshot = $this->slot->getAppointmentType()->toArray();
        $payload = [
            'uniqid' => 'fallback-slot',
            'appointmentType' => ['id' => 41, 'name' => 'Untrusted name', 'durationMinutes' => 999],
            'customer' => ['firstName' => 'Test', 'birthdate' => '1990-01-01'],
        ];
        $expected = $payload;
        $expected['appointmentType'] = $snapshot;
        $success = 201 === $upstreamStatus;
        $forwarder = $this->createMock(BookingForwarder::class);
        $forwarder->expects(self::once())->method('forward')
            ->with(self::isInstanceOf(SynstituteInstance::class), $expected)
            ->willReturn(['success' => $success, 'status' => $upstreamStatus, 'body' => '']);
        self::getContainer()->set(BookingForwarder::class, $forwarder);
        $this->client->request('POST', $authenticated ? '/api/bookings' : '/api/public/synstitutes/fallback-test/bookings', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + ($authenticated ? $this->authHeaders() : []), json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($authenticated ? ($success ? 200 : 202) : 201);

        $this->syncCategories([['id' => 41, 'name' => 'Changed category', 'durationMinutes' => 60]]);
        $this->client->request('POST', '/api/synstitute/slots/sync', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + $this->authHeaders(), json_encode(['slots' => [[
            'uniqid' => 'fallback-slot',
            'date' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d'),
            'startAt' => '12:00',
            'endAt' => '13:00',
            'appointmentType' => 41,
        ]]], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $stored = $entityManager->find(AvailableSlot::class, $this->slot->getId());
        self::assertSame($expected, $stored->getBookedPayload());
        self::assertNull($stored->getExportedAt());

        $this->client->request('GET', '/api/synstitute/bookings', [], [], $this->authHeaders());
        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $response['count']);
        self::assertSame($snapshot, $response['bookings'][0]['appointmentType']);
        self::assertSame($expected, $response['bookings'][0]['payload']);
        self::assertSame('09:00', $response['bookings'][0]['startAt']);
        self::assertSame('09:30', $response['bookings'][0]['endAt']);
        self::assertSame((new \DateTimeImmutable('tomorrow'))->format('Y-m-d'), $response['bookings'][0]['date']);
        $this->client->request('GET', '/api/synstitute/bookings', [], [], $this->authHeaders());
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['count']);
    }

    public static function invalidAppointmentTypes(): iterable
    {
        foreach ([true, false] as $authenticated) {
            foreach ([42, ['id' => 42], '41', null, ['name' => 'Consultation']] as $index => $value) {
                yield ($authenticated ? 'authenticated' : 'public') . '-' . $index => [$authenticated, $value];
            }
        }
    }

    #[DataProvider('invalidAppointmentTypes')]
    public function testInvalidCategorySelectionIsNotForwarded(bool $authenticated, mixed $value): void
    {
        $this->slot->getAppointmentType()->setSourceId(41);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $forwarder = $this->createMock(BookingForwarder::class);
        $forwarder->expects(self::never())->method('forward');
        self::getContainer()->set(BookingForwarder::class, $forwarder);
        $this->client->request('POST', $authenticated ? '/api/bookings' : '/api/public/synstitutes/fallback-test/bookings', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + ($authenticated ? $this->authHeaders() : []), json_encode([
            'uniqid' => 'fallback-slot', 'appointmentType' => $value,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->slot->getBookedAt());
    }

    private function syncCategories(array $categories): void
    {
        $this->client->request('POST', '/api/synstitute/appointment-categories/sync', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ] + $this->authHeaders(), json_encode(['appointmentCategories' => $categories], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    public function testLegacyBookingWithoutCanonicalSnapshotStillExportsSlotType(): void
    {
        $this->slot->setBookedAt(new \DateTimeImmutable())->setBookedPayload(['appointmentType' => 'Legacy arbitrary value']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->request('GET', '/api/synstitute/bookings', [], [], $this->authHeaders());
        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->slot->getAppointmentType()->toArray(), $response['bookings'][0]['appointmentType']);
    }
}
