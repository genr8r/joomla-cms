<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Ignore;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Stores the ignored tables, columns and prefixes. This is the only place the plugin writes,
 * and it writes only to its own table.
 *
 * @since  __DEPLOY_VERSION__
 */
final class IgnoreRepository
{
    /**
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const TABLE = '#__healthcheck_database_ignored';

    /**
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9_$.]{1,128}$/D';

    /**
     * @param   DatabaseInterface  $db  The Joomla database
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * All ignore entries.
     *
     * @return  list<array{kind:string, name:string, created_by:int, created:string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function all(): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['kind', 'name', 'created_by', 'created']))
            ->from($db->quoteName(self::TABLE))
            ->order($db->quoteName('id'));

        $out = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $out[] = [
                'kind'       => (string) $row['kind'],
                'name'       => (string) $row['name'],
                'created_by' => (int) $row['created_by'],
                'created'    => (string) $row['created'],
            ];
        }

        return $out;
    }

    /**
     * Add an entry. Adding an existing entry succeeds without changing it.
     *
     * @param   string  $kind    table, column or prefix
     * @param   string  $name    The name (table.column for a column); stored lowercased
     * @param   integer $userId  The user adding it
     * @param   string  $now     The creation date, UTC, Y-m-d H:i:s
     *
     * @return  boolean  False for invalid input
     *
     * @since   __DEPLOY_VERSION__
     */
    public function add(string $kind, string $name, int $userId, string $now): bool
    {
        $name = strtolower($name);

        if (!self::isValid($kind, $name)) {
            return false;
        }

        if ($this->exists($kind, $name)) {
            return true;
        }

        $db      = $this->db;
        $columns = ['kind', 'name', 'created_by', 'created'];
        $query   = $db->getQuery(true)
            ->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName($columns))
            ->values(':kind, :name, :createdby, :created')
            ->bind(':kind', $kind)
            ->bind(':name', $name)
            ->bind(':createdby', $userId, ParameterType::INTEGER)
            ->bind(':created', $now);

        try {
            $db->setQuery($query)->execute();
        } catch (ExecutionFailureException $e) {
            // A concurrent request won the unique key: the entry is there, which is what was asked.
            if (!$this->exists($kind, $name)) {
                throw $e;
            }
        }

        return true;
    }

    /**
     * Remove an entry.
     *
     * @param   string  $kind  table, column or prefix
     * @param   string  $name  The name; matched lowercased
     *
     * @return  boolean  True when a row was deleted
     *
     * @since   __DEPLOY_VERSION__
     */
    public function remove(string $kind, string $name): bool
    {
        $name  = strtolower($name);
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName('kind') . ' = :kind')
            ->where($db->quoteName('name') . ' = :name')
            ->bind(':kind', $kind)
            ->bind(':name', $name);

        $db->setQuery($query)->execute();

        return $db->getAffectedRows() > 0;
    }

    /**
     * Whether an entry exists.
     *
     * @param   string  $kind  The kind
     * @param   string  $name  The lowercased name
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function exists(string $kind, string $name): bool
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('kind') . ' = :kind')
            ->where($db->quoteName('name') . ' = :name')
            ->bind(':kind', $kind)
            ->bind(':name', $name);

        return (bool) $db->setQuery($query, 0, 1)->loadResult();
    }

    /**
     * Whether the kind and name are acceptable for an ignore entry. The Details popup uses it to offer
     * Ignore only where add() would accept it.
     *
     * @param   string  $kind  The kind
     * @param   string  $name  The name
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isValid(string $kind, string $name): bool
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return false;
        }

        $dots = substr_count($name, '.');

        return match ($kind) {
            'table'  => $dots === 0,
            'column' => $dots === 1 && $name[0] !== '.' && !str_ends_with($name, '.'),
            'prefix' => $dots === 0 && str_ends_with($name, '_'),
            default  => false,
        };
    }
}
