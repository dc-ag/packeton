<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Tests\Functional\PacketonTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for GET /api/packages/{name}/current-dependents
 *
 * Semantics: "current version" of a dependent = the single highest semVer version
 * (excluding dev-* branch aliases, e.g. dev-master) among all versions of that package,
 * optionally pre-filtered by version_tag substring. Both stable and dev-branch versions
 * (e.g. 1.0.x-dev) are eligible; only bare branch aliases (version LIKE 'dev-%') are excluded.
 *
 * Fixtures overview (all depend on test-vendor/target-pkg):
 *
 *   Package A — test-vendor/dep-require
 *     versions: 1.0.0 (require target), 2.0.0 (require target)
 *     highest semVer = 2.0.0 → has require → INCLUDED, dependency_type=require
 *
 *   Package B — test-vendor/dep-dropped
 *     versions: 1.0.0 (require target), 2.0.0 (NO dependency)
 *     highest semVer = 2.0.0 → no dependency → EXCLUDED
 *
 *   Package C — test-vendor/dep-require-dev
 *     versions: 1.0.0 (require-dev target)
 *     highest semVer = 1.0.0 → has require-dev → INCLUDED, dependency_type=require-dev
 *
 *   Package D — test-vendor/dep-overlap
 *     versions: 1.0.0 (require AND require-dev target)
 *     highest semVer = 1.0.0 → both → INCLUDED once, dependency_type=require (require wins)
 *
 *   Package E — test-vendor/dep-version-tag
 *     versions: 1.0.0 (require target), 2.0.0 (NO dependency)
 *     With version_tag="1.0": filtered set = {1.0.0}, highest = 1.0.0 → has require → INCLUDED
 *     Without version_tag: highest = 2.0.0 → no dependency → EXCLUDED
 *     (This is Example 2 from the spec: version_tag selects an older version that still has the dep)
 */
class CurrentDependentsControllerTest extends WebTestCase
{
    use PacketonTestTrait;

    /** @var Connection */
    private static Connection $conn;

    /** Inserted IDs for cleanup */
    private static array $packageIds = [];
    private static array $versionIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::ensureKernelShutdown();
        $client = static::createClient();
        self::$conn = static::getContainer()->get(ManagerRegistry::class)
            ->getConnection();

        self::insertFixtures();
        static::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient();
        self::$conn = static::getContainer()->get(ManagerRegistry::class)
            ->getConnection();
        self::cleanupFixtures();
        static::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    private static function insertFixtures(): void
    {
        $conn = self::$conn;
        $targetName = 'test-vendor/target-pkg';

        // ── Target package ────────────────────────────────────────────────────
        $conn->insert('package', [
            'name'               => $targetName,
            'description'        => 'Target package for current-dependents tests',
            'language'           => null,
            'abandoned'          => false,
            'autoupdated'        => false,
            'replacementPackage' => null,
            'repository'         => 'https://example.com/target',
            'createdAt'          => '2024-01-01 00:00:00',
            'updatedAt'          => '2024-01-01 00:00:00',
        ]);
        self::$packageIds['target'] = (int)$conn->lastInsertId();

        // ── Package A: dep-require ────────────────────────────────────────────
        // Two versions: 1.0.0 and 2.0.0, both require target.
        // Highest semVer = 2.0.0 → has require → INCLUDED, dependency_type=require
        $conn->insert('package', [
            'name'               => 'test-vendor/dep-require',
            'description'        => 'Both versions require target; highest is 2.0.0',
            'language'           => null,
            'abandoned'          => false,
            'autoupdated'        => false,
            'replacementPackage' => null,
            'repository'         => 'https://example.com/dep-require',
            'createdAt'          => '2024-01-01 00:00:00',
            'updatedAt'          => '2024-01-01 00:00:00',
        ]);
        $pkgAId = (int)$conn->lastInsertId();
        self::$packageIds['dep_require'] = $pkgAId;

        $conn->insert('package_version', [
            'package_id'        => $pkgAId,
            'name'              => 'test-vendor/dep-require',
            'version'           => '1.0.0',
            'normalizedVersion' => '1.0.0.0',
            'development'       => false,
            'createdAt'         => '2024-01-01 00:00:00',
            'releasedAt'        => '2024-01-01 00:00:00',
            'updatedAt'         => '2024-01-01 00:00:00',
            'source'            => null,
        ]);
        $verA1Id = (int)$conn->lastInsertId();
        self::$versionIds['dep_require_v1'] = $verA1Id;
        $conn->insert('link_require', ['version_id' => $verA1Id, 'packageName' => $targetName, 'packageVersion' => '*']);

        $conn->insert('package_version', [
            'package_id'        => $pkgAId,
            'name'              => 'test-vendor/dep-require',
            'version'           => '2.0.0',
            'normalizedVersion' => '2.0.0.0',
            'development'       => false,
            'createdAt'         => '2024-02-01 00:00:00',
            'releasedAt'        => '2024-02-01 00:00:00',
            'updatedAt'         => '2024-02-01 00:00:00',
            'source'            => null,
        ]);
        $verA2Id = (int)$conn->lastInsertId();
        self::$versionIds['dep_require_v2'] = $verA2Id;
        $conn->insert('link_require', ['version_id' => $verA2Id, 'packageName' => $targetName, 'packageVersion' => '*']);

        // ── Package B: dep-dropped ────────────────────────────────────────────
        // Two versions: 1.0.0 (requires target), 2.0.0 (does NOT require target).
        // Highest semVer = 2.0.0 → no dependency → EXCLUDED
        $conn->insert('package', [
            'name'               => 'test-vendor/dep-dropped',
            'description'        => 'Dropped dependency in newest version; must be excluded',
            'language'           => null,
            'abandoned'          => false,
            'autoupdated'        => false,
            'replacementPackage' => null,
            'repository'         => 'https://example.com/dep-dropped',
            'createdAt'          => '2024-01-01 00:00:00',
            'updatedAt'          => '2024-01-01 00:00:00',
        ]);
        $pkgBId = (int)$conn->lastInsertId();
        self::$packageIds['dep_dropped'] = $pkgBId;

        $conn->insert('package_version', [
            'package_id'        => $pkgBId,
            'name'              => 'test-vendor/dep-dropped',
            'version'           => '1.0.0',
            'normalizedVersion' => '1.0.0.0',
            'development'       => false,
            'createdAt'         => '2023-01-01 00:00:00',
            'releasedAt'        => '2023-01-01 00:00:00',
            'updatedAt'         => '2023-01-01 00:00:00',
            'source'            => null,
        ]);
        $verB1Id = (int)$conn->lastInsertId();
        self::$versionIds['dep_dropped_v1'] = $verB1Id;
        $conn->insert('link_require', ['version_id' => $verB1Id, 'packageName' => $targetName, 'packageVersion' => '^1.0']);

        $conn->insert('package_version', [
            'package_id'        => $pkgBId,
            'name'              => 'test-vendor/dep-dropped',
            'version'           => '2.0.0',
            'normalizedVersion' => '2.0.0.0',
            'development'       => false,
            'createdAt'         => '2024-01-01 00:00:00',
            'releasedAt'        => '2024-01-01 00:00:00',
            'updatedAt'         => '2024-01-01 00:00:00',
            'source'            => null,
        ]);
        $verB2Id = (int)$conn->lastInsertId();
        self::$versionIds['dep_dropped_v2'] = $verB2Id;
        // No link_require for verB2Id — dependency was dropped in 2.0.0

        // ── Package C: dep-require-dev ────────────────────────────────────────
        // One version: 1.0.0 (require-dev target).
        // Highest semVer = 1.0.0 → has require-dev → INCLUDED, dependency_type=require-dev
        $conn->insert('package', [
            'name'               => 'test-vendor/dep-require-dev',
            'description'        => 'Depends via require-dev',
            'language'           => null,
            'abandoned'          => false,
            'autoupdated'        => false,
            'replacementPackage' => null,
            'repository'         => 'https://example.com/dep-require-dev',
            'createdAt'          => '2024-01-01 00:00:00',
            'updatedAt'          => '2024-01-01 00:00:00',
        ]);
        $pkgCId = (int)$conn->lastInsertId();
        self::$packageIds['dep_require_dev'] = $pkgCId;

        $conn->insert('package_version', [
            'package_id'        => $pkgCId,
            'name'              => 'test-vendor/dep-require-dev',
            'version'           => '1.0.0',
            'normalizedVersion' => '1.0.0.0',
            'development'       => false,
            'createdAt'         => '2024-01-01 00:00:00',
            'releasedAt'        => '2024-01-01 00:00:00',
            'updatedAt'         => '2024-01-01 00:00:00',
            'source'            => null,
        ]);
        $verCId = (int)$conn->lastInsertId();
        self::$versionIds['dep_require_dev_v1'] = $verCId;
        $conn->insert('link_require_dev', ['version_id' => $verCId, 'packageName' => $targetName, 'packageVersion' => '*']);

        // ── Package D: dep-overlap ────────────────────────────────────────────
        // One version: 1.0.0 in BOTH require and require-dev.
        // Highest semVer = 1.0.0 → both → INCLUDED once, dependency_type=require (require wins)
        $conn->insert('package', [
            'name'               => 'test-vendor/dep-overlap',
            'description'        => 'Overlap: both require and require-dev in same version',
            'language'           => null,
            'abandoned'          => false,
            'autoupdated'        => false,
            'replacementPackage' => null,
            'repository'         => 'https://example.com/dep-overlap',
            'createdAt'          => '2024-01-01 00:00:00',
            'updatedAt'          => '2024-01-01 00:00:00',
        ]);
        $pkgDId = (int)$conn->lastInsertId();
        self::$packageIds['dep_overlap'] = $pkgDId;

        $conn->insert('package_version', [
            'package_id'        => $pkgDId,
            'name'              => 'test-vendor/dep-overlap',
            'version'           => '1.0.0',
            'normalizedVersion' => '1.0.0.0',
            'development'       => false,
            'createdAt'         => '2024-01-01 00:00:00',
            'releasedAt'        => '2024-01-01 00:00:00',
            'updatedAt'         => '2024-01-01 00:00:00',
            'source'            => null,
        ]);
        $verDId = (int)$conn->lastInsertId();
        self::$versionIds['dep_overlap_v1'] = $verDId;
        $conn->insert('link_require', ['version_id' => $verDId, 'packageName' => $targetName, 'packageVersion' => '*']);
        $conn->insert('link_require_dev', ['version_id' => $verDId, 'packageName' => $targetName, 'packageVersion' => '*']);

        // ── Package E: dep-version-tag ────────────────────────────────────────
        // Two versions: 1.0.0-rc2000.1.0 (require target), 2.0.0 (NO dependency).
        // Without version_tag: highest = 2.0.0 → no dependency → EXCLUDED
        // With version_tag="2000.1": filtered set = {1.0.0-rc2000.1.0}, highest = 1.0.0-rc2000.1.0
        //   → has require → INCLUDED
        // (Mirrors Example 2 from the spec)
        $conn->insert('package', [
            'name'               => 'test-vendor/dep-version-tag',
            'description'        => 'Dependency present only in version matching version_tag filter',
            'language'           => null,
            'abandoned'          => false,
            'autoupdated'        => false,
            'replacementPackage' => null,
            'repository'         => 'https://example.com/dep-version-tag',
            'createdAt'          => '2024-01-01 00:00:00',
            'updatedAt'          => '2024-01-01 00:00:00',
        ]);
        $pkgEId = (int)$conn->lastInsertId();
        self::$packageIds['dep_version_tag'] = $pkgEId;

        $conn->insert('package_version', [
            'package_id'        => $pkgEId,
            'name'              => 'test-vendor/dep-version-tag',
            'version'           => '1.0.0-rc2000.1.0',
            'normalizedVersion' => '1.0.0.0-RC2000.1.0',
            'development'       => false,
            'createdAt'         => '2023-06-01 00:00:00',
            'releasedAt'        => '2023-06-01 00:00:00',
            'updatedAt'         => '2023-06-01 00:00:00',
            'source'            => null,
        ]);
        $verE1Id = (int)$conn->lastInsertId();
        self::$versionIds['dep_version_tag_rc'] = $verE1Id;
        $conn->insert('link_require', ['version_id' => $verE1Id, 'packageName' => $targetName, 'packageVersion' => '*']);

        $conn->insert('package_version', [
            'package_id'        => $pkgEId,
            'name'              => 'test-vendor/dep-version-tag',
            'version'           => '2.0.0',
            'normalizedVersion' => '2.0.0.0',
            'development'       => false,
            'createdAt'         => '2024-01-01 00:00:00',
            'releasedAt'        => '2024-01-01 00:00:00',
            'updatedAt'         => '2024-01-01 00:00:00',
            'source'            => null,
        ]);
        $verE2Id = (int)$conn->lastInsertId();
        self::$versionIds['dep_version_tag_v2'] = $verE2Id;
        // No link_require for verE2Id — dependency was dropped in 2.0.0

        // ── Package F: dep-semver-sort ─────────────────────────────────────────
        // Two versions: 9.0.0 (NO dependency), 10.0.0 (require target).
        // Lexicographic sort would pick 9.0.0 as highest ("9" > "1" in string compare) → wrong.
        // Numeric semver sort correctly picks 10.0.0 as highest → has require → INCLUDED.
        $conn->insert('package', [
            'name'               => 'test-vendor/dep-semver-sort',
            'description'        => 'Tests that 10.x is correctly ranked higher than 9.x',
            'language'           => null,
            'abandoned'          => false,
            'autoupdated'        => false,
            'replacementPackage' => null,
            'repository'         => 'https://example.com/dep-semver-sort',
            'createdAt'          => '2024-01-01 00:00:00',
            'updatedAt'          => '2024-01-01 00:00:00',
        ]);
        $pkgFId = (int)$conn->lastInsertId();
        self::$packageIds['dep_semver_sort'] = $pkgFId;

        $conn->insert('package_version', [
            'package_id'        => $pkgFId,
            'name'              => 'test-vendor/dep-semver-sort',
            'version'           => '9.0.0',
            'normalizedVersion' => '9.0.0.0',
            'development'       => false,
            'createdAt'         => '2023-01-01 00:00:00',
            'releasedAt'        => '2023-01-01 00:00:00',
            'updatedAt'         => '2023-01-01 00:00:00',
            'source'            => null,
        ]);
        $verF1Id = (int)$conn->lastInsertId();
        self::$versionIds['dep_semver_sort_v9'] = $verF1Id;
        // No link_require for verF1Id — 9.0.0 has no dependency

        $conn->insert('package_version', [
            'package_id'        => $pkgFId,
            'name'              => 'test-vendor/dep-semver-sort',
            'version'           => '10.0.0',
            'normalizedVersion' => '10.0.0.0',
            'development'       => false,
            'createdAt'         => '2024-01-01 00:00:00',
            'releasedAt'        => '2024-01-01 00:00:00',
            'updatedAt'         => '2024-01-01 00:00:00',
            'source'            => null,
        ]);
        $verF2Id = (int)$conn->lastInsertId();
        self::$versionIds['dep_semver_sort_v10'] = $verF2Id;
        $conn->insert('link_require', ['version_id' => $verF2Id, 'packageName' => $targetName, 'packageVersion' => '*']);
    }

    private static function cleanupFixtures(): void
    {
        $conn = self::$conn;

        foreach (array_reverse(self::$versionIds) as $verId) {
            $conn->executeStatement('DELETE FROM link_require WHERE version_id = ?', [$verId]);
            $conn->executeStatement('DELETE FROM link_require_dev WHERE version_id = ?', [$verId]);
            $conn->executeStatement('DELETE FROM package_version WHERE id = ?', [$verId]);
        }
        foreach (array_reverse(self::$packageIds) as $pkgId) {
            $conn->executeStatement('DELETE FROM package WHERE id = ?', [$pkgId]);
        }
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        static::assertResponseStatusCodeSame(401);
    }

    public function testCurrentDependentsBasicList(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);

        static::assertArrayHasKey('packages', $data);
        static::assertArrayHasKey('count', $data);
        static::assertSame('test-vendor/target-pkg', $data['name']);

        $names = array_column($data['packages'], 'name');

        // Packages whose highest version has the dependency → included
        static::assertContains('test-vendor/dep-require', $names);
        static::assertContains('test-vendor/dep-require-dev', $names);
        static::assertContains('test-vendor/dep-overlap', $names);

        // dep-dropped: highest version (2.0.0) has no dependency → excluded
        static::assertNotContains('test-vendor/dep-dropped', $names);

        // dep-version-tag: highest version (2.0.0) has no dependency → excluded without filter
        static::assertNotContains('test-vendor/dep-version-tag', $names);
    }

    public function testDependencyTypeRequire(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);

        $byName = array_column($data['packages'], null, 'name');

        static::assertSame('require', $byName['test-vendor/dep-require']['dependency_type']);
    }

    public function testDependencyTypeRequireDev(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);

        $byName = array_column($data['packages'], null, 'name');

        static::assertSame('require-dev', $byName['test-vendor/dep-require-dev']['dependency_type']);
    }

    public function testOverlapReturnsRequireWins(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);

        $byName = array_column($data['packages'], null, 'name');

        // Package D is in both require and require-dev — must appear once with require
        static::assertArrayHasKey('test-vendor/dep-overlap', $byName);
        static::assertSame('require', $byName['test-vendor/dep-overlap']['dependency_type']);

        $overlapCount = count(array_filter($data['packages'], fn($p) => $p['name'] === 'test-vendor/dep-overlap'));
        static::assertSame(1, $overlapCount, 'Overlap package must appear exactly once');
    }

    public function testDroppedDependencyExcluded(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);

        $names = array_column($data['packages'], 'name');

        // dep-dropped had the dependency in 1.0.0 but dropped it in 2.0.0 (highest) → excluded
        static::assertNotContains('test-vendor/dep-dropped', $names);
    }

    public function testVersionTagIncludesWhenHighestFilteredVersionHasDep(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        // version_tag="2000.1" matches "1.0.0-rc2000.1.0" but not "2.0.0"
        // → filtered set for dep-version-tag = {1.0.0-rc2000.1.0}, which has the dependency
        // → dep-version-tag must be INCLUDED
        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json?version_tag=2000.1');
        $data = $this->getJsonResponse($client);

        $names = array_column($data['packages'], 'name');
        static::assertContains('test-vendor/dep-version-tag', $names);
    }

    public function testVersionTagExcludesWhenHighestFilteredVersionHasNoDep(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        // version_tag="2.0" matches "2.0.0" for dep-version-tag, which has no dependency → excluded
        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json?version_tag=2.0');
        $data = $this->getJsonResponse($client);

        $names = array_column($data['packages'], 'name');
        static::assertNotContains('test-vendor/dep-version-tag', $names);
    }

    public function testVersionTagNegativeMatch(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        // 'nonexistent' matches nothing → empty result
        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json?version_tag=nonexistent-xyz');
        $data = $this->getJsonResponse($client);

        static::assertSame(0, $data['count']);
        static::assertEmpty($data['packages']);
    }

    public function testListCountConsistency(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        // Without filter
        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);
        static::assertSame($data['count'], count($data['packages']), 'count must match packages list size (page 1, all fit)');

        // With version_tag filter
        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json?version_tag=2000.1');
        $data = $this->getJsonResponse($client);
        static::assertSame($data['count'], count($data['packages']), 'count must match packages list size with version_tag filter');
    }

    public function testNumericSemverSortingPicksHighestVersion(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        // Package F has versions 9.0.0 (no dep) and 10.0.0 (has dep).
        // Lexicographic sort would incorrectly pick 9.0.0 as highest ("9" > "1" in string compare).
        // Correct numeric semver sort picks 10.0.0 → has require → INCLUDED.
        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);

        $names = array_column($data['packages'], 'name');
        static::assertContains(
            'test-vendor/dep-semver-sort',
            $names,
            'dep-semver-sort must be included: 10.0.0 (highest numerically) has the dependency; ' .
            'lexicographic sort would wrongly pick 9.0.0 (no dep) and exclude it'
        );
    }

    public function testRequiredVersionIsPresentInResponse(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);

        $byName = array_column($data['packages'], null, 'name');

        // dep-require uses packageVersion='*' in link_require
        static::assertArrayHasKey('required_version', $byName['test-vendor/dep-require'], 'required_version key must be present');
        static::assertSame('*', $byName['test-vendor/dep-require']['required_version']);

        // dep-require-dev uses packageVersion='*' in link_require_dev
        static::assertArrayHasKey('required_version', $byName['test-vendor/dep-require-dev'], 'required_version key must be present');
        static::assertSame('*', $byName['test-vendor/dep-require-dev']['required_version']);
    }

    public function testOrderingIsAlphabetical(): void
    {
        $client = static::createClient();
        $this->basicLogin('admin', $client);

        $client->request('GET', '/api/packages/test-vendor/target-pkg/current-dependents.json');
        $data = $this->getJsonResponse($client);

        $names = array_column($data['packages'], 'name');
        $sorted = $names;
        sort($sorted);
        static::assertSame($sorted, $names, 'Results must be ordered alphabetically by package name');
    }
}
