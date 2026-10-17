<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Ignore;

use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the ignore list
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The ignore list')]
class IgnoreListTest extends UnitTestCase
{
    #[TestDox('Table, column and prefix entries match case-insensitively')]
    public function testKinds(): void
    {
        $list = new IgnoreList([
            ['kind' => 'table', 'name' => 'hc_a'],
            ['kind' => 'column', 'name' => 'hc_b.created'],
            ['kind' => 'prefix', 'name' => 'kiufs_'],
        ]);

        $this->assertTrue($list->isTableIgnored('HC_A'));
        $this->assertTrue($list->isTableIgnored('kiufs_content'));
        $this->assertFalse($list->isTableIgnored('hc_c'));
        $this->assertTrue($list->isPrefixIgnored('KIUFS_'));
        $this->assertFalse($list->isPrefixIgnored('hc_'));
        $this->assertTrue($list->isColumnIgnored('hc_b', 'Created'));
        $this->assertFalse($list->isColumnIgnored('hc_b', 'modified'));
    }
}
