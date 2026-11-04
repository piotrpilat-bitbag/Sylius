<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\Behat\Context\Hook;

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Doctrine\DBAL\Connection;
use Sylius\Bundle\CoreBundle\Doctrine\Platform\PlatformHelper;

/**
 * Drops the rows earlier scenarios left behind, keeping the fixtures intact.
 *
 * The usual DoctrineORMContext cannot be used here. It runs a full ORMPurger,
 * and this suite's fixtures - two channels and the catalogue - are loaded
 * BEFORE the worker process starts, with no Given step to recreate them. A full
 * purge would leave an empty database and every scenario would fail on it.
 *
 * So instead of naming the rows that belong to a scenario, this records the
 * highest id per table once, while the database still holds nothing but the
 * fixtures, and removes everything above that line before each later scenario.
 * Nothing has to know which column marks a row as "ours", and the baseline
 * survives untouched.
 *
 * Why it matters: the database is shared by the whole suite, so a customer, an
 * order or an address left by one checkout was still there for the next one.
 * That is how a single failure turned into three red scenarios.
 *
 * It does NOT reset the worker process - that state is the thing under
 * measurement and has to carry over.
 */
final class WorkerDatabaseContext implements Context
{
    /**
     * Measured, not guessed: row counts taken before and after a full run.
     * Ordered children first, so the deletes hold up even where foreign key
     * checks cannot be switched off.
     */
    private const TABLES = [
        'sylius_order_item',
        'sylius_payment',
        'sylius_order',
        'sylius_shop_user',
        'sylius_admin_user',
        'sylius_address',
        'sylius_customer',
        'sylius_locale',
    ];

    /**
     * STATIC on purpose. Behat builds a fresh context object for every
     * scenario, so an instance property would be empty each time and the
     * baseline would be re-taken instead of used - the purge would silently
     * never run.
     *
     * @var array<string, int>
     */
    private static array $baseline = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    #[BeforeScenario]
    public function removeRowsLeftByEarlierScenarios(): void
    {
        //  First scenario: the database still holds only the fixtures, so this
        //  is the line to measure everything else against.
        if ([] === self::$baseline) {
            self::$baseline = $this->highestIds();

            return;
        }

        $isMysql = PlatformHelper::isMysql($this->connection->getDatabasePlatform());
        if ($isMysql) {
            $this->connection->executeStatement('SET foreign_key_checks = 0');
        }

        try {
            foreach (self::TABLES as $table) {
                if (!isset(self::$baseline[$table])) {
                    continue;
                }

                $this->connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE id > :baseline', $table),
                    ['baseline' => self::$baseline[$table]],
                );
            }
        } finally {
            if ($isMysql) {
                $this->connection->executeStatement('SET foreign_key_checks = 1');
            }
        }
    }

    /** @return array<string, int> */
    private function highestIds(): array
    {
        $ids = [];

        foreach (self::TABLES as $table) {
            try {
                $ids[$table] = (int) $this->connection->fetchOne(sprintf('SELECT COALESCE(MAX(id), 0) FROM %s', $table));
            } catch (\Throwable) {
                //  A table the installed Sylius does not have is simply skipped;
                //  this list is a convenience, not a schema contract.
                continue;
            }
        }

        return $ids;
    }
}
