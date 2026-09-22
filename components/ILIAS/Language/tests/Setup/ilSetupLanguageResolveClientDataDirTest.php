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

use PHPUnit\Framework\TestCase;

/**
 * Focused unit coverage for ilSetupLanguage::resolveClientDataDir() (private) - the Setup-context,
 * constant-free equivalent of the CLIENT_DATA_DIR global constant (see
 * components/ILIAS/Language/tools/po-migration/README.md, "Overlay: Installations-eigene
 * `.po`/`.mo`-Dateien", and ILIAS\Setup\Objective\ClientIdReadObjective for the production logic this
 * mirrors without depending on a Setup\Environment).
 *
 * ilSetupLanguage's constructor always computes its own $absolute_path via realpath() relative to this
 * class' own location on disk - there is no constructor hook to inject a different one. Every test
 * below therefore builds a real (cheap, side-effect-free - see the constructor) ilSetupLanguage
 * instance first, then overwrites its private $absolute_path property via reflection to point at a
 * throwaway fixture root, and finally invokes the private resolveClientDataDir() method via reflection
 * too - the same two-step "reflect onto a private member" approach already used for other private
 * members in this component's test suite (see ilSetupLanguageTest::callCheckLanguage()).
 */
class ilSetupLanguageResolveClientDataDirTest extends TestCase
{
    private ?string $fixture_root = null;

    protected function tearDown(): void
    {
        if ($this->fixture_root !== null && is_dir($this->fixture_root)) {
            $this->removeDirectory($this->fixture_root);
        }
        parent::tearDown();
    }

    private function createFixtureRoot(): string
    {
        $this->fixture_root = sys_get_temp_dir() . '/ilias_setup_lang_cdd_test_' . bin2hex(random_bytes(8));
        mkdir($this->fixture_root, 0775, true);
        return $this->fixture_root;
    }

    private function resolveClientDataDirWithAbsolutePath(string $absolute_path): ?string
    {
        $setup_language = new ilSetupLanguage('en');

        $property = new ReflectionProperty(ilSetupLanguage::class, 'absolute_path');
        $property->setValue($setup_language, $absolute_path);

        $method = new ReflectionMethod($setup_language, 'resolveClientDataDir');
        return $method->invoke($setup_language);
    }

    private function writeIniFile(string $root, ?string $datadir): void
    {
        $content = "; <?php exit; ?>\n[clients]\n";
        if ($datadir !== null) {
            $content .= 'datadir = "' . $datadir . '"' . "\n";
        }
        file_put_contents($root . '/ilias.ini.php', $content);
    }

    public function testReturnsNullWhenIniFileIsMissing(): void
    {
        $root = $this->createFixtureRoot();
        // deliberately no ilias.ini.php written

        $this->assertNull($this->resolveClientDataDirWithAbsolutePath($root));
    }

    public function testReturnsNullWhenClientsSectionOrDatadirKeyIsMissing(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/ilias.ini.php',
            "; <?php exit; ?>\n[server]\nhttp_path = \"http://localhost\"\n"
        );

        $this->assertNull($this->resolveClientDataDirWithAbsolutePath($root));
    }

    public function testReturnsNullWhenDatadirIsAnEmptyString(): void
    {
        $root = $this->createFixtureRoot();
        $this->writeIniFile($root, '');

        $this->assertNull($this->resolveClientDataDirWithAbsolutePath($root));
    }

    /**
     * A non-string datadir value (parse_ini_file() produces an array for "datadir[] = ..." entries)
     * must be treated the same as "missing", never passed on to is_dir()/path concatenation - which
     * would otherwise emit a PHP warning or coerce unpredictably.
     */
    public function testReturnsNullWhenDatadirIsNotAString(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/ilias.ini.php',
            "; <?php exit; ?>\n[clients]\ndatadir[] = \"a\"\ndatadir[] = \"b\"\n"
        );

        $this->assertNull($this->resolveClientDataDirWithAbsolutePath($root));
    }

    public function testReturnsNullWhenDatadirPointsAtANonExistentDirectory(): void
    {
        $root = $this->createFixtureRoot();
        $this->writeIniFile($root, $root . '/does-not-exist');

        $this->assertNull($this->resolveClientDataDirWithAbsolutePath($root));
    }

    public function testReturnsNullWhenDatadirHasNoClientSubdirectoryYet(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/data', 0775, true);
        $this->writeIniFile($root, $root . '/data');

        // No client has been created yet - a from-scratch installation before the first client exists.
        $this->assertNull($this->resolveClientDataDirWithAbsolutePath($root));
    }

    public function testReturnsTheSingleClientSubdirectoryWhenExactlyOneExists(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/data/default', 0775, true);
        $this->writeIniFile($root, $root . '/data');

        $this->assertSame(
            $root . '/data/default',
            $this->resolveClientDataDirWithAbsolutePath($root)
        );
    }

    /**
     * Plain files sitting alongside client subdirectories (e.g. a README or a lockfile the data
     * directory happens to also hold) must not be counted as client candidates - only entries for
     * which is_dir() is true count.
     */
    public function testIgnoresPlainFilesWhenCountingClientCandidates(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/data/default', 0775, true);
        file_put_contents($root . '/data/readme.txt', 'not a client');
        $this->writeIniFile($root, $root . '/data');

        $this->assertSame(
            $root . '/data/default',
            $this->resolveClientDataDirWithAbsolutePath($root)
        );
    }

    /**
     * More than one client subdirectory is unresolvable/ambiguous - ILIAS has not supported more than
     * one client per installation since https://docu.ilias.de/goto.php?target=wiki_1357_Setup_-_Abandon_Multi_Client,
     * so there is no rule for picking "the right one" here.
     */
    public function testReturnsNullWhenMoreThanOneClientSubdirectoryExists(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/data/default', 0775, true);
        mkdir($root . '/data/second', 0775, true);
        $this->writeIniFile($root, $root . '/data');

        $this->assertNull($this->resolveClientDataDirWithAbsolutePath($root));
    }

    /**
     * A relative datadir value (as shipped in the real ilias.ini.php, e.g. "public/data") must be
     * resolved relative to $absolute_path, mirroring ClientIdReadObjective's own resolution.
     */
    public function testResolvesARelativeDatadirValueRelativeToTheAbsolutePath(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/public/data/default', 0775, true);
        $this->writeIniFile($root, 'public/data');

        $this->assertSame(
            $root . '/public/data/default',
            $this->resolveClientDataDirWithAbsolutePath($root)
        );
    }

    /**
     * An absolute datadir value (starting with "/") must be used as-is, never prefixed with
     * $absolute_path - this is the real-world shape for a container deployment where the data
     * directory lives entirely outside the ILIAS webroot (see the real ilias.ini.php shipped in this
     * checkout, whose "datadir" is "/var/iliasdata").
     */
    public function testResolvesAnAbsoluteDatadirValueAsIs(): void
    {
        $root = $this->createFixtureRoot();
        $absolute_datadir = sys_get_temp_dir()
            . '/ilias_setup_lang_cdd_test_absolute_datadir_' . bin2hex(random_bytes(8));
        mkdir($absolute_datadir . '/default', 0775, true);

        try {
            $this->writeIniFile($root, $absolute_datadir);

            $this->assertSame(
                $absolute_datadir . '/default',
                $this->resolveClientDataDirWithAbsolutePath($root)
            );
        } finally {
            $this->removeDirectory($absolute_datadir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
