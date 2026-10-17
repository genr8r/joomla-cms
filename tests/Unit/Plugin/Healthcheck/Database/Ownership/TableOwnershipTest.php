<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Ownership;

use Joomla\Plugin\Healthcheck\Database\Ownership\TableOwnership;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the table ownership reader
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The table ownership reader')]
class TableOwnershipTest extends UnitTestCase
{
    /**
     * @param   string  $type     Extension type
     * @param   string  $element  Element
     * @param   string  $folder   Plugin folder
     * @param   string  $client   Client id (string, as a database row gives it)
     *
     * @return  object
     *
     * @since   __DEPLOY_VERSION__
     */
    private function extension(string $type, string $element, string $folder = '', string $client = '0'): object
    {
        return (object) ['type' => $type, 'element' => $element, 'folder' => $folder, 'client_id' => $client];
    }

    /**
     * @return  TableOwnership
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ownership(): TableOwnership
    {
        $root = \dirname(__DIR__) . '/fixtures/site';

        return new TableOwnership($root, $root . '/administrator');
    }

    #[TestDox('Core tables and tables from install and update SQL are accounted for')]
    public function testCoreAndExtensionTables(): void
    {
        $names = $this->ownership()->accounted([
            $this->extension('component', 'com_foo'),
            $this->extension('plugin', 'bar', 'system'),
            $this->extension('module', 'mod_baz', '', '0'),
        ]);

        $this->assertContains('#__content', $names);
        $this->assertContains('#__foo_items', $names);
        $this->assertContains('#__foo_log', $names);
        $this->assertSame($names, array_values(array_unique($names)));
    }

    #[TestDox('Extension types without a known location are skipped')]
    public function testUnknownTypeSkipped(): void
    {
        $with    = $this->ownership()->accounted([$this->extension('library', 'foo')]);
        $without = $this->ownership()->accounted([]);

        $this->assertSame($without, $with);
    }

    #[TestDox('A manifest path leaving the extension folder is not read')]
    public function testPathEscapeRejected(): void
    {
        $names = $this->ownership()->accounted([$this->extension('component', 'com_evil')]);

        $this->assertNotContains('#__evil', $names);
    }
}
