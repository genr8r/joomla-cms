<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Ignore;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The set of ignored tables, columns and prefixes. Matching is case-insensitive.
 *
 * @since  __DEPLOY_VERSION__
 */
final class IgnoreList
{
    /**
     * @var    array<string, true>
     * @since  __DEPLOY_VERSION__
     */
    private array $tables = [];

    /**
     * @var    array<string, true>
     * @since  __DEPLOY_VERSION__
     */
    private array $columns = [];

    /**
     * @var    array<string, true>
     * @since  __DEPLOY_VERSION__
     */
    private array $prefixes = [];

    /**
     * @param   array<array{kind:string, name:string}>  $entries  Ignore entries
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $name = strtolower((string) ($entry['name'] ?? ''));

            switch ($entry['kind'] ?? '') {
                case 'table':
                    $this->tables[$name] = true;
                    break;
                case 'column':
                    $this->columns[$name] = true;
                    break;
                case 'prefix':
                    $this->prefixes[$name] = true;
                    break;
            }
        }
    }

    /**
     * @param   string  $table  Table name
     *
     * @return  boolean  True for a table entry, or when a prefix entry starts the name
     *
     * @since   __DEPLOY_VERSION__
     */
    public function isTableIgnored(string $table): bool
    {
        $table = strtolower($table);

        if (isset($this->tables[$table])) {
            return true;
        }

        foreach (array_keys($this->prefixes) as $prefix) {
            if ($prefix !== '' && str_starts_with($table, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   string  $prefix  Table prefix
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function isPrefixIgnored(string $prefix): bool
    {
        return isset($this->prefixes[strtolower($prefix)]);
    }

    /**
     * @param   string  $table   Table name
     * @param   string  $column  Column name
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function isColumnIgnored(string $table, string $column): bool
    {
        return isset($this->columns[strtolower($table . '.' . $column)]);
    }
}
