<?php
/**
 * Base class for feature tests that need a database connection.
 * Uses transactions to roll back changes after each test.
 */

namespace Tests;

use App\Helpers\Database;

abstract class DatabaseTestCase extends TestCase
{
    protected static bool $dbAvailable = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        try {
            Database::getInstance();
            static::$dbAvailable = true;
        } catch (\Throwable $e) {
            static::$dbAvailable = false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available. Set up DB config to run feature tests.');
        }

        // Start a transaction so we can roll back after each test
        Database::getInstance()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            try {
                Database::getInstance()->rollback();
            } catch (\Throwable) {
                // Transaction may already be closed
            }
        }

        parent::tearDown();
    }
}
