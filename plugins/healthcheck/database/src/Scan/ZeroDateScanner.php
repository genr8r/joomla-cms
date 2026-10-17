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
     * `inprogress` is the table being counted right now ({key, at}, `at` from the scanner's clock) or null,
     * `skipped` the tables a request died on (key => {name, at}, `at` Y-m-d H:i:s UTC, or null for an entry
     * cached by an older version), and `error` the table the last run could not check ({table, at}) or null.
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
     * Seconds after which an in-progress marker means its request died: the longest a request may run
     * (the time budget or PHP's time limit, 300 when that is unlimited) plus a margin.
     *
     * @param   float    $budgetSeconds     Seconds a run may take
     * @param   integer  $maxExecutionTime  PHP's max_execution_time, 0 for unlimited
     *
     * @return  float
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function deadAfter(float $budgetSeconds, int $maxExecutionTime): float
    {
        return (float) max($budgetSeconds, $maxExecutionTime > 0 ? $maxExecutionTime : 300) + 30.0;
    }

    /**
     * Whether another request is counting the cursor table right now: its marker is younger than $deadAfter.
     *
     * @param   array  $state      The state
     * @param   float  $now        Current time from the scanner's clock
     * @param   float  $deadAfter  Seconds after which a marker means its request died
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isBusy(array $state, float $now, float $deadAfter): bool
    {
        $marker = self::marker($state);

        return $marker !== null && $marker['key'] === self::cursorKey($state) && $now - $marker['at'] < $deadAfter;
    }

    /**
     * Begin a new pass. Earlier results are kept until their tables are checked again. Tables skipped as too
     * large within the last $refreshHours stay skipped, so Check now does not die on them again; older ones
     * are tried again.
     *
     * @param   array    $state         The state
     * @param   string   $tablePrefix   The site's table prefix
     * @param   string   $now           Current time (Y-m-d H:i:s, UTC)
     * @param   integer  $refreshHours  Hours a result stays fresh
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function start(array $state, string $tablePrefix, string $now, int $refreshHours): array
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
        $state['skipped']    = array_filter(
            self::skipped($state),
            static fn (array $entry): bool => !ResultStore::isStale($entry['at'], $refreshHours, $now)
        );
        $state['error']      = null;

        return $state;
    }

    /**
     * Check tables from the cursor until the budget is used up, always at least one.
     *
     * Before each table it is marked in `inprogress` with the clock time, and the state is handed to
     * $beforeTable, so the caller can store it. A marker on the cursor table older than $deadAfter means the
     * request counting it died: the table is recorded in `skipped` and the pass moves on instead of dying on
     * it again. A younger marker means another request is counting it now: the state is returned unchanged
     * (callers check isBusy() first). Tables in `skipped` are passed over.
     *
     * A table that is gone is dropped from the results. Any other failure keeps the table's previous result,
     * records `error`, leaves the cursor on that table and ends the run, so a pass never completes through
     * errors.
     *
     * @param   array          $state          The state
     * @param   string         $tablePrefix    The site's table prefix
     * @param   float          $budgetSeconds  Seconds this run may take
     * @param   string         $now            Current time (Y-m-d H:i:s, UTC)
     * @param   integer        $refreshHours   Hours a result stays fresh, for a pass started here
     * @param   float          $deadAfter      Seconds after which a marker means its request died
     * @param   \Closure|null  $beforeTable    Called with the state before each table is counted
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function run(
        array $state,
        string $tablePrefix,
        float $budgetSeconds,
        string $now,
        int $refreshHours,
        float $deadAfter,
        ?\Closure $beforeTable = null
    ): array {
        if (($state['pass'] ?? null) === null) {
            $state = $this->start($state, $tablePrefix, $now, $refreshHours);
        }

        $state += self::emptyState();
        $began  = ($this->clock)();

        if (self::isBusy($state, $began, $deadAfter)) {
            return $state;
        }

        $state['skipped'] = self::skipped($state);
        $keys             = array_keys($state['tables']);
        $total            = \count($keys);

        // Not busy: a marker on the cursor table is dead; any other marker (or an older shape) is left over.
        $marker              = self::marker($state);
        $dead                = $marker !== null && $marker['key'] === self::cursorKey($state);
        $state['inprogress'] = null;

        while ($state['cursor'] < $total) {
            $key   = $keys[$state['cursor']];
            $table = $state['tables'][$key];

            if ($dead) {
                // The request that counted this table died: too large to check in one request.
                $dead                   = false;
                $state['skipped'][$key] = ['name' => $table['name'], 'at' => $now];
                $state['cursor']++;

                continue;
            }

            if (isset($state['skipped'][$key])) {
                // Skipped as too large recently: not tried again before the refresh hours are up.
                $state['cursor']++;

                continue;
            }

            $state['inprogress'] = ['key' => (string) $key, 'at' => (float) ($this->clock)()];

            if ($beforeTable !== null) {
                $beforeTable($state);
            }

            try {
                $counts = $this->catalog->zeroDateCounts($table['name'], array_column($table['columns'], 'column'));
            } catch (MissingObjectException $e) {
                // Dropped or renamed since the pass started.
                $counts = null;
            } catch (\Throwable $e) {
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
    /**
     * The in-progress marker, or null when there is none or it has an older shape.
     *
     * @param   array  $state  The state
     *
     * @return  array{key:string, at:float}|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function marker(array $state): ?array
    {
        $marker = $state['inprogress'] ?? null;

        if (!\is_array($marker) || !isset($marker['key'], $marker['at']) || !is_numeric($marker['at'])) {
            return null;
        }

        return ['key' => (string) $marker['key'], 'at' => (float) $marker['at']];
    }

    /**
     * Key of the table at the cursor, or null when the pass is through.
     *
     * @param   array  $state  The state
     *
     * @return  ?string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function cursorKey(array $state): ?string
    {
        $key = array_keys($state['tables'] ?? [])[(int) ($state['cursor'] ?? 0)] ?? null;

        return $key === null ? null : (string) $key;
    }

    /**
     * The skipped tables as key => {name, at}; a bare name cached by an older version gets no time.
     *
     * @param   array  $state  The state
     *
     * @return  array<string, array{name:string, at:?string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function skipped(array $state): array
    {
        $skipped = [];

        foreach ((array) ($state['skipped'] ?? []) as $key => $entry) {
            $skipped[$key] = \is_array($entry)
                ? ['name' => (string) ($entry['name'] ?? $key), 'at' => isset($entry['at']) ? (string) $entry['at'] : null]
                : ['name' => (string) $entry, 'at' => null];
        }

        return $skipped;
    }
}
