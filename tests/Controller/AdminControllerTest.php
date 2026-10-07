<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SynstituteInstance;
use App\Kernel;
use App\Repository\SynstituteInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminControllerTest extends WebTestCase
{
    private string $databaseFile;
    private string $adminUsername;
    private array $previousEnvironment = [];
    private KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $databaseFile = tempnam(sys_get_temp_dir(), 'dmz-admin-test-');
        self::assertNotFalse($databaseFile);
        $this->databaseFile = $databaseFile;
        $this->adminUsername = 'test-admin-' . bin2hex(random_bytes(8));

        foreach ([
            'DATABASE_URL' => 'sqlite:///' . $this->databaseFile,
            'ADMIN_USERNAME' => $this->adminUsername,
            'ADMIN_PASSWORD_HASH' => password_hash('test-only-password-12345', PASSWORD_DEFAULT),
        ] as $name => $value) {
            $this->previousEnvironment[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->client = self::createClient(['environment' => 'test'], ['HTTPS' => 'on']);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->previousEnvironment as $name => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        if (isset($this->databaseFile)) {
            unlink($this->databaseFile);
        }
    }

    public function testAdminRequiresHttpsAndAuthentication(): void
    {
        $this->client->request('GET', 'http://localhost/admin/login', [], [], ['HTTPS' => 'off']);
        self::assertResponseRedirects('https://localhost/admin/login');
        $this->client->request('GET', 'https://localhost/admin');
        self::assertResponseRedirects('https://localhost/admin/login');
    }

    public function testLoginRejectsMissingCsrfToken(): void
    {
        $this->client->request('POST', '/admin/login', [
            '_username' => $this->adminUsername,
            '_password' => 'test-only-password-12345',
        ]);
        self::assertResponseRedirects('/admin/login');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('https://localhost/admin/login');
    }

    public function testInstanceLifecycleAndKeyVisibility(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler->selectButton('Creer et generer la cle API')->form([
            'identifier' => 'synstitute-test',
            'bookingTargetUrl' => 'https://93.184.216.34/bookings',
        ]));
        self::assertResponseStatusCodeSame(201);
        $key = $this->client->getCrawler()->filter('code')->text();
        $instance = $this->findInstance();
        self::assertTrue(password_verify($key, $instance->getApiKeyHash()));
        self::assertTrue($instance->isActive());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString("frame-ancestors 'none'", $this->client->getResponse()->headers->get('Content-Security-Policy'));

        $crawler = $this->client->request('GET', '/admin');
        self::assertStringNotContainsString($key, $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($instance->getApiKeyHash(), $this->client->getResponse()->getContent());
        $this->client->submit($crawler->selectButton('Enregistrer l\'URL')->form([
            'bookingTargetUrl' => 'https://93.184.216.34/new-target',
        ]));
        self::assertResponseRedirects('/admin', 303);
        self::assertSame('https://93.184.216.34/new-target', $this->findInstance()->getBookingTargetUrl());

        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler->selectButton('Desactiver')->form());
        self::assertResponseRedirects('/admin', 303);
        self::assertFalse($this->findInstance()->isActive());
        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler->selectButton('Activer')->form());
        self::assertTrue($this->findInstance()->isActive());

        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->selectButton('Renouveler la cle API')->form(['confirm' => '1']);
        $this->client->submit($form);
        self::assertResponseIsSuccessful();
        $newKey = $this->client->getCrawler()->filter('code')->text();
        self::assertNotSame($key, $newKey);
        self::assertTrue(password_verify($newKey, $this->findInstance()->getApiKeyHash()));
        self::assertFalse(password_verify($key, $this->findInstance()->getApiKeyHash()));
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue(password_verify($newKey, $this->findInstance()->getApiKeyHash()));
    }

    public function testAdminCannotReadBookingsAndCanLogout(): void
    {
        $this->login();
        $this->client->request('GET', '/api/synstitute/bookings');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/view/bookings/1');
        self::assertResponseStatusCodeSame(401);
        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler->selectButton('Se deconnecter')->form());
        self::assertResponseRedirects('/admin/login');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('https://localhost/admin/login');
    }

    public function testCreationRejectsInvalidInputAndCsrf(): void
    {
        $this->login();
        $this->client->request('POST', '/admin/instances', [
            'identifier' => 'synstitute-test',
            'bookingTargetUrl' => 'https://93.184.216.34/bookings',
        ]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler->selectButton('Creer et generer la cle API')->form([
            'identifier' => 'synstitute-test',
            'bookingTargetUrl' => 'https://127.0.0.1/bookings',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getContainer()->get(SynstituteInstanceRepository::class)->findOneBy(['identifier' => 'synstitute-test']));
    }

    public function testSynstituteUserCannotAccessAdministration(): void
    {
        $instance = (new SynstituteInstance())
            ->setIdentifier('other-instance')
            ->setApiKeyHash('unused')
            ->setBookingTargetUrl('https://93.184.216.34/bookings');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($instance);
        $entityManager->flush();
        $this->client->loginUser($instance, 'admin');
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $crawler = $this->client->request('GET', '/admin/login');
            $this->client->submit($crawler->selectButton('Se connecter')->form([
                '_username' => $this->adminUsername,
                '_password' => 'incorrect-test-password',
            ]));
            self::assertResponseRedirects('/admin/login');
        }
        $crawler = $this->client->request('GET', '/admin/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $this->adminUsername,
            '_password' => 'test-only-password-12345',
        ]));
        self::assertResponseRedirects('/admin/login');
    }

    public function testMutationRoutesRejectMissingCsrfTokens(): void
    {
        $this->login();
        foreach (['update', 'status', 'rotate-key'] as $action) {
            $this->client->request('POST', '/admin/instances/1/' . $action);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/admin/logout');
        self::assertResponseStatusCodeSame(403);
    }

    public function testApiRemainsStateless(): void
    {
        $this->client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $this->client->request('GET', '/api/synstitute/bookings');
        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testUnconfiguredAdministratorCannotLogIn(): void
    {
        $_ENV['ADMIN_PASSWORD_HASH'] = $_SERVER['ADMIN_PASSWORD_HASH'] = '';
        $crawler = $this->client->request('GET', '/admin/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $this->adminUsername,
            '_password' => 'test-only-password-12345',
        ]));
        self::assertResponseRedirects('/admin/login');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('https://localhost/admin/login');
    }

    private function login(): void
    {
        $crawler = $this->client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $this->adminUsername,
            '_password' => 'test-only-password-12345',
        ]));
        self::assertResponseRedirects('/admin');
    }

    private function findInstance(): SynstituteInstance
    {
        $instance = self::getContainer()->get(SynstituteInstanceRepository::class)->findOneBy(['identifier' => 'synstitute-test']);
        self::assertInstanceOf(SynstituteInstance::class, $instance);

        return $instance;
    }
}
