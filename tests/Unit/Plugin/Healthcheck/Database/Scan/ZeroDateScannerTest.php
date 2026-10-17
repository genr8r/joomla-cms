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
     * @param   array  $zeros  Zero counts keyed by "table.column"; a missing key makes the table throw
     *
     * @return  Catalog
     *
     * @since   __DEPLOY_VERSION__
     */
    private function catalog(array &$zeros): Catalog
    {
        return new class ($zeros) implements Catalog {
            public function __construct(private array &$zeros)
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

            public function zeroDateCount(string $table, string $column): int
            {
                $key = strtolower($table) . '.' . $column;

                if (!isset($this->zeros[$key])) {
                    throw new \RuntimeException('gone');
                }

                return $this->zeros[$key];
            }
        };
    }

    /**
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
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function zeros(): array
    {
        return ['hc_a.created' => 2, 'hc_b.modified' => 0, 'hc_c.day' => 1];
    }

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
        $zeros = $this->zeros();
        unset($zeros['hc_b.modified']);
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);

        $this->assertTrue($state['complete']);
        $this->assertSame(['HC_A.created=2', 'hc_c.day=1'], $this->open($state));
        $this->assertArrayNotHasKey('hc_b', $state['results']);
    }

    #[TestDox('a dropped table loses its earlier result')]
    public function testDroppedTableLosesResult()
    {
        $zeros   = $this->zeros();
        $zeros['hc_b.modified'] = 4;
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW);
        $this->assertArrayHasKey('hc_b', $state['results']);

        unset($zeros['hc_b.modified']);
        $state = $scanner->run($scanner->start($state, 'hc_', self::NOW), 'hc_', 10, self::NOW);

        $this->assertArrayNotHasKey('hc_b', $state['results']);
    }

    #[TestDox('a result for a table that is gone is pruned when the pass completes')]
    public function testTableNoLongerPresentIsPruned()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state                       = ZeroDateScanner::emptyState();
        $state['results']['hc_old'] = [
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
