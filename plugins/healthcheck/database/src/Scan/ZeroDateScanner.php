<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Scan;

use Joomla\CMS\Log\Log;
use Joomla\Plugin\Healthcheck\Database\Catalog\Catalog;
use Joomla\Plugin\Healthcheck\Database\Catalog\MissingObjectException;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finds zero dates table by table within a time budget, keeping a merged view of the latest result per table.
 *
 * The state holds arrays and scalars only, so it can be cached as is.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ZeroDateScanner
{
    /**
     * Constructor
     *
     * @param   Catalog   $catalog  The catalog
     * @param   \Closure  $clock    Returns the current time in float seconds
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly Catalog $catalog, private readonly \Closure $clock)
    {
    }

    /**
     * The state before the first pass.
     *
     * `inprogress` is the key of the table being counted right now, `skipped` the tables a request died on
     * (key => spelling), and `error` the table the last run could not check ({table, at}) or null.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function emptyState(): array
    {
        return [
            'pass'       => null,
            'cursor'     => 0,
            'tables'     => [],
            'results'    => [],
            'complete'   => false,
            'updated'    => null,
            'inprogress' => null,
            'skipped'    => [],
            'error'      => null,
        ];
    }

    /**
     * Begin a new pass. Earlier results are kept until their tables are checked again; skipped tables are
     * tried again.
     *
     * @param   array   $state        The state
     * @param   string  $tablePrefix  The site's table prefix
     * @param   string  $now          Current time (Y-m-d H:i:s, UTC)
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function start(array $state, string $tablePrefix, string $now): array
    {
        $tables = [];

        foreach ($this->catalog->dateColumns($tablePrefix) as $column) {
            $key = strtolower($column['table']);

            $tables[$key]['name']      = $tables[$key]['name'] ?? $column['table'];
            $tables[$key]['columns'][] = ['column' => $column['column'], 'type' => $column['type']];
        }

        ksort($tables);

        $state = $state + self::emptyState();

        $state['pass']       = $now;
        $state['cursor']     = 0;
        $state['tables']     = $tables;
        $state['complete']   = false;
        $state['inprogress'] = null;
        $state['skipped']    = [];
        $state['error']      = null;

        return $state;
    }

    /**
     * Check tables from the cursor until the budget is used up, always at least one.
     *
     * Before each table its key is put in `inprogress` and the state is handed to $beforeTable, so the caller
     * can store it: when a request dies on a huge table, the next run finds the marker on the cursor table,
     * records it in `skipped` and moves on instead of dying on it again.
     *
     * A table that is gone is dropped from the results. Any other failure keeps the table's previous result,
     * records `error`, leaves the cursor on that table and ends the run, so a pass never completes through
     * errors.
     *
     * @param   array          $state          The state
     * @param   string         $tablePrefix    The site's table prefix
     * @param   float          $budgetSeconds  Seconds this run may take
     * @param   string         $now            Current time (Y-m-d H:i:s, UTC)
     * @param   \Closure|null  $beforeTable    Called with the state before each table is counted
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function run(array $state, string $tablePrefix, float $budgetSeconds, string $now, ?\Closure $beforeTable = null): array
    {
        if (($state['pass'] ?? null) === null) {
            $state = $this->start($state, $tablePrefix, $now);
        }

        $state += self::emptyState();
        $began  = ($this->clock)();
        $keys   = array_keys($state['tables']);
        $total  = \count($keys);

        // A marker for another table than the cursor one is left over from an older pass.
        if ($state['inprogress'] !== null && $state['inprogress'] !== ($keys[$state['cursor']] ?? null)) {
            $state['inprogress'] = null;
        }

        while ($state['cursor'] < $total) {
            $key   = $keys[$state['cursor']];
            $table = $state['tables'][$key];

            if ($state['inprogress'] === $key) {
                // The request that counted this table died: too large to check in one request.
                $state['skipped'][$key] = $table['name'];
                $state['inprogress']    = null;
                $state['cursor']++;

                continue;
            }

            $state['inprogress'] = $key;

            if ($beforeTable !== null) {
                $beforeTable($state);
            }

            try {
                $counts = $this->catalog->zeroDateCounts($table['name'], array_column($table['columns'], 'column'));
            } catch (MissingObjectException $e) {
                // Dropped or renamed since the pass started.
                $counts = null;
            } catch (\RuntimeException $e) {
                Log::add('Database Health Check: could not check ' . $table['name'] . ': ' . $e->getMessage(), Log::WARNING, 'healthcheck');

                $state['inprogress'] = null;
                $state['error']      = ['table' => $table['name'], 'at' => $now];

                break;
            }

            $columns = [];

            foreach ($counts === null ? [] : $table['columns'] as $column) {
                $zero = (int) ($counts[$column['column']] ?? 0);

                if ($zero > 0) {
                    $columns[] = ['column' => $column['column'], 'type' => $column['type'], 'zero' => $zero];
                }
            }

            if ($columns === []) {
                unset($state['results'][$key]);
            } else {
                $state['results'][$key] = ['table' => $table['name'], 'checked' => $now, 'columns' => $columns];
            }

            unset($state['skipped'][$key]);
            $state['inprogress'] = null;
            $state['error']      = null;
            $state['cursor']++;

            if (($this->clock)() - $began >= $budgetSeconds) {
                break;
            }
        }

        if ($state['cursor'] >= $total && $state['error'] === null) {
            $state['complete'] = true;
            $state['updated']  = $now;
            $state['results']  = array_intersect_key($state['results'], $state['tables']);
            $state['skipped']  = array_intersect_key($state['skipped'], $state['tables']);
        }

        return $state;
    }

    /**
     * Open and ignored zero-date columns, by table then column order.
     *
     * @param   array       $state    The state
     * @param   IgnoreList  $ignored  Ignore entries
     *
     * @return  list<array{table:string, column:string, type:string, zero:int, ignored:bool}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function findings(array $state, IgnoreList $ignored): array
    {
        $results = $state['results'] ?? [];
        ksort($results);

        $findings = [];

        foreach ($results as $result) {
            foreach ($result['columns'] as $column) {
                $findings[] = [
                    'table'   => $result['table'],
                    'column'  => $column['column'],
                    'type'    => $column['type'],
                    'zero'    => (int) $column['zero'],
                    'ignored' => $ignored->isColumnIgnored($result['table'], $column['column']),
                ];
            }
        }

        return $findings;
    }

    /**
     * Number of zero-date columns that are not ignored.
     *
     * @param   array       $state    The state
     * @param   IgnoreList  $ignored  Ignore entries
     *
     * @return  integer  Columns not ignored
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function openCount(array $state, IgnoreList $ignored): int
    {
        return \count(array_filter(self::findings($state, $ignored), static fn ($f) => !$f['ignored']));
    }

    /**
     * Number of zero-date columns that are ignored.
     *
     * @param   array       $state    The state
     * @param   IgnoreList  $ignored  Ignore entries
     *
     * @return  integer  Columns ignored
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function ignoredCount(array $state, IgnoreList $ignored): int
    {
        return \count(array_filter(self::findings($state, $ignored), static fn ($f) => $f['ignored']));
    }

    /**
     * How far the current pass is: tables checked (cursor) and tables in the pass.
     *
     * @param   array  $state  The state
     *
     * @return  array{checked:int, total:int}
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function progress(array $state): array
    {
        return ['checked' => (int) ($state['cursor'] ?? 0), 'total' => \count($state['tables'] ?? [])];
    }
}
