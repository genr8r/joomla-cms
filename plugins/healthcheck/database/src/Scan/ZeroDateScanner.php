<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Scan;

use Joomla\Plugin\Healthcheck\Database\Catalog\Catalog;
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
     * @param   Catalog   $catalog  The catalog
     * @param   \Closure  $clock    Returns the current time in float seconds
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly Catalog $catalog, private readonly \Closure $clock)
    {
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function emptyState(): array
    {
        return ['pass' => null, 'cursor' => 0, 'tables' => [], 'results' => [], 'complete' => false, 'updated' => null];
    }

    /**
     * Begin a new pass. Earlier results are kept until their tables are checked again.
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

        $state['pass']     = $now;
        $state['cursor']   = 0;
        $state['tables']   = $tables;
        $state['complete'] = false;
        $state['results']  = $state['results'] ?? [];

        return $state;
    }

    /**
     * Check tables from the cursor until the budget is used up, always at least one.
     *
     * @param   array    $state          The state
     * @param   string   $tablePrefix    The site's table prefix
     * @param   float    $budgetSeconds  Seconds this run may take
     * @param   string   $now            Current time (Y-m-d H:i:s, UTC)
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function run(array $state, string $tablePrefix, float $budgetSeconds, string $now): array
    {
        if (($state['pass'] ?? null) === null) {
            $state = $this->start($state + self::emptyState(), $tablePrefix, $now);
        }

        $began = ($this->clock)();
        $keys  = array_keys($state['tables']);
        $total = \count($keys);

        while ($state['cursor'] < $total) {
            $key   = $keys[$state['cursor']];
            $table = $state['tables'][$key];

            try {
                $columns = [];

                foreach ($table['columns'] as $column) {
                    $zero = $this->catalog->zeroDateCount($table['name'], $column['column']);

                    if ($zero > 0) {
                        $columns[] = ['column' => $column['column'], 'type' => $column['type'], 'zero' => $zero];
                    }
                }

                if ($columns === []) {
                    unset($state['results'][$key]);
                } else {
                    $state['results'][$key] = ['table' => $table['name'], 'checked' => $now, 'columns' => $columns];
                }
            } catch (\RuntimeException $e) {
                unset($state['results'][$key]);
            }

            $state['cursor']++;

            if (($this->clock)() - $began >= $budgetSeconds) {
                break;
            }
        }

        if ($state['cursor'] >= $total) {
            $state['complete'] = true;
            $state['updated']  = $now;
            $state['results']  = array_intersect_key($state['results'], $state['tables']);
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
