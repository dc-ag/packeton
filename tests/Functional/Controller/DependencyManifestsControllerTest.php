<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Tests\Functional\PacketonTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for GET /api/all-packages/dependency-manifests.json
 *
 * Semantics: for every accessible package, the "current version" is the highest semVer version
 * excluding branch aliases (version LIKE 'dev-%'). By default only stable versions are eligible
 * (normalizedVersion without a '-' pre-release suffix); with ?rc=1 only RC versions
 * (version LIKE '%-rc%') are eligible. The manifest carries the require / require-dev maps of
 * exactly that current version.
 *
 * Fixtures (all under the manifest-test/ vendor so other fixtures don't interfere):
 *
 *   manifest-test/lib
 *     1.0.0  require dep ^1.0, require-dev tool 2.0.0
 *     1.1.0  require dep ^1.1   (require-dev dropped)
 *     → stable current = 1.1.0 → require {dep: ^1.1}, requireDev {}
 *
 *   manifest-test/withdev
 *     1.0.0  require dep 1.0.0, require-dev tool 1.0.0
 *     → stable current = 1.0.0 → require {dep: 1.0.0}, requireDev {tool: 1.0.0}
 *
 *   manifest-test/mixed
 *     3.0.0          stable, require dep 3.0.0
 *     3.1.0-rc1.0.0  rc,     require dep 3.1.0
 *     → stable current = 3.0.0 (rc must NOT win); rc current = 3.1.0-rc1.0.0
 *
 *   manifest-test/rc-only
 *     2.0.0-rc1.0.0  require dep 2.0.0
 *     → stable: excluded (no stable version); rc current = 2.0.0-rc1.0.0
 *
 *   manifest-test/dev-only
 *     dev-main  → excluded from both channels
 *
 *   manifest-test/leaf
 *     1.0.0  (no require / require-dev)
 *     → included with empty require / requireDev maps
 */
class DependencyManifestsControllerTest extends WebTestCase
{
    use PacketonTestTrait;

    private static Connection $conn;

    /** @var array<string, int> */
    private static array $packageIds = [];
    /** @var array<string, int> */
    private static array $versionIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::ensureKernelShutdown();
        static::createClient();
        self::$conn = static::getContainer()->get(ManagerRegistry::class)->getConnection();

        self::insertFixtures();
        static::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        static::ensureKernelShutdown();
        static::createClient();
        self::$conn = static::getContainer()->get(ManagerRegistry::class)->getConnection();
        self::cleanupFixtures();
        static::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    private static function insertPackage(string $key, string $name): void
    {
        self::$conn->insert('package', [
            'name'               => $name,
            'description'        => 'manifest test fixture',
            'language'           => null,
            'abandoned'          => false,
            'autoupdated'        => false,
            'replacementPackage' => null,
            'repository'         => 'https://example.com/' . $key,
            'createdAt'          => '2024-01-01 00:00:00',
            'updatedAt'          => '2024-01-01 00:00:00',
        ]);
        self::$packageIds[$key] = (int) self::$conn->lastInsertId();
    }

    /**
     * @param array<string, string> $require
     * @param array<string, string> $requireDev
     */
    private static function insertVersion(
        string $versionKey,
        string $packageKey,
        string $name,
        string $version,
        string $normalizedVersion,
        bool $development,
        array $require = [],
        array $requireDev = []
    ): void {
        self::$conn->insert('package_version', [
            'package_id'        => self::$packageIds[$packageKey],
            'name'              => $name,
            'version'           => $version,
            'normalizedVersion' => $normalizedVersion,
            'development'       => $development,
            'createdAt'         => '2024-01-01 00:00:00',
            'releasedAt'        => '2024-01-01 00:00:00',
            'updatedAt'         => '2024-01-01 00:00:00',
            'source'            => null,
        ]);
        $versionId = (int) self::$conn->lastInsertId();
        self::$versionIds[$versionKey] = $versionId;

        foreach ($require as $depName => $constraint) {
            self::$conn->insert('link_require', [
                'version_id'     => $versionId,
                'packageName'    => $depName,
                'packageVersion' => $constraint,
            ]);
        }
        foreach ($requireDev as $depName => $constraint) {
            self::$conn->insert('link_require_dev', [
                'version_id'     => $versionId,
                'packageName'    => $depName,
                'packageVersion' => $constraint,
            ]);
        }
    }

    private static function insertFixtures(): void
    {
        self::insertPackage('lib', 'manifest-test/lib');
        self::insertVersion('lib_v1', 'lib', 'manifest-test/lib', '1.0.0', '1.0.0.0', false, ['manifest-test/dep' => '^1.0'], ['manifest-test/tool' => '2.0.0']);
        self::insertVersion('lib_v11', 'lib', 'manifest-test/lib', '1.1.0', '1.1.0.0', false, ['manifest-test/dep' => '^1.1']);

        self::insertPackage('withdev', 'manifest-test/withdev');
        self::insertVersion('withdev_v1', 'withdev', 'manifest-test/withdev', '1.0.0', '1.0.0.0', false, ['manifest-test/dep' => '1.0.0'], ['manifest-test/tool' => '1.0.0']);

        self::insertPackage('mixed', 'manifest-test/mixed');
        self::insertVersion('mixed_v3', 'mixed', 'manifest-test/mixed', '3.0.0', '3.0.0.0', false, ['manifest-test/dep' => '3.0.0']);
        self::insertVersion('mixed_rc', 'mixed', 'manifest-test/mixed', '3.1.0-rc1.0.0', '3.1.0.0-RC1.0.0', false, ['manifest-test/dep' => '3.1.0']);

        self::insertPackage('rc_only', 'manifest-test/rc-only');
        self::insertVersion('rc_only_rc', 'rc_only', 'manifest-test/rc-only', '2.0.0-rc1.0.0', '2.0.0.0-RC1.0.0', false, ['manifest-test/dep' => '2.0.0']);

        self::insertPackage('dev_only', 'manifest-test/dev-only');
        self::insertVersion('dev_only_dev', 'dev_only', 'manifest-test/dev-only', 'dev-main', '9999999-dev', true, ['manifest-test/dep' => 'dev-main']);

        self::insertPackage('leaf', 'manifest-test/leaf');
        self::insertVersion('leaf_v1', 'leaf', 'manifest-test/leaf', '1.0.0', '1.0.0.0', false);
    }

    private static function cleanupFixtures(): void
    {
        foreach (array_reverse(self::$versionIds) as $verId) {
            self::$conn->executeStatement('DELETE FROM link_require WHERE version_id = ?', [$verId]);
            self::$conn->executeStatement('DELETE FROM link_require_dev WHERE version_id = ?', [$verId]);
            self::$conn->executeStatement('DELETE FROM package_version WHERE id = ?', [$verId]);
        }
        foreach (array_reverse(self::$packageIds) as $pkgId) {
            self::$conn->executeStatement('DELETE FROM package WHERE id = ?', [$pkgId]);
        }
    }

    /**
     * @return array<string, array{name: string, currentVersion: string, require: array<string, string>, requireDev: array<string, string>}>
     */
    private function fetchManifestsByName(bool $rc = false): array
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        $client->request('GET', '/api/all-packages/dependency-manifests.json' . ($rc ? '?rc=1' : ''));
        $data = $this->getJsonResponse($client);

        static::assertArrayHasKey('manifests', $data);

        return array_column($data['manifests'], null, 'name');
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/all-packages/dependency-manifests.json');
        static::assertResponseStatusCodeSame(401);
    }

    public function testStableManifestPicksHighestStableAndCurrentRequires(): void
    {
        $byName = $this->fetchManifestsByName();

        static::assertArrayHasKey('manifest-test/lib', $byName);
        $lib = $byName['manifest-test/lib'];
        static::assertSame('1.1.0', $lib['currentVersion']);
        static::assertSame(['manifest-test/dep' => '^1.1'], $lib['require']);
        // require-dev was present in 1.0.0 but dropped in the current 1.1.0
        static::assertSame([], $lib['requireDev']);
    }

    public function testRequireDevPopulatedFromCurrentVersion(): void
    {
        $byName = $this->fetchManifestsByName();

        static::assertArrayHasKey('manifest-test/withdev', $byName);
        $withDev = $byName['manifest-test/withdev'];
        static::assertSame('1.0.0', $withDev['currentVersion']);
        static::assertSame(['manifest-test/dep' => '1.0.0'], $withDev['require']);
        static::assertSame(['manifest-test/tool' => '1.0.0'], $withDev['requireDev']);
    }

    public function testRcVersionsExcludedFromStableChannel(): void
    {
        $byName = $this->fetchManifestsByName();

        // rc-only has no stable version → omitted entirely from the stable response
        static::assertArrayNotHasKey('manifest-test/rc-only', $byName);

        // mixed must report its stable 3.0.0, never the numerically-higher 3.1.0-rc
        static::assertArrayHasKey('manifest-test/mixed', $byName);
        static::assertSame('3.0.0', $byName['manifest-test/mixed']['currentVersion']);
        static::assertSame(['manifest-test/dep' => '3.0.0'], $byName['manifest-test/mixed']['require']);
    }

    public function testRcChannelSelectsRcVersions(): void
    {
        $byName = $this->fetchManifestsByName(rc: true);

        static::assertArrayHasKey('manifest-test/rc-only', $byName);
        static::assertSame('2.0.0-rc1.0.0', $byName['manifest-test/rc-only']['currentVersion']);
        static::assertSame(['manifest-test/dep' => '2.0.0'], $byName['manifest-test/rc-only']['require']);

        static::assertArrayHasKey('manifest-test/mixed', $byName);
        static::assertSame('3.1.0-rc1.0.0', $byName['manifest-test/mixed']['currentVersion']);
        static::assertSame(['manifest-test/dep' => '3.1.0'], $byName['manifest-test/mixed']['require']);
    }

    public function testDevOnlyPackageExcludedFromBothChannels(): void
    {
        static::assertArrayNotHasKey('manifest-test/dev-only', $this->fetchManifestsByName());
        static::assertArrayNotHasKey('manifest-test/dev-only', $this->fetchManifestsByName(rc: true));
    }

    public function testDeplessPackageIncludedWithEmptyMaps(): void
    {
        $byName = $this->fetchManifestsByName();

        static::assertArrayHasKey('manifest-test/leaf', $byName);
        static::assertSame('1.0.0', $byName['manifest-test/leaf']['currentVersion']);
        static::assertSame([], $byName['manifest-test/leaf']['require']);
        static::assertSame([], $byName['manifest-test/leaf']['requireDev']);
    }
}
