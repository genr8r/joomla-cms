<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Catalog;


use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Catalog for MySQL and MariaDB (mysqli and pdomysql drivers).
 *
 * @since   __DEPLOY_VERSION__
 */
final class MysqlCatalog implements Catalog
{
    /**
     * @param   DatabaseInterface  $db  The database.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function tables(): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('TABLE_NAME', 'name'),
                    $db->quoteName('TABLE_ROWS', 'rows'),
                    $db->quoteName('DATA_LENGTH', 'data_length'),
                    $db->quoteName('INDEX_LENGTH', 'index_length'),
                    $db->quoteName('UPDATE_TIME', 'updated'),
                ]
            )
            ->from($db->quoteName('information_schema.TABLES'))
            ->where($db->quoteName('TABLE_SCHEMA') . ' = DATABASE()')
            ->where($db->quoteName('TABLE_TYPE') . ' = ' . $db->quote('BASE TABLE'))
            ->order($db->quoteName('TABLE_NAME'));

        $result = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $bytes = ($row['data_length'] === null && $row['index_length'] === null)
                ? null
                : (int) $row['data_length'] + (int) $row['index_length'];

            $result[] = [
                'name'    => (string) $row['name'],
                'rows'    => $row['rows'] === null ? null : (int) $row['rows'],
                'bytes'   => $bytes,
                'updated' => $row['updated'] === null ? null : (string) $row['updated'],
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function supportsZeroDates(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function dateColumns(string $tablePrefix): array
    {
        $db      = $this->db;
        $pattern = addcslashes($tablePrefix, '\\_%') . '%';
        $query   = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('TABLE_NAME', 'tbl'),
                    $db->quoteName('COLUMN_NAME', 'col'),
                    $db->quoteName('DATA_TYPE', 'typ'),
                ]
            )
            ->from($db->quoteName('information_schema.COLUMNS'))
            ->where($db->quoteName('TABLE_SCHEMA') . ' = DATABASE()')
            ->where($db->quoteName('DATA_TYPE') . ' IN (' . implode(',', $db->quote(['date', 'datetime', 'timestamp'])) . ')')
            ->where($db->quoteName('TABLE_NAME') . ' LIKE :prefix')
            ->bind(':prefix', $pattern)
            ->order([$db->quoteName('TABLE_NAME'), $db->quoteName('ORDINAL_POSITION')]);

        $result = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $result[] = ['table' => (string) $row['tbl'], 'column' => (string) $row['col'], 'type' => strtolower((string) $row['typ'])];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function zeroDateCount(string $table, string $column): int
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName($table))
            ->where('CAST(' . $db->quoteName($column) . ' AS CHAR(10)) = ' . $db->quote('0000-00-00'));

        try {
            return (int) $db->setQuery($query)->loadResult();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
