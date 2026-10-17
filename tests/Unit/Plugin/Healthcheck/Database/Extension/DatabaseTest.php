<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Extension;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Input\Input;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Plugin\Healthcheck\Database\Extension\Database;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreRepository;
use Joomla\Plugin\Healthcheck\Database\Ownership\TableOwnership;
use Joomla\Plugin\Healthcheck\Database\Scan\ResultStore;
use Joomla\Plugin\Healthcheck\Database\Scan\UnusedTables;
use Joomla\Plugin\Healthcheck\Database\Scan\ZeroDateScanner;
use Joomla\Session\SessionInterface;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Database health check findings table
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The Database health check findings table')]
class DatabaseTest extends UnitTestCase
{
    private const EVIL = '<img src=x onerror=1>';

    /**
     * @var    array  $_GET before the test
     * @since  __DEPLOY_VERSION__
     */
    private array $get = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->get = $_GET;

        $language = $this->createStub(Language::class);
        $language->method('_')->willReturnArgument(0);
        $language->method('getPluralSuffixes')->willReturn(['MORE']);
        $language->method('hasKey')->willReturn(false);
        Factory::$language = $language;

        HTMLHelper::register('date', static fn ($value): string => 'DATE(' . $value . ')');
    }

    protected function tearDown(): void
    {
        HTMLHelper::unregister('date');
        Factory::$language    = null;
        Factory::$application = null;
        $_GET                 = $this->get;

        parent::tearDown();
    }

    /**
     * @param   string[]  $names  Table names
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function tables(array $names): array
    {
        return array_map(static fn ($name) => ['name' => $name, 'rows' => 1, 'bytes' => 1024, 'updated' => null], $names);
    }

    /**
     * Zero-date state with one result per table.
     *
     * @param   array<string, string[]>  $tables  Table name => column names
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function zeroState(array $tables): array
    {
        $state = ZeroDateScanner::emptyState();

        foreach ($tables as $table => $columns) {
            $state['results'][strtolower($table)] = [
                'table'   => $table,
                'checked' => '2026-10-17 10:00:00',
                'columns' => array_map(static fn ($column) => ['column' => $column, 'type' => 'datetime', 'zero' => 3], $columns),
            ];
        }

        return $state;
    }

    #[TestDox('One row per group with open tables, own group first, counting open tables only')]
    public function testUnusedRows(): void
    {
        $ignores = new IgnoreList([['kind' => 'table', 'name' => 'hc_orphan_b'], ['kind' => 'table', 'name' => 'old_x']]);
        $groups  = UnusedTables::groups(
            $this->tables(['old_x', 'hc_orphan_a', 'hc_orphan_b', 'hc_orphan_c', 'kiufs_content', 'kiufs_users']),
            ['#__content'],
            'hc_',
            $ignores
        );

        $rows = Database::tableRows($groups, [], 'hc_');

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['finding' => 'PLG_HEALTHCHECK_DATABASE_FINDING_UNUSED', 'where' => 'hc_', 'count' => 2, 'kind' => 'unused', 'key' => 'hc_'],
            $rows[0]
        );
        $this->assertSame(
            ['finding' => 'PLG_HEALTHCHECK_DATABASE_FINDING_UNUSED_OTHER', 'where' => 'kiufs_', 'count' => 2, 'kind' => 'unused', 'key' => 'kiufs_'],
            $rows[1]
        );
    }

    #[TestDox('A fully ignored group and a prefix-ignored group give no row')]
    public function testIgnoredGroupsGiveNoRow(): void
    {
        $ignores = new IgnoreList([['kind' => 'table', 'name' => 'hc_orphan'], ['kind' => 'prefix', 'name' => 'kiufs_']]);
        $groups  = UnusedTables::groups($this->tables(['hc_orphan', 'kiufs_content', 'kiufs_users']), [], 'hc_', $ignores);

        $this->assertSame([], Database::tableRows($groups, [], 'hc_'));
    }

    #[TestDox('Tables without a prefix are labelled as such')]
    public function testNoPrefixGroup(): void
    {
        $groups = UnusedTables::groups($this->tables(['legacy']), [], 'hc_', new IgnoreList([]));
        $rows   = Database::tableRows($groups, [], 'hc_');

        $this->assertSame('PLG_HEALTHCHECK_DATABASE_WHERE_NO_PREFIX', $rows[0]['where']);
        $this->assertSame('', $rows[0]['key']);
    }

    #[TestDox('One row per table with open zero-date columns, counting its open columns')]
    public function testZeroDateRows(): void
    {
        $ignores  = new IgnoreList([['kind' => 'column', 'name' => 'hc_a.modified'], ['kind' => 'column', 'name' => 'hc_b.created']]);
        $findings = ZeroDateScanner::findings($this->zeroState(['hc_a' => ['created', 'modified', 'publish_up'], 'hc_b' => ['created']]), $ignores);

        $this->assertSame(
            [['finding' => 'PLG_HEALTHCHECK_DATABASE_FINDING_ZERODATES', 'where' => 'hc_a', 'count' => 2, 'kind' => 'zerodates', 'key' => 'hc_a']],
            Database::tableRows([], $findings, 'hc_')
        );
    }

    #[TestDox('Unused rows come before zero-date rows')]
    public function testRowOrder(): void
    {
        $groups   = UnusedTables::groups($this->tables(['hc_orphan']), [], 'hc_', new IgnoreList([]));
        $findings = ZeroDateScanner::findings($this->zeroState(['hc_content' => ['created']]), new IgnoreList([]));

        $this->assertSame(['unused', 'zerodates'], array_column(Database::tableRows($groups, $findings, 'hc_'), 'kind'));
    }

    #[TestDox('HTML in table names is escaped by every custom renderer')]
    public function testHtmlInNamesIsEscaped(): void
    {
        $findings = ZeroDateScanner::findings($this->zeroState([self::EVIL => ['created']]), new IgnoreList([]));
        $rows     = Database::tableRows([], $findings, 'hc_');
        $labels   = ['finding' => 'Finding', 'where' => 'Where', 'count' => 'Count', 'details' => 'Details'];
        $html     = '';

        foreach (Database::columns($labels) as $column) {
            $this->assertNotSame('', $column['title']);

            if (($column['type'] ?? '') === 'custom') {
                $html .= $column['renderer']($rows[0][$column['key']] ?? '', $rows[0]);
            }
        }

        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    /**
     * A plugin wired with stubs: no ignore entries, cached results as given, a Super User.
     *
     * @param   array  $params  Plugin params
     * @param   array  $cached  Cache key => stored value
     * @param   array  $opts    server (type), admin (core.admin), client, input (data)
     *
     * @return  Database
     *
     * @since   __DEPLOY_VERSION__
     */
    private function plugin(array $params, array $cached, array $opts = []): Database
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('getServerType')->willReturn($opts['server'] ?? 'mysql');
        $db->method('getPrefix')->willReturn('hc_');
        $db->method('getQuery')->willThrowException(new \RuntimeException('no database in unit tests'));
        $db->method('createQuery')->willThrowException(new \RuntimeException('no database in unit tests'));

        $cache = $this->createStub(OutputController::class);
        $cache->method('get')->willReturnCallback(static fn ($key) => $cached[$key] ?? false);

        $factory = $this->createStub(CacheControllerFactoryInterface::class);
        $factory->method('createCacheController')->willReturn($cache);

        $user = $this->createStub(User::class);
        $user->method('authorise')->willReturn($opts['admin'] ?? true);

        $session = $this->createStub(SessionInterface::class);
        $session->method('get')->willReturn($user);
        $session->method('getToken')->willReturn('t');

        $app = $this->createStub(AdministratorApplication::class);
        $app->method('getIdentity')->willReturn($user);
        $app->method('getSession')->willReturn($session);
        $app->method('get')->willReturn('secret');
        $app->method('isClient')->willReturnCallback(static fn ($client) => $client === ($opts['client'] ?? 'administrator'));
        $app->method('getInput')->willReturn(new Input($opts['input'] ?? []));
        Factory::$application = $app;

        $plugin = new Database(
            ['name' => 'database', 'type' => 'healthcheck', 'params' => json_encode($params + ['context' => 'general'])],
            new IgnoreRepository($db),
            new ResultStore($factory),
            new TableOwnership('/nonexistent', '/nonexistent')
        );
        $plugin->setApplication($app);
        $plugin->setDatabase($db);

        return $plugin;
    }

    /**
     * Icons added by the plugin, keyed by id.
     *
     * @param   Database  $plugin  The plugin
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function icons(Database $plugin): array
    {
        $event = new HealthChecksEvent('onHealthcheckGetIcons', ['context' => 'general']);
        $plugin->onHealthcheckGetIcons($event);

        return array_column($event->getArgument('result')[0], null, 'id');
    }

    #[TestDox('The icons come in order, all in the Database section, with a live schema icon')]
    public function testIcons(): void
    {
        $now    = (new Date())->toSql();
        $unused = ['updated' => $now, 'tables' => $this->tables(['hc_orphan', 'kiufs_a', 'kiufs_b']), 'accounted' => []];
        $icons  = $this->icons($this->plugin([], ['unused' => $unused]));

        $this->assertSame(
            ['plg_healthcheck_database_schema', 'plg_healthcheck_database_zerodates', 'plg_healthcheck_database_unused', 'plg_healthcheck_database_checknow'],
            array_keys($icons)
        );
        $this->assertSame(['PLG_HEALTHCHECK_DATABASE_SECTION'], array_values(array_unique(array_column($icons, 'section'))));

        $schema = $icons['plg_healthcheck_database_schema'];
        $this->assertStringContainsString('&amp;task=schema&amp;format=json&amp;', $schema['ajaxurl']);
        $this->assertStringEndsWith('=1', $schema['ajaxurl']);
        $this->assertArrayNotHasKey('amount', $schema);
        $this->assertArrayNotHasKey('status', $schema);

        $zero = $icons['plg_healthcheck_database_zerodates'];
        $this->assertSame('PLG_HEALTHCHECK_DATABASE_ZERODATES_NEVER', $zero['text']);
        $this->assertArrayNotHasKey('amount', $zero);
        $this->assertArrayNotHasKey('status', $zero);

        $this->assertSame(3, $icons['plg_healthcheck_database_unused']['amount']);
        $this->assertSame('warning', $icons['plg_healthcheck_database_unused']['status']);
        $this->assertSame('plgHealthcheckDatabaseUnused', $icons['plg_healthcheck_database_unused']['onclick']);
        $this->assertArrayNotHasKey('status', $icons['plg_healthcheck_database_checknow']);
    }

    #[TestDox('A partial zero-date pass shows its results, the check time and the progress')]
    public function testZeroDatesPartial(): void
    {
        $state             = $this->zeroState(['hc_a' => ['created', 'modified']]);
        $state['pass']     = '2026-10-17 10:00:00';
        $state['cursor']   = 1;
        $state['tables']   = ['hc_a' => [], 'hc_b' => [], 'hc_c' => []];
        $state['updated']  = '2026-10-16 10:00:00';
        $state['complete'] = false;

        $icons = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]));
        $zero  = $icons['plg_healthcheck_database_zerodates'];

        $this->assertArrayNotHasKey('plg_healthcheck_database_unused', $icons);
        $this->assertSame(2, $zero['amount']);
        $this->assertSame('warning', $zero['status']);
        $this->assertSame(
            'PLG_HEALTHCHECK_DATABASE_N_IN_M<br>PLG_HEALTHCHECK_DATABASE_ZERODATES_CHECKED<br>PLG_HEALTHCHECK_DATABASE_ZERODATES_PARTIAL',
            $zero['text']
        );
    }

    #[TestDox('With both checks off only the schema icon is shown')]
    public function testChecksOff(): void
    {
        $icons = $this->icons($this->plugin(['check_unused' => 0, 'check_zerodates' => 0], []));

        $this->assertSame(['plg_healthcheck_database_schema'], array_keys($icons));
    }

    /**
     * Use the plugin's real en-GB strings with English plural suffixes.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function english(): void
    {
        $strings  = parse_ini_file(JPATH_ROOT . '/administrator/language/en-GB/plg_healthcheck_database.ini');
        $language = $this->createStub(Language::class);
        $language->method('_')->willReturnCallback(static fn ($key) => $strings[$key] ?? $key);
        $language->method('hasKey')->willReturnCallback(static fn ($key) => isset($strings[$key]));
        $language->method('getPluralSuffixes')->willReturnCallback(static fn ($n) => match ((int) $n) {
            0       => ['0'],
            1       => ['ONE', '1'],
            default => ['OTHER', 'MORE'],
        });
        Factory::$language = $language;
    }

    #[TestDox('The schema result counts extensions with problems, the problems and the extensions checked')]
    public function testSchemaSummary(): void
    {
        $this->english();

        $this->assertSame(
            ['amount' => 2, 'status' => 'warning', 'problems' => 3, 'extensions' => 3, 'text' => '3 problems in 2 extensions'],
            Database::schemaSummary([['errorsCount' => 2], ['errorsCount' => 0], ['errorsCount' => 1]])
        );
        $this->assertSame('1 problem in 1 extension', Database::schemaSummary([['errorsCount' => 1]])['text']);
        $this->assertSame(
            ['amount' => 0, 'status' => 'success', 'problems' => 0, 'extensions' => 4, 'text' => 'All 4 extensions match their schema'],
            Database::schemaSummary(array_fill(0, 4, ['errorsCount' => 0]))
        );
        $this->assertSame('1 extension matches its schema', Database::schemaSummary([['errorsCount' => 0]])['text']);
    }

    #[TestDox('Icon summaries use singular and plural forms')]
    public function testPlurals(): void
    {
        $this->english();

        $now    = (new Date())->toSql();
        $unused = ['updated' => $now, 'tables' => $this->tables(['hc_orphan']), 'accounted' => []];
        $state  = $this->zeroState(['hc_a' => ['created']]);

        $state['updated']  = $now;
        $state['complete'] = true;

        $icons = $this->icons($this->plugin([], ['unused' => $unused, 'zerodates' => $state]));

        $this->assertSame('1 table in 1 group', $icons['plg_healthcheck_database_unused']['text']);
        $this->assertStringStartsWith('1 column in 1 table<br>', $icons['plg_healthcheck_database_zerodates']['text']);
    }

    #[TestDox('The unused icon says unavailable when the refresh fails and nothing is cached')]
    public function testUnusedUnavailable(): void
    {
        $unused = $this->icons($this->plugin(['check_zerodates' => 0], []))['plg_healthcheck_database_unused'];

        $this->assertSame('PLG_HEALTHCHECK_DATABASE_UNAVAILABLE', $unused['text']);
        $this->assertArrayNotHasKey('amount', $unused);
        $this->assertArrayNotHasKey('status', $unused);
    }

    #[TestDox('The unused icon says unavailable on an unsupported database driver')]
    public function testUnsupportedDriver(): void
    {
        $icons = $this->icons($this->plugin([], [], ['server' => 'sqlsrv']));

        $this->assertArrayNotHasKey('plg_healthcheck_database_zerodates', $icons);
        $this->assertSame('PLG_HEALTHCHECK_DATABASE_UNAVAILABLE', $icons['plg_healthcheck_database_unused']['text']);
        $this->assertArrayNotHasKey('status', $icons['plg_healthcheck_database_unused']);
    }

    /**
     * Dispatch onAjaxDatabase and return the exception code, or 0 when none was thrown.
     *
     * @param   array    $opts       Options for plugin()
     * @param   boolean  $withToken  Whether the query carries the form token
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ajaxCode(array $opts, bool $withToken = true): int
    {
        $plugin = $this->plugin([], [], $opts);
        $_GET   = $withToken ? [Session::getFormToken() => '1'] : ['bad' => '1'];

        try {
            $plugin->onAjaxDatabase(new AjaxEvent('onAjaxDatabase', ['subject' => Factory::$application]));
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }

        return 0;
    }

    #[TestDox('The Ajax handler refuses a bad token, a non Super User and the site client, and an unknown task')]
    public function testAjaxGuard(): void
    {
        $task = ['input' => ['task' => 'nope']];

        $this->assertSame(403, $this->ajaxCode($task, false));
        $this->assertSame(403, $this->ajaxCode($task + ['admin' => false]));
        $this->assertSame(403, $this->ajaxCode($task + ['client' => 'site']));
        $this->assertSame(400, $this->ajaxCode($task));
    }

    #[TestDox('The Details button carries the finding kind and key')]
    public function testDetailsButton(): void
    {
        $columns = array_column(Database::columns(['finding' => 'F', 'where' => 'W', 'count' => 'C', 'details' => 'Details']), null, 'key');
        $html    = $columns['details']['renderer']('', ['kind' => 'unused', 'key' => 'kiufs_"x']);

        $this->assertStringContainsString('class="btn btn-sm btn-outline-secondary plg-hc-db-details"', $html);
        $this->assertStringContainsString('data-hc-db-kind="unused"', $html);
        $this->assertStringContainsString('data-hc-db-key="kiufs_&quot;x"', $html);
        $this->assertSame('badge', $columns['count']['type']);
        $this->assertSame('warning', $columns['count']['badgeClass']);
    }
}
