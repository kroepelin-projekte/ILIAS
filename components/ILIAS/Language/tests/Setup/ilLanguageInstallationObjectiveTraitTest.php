<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);

use ILIAS\Setup;
use PHPUnit\Framework\TestCase;

/**
 * Guards the database handling of ilLanguageInstallationObjectiveTrait.
 *
 * The trait used to overwrite $GLOBALS['ilDB'] with the Setup-provided
 * database for the duration of the install and restore it afterwards, with a
 * "@todo remove this once ilSetupLanguage supports proper DI" attached. That
 * is gone: ilSetupLanguage resolves its database lazily, so setDbHandler()
 * is authoritative for everything the install path touches. These tests pin
 * both halves of that down, because a regression would be silent - the
 * global fallback would simply take over again.
 */
class ilLanguageInstallationObjectiveTraitTest extends TestCase
{
    private bool $had_global_db;
    private mixed $previous_global_db = null;

    protected function setUp(): void
    {
        // These tests deliberately manipulate $GLOBALS['ilDB'] - one replaces
        // it, one removes it - so it has to be restored afterwards. Tests run
        // in random order and other tests in this component do rely on the
        // global.
        $this->had_global_db = isset($GLOBALS['ilDB']);
        $this->previous_global_db = $GLOBALS['ilDB'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->had_global_db) {
            $GLOBALS['ilDB'] = $this->previous_global_db;
        } else {
            unset($GLOBALS['ilDB']);
        }
    }

    /**
     * @param list<string> $log collects the tag of every database actually queried
     */
    private function createDatabaseMock(string $tag, array &$log): ilDBInterface
    {
        $db = $this->createMock(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn(mixed $value): string => "'" . (string) $value . "'");
        $db->method('like')->willReturn('1=1');
        $db->method('now')->willReturn('NOW()');
        $db->method('nextId')->willReturn(1);
        $db->method('manipulate')->willReturnCallback(static function () use ($tag, &$log): int {
            $log[] = $tag;
            return 1;
        });
        $db->method('query')->willReturnCallback(function () use ($tag, &$log) {
            $log[] = $tag;
            return $this->createMock(ilDBStatement::class);
        });

        // A single installed language, then exhausted. $rows must be captured
        // by reference - a by-value capture would hand out the same row for
        // ever and the "while ($row = fetchObject())" loops would not end.
        $rows = [(object) ['title' => 'de', 'obj_id' => 7, 'description' => 'installed']];
        $db->method('fetchObject')->willReturnCallback(static function () use (&$rows) {
            return array_shift($rows);
        });

        return $db;
    }

    public function testInjectedDatabaseTakesPrecedenceOverTheGlobal(): void
    {
        $log = [];
        $GLOBALS['ilDB'] = $this->createDatabaseMock('GLOBAL', $log);

        $setup_language = new ilSetupLanguage('de');
        $setup_language->setDbHandler($this->createDatabaseMock('INJECTED', $log));

        $setup_language->getInstalledLanguages();
        $setup_language->registerInstalledLanguage('de', [], []);

        $this->assertNotEmpty($log, 'no database was queried at all');
        $this->assertSame(
            ['INJECTED'],
            array_values(array_unique($log)),
            'the global was used even though a database had been injected'
        );
    }

    public function testAchieveNeedsNoGlobalDatabaseAtAll(): void
    {
        $log = [];
        unset($GLOBALS['ilDB']);

        $objective = new ilLanguagesUpdatedObjective(new ilSetupLanguage('en'));
        $environment = new Setup\ArrayEnvironment([
            Setup\Environment::RESOURCE_DATABASE => $this->createDatabaseMock('INJECTED', $log),
        ]);

        $objective->achieve($environment);

        $this->assertFalse(isset($GLOBALS['ilDB']), 'the global database must not be (re)created');
        $this->assertSame(['INJECTED'], array_values(array_unique($log)));
    }
}
