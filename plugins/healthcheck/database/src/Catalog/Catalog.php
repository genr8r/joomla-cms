<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Catalog;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Read-only access to the database metadata the Database health check needs.
 *
 * @since   __DEPLOY_VERSION__
 */
interface Catalog
{
    /**
     * Base tables (no views) of the current database or schema.
     *
     * @return  list<array{name:string, rows:?int, bytes:?int, updated:?string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function tables(): array;

    /**
     * Whether the engine can store zero dates (0000-00-00).
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function supportsZeroDates(): bool;

    /**
     * Date, datetime and timestamp columns of the tables starting with the prefix.
     *
     * @param   string  $tablePrefix  The table name prefix.
     *
     * @return  list<array{table:string, column:string, type:string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function dateColumns(string $tablePrefix): array;

    /**
     * Number of rows holding a zero date in the column.
     *
     * @param   string  $table   The real table name (prefix applied).
     * @param   string  $column  The column name.
     *
     * @return  integer
     *
     * @throws  \RuntimeException  If the table or column no longer exists.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function zeroDateCount(string $table, string $column): int;
}
