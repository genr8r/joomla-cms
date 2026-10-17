<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Scan;

use Joomla\Plugin\Healthcheck\Database\Catalog\Catalog;
use Joomla\Plugin\Healthcheck\Database\Catalog\MissingObjectException;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;
use Joomla\Plugin\Healthcheck\Database\Scan\ResultStore;
use Joomla\Plugin\Healthcheck\Database\Scan\ZeroDateScanner;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the zero-date scanner
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The zero-date scanner')]
class ZeroDateScannerTest extends UnitTestCase
{
    private const NOW = '2026-10-17 10:00:00';

    /**
     * A catalog with three date tables. Tables listed in `gone` are missing, tables listed in `broken` fail
     * with another error; every call is recorded in `calls`.
     *
     * @param   array  $zeros  Zero counts keyed by "table.column", 0 when absent
     * @param   array  $fake   gone (table names), broken (table names), calls (filled in)
     *
     * @return  Catalog
     *
     * @since   __DEPLOY_VERSION__
     */
    private function catalog(array &$zeros, array &$fake = []): Catalog
    {
        $fake += ['gone' => [], 'broken' => [], 'calls' => []];

        return new class ($zeros, $fake) implements Catalog {
            public function __construct(private array &$zeros, private array &$fake)
            {
            }

            public function tables(): array
            {
                return [];
            }

            public function supportsZeroDates(): bool
            {
                return true;
            }

            public function dateColumns(string $tablePrefix): array
            {
                return [
                    ['table' => 'hc_c', 'column' => 'day', 'type' => 'date'],
                    ['table' => 'HC_A', 'column' => 'created', 'type' => 'datetime'],
                    ['table' => 'hc_b', 'column' => 'modified', 'type' => 'timestamp'],
                ];
            }

            public function zeroDateCounts(string $table, array $columns): array
            {
                $this->fake['calls'][] = [$table, $columns];
                $key                   = strtolower($table);

                if (\in_array($key, $this->fake['gone'], true)) {
                    throw new MissingObjectException('gone', 1146, new \RuntimeException('previous'));
                }

                if (\in_array($key, $this->fake['broken'], true)) {
                    throw new \RuntimeException('lost connection', 2013);
                }

                $counts = [];

                foreach ($columns as $column) {
                    $counts[$column] = $this->zeros[$key . '.' . $column] ?? 0;
                }

                return $counts;
            }
        };
    }

    /**
     * A scanner whose clock moves 2 seconds per reading.
     *
     * @param   Catalog  $catalog  The catalog
     *
     * @return  ZeroDateScanner
     *
     * @since   __DEPLOY_VERSION__
     */
    private function scanner(Catalog $catalog): ZeroDateScanner
    {
        $time = 0.0;

        return new ZeroDateScanner($catalog, static function () use (&$time) {
            $time += 2.0;

            return $time;
        });
    }

    /**
     * Zero counts of the fake catalog.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function zeros(): array
    {
        return ['hc_a.created' => 2, 'hc_b.modified' => 0, 'hc_c.day' => 1];
    }

    /**
     * The findings as "table.column=zero" strings.
     *
     * @param   array  $state  The state
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function open(array $state): array
    {
        return array_map(
            static fn ($f) => $f['table'] . '.' . $f['column'] . '=' . $f['zero'],
            ZeroDateScanner::findings($state, new IgnoreList([]))
        );
    }

    #[TestDox('a pass within the budget completes in one run')]
    public function testFullPassWithinBudget()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);

        $this->assertTrue($state['complete']);
        $this->assertSame(self::NOW, $state['updated']);
        $this->assertSame(['HC_A.created=2', 'hc_c.day=1'], $this->open($state));
        $this->assertSame(['checked' => 3, 'total' => 3], ZeroDateScanner::progress($state));
    }

    #[TestDox('a small budget splits the pass over several runs')]
    public function testBudgetSplitsPass()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 1, self::NOW);

        $this->assertFalse($state['complete']);
        $this->assertSame(['checked' => 1, 'total' => 3], ZeroDateScanner::progress($state));
        $this->assertSame(['HC_A.created=2'], $this->open($state));

        $state = $scanner->run($state, 'hc_', 1, self::NOW);
        $this->assertFalse($state['complete']);

        $state = $scanner->run($state, 'hc_', 1, self::NOW);
        $this->assertTrue($state['complete']);
        $this->assertSame(['checked' => 3, 'total' => 3], ZeroDateScanner::progress($state));
    }

    #[TestDox('earlier results stay visible when a new pass starts')]
    public function testMergedResultsSurviveRestart()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);
        $state = $scanner->start($state, 'hc_', self::NOW);

        $this->assertFalse($state['complete']);
        $this->assertSame(0, $state['cursor']);

        $state = $scanner->run($state, 'hc_', 1, self::NOW);

        $this->assertSame(['HC_A.created=2', 'hc_c.day=1'], $this->open($state));
    }

    #[TestDox('a fixed column disappears when its table is re-checked')]
    public function testFixedColumnDisappears()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);

        $zeros['hc_a.created'] = 0;
        $state                 = $scanner->run($scanner->start($state, 'hc_', self::NOW), 'hc_', 10, self::NOW);

        $this->assertSame(['hc_c.day=1'], $this->open($state));
    }

    #[TestDox('a table dropped during the pass is skipped')]
    public function testDroppedTableIsSkipped()
    {
        $zeros   = $this->zeros();
        $fake    = ['gone' => ['hc_b']];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);

        $this->assertTrue($state['complete']);
        $this->assertSame(['HC_A.created=2', 'hc_c.day=1'], $this->open($state));
        $this->assertArrayNotHasKey('hc_b', $state['results']);
    }

    #[TestDox('a dropped table loses its earlier result')]
    public function testDroppedTableLosesResult()
    {
        $zeros                  = $this->zeros();
        $zeros['hc_b.modified'] = 4;
        $fake                   = [];
        $scanner                = $this->scanner($this->catalog($zeros, $fake));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);
        $this->assertArrayHasKey('hc_b', $state['results']);

        $fake['gone'] = ['hc_b'];
        $state        = $scanner->run($scanner->start($state, 'hc_', self::NOW), 'hc_', 10, self::NOW);

        $this->assertArrayNotHasKey('hc_b', $state['results']);
    }

    #[TestDox('each table is counted with one catalog call for all its date columns')]
    public function testOneCallPerTable()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);

        $this->assertSame([['HC_A', ['created']], ['hc_b', ['modified']], ['hc_c', ['day']]], $fake['calls']);
    }

    #[TestDox('any other error keeps the result, stops the batch, does not advance and records the table')]
    public function testOtherErrorStopsWithoutAdvancing()
    {
        $zeros                  = $this->zeros();
        $zeros['hc_b.modified'] = 4;
        $fake                   = [];
        $scanner                = $this->scanner($this->catalog($zeros, $fake));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);
        $this->assertArrayHasKey('hc_b', $state['results']);

        $later          = '2026-10-17 11:00:00';
        $fake['broken'] = ['hc_b'];
        $fake['calls']  = [];
        $state          = $scanner->run($scanner->start($state, 'hc_', $later), 'hc_', 10, $later);

        $this->assertFalse($state['complete']);
        $this->assertSame(self::NOW, $state['updated'], 'the last complete pass stays the reference');
        $this->assertSame(['checked' => 1, 'total' => 3], ZeroDateScanner::progress($state));
        $this->assertSame(['table' => 'hc_b', 'at' => $later], $state['error']);
        $this->assertSame(self::NOW, $state['results']['hc_b']['checked'], 'the previous result is kept');
        $this->assertSame(['HC_A', 'hc_b'], array_column($fake['calls'], 0), 'the batch stops at the failing table');

        // Retrying fails again without advancing; the pass never completes through errors.
        $state = $scanner->run($state, 'hc_', 10, $later);
        $this->assertFalse($state['complete']);
        $this->assertSame(1, $state['cursor']);

        // The next successful table clears the error.
        $fake['broken'] = [];
        $state          = $scanner->run($state, 'hc_', 10, $later);
        $this->assertTrue($state['complete']);
        $this->assertNull($state['error']);
        $this->assertSame($later, $state['results']['hc_b']['checked']);
    }

    #[TestDox('the table is marked in progress, and handed to the callback, before the catalog is asked')]
    public function testInProgressMarker()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));
        $seen    = [];

        $state = $scanner->run(
            ZeroDateScanner::emptyState(),
            'hc_',
            10,
            self::NOW,
            static function (array $state) use (&$seen, &$fake): void {
                $seen[] = [$state['inprogress'], \count($fake['calls'])];
            }
        );

        $this->assertSame([['hc_a', 0], ['hc_b', 1], ['hc_c', 2]], $seen);
        $this->assertNull($state['inprogress']);
    }

    #[TestDox('a table a request died on is skipped as too large, and the pass moves on')]
    public function testDeadRequestTableIsSkipped()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        // A run that died while counting hc_b left this state behind.
        $state               = $scanner->start(ZeroDateScanner::emptyState(), 'hc_', self::NOW);
        $state['cursor']     = 1;
        $state['inprogress'] = 'hc_b';

        $state = $scanner->run($state, 'hc_', 10, self::NOW);

        $this->assertTrue($state['complete']);
        $this->assertSame(['hc_b' => 'hc_b'], $state['skipped']);
        $this->assertSame([['hc_c', ['day']]], $fake['calls']);
        $this->assertNull($state['inprogress']);

        // A new pass tries it again.
        $state = $scanner->run($scanner->start($state, 'hc_', self::NOW), 'hc_', 10, self::NOW);
        $this->assertSame([], $state['skipped']);
    }

    #[TestDox('a stale in-progress marker for another table is dropped')]
    public function testStaleMarkerDropped()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state               = $scanner->start(ZeroDateScanner::emptyState(), 'hc_', self::NOW);
        $state['inprogress'] = 'hc_c';

        $state = $scanner->run($state, 'hc_', 1, self::NOW);

        $this->assertSame([], $state['skipped']);
        $this->assertSame(['checked' => 1, 'total' => 3], ZeroDateScanner::progress($state));
    }

    #[TestDox('a result for a table that is gone is pruned when the pass completes')]
    public function testTableNoLongerPresentIsPruned()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state                       = ZeroDateScanner::emptyState();
        $state['results']['hc_old']  = [
            'table'   => 'hc_old',
            'checked' => '2026-10-01 00:00:00',
            'columns' => [['column' => 'x', 'type' => 'date', 'zero' => 5]],
        ];

        $state = $scanner->run($state, 'hc_', 10, self::NOW);

        $this->assertArrayNotHasKey('hc_old', $state['results']);
    }

    #[TestDox('ignored columns are flagged and counted apart')]
    public function testIgnoredColumns()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));
        $state   = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);
        $ignored = new IgnoreList([['kind' => 'column', 'name' => 'hc_a.created']]);

        $this->assertSame(1, ZeroDateScanner::openCount($state, $ignored));
        $this->assertSame(1, ZeroDateScanner::ignoredCount($state, $ignored));

        $flags = array_column(ZeroDateScanner::findings($state, $ignored), 'ignored', 'column');

        $this->assertTrue($flags['created']);
        $this->assertFalse($flags['day']);
    }

    #[TestDox('the state survives a JSON round trip')]
    public function testStateIsJsonFriendly()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));
        $state   = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 1, self::NOW);

        $this->assertSame($state, json_decode(json_encode($state), true));
    }

    #[TestDox('staleness follows the refresh hours')]
    public function testIsStale()
    {
        $this->assertTrue(ResultStore::isStale(null, 24, self::NOW));
        $this->assertFalse(ResultStore::isStale('2026-10-16 11:00:00', 24, self::NOW));
        $this->assertTrue(ResultStore::isStale('2026-10-16 09:00:00', 24, self::NOW));
        $this->assertTrue(ResultStore::isStale('2026-10-16 10:00:00', 24, self::NOW));
        $this->assertTrue(ResultStore::isStale('garbage', 24, self::NOW));
    }
}
