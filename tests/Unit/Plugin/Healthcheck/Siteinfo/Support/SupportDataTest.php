<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Siteinfo
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Siteinfo\Support;

use Joomla\Plugin\Healthcheck\Siteinfo\Support\SupportData;
use Joomla\Plugin\Quickicon\Eos\Extension\Eos;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Site Information support data
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The Site Information support data')]
class SupportDataTest extends UnitTestCase
{
    /**
     * Fixed "today" for all status tests.
     *
     * @return  \DateTimeImmutable
     *
     * @since   __DEPLOY_VERSION__
     */
    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-15', new \DateTimeZone('UTC'));
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function normalizeProvider(): array
    {
        return [
            'plain'           => ['8.4.1', '8.4.1'],
            'mariadb suffix'  => ['11.8.3-MariaDB-ubu2404', '11.8.3'],
            'ubuntu mysql'    => ['8.0.36-0ubuntu0.22.04.1', '8.0.36'],
            'joomla dev'      => ['6.2.1-dev', '6.2.1'],
            'joomla alpha'    => ['6.3.0-alpha1', '6.3.0'],
            'not a version'   => ['unknown', ''],
            'empty'           => ['', ''],
        ];
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('normalizeProvider')]
    #[TestDox('normalizes $version to $expected')]
    public function testNormalize(string $version, string $expected): void
    {
        $this->assertSame($expected, SupportData::normalize($version));
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function cycleProvider(): array
    {
        return [
            ['php', '8.4.1', '8.4'],
            ['mysql', '8.4.3', '8.4'],
            ['mariadb', '10.11.6', '10.11'],
            ['mariadb', '11.8.3-MariaDB-ubu2404', '11.8'],
            ['postgresql', '17.4', '17'],
            ['postgresql', '9.6.24', '9.6'],
            ['joomla', '6.2.1-dev', '6'],
        ];
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('cycleProvider')]
    #[TestDox('resolves $product $version to cycle $expected')]
    public function testCycleFor(string $product, string $version, string $expected): void
    {
        $this->assertSame($expected, SupportData::cycleFor($product, $version));
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function eolProvider(): array
    {
        return [
            'eol passed'             => ['php', '8.1.2', 'critical', 'PLG_HEALTHCHECK_SITEINFO_STATUS_EOL_PAST', ['2025-12-31']],
            'eol within 6 months'    => ['php', '8.2.5', 'warning', 'PLG_HEALTHCHECK_SITEINFO_STATUS_EOL_SOON', ['2026-12-31']],
            'security fixes only'    => ['php', '8.3.10', 'warning', 'PLG_HEALTHCHECK_SITEINFO_STATUS_SECURITY_ONLY', ['2027-12-31']],
            'supported'              => ['mariadb', '11.8.3', 'healthy', 'PLG_HEALTHCHECK_SITEINFO_STATUS_SUPPORTED', ['2028-06-04']],
            'mariadb dev site'       => ['mariadb', '12.2.2-MariaDB', 'critical', 'PLG_HEALTHCHECK_SITEINFO_STATUS_EOL_PAST', ['2026-05-28']],
            'older than known'       => ['php', '5.6.40', 'critical', 'PLG_HEALTHCHECK_SITEINFO_STATUS_EOL_OLD', []],
            'newer than known'       => ['php', '9.0.0', 'healthy', 'PLG_HEALTHCHECK_SITEINFO_STATUS_NEWER', []],
            'gap in known cycles'    => ['mysql', '8.5.0', 'none', 'PLG_HEALTHCHECK_SITEINFO_STATUS_NO_DATA', []],
            'joomla dev version'     => ['joomla', '6.2.1-dev', 'healthy', 'PLG_HEALTHCHECK_SITEINFO_STATUS_SUPPORTED', ['2029-10-16']],
            'empty version'          => ['mysql', '', 'none', 'PLG_HEALTHCHECK_SITEINFO_STATUS_NO_DATA', []],
            'unparseable version'    => ['php', 'unknown', 'none', 'PLG_HEALTHCHECK_SITEINFO_STATUS_NO_DATA', []],
        ];
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('eolProvider')]
    #[TestDox('reports $product $version as $status')]
    public function testEolStatus(string $product, string $version, string $status, string $key, array $args): void
    {
        $result = (new SupportData())->getEolStatus($product, $version, $this->today());

        $this->assertSame($status, $result['status']);
        $this->assertSame($key, $result['key']);
        $this->assertSame($args, $result['args']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('uses the same Joomla 6 end of support date as the EOS quickicon')]
    public function testJoomlaEolMatchesEosQuickicon(): void
    {
        $eosDate = (new \ReflectionClassConstant(Eos::class, 'EOS_DATE'))->getValue();

        $this->assertSame($eosDate, (new SupportData())->getCycle('joomla', '6')['eol']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('knows which products have end of life data')]
    public function testHasEolData(): void
    {
        $data = new SupportData();

        $this->assertTrue($data->hasEolData('postgresql'));
        $this->assertFalse($data->hasEolData('nginx'));
    }
}
