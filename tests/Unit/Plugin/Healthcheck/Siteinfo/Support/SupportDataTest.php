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

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function requirementProvider(): array
    {
        return [
            'below minimum'      => ['mariadb', '10.3.39', 'critical', 'PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_BELOW_MINIMUM', ['10.4']],
            'below supported'    => ['mariadb', '10.4.34', 'warning', 'PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_BELOW_SUPPORTED', ['10.6']],
            'below recommended'  => ['mariadb', '11.8.3-MariaDB', 'none', 'PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_BELOW_RECOMMENDED', ['12.0']],
            'web server note'    => ['nginx', '1.26.0', 'none', 'PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_BELOW_RECOMMENDED', ['1.29']],
            'web server old'     => ['nginx', '1.24.0', 'warning', 'PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_BELOW_SUPPORTED', ['1.26']],
            'php minimum'        => ['php', '8.2.29', 'critical', 'PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_BELOW_MINIMUM', ['8.3.0']],
        ];
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('requirementProvider')]
    #[TestDox('checks $product $version against Joomla requirements as $status')]
    public function testRequirementStatus(string $product, string $version, string $status, string $key, array $args): void
    {
        $result = (new SupportData())->getRequirementStatus($product, $version);

        $this->assertSame($status, $result['status']);
        $this->assertSame($key, $result['key']);
        $this->assertSame($args, $result['args']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('has no requirement message for recommended or unknown products')]
    public function testRequirementStatusNull(): void
    {
        $data = new SupportData();

        $this->assertNull($data->getRequirementStatus('mariadb', '12.0.2'));
        $this->assertNull($data->getRequirementStatus('apache', '2.4.62'));
        $this->assertNull($data->getRequirementStatus('php', '8.4.1'));
        $this->assertNull($data->getRequirementStatus('litespeed', '6.3'));
        $this->assertNull($data->getRequirementStatus('mariadb', ''));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('lets refreshed requirements replace built-in ones per product')]
    public function testRequirementOverride(): void
    {
        $data = new SupportData(['mariadb' => ['12.3', '11.4', '10.6']]);

        $this->assertSame('critical', $data->getRequirementStatus('mariadb', '10.5.27')['status']);
        $this->assertSame(['8.3.0'], $data->getRequirementStatus('php', '8.2.29')['args']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('parses the manual Technical Requirements page')]
    public function testParseManual(): void
    {
        $html   = file_get_contents(__DIR__ . '/fixtures/technical-requirements.html');
        $result = SupportData::parseManual($html, 6);

        $this->assertSame(
            [
                'php'        => ['8.4', '8.3.0', '8.3.0'],
                'mysql'      => ['8.4', '8.0.13', '8.0.13'],
                'mariadb'    => ['12.0', '10.6', '10.4'],
                'postgresql' => ['17.6', '14.0', '12.0'],
                'apache'     => ['2.4', '2.4', null],
                'nginx'      => ['1.29', '1.26', null],
                'iis'        => ['10', '10', null],
            ],
            $result['requirements']
        );
        $this->assertSame('256M', $result['memory']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('rejects a manual page without requirements for the running major version')]
    public function testParseManualMissingMajor(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        SupportData::parseManual(file_get_contents(__DIR__ . '/fixtures/technical-requirements.html'), 7);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('rejects a manual page whose heading has no table')]
    public function testParseManualMissingTable(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        SupportData::parseManual('<html><body><h3>Requirements for Joomla! 6.x</h3><p>Moved.</p></body></html>', 6);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('rejects an empty manual page')]
    public function testParseManualEmpty(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        SupportData::parseManual('', 6);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('rejects a requirements table without a PHP row')]
    public function testParseManualMissingPhpRow(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        SupportData::parseManual('<html><body><h3>Requirements for Joomla! 6.x</h3><table><thead><tr><th>Software</th><th>Recommended</th><th>Supported</th><th>Minimum</th></tr></thead><tbody><tr><td>MySQL</td><td>8.4</td><td>8.0.13</td><td>8.0.13</td></tr></tbody></table></body></html>', 6);
    }
}
