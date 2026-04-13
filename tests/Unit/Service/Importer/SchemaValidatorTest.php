<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\Importer;

use Timbrs\DatabaseDumps\Contract\ConnectionRegistryInterface;
use Timbrs\DatabaseDumps\Contract\DatabaseConnectionInterface;
use Timbrs\DatabaseDumps\Service\Importer\SchemaValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    /** @var MockObject&DatabaseConnectionInterface */
    private $connection;
    /** @var MockObject&ConnectionRegistryInterface */
    private $registry;
    /** @var SchemaValidator */
    private $validator;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(DatabaseConnectionInterface::class);
        $this->connection->method('getPlatformName')->willReturn('postgresql');

        $this->registry = $this->createMock(ConnectionRegistryInterface::class);
        $this->registry->method('getConnection')->willReturn($this->connection);

        $this->validator = new SchemaValidator($this->registry);
    }

    /**
     * Дамп и БД содержат одинаковые столбцы — результат валиден, оба массива пусты
     */
    public function testValidateAllMatch(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn(
            array(
                array('column_name' => 'id'),
                array('column_name' => 'name'),
                array('column_name' => 'email')
            )
        );

        $result = $this->validator->validate('public', 'users', array('id', 'name', 'email'));

        $this->assertTrue($result->isValid());
        $this->assertSame(array(), $result->getMissingInDb());
        $this->assertSame(array(), $result->getMissingInDump());
        $this->assertSame('', $result->getDescription());
    }

    /**
     * Дамп содержит столбцы, которых нет в БД — результат невалиден, missingInDb заполнен
     */
    public function testValidateMissingInDb(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn(
            array(
                array('column_name' => 'id'),
                array('column_name' => 'name')
            )
        );

        $result = $this->validator->validate('public', 'users', array('id', 'name', 'email', 'phone'));

        $this->assertFalse($result->isValid());
        $this->assertSame(array('email', 'phone'), $result->getMissingInDb());
        $this->assertSame(array(), $result->getMissingInDump());
        $this->assertContains('email', $result->getMissingInDb());
        $this->assertContains('phone', $result->getMissingInDb());
    }

    /**
     * БД содержит столбцы, которых нет в дампе — результат всё равно валиден, missingInDump заполнен
     */
    public function testValidateMissingInDump(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn(
            array(
                array('column_name' => 'id'),
                array('column_name' => 'name'),
                array('column_name' => 'email'),
                array('column_name' => 'created_at')
            )
        );

        $result = $this->validator->validate('public', 'users', array('id', 'name'));

        $this->assertTrue($result->isValid());
        $this->assertSame(array(), $result->getMissingInDb());
        $this->assertSame(array('email', 'created_at'), $result->getMissingInDump());
        $this->assertContains('email', $result->getMissingInDump());
        $this->assertContains('created_at', $result->getMissingInDump());
    }

    /**
     * Часть столбцов отсутствует в БД, часть — в дампе
     */
    public function testValidateBothMissing(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn(
            array(
                array('column_name' => 'id'),
                array('column_name' => 'name'),
                array('column_name' => 'created_at')
            )
        );

        $result = $this->validator->validate('public', 'users', array('id', 'name', 'deleted_flag'));

        $this->assertFalse($result->isValid());
        $this->assertSame(array('deleted_flag'), $result->getMissingInDb());
        $this->assertSame(array('created_at'), $result->getMissingInDump());
    }

    /**
     * Пустой массив столбцов дампа — результат валиден
     */
    public function testValidateEmptyDumpColumns(): void
    {
        $this->connection->expects($this->never())->method('fetchAllAssociative');

        $result = $this->validator->validate('public', 'users', array());

        $this->assertTrue($result->isValid());
        $this->assertSame(array(), $result->getMissingInDb());
        $this->assertSame(array(), $result->getMissingInDump());
    }

    /**
     * Таблица не найдена в БД (пустой результат запроса) — все столбцы дампа попадают в missingInDb
     */
    public function testValidateTableNotFoundInDb(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn(array());

        $result = $this->validator->validate('public', 'nonexistent', array('id', 'name', 'email'));

        $this->assertFalse($result->isValid());
        $this->assertSame(array('id', 'name', 'email'), $result->getMissingInDb());
        $this->assertSame(array(), $result->getMissingInDump());
    }
}
