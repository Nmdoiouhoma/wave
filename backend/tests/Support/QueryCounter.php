<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** Test-only DBAL middleware counting statements sent to PostgreSQL (N+1 assertions). */
final class QueryCounter implements Middleware
{
    public static int $count = 0;

    /** @var list<string> */
    public static array $sql = [];

    public static function reset(): void
    {
        self::$count = 0;
        self::$sql = [];
    }

    public static function record(string $sql): void
    {
        ++self::$count;
        self::$sql[] = $sql;
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        QueryCounter::record($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        QueryCounter::record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        QueryCounter::record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
