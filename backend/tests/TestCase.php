<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase menjalankan migrate:fresh pada database non-memory.
     * Hentikan test sebelum trait berjalan jika koneksi bukan SQLite in-memory.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Test harus memakai SQLite in-memory, bukan [{$connection}] [{$database}]. Dibatalkan sebelum database disentuh."
            );
        }

        return parent::setUpTraits();
    }
}
