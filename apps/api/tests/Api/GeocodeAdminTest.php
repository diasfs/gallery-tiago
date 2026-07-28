<?php

namespace App\Tests\Api;

use App\Entity\AdminUser;
use App\Tests\Fake\FakeGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class GeocodeAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'geocode-admin@gallery.test';
    private const ADMIN_PASSWORD = 'correct-horse-battery-staple';

    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGeocoder::reset();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearAdmins();
        $this->createAdmin();
    }

    protected function tearDown(): void
    {
        $this->clearAdmins();
        FakeGeocoder::reset();
        parent::tearDown();
    }

    public function testGeocodeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/geocode?q=paris');
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/admin/geocode/reverse?lat=1&lon=2');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testGeocodeSearchReturnsMappedResults(): void
    {
        FakeGeocoder::$searchResults = [
            [
                'name' => 'Paris',
                'city' => 'Paris',
                'country' => 'France',
                'latitude' => 48.8566,
                'longitude' => 2.3522,
                'displayName' => 'Paris, France',
            ],
        ];

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/geocode?q=paris');

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, FakeGeocoder::$searchCalls);
        $this->assertSame('paris', FakeGeocoder::$lastSearchQuery);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertCount(1, $data);
        $this->assertSame('Paris', $data[0]['name']);
        $this->assertSame('France', $data[0]['country']);
        $this->assertSame(48.8566, $data[0]['latitude']);
    }

    public function testGeocodeSearchRejectsShortQuery(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/geocode?q=a');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, FakeGeocoder::$searchCalls);
    }

    public function testGeocodeReverseReturnsResult(): void
    {
        FakeGeocoder::$reverseResult = [
            'name' => 'Paris',
            'city' => 'Paris',
            'country' => 'France',
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'displayName' => 'Paris, France',
        ];

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/geocode/reverse?lat=48.8566&lon=2.3522');

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, FakeGeocoder::$reverseCalls);
        $this->assertSame(48.8566, FakeGeocoder::$lastReverseLat);
        $this->assertSame(2.3522, FakeGeocoder::$lastReverseLon);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Paris', $data['name']);
        $this->assertSame(2.3522, $data['longitude']);
    }

    public function testGeocodeReverseRejectsInvalidCoordinates(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/geocode/reverse?lat=abc&lon=2');

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, FakeGeocoder::$reverseCalls);
    }

    public function testGeocodeReverseReturns404WhenNotFound(): void
    {
        FakeGeocoder::$reverseResult = null;

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/geocode/reverse?lat=0&lon=0');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(1, FakeGeocoder::$reverseCalls);
    }

    private function clearAdmins(): void
    {
        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $admin) {
            $this->em->remove($admin);
        }
        $this->em->flush();
    }

    private function createAdmin(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser(self::ADMIN_EMAIL, 'temp');
        $admin->setPassword($hasher->hashPassword($admin, self::ADMIN_PASSWORD));
        $this->em->persist($admin);
        $this->em->flush();
    }

    private function loginAsAdmin(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/login', [
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
        $this->assertResponseIsSuccessful();
    }
}
