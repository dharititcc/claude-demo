<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * Whether the server let us opt out of binary logging.
     *
     * Static so one refusal is not re-tried on every connection of every test.
     */
    private static ?bool $canSkipBinlog = null;

    /**
     * Register the binlog opt-out before the framework touches the database.
     *
     * This hooks createApplication() rather than setUp() because the truncation
     * trait opens the central connection during setUpTraits(), which runs first —
     * a listener registered in setUp() would miss it.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app['events']->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $this->skipBinlogFor($event->connection);
        });

        return $app;
    }

    /**
     * Ask MySQL not to write this connection's statements to the binary log.
     *
     * Provisioning one tenant costs ~118 DDL statements, and with sync_binlog=1
     * the server fsyncs the binary log on each of them — about a third of the
     * time a test spends creating its tenant. Test databases are dropped at the
     * end of the run, so nothing here is worth replicating or replaying.
     *
     * This is deliberately session-scoped. The global equivalent
     * (innodb_flush_log_at_trx_commit=0, sync_binlog=0) is a much bigger win but
     * changes the durability of every database on the server, including whatever
     * real data a developer keeps beside the test schemas. That trade is fine in
     * a CI container that is deleted minutes later, and it is made there — see
     * the "Tune MySQL for throwaway test data" step in backend-ci.yml. It is not
     * something a test run should do to someone's machine.
     */
    private function skipBinlogFor(mixed $connection): void
    {
        if (self::$canSkipBinlog === false || ! $connection instanceof MySqlConnection) {
            return;
        }

        // Only ever touch a database this suite owns. If the suite is somehow
        // pointed at a real schema, the binlog is the least of the problems —
        // but do not be the one to disable it.
        if (! $this->isTestDatabase((string) $connection->getDatabaseName())) {
            return;
        }

        try {
            $connection->statement('SET sql_log_bin = 0');
            self::$canSkipBinlog = true;
        } catch (Throwable) {
            // Needs BINLOG_ADMIN (or SUPER). Without it the suite is slower but
            // entirely correct, so this is not worth failing over.
            self::$canSkipBinlog = false;
        }
    }

    /**
     * Both test schema shapes: the central `..._test` database and the
     * per-tenant `testtenant_*` databases, whose prefix phpunit.xml pins.
     */
    private function isTestDatabase(string $database): bool
    {
        $tenantPrefix = (string) config('tenancy.database.prefix');

        return str_ends_with($database, '_test')
            || (str_starts_with($tenantPrefix, 'testtenant_') && str_starts_with($database, $tenantPrefix));
    }
}
