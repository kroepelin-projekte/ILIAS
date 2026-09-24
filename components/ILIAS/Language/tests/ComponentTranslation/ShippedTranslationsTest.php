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

namespace ILIAS\Language\ComponentTranslation;

use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ShippedTranslations: compile() must compile the shipped `.po` of one module exactly like
 * TranslationCatalog::toMoString() (fuzzy included, empty left out), with every value cleaned by
 * TranslationMarkupPolicy first; read() must serve the build artifact when it is current, and
 * compile the `.po` itself - with the identical result - when the artifact is missing, stale or
 * broken (Konzept Entscheidung 1/2).
 */
class ShippedTranslationsTest extends TestCase
{
    private string $root;
    private ShippedTranslations $shipped_translations;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ilias_shipped_translations_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
        $this->shipped_translations = new ShippedTranslations();
    }

    protected function tearDown(): void
    {
        \MigratedPoFixture::removeDirectory($this->root);
    }

    private function directory(string $prefix, string $relative_path): LanguageFileDirectory
    {
        return \MigratedPoFixture::directory($prefix, $relative_path);
    }

    private function shippedPoPath(string $module, string $lang_key): string
    {
        return $this->root . '/' . $module . '_' . $lang_key . '.po';
    }

    private function artifactPath(string $lang_key, string $module): string
    {
        return $this->root . '/artifacts/language/' . $lang_key . '/' . $module . '.mo';
    }

    // ------------------------------------------------------------ compile()

    /**
     * Only the messages of the requested module (context) are compiled - a shipped `.po` may in
     * principle carry other contexts, and they must not leak into a different module's artifact.
     */
    public function testCompileOnlyIncludesEntriesOfTheRequestedModuleContext(): void
    {
        $catalog = new TranslationCatalog();
        $catalog->add(\MigratedPoFixture::entry('itest', 'greeting', 'Hallo'));
        $catalog->add(\MigratedPoFixture::entry('other', 'greeting', 'Servus'));
        \MigratedPoFixture::writePo($this->shippedPoPath('itest', 'de'), $catalog);

        $mo = $this->shipped_translations->compile($this->shippedPoPath('itest', 'de'), 'itest');

        $this->assertSame(['greeting' => 'Hallo'], TranslationCatalog::readMoTranslationsFromString($mo));
    }

    /**
     * compile() must produce byte-identical output to TranslationCatalog::toMoString() of the
     * equivalent in-memory catalog: fuzzy messages compiled in, empty translations left out.
     */
    public function testCompileMatchesToMoStringSemanticsForFuzzyAndEmptyMessages(): void
    {
        $catalog = \MigratedPoFixture::catalog('itest', [
            'fuzzy_included' => ['value' => 'Ungeprüft', 'fuzzy' => true],
            'empty_excluded' => '',
            'normal' => 'Normal',
        ]);
        \MigratedPoFixture::writePo($this->shippedPoPath('itest', 'de'), $catalog);

        $mo = $this->shipped_translations->compile($this->shippedPoPath('itest', 'de'), 'itest');

        $this->assertSame(
            ['fuzzy_included' => 'Ungeprüft', 'normal' => 'Normal'],
            TranslationCatalog::readMoTranslationsFromString($mo)
        );
        $this->assertSame($catalog->toMoString(), $mo);
    }

    /**
     * A value with markup TranslationMarkupPolicy does not allow is compiled sanitized, and the
     * callback is invoked with the identifier and the violations that were removed - a value that is
     * already allowed never triggers it.
     */
    public function testCompileSanitizesDisallowedMarkupAndReportsIt(): void
    {
        $catalog = \MigratedPoFixture::catalog('itest', [
            'bad' => '<script>alert(1)</script>Hallo',
            'good' => '<b>Hallo</b>',
        ]);
        \MigratedPoFixture::writePo($this->shippedPoPath('itest', 'de'), $catalog);

        $reported = [];
        $mo = $this->shipped_translations->compile(
            $this->shippedPoPath('itest', 'de'),
            'itest',
            static function (string $identifier, array $violations) use (&$reported): void {
                $reported[$identifier] = $violations;
            }
        );

        $translations = TranslationCatalog::readMoTranslationsFromString($mo);
        $this->assertStringNotContainsString('<script', $translations['bad']);
        $this->assertStringContainsString('Hallo', $translations['bad']);
        $this->assertSame('<b>Hallo</b>', $translations['good']);
        $this->assertArrayHasKey('bad', $reported);
        $this->assertArrayNotHasKey('good', $reported);
    }

    public function testCompileThrowsForAnUnreadableShippedPo(): void
    {
        $this->expectException(RuntimeException::class);

        $this->shipped_translations->compile($this->shippedPoPath('itest', 'de'), 'itest');
    }

    // ------------------------------------------------------------ read()

    public function testReadReturnsNullWhenNoShippedPoExists(): void
    {
        $directory = $this->directory('itest', 'lang-of/');
        mkdir($this->root . '/lang-of', 0775, true);

        $this->assertNull($this->shipped_translations->read($this->root, $directory, 'de'));
    }

    public function testReadServesTheCompiledArtifactWhenItIsCurrent(): void
    {
        $directory = $this->directory('itest', '/');
        \MigratedPoFixture::writePo($this->shippedPoPath('itest', 'de'), \MigratedPoFixture::catalog('itest', ['greeting' => 'Aus der PO']));
        $artifact_catalog = \MigratedPoFixture::catalog('itest', ['greeting' => 'Aus dem Artefakt']);
        \MigratedPoFixture::writeMo($this->artifactPath('de', 'itest'), $artifact_catalog);
        // artifact must be at least as new as the .po to be considered current
        touch($this->artifactPath('de', 'itest'), time() + 10);

        $result = $this->shipped_translations->read($this->root, $directory, 'de');

        $this->assertSame(['greeting' => 'Aus dem Artefakt'], $result);
    }

    public function testReadCompilesThePoItselfWhenNoArtifactExistsAndReportsItOnce(): void
    {
        $directory = $this->directory('itest', '/');
        \MigratedPoFixture::writePo($this->shippedPoPath('itest', 'de'), \MigratedPoFixture::catalog('itest', ['greeting' => 'Aus der PO']));

        $reported = [];
        $result = $this->shipped_translations->read(
            $this->root,
            $directory,
            'de',
            static function (string $message, bool $is_missing) use (&$reported): void {
                $reported[] = [$message, $is_missing];
            }
        );

        $this->assertSame(['greeting' => 'Aus der PO'], $result);
        $this->assertCount(1, $reported);
        $this->assertStringContainsString('setup.php build', $reported[0][0]);
        // a MISSING artifact is reported with $is_missing === true (a Notice at the ilLanguage level,
        // not a Warning - see readMigratedLanguageFile())
        $this->assertTrue($reported[0][1]);
    }

    /**
     * The .po changed after the artifact was built (mtime newer) - the result must still be exactly
     * what compiling the .po now produces, not the stale artifact content, and this expected/normal
     * situation must not be reported as "unusable".
     */
    public function testReadCompilesThePoItselfWhenItIsNewerThanTheArtifactWithoutReportingAProblem(): void
    {
        $directory = $this->directory('itest', '/');
        \MigratedPoFixture::writeMo($this->artifactPath('de', 'itest'), \MigratedPoFixture::catalog('itest', ['greeting' => 'Veraltetes Artefakt']));
        touch($this->artifactPath('de', 'itest'), time() - 100);
        \MigratedPoFixture::writePo($this->shippedPoPath('itest', 'de'), \MigratedPoFixture::catalog('itest', ['greeting' => 'Aktualisierte PO']));
        touch($this->shippedPoPath('itest', 'de'), time());

        $reported = [];
        $result = $this->shipped_translations->read(
            $this->root,
            $directory,
            'de',
            static function (string $message) use (&$reported): void {
                $reported[] = $message;
            }
        );

        $this->assertSame(['greeting' => 'Aktualisierte PO'], $result);
        $this->assertSame([], $reported);
    }

    /**
     * A broken (unreadable/corrupt) artifact must not break read(): it falls back to compiling the
     * .po itself, with the identical result a working artifact would have had, and reports the
     * problem exactly once.
     */
    public function testReadFallsBackToCompilingThePoWhenTheArtifactIsCorrupt(): void
    {
        $directory = $this->directory('itest', '/');
        \MigratedPoFixture::writePo($this->shippedPoPath('itest', 'de'), \MigratedPoFixture::catalog('itest', ['greeting' => 'Aus der PO']));
        mkdir(dirname($this->artifactPath('de', 'itest')), 0775, true);
        file_put_contents($this->artifactPath('de', 'itest'), 'not a valid mo file at all, but long enough');
        touch($this->artifactPath('de', 'itest'), time() + 10);

        $reported = [];
        $result = $this->shipped_translations->read(
            $this->root,
            $directory,
            'de',
            static function (string $message, bool $is_missing) use (&$reported): void {
                $reported[] = [$message, $is_missing];
            }
        );

        $this->assertSame(['greeting' => 'Aus der PO'], $result);
        $this->assertCount(1, $reported);
        $this->assertStringContainsString('cannot be read', $reported[0][0]);
        // a BROKEN (unreadable) artifact is reported with $is_missing === false (a Warning at the
        // ilLanguage level, not a Notice) - distinct from the "missing artifact" case above
        $this->assertFalse($reported[0][1]);
    }

    public function testReadThrowsWhenTheShippedPoItselfIsBroken(): void
    {
        $directory = $this->directory('itest', '/');
        file_put_contents($this->shippedPoPath('itest', 'de'), "msgid \"kaputt\n");

        $this->expectException(RuntimeException::class);

        $this->shipped_translations->read($this->root, $directory, 'de');
    }

    public function testReadRejectsAnInvalidLangKeyAsAPathTraversalGuard(): void
    {
        $directory = $this->directory('itest', '/');

        $this->expectException(\InvalidArgumentException::class);

        $this->shipped_translations->read($this->root, $directory, '../evil');
    }
}
