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

/**
 * The process state native gettext (the PHP extension "gettext" on top of the C library) needs to
 * serve the compiled catalogs of the migrated modules, and the one place that calls it.
 *
 * Native gettext only translates while LC_MESSAGES is not "C"/"POSIX", and looks a catalog up as
 * `<bound directory>/<language>/LC_MESSAGES/<domain>.mo`. ILIAS neither depends on system locales
 * nor on the language of the locale:
 * - the build ships its own locale (a copy of the C library's "C.utf8", see ShippedTranslationsBuild)
 *   named LOCALE_NAME; activate() loads it through LOCPATH and removes LOCPATH again right away (as
 *   long as it is set, setlocale() finds no system locale);
 * - LANGUAGE is set to CATALOG_LANGUAGE, so the "<language>" above is always "messages" - the
 *   language of a catalog is part of its domain ("<module>.<lang>", see MigratedLanguageFilePaths),
 *   since the C library caches what it found per process and a later change of LANGUAGE would not
 *   reliably switch catalogs;
 * - every domain is bound with the codeset UTF-8, so LC_CTYPE never converts a value.
 *
 * Both settings are process-wide. PHP itself takes them back at the end of every request (in the
 * request shutdown of ext/standard: variables set with putenv() get their former value again, a
 * locale changed with setlocale() is reset to "C") - verified in FPM: the next request of the same
 * worker starts with LC_MESSAGES "C" and without LANGUAGE. No shutdown function of its own is needed
 * (it would only prevent a later shutdown function from still translating). In a thread-safe (ZTS)
 * PHP the settings apply to every thread of the process, and the end of another thread's request
 * can take them back in the middle of this one: isActive() checks LC_MESSAGES and LANGUAGE, and
 * reactivateIfLost() activates again (the administration shows a hint, see isThreadSafe()).
 * Child processes (exec(), proc_open()) inherit LANGUAGE=messages - harmless, a C program only
 * looks at it with a locale other than "C".
 *
 * The C library never reloads a catalog file it has read once in a process (also not one that was
 * replaced) - every new state therefore gets a directory of its own (a build, an overlay revision),
 * and binding the domain to that directory loads it.
 */
final class NativeGettext
{
    /**
     * The name of the locale the build ships (see ShippedTranslationsBuild).
     */
    public const string LOCALE_NAME = 'ilias_messages';

    /**
     * The value of LANGUAGE, i.e. the directory below a bound directory the catalogs are looked up in.
     */
    public const string CATALOG_LANGUAGE = 'messages';

    /**
     * Where a catalog lies below a bound directory: `<bound directory>/messages/LC_MESSAGES/<domain>.mo`.
     */
    public const string CATALOG_DIRECTORY = self::CATALOG_LANGUAGE . '/LC_MESSAGES';

    private const string CODESET = 'UTF-8';

    /**
     * Longer message ids are refused by PHP (ValueError) - they are never found.
     */
    private const int MAX_MESSAGE_LENGTH = 4096;

    /**
     * The locale directory activate() last succeeded with, `null` while not activated.
     */
    private static ?string $locale_directory = null;

    /**
     * LANGUAGE and LC_MESSAGES before the first activate() since the last restore() (`false`: not
     * set), see restore().
     *
     * @var array{language: string|false, messages: string|false}|null
     */
    private static ?array $previous = null;

    private static ?bool $thread_safe_for_tests = null;

    private static ?string $problem = null;

    public static function isExtensionLoaded(): bool
    {
        return extension_loaded('gettext');
    }

    /**
     * Whether PHP is thread-safe (ZTS): the settings of activate() then apply to every thread of
     * the process and are not taken back.
     */
    public static function isThreadSafe(): bool
    {
        return self::$thread_safe_for_tests ?? (bool) PHP_ZTS;
    }

    /**
     * Makes isThreadSafe() return $thread_safe (`null`: PHP_ZTS again) - for tests only.
     *
     * @internal
     */
    public static function useThreadSafeForTests(?bool $thread_safe): void
    {
        self::$thread_safe_for_tests = $thread_safe;
    }

    /**
     * Activates the locale LOCALE_NAME of $locale_directory for LC_MESSAGES and sets LANGUAGE (see
     * the class docblock). Whether native gettext translates afterwards - if not, getProblem() tells
     * why.
     */
    public static function activate(string $locale_directory): bool
    {
        if (!self::isExtensionLoaded()) {
            self::$problem = 'the PHP extension "gettext" is not loaded';
            return false;
        }
        if (self::$locale_directory === $locale_directory && self::isActive()) {
            return true;
        }
        if (!is_dir($locale_directory . '/' . self::LOCALE_NAME)) {
            self::$problem = sprintf('the locale "%s" is missing in "%s"', self::LOCALE_NAME, $locale_directory);
            return false;
        }

        self::$previous ??= ['language' => getenv('LANGUAGE'), 'messages' => setlocale(LC_MESSAGES, '0')];
        $locpath = getenv('LOCPATH');
        putenv('LOCPATH=' . $locale_directory);
        $locale = setlocale(LC_MESSAGES, self::LOCALE_NAME);
        // as long as LOCPATH is set, setlocale() finds no locale of the system (e.g. for LC_TIME)
        putenv($locpath === false ? 'LOCPATH' : 'LOCPATH=' . $locpath);
        if ($locale !== self::LOCALE_NAME) {
            self::$problem = sprintf('the locale "%s" of "%s" cannot be activated', self::LOCALE_NAME, $locale_directory);
            return false;
        }
        putenv('LANGUAGE=' . self::CATALOG_LANGUAGE);

        self::$locale_directory = $locale_directory;
        self::$problem = null;

        return true;
    }

    /**
     * Whether activate() succeeded and its settings are still in place - other code (e.g.
     * ilInitialisation::initLocale() with LC_ALL) may have changed LC_MESSAGES since.
     */
    public static function isActive(): bool
    {
        return self::$locale_directory !== null
            && setlocale(LC_MESSAGES, '0') === self::LOCALE_NAME
            && getenv('LANGUAGE') === self::CATALOG_LANGUAGE;
    }

    /**
     * Activates the locale again if it was activated before and its settings changed since.
     *
     * @return bool whether it had to be (and could be) activated again - a lookup that found
     *         nothing is worth repeating then
     */
    public static function reactivateIfLost(): bool
    {
        if (self::$locale_directory === null || self::isActive()) {
            return false;
        }

        return self::activate(self::$locale_directory);
    }

    /**
     * Takes LANGUAGE and LC_MESSAGES back to what they were before the first activate() - a setting
     * changed by other code since is left as it is. Only for a long-running process that should not
     * keep the settings (e.g. Setup's build after its check); a web request needs no call, PHP takes
     * them back itself (see the class docblock). Not in a thread-safe PHP, where other threads may be
     * using them. The activated locale directory is kept: reactivateIfLost() can activate it again.
     */
    public static function restore(): void
    {
        if (self::$previous === null || self::isThreadSafe()) {
            return;
        }
        if (self::$previous['messages'] !== false && setlocale(LC_MESSAGES, '0') === self::LOCALE_NAME) {
            setlocale(LC_MESSAGES, self::$previous['messages']);
        }
        if (getenv('LANGUAGE') === self::CATALOG_LANGUAGE) {
            putenv(self::$previous['language'] === false ? 'LANGUAGE' : 'LANGUAGE=' . self::$previous['language']);
        }
        self::$previous = null;
    }

    /**
     * Why the last activate() failed, `null` if it did not.
     */
    public static function getProblem(): ?string
    {
        return self::$problem;
    }

    /**
     * Binds $domain to $directory (see the class docblock) with the codeset UTF-8.
     *
     * @return bool `false` if the extension is missing or $directory does not exist
     */
    public static function bind(string $domain, string $directory): bool
    {
        return self::isExtensionLoaded()
            && @bindtextdomain($domain, $directory) !== false
            && @bind_textdomain_codeset($domain, self::CODESET) !== false;
    }

    /**
     * Whether the catalog of the bound $domain can be read: its header (the translation of the
     * empty message id) is not empty - every catalog ILIAS compiles has one.
     */
    public static function isLoaded(string $domain): bool
    {
        return self::isExtensionLoaded() && dgettext($domain, '') !== '';
    }

    /**
     * The translation of $message_id in $domain - $message_id itself if there is none (that is how
     * the C library answers), also for an empty or too long $message_id.
     */
    public static function translate(string $domain, string $message_id): string
    {
        if (!self::canLookUp($message_id)) {
            return $message_id;
        }

        return dgettext($domain, $message_id);
    }

    /**
     * The form of the plural message $message_id in $domain the "Plural-Forms" rule of the catalog
     * selects for $n (a negative $n counts like its absolute value) - $message_id itself if there is
     * no such plural message.
     */
    public static function translatePlural(string $domain, string $message_id, int $n): string
    {
        if (!self::canLookUp($message_id)) {
            return $message_id;
        }
        $n = $n === PHP_INT_MIN ? PHP_INT_MAX : abs($n);

        return dngettext($domain, $message_id, $message_id, $n);
    }

    private static function canLookUp(string $message_id): bool
    {
        return $message_id !== '' && strlen($message_id) <= self::MAX_MESSAGE_LENGTH && self::isExtensionLoaded();
    }

    /**
     * Forgets the activation (without taking the settings back) - for tests only.
     *
     * @internal
     */
    public static function resetForTests(): void
    {
        self::$thread_safe_for_tests = null;
        self::restore();
        self::$locale_directory = null;
        self::$previous = null;
        self::$problem = null;
    }
}
