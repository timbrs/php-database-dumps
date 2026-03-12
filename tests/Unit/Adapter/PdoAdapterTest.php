<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Timbrs\DatabaseDumps\Adapter\PdoAdapter;
use Timbrs\DatabaseDumps\Platform\PlatformFactory;

class PdoAdapterTest extends TestCase
{
    public function testGetPlatformNameOracle(): void
    {
        $pdo = $this->createPdoMock('oci');
        $adapter = new PdoAdapter($pdo);

        $this->assertEquals(PlatformFactory::ORACLE, $adapter->getPlatformName());
    }

    public function testGetPlatformNamePostgres(): void
    {
        $pdo = $this->createPdoMock('pgsql');
        $adapter = new PdoAdapter($pdo);

        $this->assertEquals(PlatformFactory::POSTGRESQL, $adapter->getPlatformName());
    }

    public function testGetPlatformNameMysql(): void
    {
        $pdo = $this->createPdoMock('mysql');
        $adapter = new PdoAdapter($pdo);

        $this->assertEquals(PlatformFactory::MYSQL, $adapter->getPlatformName());
    }

    public function testGetPlatformNameUnknown(): void
    {
        $pdo = $this->createPdoMock('sqlite');
        $adapter = new PdoAdapter($pdo);

        $this->assertEquals('sqlite', $adapter->getPlatformName());
    }

    public function testQuote(): void
    {
        $pdo = $this->createPdoMock('mysql');
        $pdo->method('quote')->willReturnCallback(function ($value) {
            return "'" . addslashes($value) . "'";
        });

        $adapter = new PdoAdapter($pdo);

        $this->assertEquals("'test'", $adapter->quote('test'));
    }

    public function testExecuteStatement(): void
    {
        $pdo = $this->createPdoMock('mysql');
        $pdo->expects($this->once())
            ->method('exec')
            ->with('DELETE FROM users');

        $adapter = new PdoAdapter($pdo);
        $adapter->executeStatement('DELETE FROM users');
    }

    public function testFetchAllAssociative(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([
            ['id' => 1, 'name' => 'Test'],
        ]);

        $pdo = $this->createPdoMock('mysql');
        $pdo->method('query')->willReturn($stmt);

        $adapter = new PdoAdapter($pdo);
        $rows = $adapter->fetchAllAssociative('SELECT * FROM users');

        $this->assertCount(1, $rows);
        $this->assertEquals(1, $rows[0]['id']);
        $this->assertEquals('Test', $rows[0]['name']);
    }

    public function testFetchAllAssociativeNormalizesOracleKeys(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([
            ['ID' => 1, 'NAME' => 'Test', 'EMAIL' => 'test@example.com'],
        ]);

        $pdo = $this->createPdoMock('oci');
        $pdo->method('query')->willReturn($stmt);

        $adapter = new PdoAdapter($pdo);
        $rows = $adapter->fetchAllAssociative('SELECT * FROM users');

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('email', $rows[0]);
        $this->assertEquals(1, $rows[0]['id']);
        $this->assertEquals('Test', $rows[0]['name']);
    }

    public function testFetchAllAssociativeConvertsLobResources(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, 'LOB content here');
        rewind($stream);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([
            ['ID' => 1, 'DATA' => $stream],
        ]);

        $pdo = $this->createPdoMock('oci');
        $pdo->method('query')->willReturn($stmt);

        $adapter = new PdoAdapter($pdo);
        $rows = $adapter->fetchAllAssociative('SELECT * FROM docs');

        $this->assertEquals('LOB content here', $rows[0]['data']);
        $this->assertIsString($rows[0]['data']);
    }

    public function testFetchAllAssociativeNormalizesPostgresBooleans(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([
            ['id' => 1, 'is_active' => 't', 'is_deleted' => 'f'],
        ]);
        $stmt->method('columnCount')->willReturn(3);
        $stmt->method('getColumnMeta')->willReturnCallback(function ($i) {
            $columns = [
                ['name' => 'id', 'native_type' => 'int4'],
                ['name' => 'is_active', 'native_type' => 'bool'],
                ['name' => 'is_deleted', 'native_type' => 'bool'],
            ];
            return isset($columns[$i]) ? $columns[$i] : false;
        });

        $pdo = $this->createPdoMock('pgsql');
        $pdo->method('query')->willReturn($stmt);

        $adapter = new PdoAdapter($pdo);
        $rows = $adapter->fetchAllAssociative('SELECT * FROM users');

        $this->assertTrue($rows[0]['is_active']);
        $this->assertFalse($rows[0]['is_deleted']);
        $this->assertSame(1, $rows[0]['id']);
    }

    public function testFetchAllAssociativeDoesNotNormalizeMysqlBooleans(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([
            ['id' => 1, 'is_active' => 1],
        ]);

        $pdo = $this->createPdoMock('mysql');
        $pdo->method('query')->willReturn($stmt);

        $adapter = new PdoAdapter($pdo);
        $rows = $adapter->fetchAllAssociative('SELECT * FROM users');

        // MySQL: значение не нормализуется, остаётся integer
        $this->assertSame(1, $rows[0]['is_active']);
    }

    public function testFetchFirstColumn(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->with(\PDO::FETCH_COLUMN)->willReturn(['val1', 'val2']);

        $pdo = $this->createPdoMock('mysql');
        $pdo->method('prepare')->willReturn($stmt);

        $adapter = new PdoAdapter($pdo);
        $result = $adapter->fetchFirstColumn('SELECT name FROM users WHERE id = :id', ['id' => 1]);

        $this->assertEquals(['val1', 'val2'], $result);
    }

    public function testTransactions(): void
    {
        $pdo = $this->createPdoMock('mysql');
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit');
        $pdo->method('inTransaction')->willReturn(true);

        $adapter = new PdoAdapter($pdo);
        $adapter->beginTransaction();
        $this->assertTrue($adapter->isTransactionActive());
        $adapter->commit();
    }

    public function testRollBack(): void
    {
        $pdo = $this->createPdoMock('mysql');
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('rollBack');

        $adapter = new PdoAdapter($pdo);
        $adapter->beginTransaction();
        $adapter->rollBack();
    }

    /**
     * @return \PDO&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createPdoMock(string $driverName)
    {
        $pdo = $this->getMockBuilder(\PDO::class)
            ->disableOriginalConstructor()
            ->getMock();

        $pdo->method('getAttribute')
            ->with(\PDO::ATTR_DRIVER_NAME)
            ->willReturn($driverName);

        return $pdo;
    }
}
