<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Mirror;

use Packeton\Composer\MetadataMinifier;
use Packeton\Mirror\Decorator\ProxyRepositoryFacade;
use Packeton\Mirror\Model\JsonMetadata;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\Service\RemotePackagesManager;
use Packeton\Mirror\Service\SyncProviderService;
use PHPUnit\Framework\TestCase;

class ProxyRepositoryFacadeTest extends TestCase
{
    private const ROOT = ['metadata-url' => '/p2/%package%.json', 'packages' => []];

    public function testColdCacheReturnsTheRootThatWasJustStored(): void
    {
        $stored = new JsonMetadata(\json_encode(self::ROOT));
        $repository = $this->repository();
        $repository->method('rootMetadata')->willReturnOnConsecutiveCalls(null, $stored);
        $repository->expects(self::once())->method('dumpRootMeta')->with(self::ROOT);

        $metadata = $this->facade($repository)->rootMetadata();

        self::assertSame($stored, $metadata);
    }

    public function testColdCacheFallsBackToTheFetchedRootWhenNothingWasStored(): void
    {
        $repository = $this->repository();
        $repository->method('rootMetadata')->willReturn(null);

        $metadata = $this->facade($repository)->rootMetadata();

        self::assertSame(self::ROOT, $metadata->decodeJson());
    }

    public function testWarmCacheDoesNotFetch(): void
    {
        $cached = new JsonMetadata(\json_encode(self::ROOT));
        $repository = $this->repository();
        $repository->method('rootMetadata')->willReturn($cached);
        $repository->expects(self::never())->method('dumpRootMeta');
        $sync = $this->createMock(SyncProviderService::class);
        $sync->expects(self::never())->method('loadRootComposer');

        self::assertSame($cached, $this->facade($repository, $sync)->rootMetadata());
    }

    private function repository(): RemoteProxyRepository
    {
        $repository = $this->createMock(RemoteProxyRepository::class);
        $repository->method('getConfig')->willReturn(new ProxyOptions(['url' => 'https://repo.packagist.org', 'sync_lazy' => true]));
        $repository->method('getPackageManager')->willReturn($this->createMock(RemotePackagesManager::class));

        return $repository;
    }

    private function facade(RemoteProxyRepository $repository, ?SyncProviderService $sync = null): ProxyRepositoryFacade
    {
        if (null === $sync) {
            $sync = $this->createMock(SyncProviderService::class);
            $sync->method('loadRootComposer')->willReturn(self::ROOT);
        }

        return new ProxyRepositoryFacade($repository, $sync, $this->createMock(MetadataMinifier::class));
    }
}
