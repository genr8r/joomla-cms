<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Securityheaders
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Securityheaders\Headers;

use Joomla\Http\HttpFactory;
use Joomla\Plugin\Healthcheck\Securityheaders\Headers\HeaderFetcher;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the security headers fetcher (no outbound network)
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The security headers fetcher')]
class HeaderFetcherTest extends UnitTestCase
{
    private const NOW = 1800000000;

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('lower-cases names and joins repeated headers')]
    public function testNormalise(): void
    {
        $this->assertSame(
            ['x-frame-options' => 'SAMEORIGIN, SAMEORIGIN', 'server' => 'Caddy', 'x-empty' => ''],
            HeaderFetcher::normalise(['X-Frame-Options' => ['SAMEORIGIN', ' SAMEORIGIN '], 'Server' => 'Caddy', 'X-Empty' => [''], '' => ['dropped']])
        );
        $this->assertSame(['set-cookie' => 'a=1, b=2'], HeaderFetcher::normalise(['Set-Cookie' => ['a=1'], 'set-cookie' => ['b=2']]));
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function resolveProvider(): array
    {
        return [
            'absolute'             => ['http://example.org/', 'https://example.org/', 'https://example.org/'],
            'scheme-relative'      => ['https://example.org/', '//www.example.org/', 'https://www.example.org/'],
            'root-relative'        => ['https://example.org:8443/joomla/', '/joomla/en/', 'https://example.org:8443/joomla/en/'],
            'path-relative'        => ['https://example.org/joomla/', 'en/', 'https://example.org/joomla/en/'],
            'path-relative no dir' => ['https://example.org', 'en/', 'https://example.org/en/'],
            'empty'                => ['https://example.org/', '  ', null],
            'other scheme'         => ['https://example.org/', 'ftp://example.org/', null],
        ];
    }

    /**
     * @param   string   $base      Requested URL
     * @param   string   $location  Location header
     * @param   ?string  $expected  Resolved URL
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('resolveProvider')]
    #[TestDox('resolves redirect locations against the requested URL')]
    public function testResolve(string $base, string $location, ?string $expected): void
    {
        $this->assertSame($expected, HeaderFetcher::resolve($base, $location));
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function usableProvider(): array
    {
        $now     = self::NOW;
        $success = ['ok' => true, 'url' => 'https://example.org/', 'status' => 200, 'headers' => ['server' => 'x'], 'reason' => '', 'checkedAt' => $now - 3600];
        $failure = ['ok' => false, 'url' => 'https://example.org/', 'status' => 0, 'headers' => [], 'reason' => 'refused', 'checkedAt' => $now - 600];

        return [
            'fresh success'         => [$success, true],
            'success at 24 hours'   => [['checkedAt' => $now - 86400] + $success, false],
            'fresh failure'         => [$failure, true],
            'failure at one hour'   => [['checkedAt' => $now - 3600] + $failure, false],
            'checked in the future' => [['checkedAt' => $now + 60] + $success, false],
            'cache miss'            => [false, false],
            'missing key'           => [array_diff_key($success, ['headers' => true]), false],
            'headers not an array'  => [['headers' => 'x'] + $success, false],
            'status as string'      => [['status' => '200'] + $success, false],
        ];
    }

    /**
     * @param   mixed    $cached    Cache content
     * @param   boolean  $expected  Usable
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('usableProvider')]
    #[TestDox('reuses well-formed cached responses for 24 hours, failures for 1 hour')]
    public function testIsUsable(mixed $cached, bool $expected): void
    {
        $this->assertSame($expected, HeaderFetcher::isUsable($cached, self::NOW));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('clamps the timeout to 1 to 10 seconds')]
    public function testTimeout(): void
    {
        $this->assertSame([5, 7, 1, 10, 5], array_map([HeaderFetcher::class, 'timeout'], [null, '7', '0', '99', 'x']));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('reports a refused connection as a failure, not an exception')]
    public function testRefused(): void
    {
        $result = (new HeaderFetcher(new HttpFactory()))->fetch('http://127.0.0.1:1/', 1);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['headers']);
        $this->assertNotSame('', $result['reason']);
        $this->assertSame('http://127.0.0.1:1/', $result['url']);
    }
}
