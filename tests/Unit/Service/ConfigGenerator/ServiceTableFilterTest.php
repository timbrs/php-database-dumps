<?php

namespace Timbrs\DatabaseDumps\Tests\Unit\Service\ConfigGenerator;

use Timbrs\DatabaseDumps\Service\ConfigGenerator\ServiceTableFilter;
use PHPUnit\Framework\TestCase;

class ServiceTableFilterTest extends TestCase
{
    /** @var ServiceTableFilter */
    private $filter;

    protected function setUp(): void
    {
        $this->filter = new ServiceTableFilter();
    }

    /**
     * @dataProvider ignoredTablesProvider
     */
    public function testShouldIgnoreExactNames(string $tableName): void
    {
        $this->assertTrue($this->filter->shouldIgnore($tableName));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ignoredTablesProvider(): array
    {
        return [
            'migrations' => ['migrations'],
            'password_resets' => ['password_resets'],
            'password_reset_tokens' => ['password_reset_tokens'],
            'failed_jobs' => ['failed_jobs'],
            'personal_access_tokens' => ['personal_access_tokens'],
            'cache' => ['cache'],
            'cache_locks' => ['cache_locks'],
            'sessions' => ['sessions'],
            'jobs' => ['jobs'],
            'job_batches' => ['job_batches'],
            'telescope_entries' => ['telescope_entries'],
            'telescope_entries_tags' => ['telescope_entries_tags'],
            'telescope_monitoring' => ['telescope_monitoring'],
            'doctrine_migration_versions' => ['doctrine_migration_versions'],
            'messenger_messages' => ['messenger_messages'],
            'rememberme_token' => ['rememberme_token'],
            'migration_versions' => ['migration_versions'],
        ];
    }

    public function testShouldIgnorePrefixes(): void
    {
        $this->assertTrue($this->filter->shouldIgnore('horizon_jobs'));
        $this->assertTrue($this->filter->shouldIgnore('horizon_metrics'));
        $this->assertTrue($this->filter->shouldIgnore('pulse_values'));
        $this->assertTrue($this->filter->shouldIgnore('pulse_entries'));
        $this->assertTrue($this->filter->shouldIgnore('sanctum_tokens'));
    }

    public function testShouldIgnoreKeywordSegments(): void
    {
        $this->assertTrue($this->filter->shouldIgnore('access_log'));
        $this->assertTrue($this->filter->shouldIgnore('access_logs'));
        $this->assertTrue($this->filter->shouldIgnore('data_backup'));
        $this->assertTrue($this->filter->shouldIgnore('data_backups'));
        $this->assertTrue($this->filter->shouldIgnore('api_test'));
        $this->assertTrue($this->filter->shouldIgnore('api_tests'));
        $this->assertTrue($this->filter->shouldIgnore('log_entries'));
        $this->assertTrue($this->filter->shouldIgnore('test_results'));
    }

    public function testShouldNotIgnoreNormalTables(): void
    {
        $this->assertFalse($this->filter->shouldIgnore('users'));
        $this->assertFalse($this->filter->shouldIgnore('orders'));
        $this->assertFalse($this->filter->shouldIgnore('products'));
        $this->assertFalse($this->filter->shouldIgnore('categories'));
        $this->assertFalse($this->filter->shouldIgnore('catalog'));
        $this->assertFalse($this->filter->shouldIgnore('dialog'));
        $this->assertFalse($this->filter->shouldIgnore('blog_posts'));
        $this->assertFalse($this->filter->shouldIgnore('testimonials'));
        $this->assertFalse($this->filter->shouldIgnore('login_attempts'));
    }

    public function testShouldIgnoreCaseInsensitive(): void
    {
        $this->assertTrue($this->filter->shouldIgnore('Migrations'));
        $this->assertTrue($this->filter->shouldIgnore('MIGRATIONS'));
        $this->assertTrue($this->filter->shouldIgnore('Horizon_Jobs'));
        $this->assertTrue($this->filter->shouldIgnore('Access_LOG'));
    }
}
