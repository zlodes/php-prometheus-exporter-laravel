<?php

declare(strict_types=1);

namespace Zlodes\PrometheusClient\Laravel\Tests\Storage;

use Illuminate\Contracts\Redis\Connection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Orchestra\Testbench\TestCase;
use RedisException;
use RuntimeException;
use Zlodes\PrometheusClient\Exception\MetricKeySerializationException;
use Zlodes\PrometheusClient\Exception\MetricKeyUnserializationException;
use Zlodes\PrometheusClient\Exception\StorageReadException;
use Zlodes\PrometheusClient\Exception\StorageWriteException;
use Zlodes\PrometheusClient\KeySerialization\Serializer;
use Zlodes\PrometheusClient\Laravel\Storage\RedisStorage;
use Zlodes\PrometheusClient\Storage\DTO\MetricNameWithLabels;
use Zlodes\PrometheusClient\Storage\DTO\MetricValue;
use Zlodes\PrometheusClient\Storage\StorageTesting;

class RedisStorageTest extends TestCase
{
    use StorageTesting;
    use MockeryPHPUnitIntegration;

    public function setUp(): void
    {
        parent::setUp();

        /** @var Connection $redis */
        $redis = $this->app->make(Connection::class);

        $redis->command('FLUSHALL');
    }

    public function testClear(): void
    {
        /** @var Connection $redis */
        $redis = $this->app->make(Connection::class);

        $storage = $this->app->make(RedisStorage::class, [
            'connection' => $redis,
        ]);

        $storage->setValue(new MetricValue(
            new MetricNameWithLabels('foo', []),
            42,
        ));

        $storage->persistHistogram(
            new MetricValue(
                new MetricNameWithLabels('bar'),
                0.5,
            ),
            [0.1, 0.2, 0.3]
        );

        $storage->clear();

        $keys = $redis->command('KEYS', ['*']);

        self::assertEquals([], $keys);
    }

    public function testRedisExceptionWhileFetch(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
        );

        $connectionMock
            ->expects('command')
            ->with('HGETALL', Mockery::any())
            ->andThrow(new RedisException('Something went wrong'));

        $this->expectException(StorageReadException::class);
        $this->expectExceptionMessage('Cannot execute HGETALL command');

        iterator_to_array($storage->fetch());
    }

    public function testUnserializationExceptionWhileFetch(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
            serializer: $serializerMock = Mockery::mock(Serializer::class),
        );

        $connectionMock
            ->expects('command')
            ->with('HGETALL', Mockery::any())
            ->andReturn([
                'foo' => 42,
            ]);

        $serializerMock
            ->expects('unserialize')
            ->with('foo')
            ->andThrow(new MetricKeyUnserializationException('Something went wrong'));

        $this->expectException(StorageReadException::class);
        $this->expectExceptionMessage('Cannot unserialize metrics key for key: foo');

        iterator_to_array($storage->fetch());
    }

    public function testExceptionWhileFetch(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
        );

        $connectionMock
            ->expects('command')
            ->with('HGETALL', Mockery::any())
            ->andThrow(new RuntimeException('Something went wrong'));

        $this->expectException(StorageReadException::class);

        iterator_to_array($storage->fetch());
    }

    public function testExceptionWhileClear(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
        );

        $connectionMock
            ->expects('command')
            ->with('DEL', Mockery::any())
            ->andThrow(new RuntimeException('Something went wrong'));

        $this->expectException(StorageWriteException::class);

        $storage->clear();
    }

    public function testDenormalizationExceptionWhileIncrementingValue(): void
    {
        $storage = new RedisStorage(
            Mockery::mock(Connection::class),
            serializer: $serializerMock = Mockery::mock(Serializer::class),
        );

        $serializerMock
            ->expects('serialize')
            ->andThrow(new MetricKeySerializationException('Something went wrong'));

        $this->expectException(StorageWriteException::class);
        $this->expectExceptionMessage('Cannot serialize metrics key');

        $storage->incrementValue(
            new MetricValue(
                new MetricNameWithLabels('foo', []),
                1,
            )
        );
    }

    public function testRedisExceptionWhileIncrementingValue(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
        );

        $connectionMock
            ->expects('command')
            ->with('HINCRBYFLOAT', Mockery::any())
            ->andThrow(new RedisException('Something went wrong'));

        $this->expectException(StorageWriteException::class);
        $this->expectExceptionMessage('Cannot execute HINCRBYFLOAT command');

        $storage->incrementValue(
            new MetricValue(
                new MetricNameWithLabels('foo', []),
                1,
            )
        );
    }

    public function testSerializationExceptionWhileSettingValue(): void
    {
        $storage = new RedisStorage(
            Mockery::mock(Connection::class),
            serializer: $serializerMock = Mockery::mock(Serializer::class),
        );

        $serializerMock
            ->expects('serialize')
            ->andThrow(new MetricKeySerializationException('Something went wrong'));

        $this->expectException(StorageWriteException::class);
        $this->expectExceptionMessage('Cannot serialize metrics key');

        $storage->setValue(
            new MetricValue(
                new MetricNameWithLabels('foo', []),
                1,
            )
        );
    }

    public function testRedisExceptionWhileSettingValue(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
        );

        $connectionMock
            ->expects('command')
            ->with('HSET', Mockery::any())
            ->andThrow(new RedisException('Something went wrong'));

        $this->expectException(StorageWriteException::class);
        $this->expectExceptionMessage('Cannot execute HSET command');

        $storage->setValue(
            new MetricValue(
                new MetricNameWithLabels('foo', []),
                1,
            )
        );
    }

    public function testStorageWriteExceptionWhilePersistingOfHistogram(): void
    {
        $storage = new RedisStorage(
            Mockery::mock(Connection::class),
            $serializerMock = Mockery::mock(Serializer::class),
        );

        $serializerMock
            ->expects('serialize')
            ->andThrow(new MetricKeySerializationException('Something went wrong'));

        $this->expectException(StorageWriteException::class);
        $this->expectExceptionMessage('Cannot serialize metric key');

        $storage->persistHistogram(
            new MetricValue(
                new MetricNameWithLabels('foo', []),
                1,
            ),
            [0.1, 0.2],
        );
    }

    public function testUnserializationExceptionWhileFetchingHistograms(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
            $serializerMock = Mockery::mock(Serializer::class),
        );

        $connectionMock
            ->allows('command')
            ->with('HGETALL', Mockery::any())
            ->andReturnUsing(static function (string $command, array $args) {
                $data = [
                    'metrics_histograms_sum' => [
                        'non-unserializable-key' => 10,
                    ],
                ];

                $key = $args[0];
                return $data[$key] ?? [];
            });

        $serializerMock
            ->expects('unserialize')
            ->with('non-unserializable-key')
            ->andThrow(new MetricKeyUnserializationException('Something went wrong'));


        $this->expectException(StorageReadException::class);
        $this->expectExceptionMessage('Cannot unserialize metrics key for key: non-unserializable-key');

        iterator_to_array($storage->fetch(), false);
    }

    public function testRedisExceptionWhileFetchingHistogramBuckets(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
        );

        // First call to HGETALL from fetchGaugeAndCounterMetrics
        $connectionMock
            ->expects('command')
            ->with('HGETALL', [RedisStorage::SIMPLE_HASH_NAME])
            ->andReturn([]);

        // Second call to HGETALL from fetchHistogramMetrics (sum)
        $connectionMock
            ->expects('command')
            ->with('HGETALL', [RedisStorage::HISTOGRAM_SUM_HASH_NAME])
            ->andReturn([
                'foo' => 10,
            ]);

        // Third call to HGETALL from fetchHistogramMetrics (count)
        $connectionMock
            ->expects('command')
            ->with('HGETALL', [RedisStorage::HISTOGRAM_COUNT_HASH_NAME])
            ->andReturn([
                'foo' => 2,
            ]);

        // Fourth call to HGETALL from fetchHistogramMetrics (buckets)
        $connectionMock
            ->expects('command')
            ->with('HGETALL', [RedisStorage::HISTOGRAM_HASH_NAME_PREFIX . 'foo'])
            ->andThrow(new RedisException('Something went wrong'));

        $this->expectException(StorageReadException::class);
        $this->expectExceptionMessage('Cannot execute HGETALL command');

        iterator_to_array($storage->fetch(), false);
    }

    public function testStorageReadExceptionWhileFetchingHistograms(): void
    {
        $storage = new RedisStorage(
            $connectionMock = Mockery::mock(Connection::class),
        );

        // First call to HGETALL from fetchGaugeAndCounterMetrics
        $connectionMock
            ->expects('command')
            ->with('HGETALL', [RedisStorage::SIMPLE_HASH_NAME])
            ->andReturn([]);

        // Second call to HGETALL from fetchHistogramMetrics
        $connectionMock
            ->expects('command')
            ->with('HGETALL', [RedisStorage::HISTOGRAM_SUM_HASH_NAME])
            ->andThrow(new RedisException('Something went wrong'));

        $this->expectException(StorageReadException::class);
        $this->expectExceptionMessage('Cannot execute HGETALL command');

        iterator_to_array($storage->fetch(), false);
    }

    private function createStorage(): RedisStorage
    {
        /** @var RedisStorage */
        return $this->app->make(RedisStorage::class);
    }
}
