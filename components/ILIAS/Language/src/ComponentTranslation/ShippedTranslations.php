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

use ILIAS\Language\ComponentTranslation\Catalog\CompiledTranslations;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use RuntimeException;

/**
 * The shipped state of a migrated module - what its shipped `.po` holds, as txt() serves it before
 * the local changes of the overlay (see MigratedLanguageFileSync) are applied on top.
 *
 * Setup's build compiles every shipped `.po` once into `artifacts/language/<lang>/<module>.mo`
 * (see ShippedLanguageFilesCompiledObjective and MigratedLanguageFilePaths::shippedArtifactFile()),
 * read() serves that artifact. Should the artifact be missing or older than its `.po` (the `.po`
 * changed since the last build), read() compiles the `.po` itself - with exactly the result the
 * build would have written, since both go through compile().
 *
 * compile() is TranslationCatalog::toMoString() of the module's messages (context = module, or no
 * context at all - see MigratedLanguageFileSync::moduleEntries()), with
 * every value - every form of a plural message - cleaned by TranslationMarkupPolicy::sanitize():
 * fuzzy messages are compiled too, messages without a translation are left out.
 */
final class ShippedTranslations
{
    public function __construct(
        private readonly TranslationMarkupPolicy $markup_policy = new TranslationMarkupPolicy()
    ) {
    }

    /**
     * The compiled `.mo` of the messages of $module in $shipped_po.
     *
     * @param (callable(string $identifier, list<string> $violations): void)|null $on_markup_violation
     *        called for every value that had to be cleaned, with what was removed from it (for a form
     *        of a plural message with its form key, see PluralFormKey)
     * @param (callable(string $message): void)|null $on_invalid_plural_forms called if the module has
     *        plural messages, but the "Plural-Forms" header is missing or invalid (the Germanic rule
     *        is used for them then, see PluralForms)
     * @throws RuntimeException if $shipped_po cannot be read, parsed or compiled
     */
    public function compile(
        string $shipped_po,
        string $module,
        ?callable $on_markup_violation = null,
        ?callable $on_invalid_plural_forms = null
    ): string {
        $shipped = TranslationCatalog::fromPoFile($shipped_po);
        $has_plurals = false;

        $catalog = new TranslationCatalog();
        foreach ($shipped->getHeaders() as $name => $value) {
            $catalog->setHeader($name, $value);
        }
        // the module's entries - with the module as context or without one (see
        // MigratedLanguageFileSync::moduleEntries())
        foreach (MigratedLanguageFileSync::moduleEntries($shipped, $module) as $entry) {
            if ($entry->isPlural()) {
                $has_plurals = true;
                $forms = $entry->getPluralTranslations();
                foreach ($forms as $form => $value) {
                    $forms[$form] = $this->sanitized($value, PluralFormKey::of($entry->getId(), $form), $on_markup_violation);
                }
                $entry->setPlural((string) $entry->getPluralId(), $forms);
            } else {
                $entry->translate($this->sanitized($entry->getTranslation(), $entry->getId(), $on_markup_violation));
            }
            $catalog->add($entry);
        }
        if ($has_plurals) {
            PluralForms::fromHeaderOrGermanic($shipped->getHeader('Plural-Forms'), $on_invalid_plural_forms);
        }

        return $catalog->toMoString();
    }

    /**
     * $value cleaned by the markup policy, $on_markup_violation called for $key if anything was removed.
     *
     * @param (callable(string $identifier, list<string> $violations): void)|null $on_markup_violation
     */
    private function sanitized(string $value, string $key, ?callable $on_markup_violation): string
    {
        $violations = $this->markup_policy->findViolations($value);
        if ($violations === []) {
            return $value;
        }
        if ($on_markup_violation !== null) {
            $on_markup_violation($key, $violations);
        }

        return $this->markup_policy->sanitize($value);
    }

    /**
     * The shipped state of $directory's module for $lang_key as identifier => value (for a plural
     * message the value of its default form, see PluralForms), or `null` if the module is not
     * migrated for $lang_key (no shipped `.po`).
     *
     * @param (callable(string $message, bool $is_missing): void)|null $on_artifact_unusable called
     *        when the artifact is missing ($is_missing) or cannot be read and the `.po` is compiled
     *        instead (not when the `.po` is merely newer - that is expected between changing a
     *        `.po` and the next build)
     * @return array<string, string>|null
     * @throws \InvalidArgumentException for a $lang_key or module name that cannot be part of a path
     * @throws RuntimeException if the shipped `.po` cannot be read, parsed or compiled
     */
    public function read(
        string $ilias_absolute_path,
        LanguageFileDirectory $directory,
        string $lang_key,
        ?callable $on_artifact_unusable = null
    ): ?array {
        return $this->readCompiled($ilias_absolute_path, $directory, $lang_key, $on_artifact_unusable)?->getTranslations();
    }

    /**
     * read(), plus every form of the plural messages and the "Plural-Forms" header of the module.
     *
     * @param (callable(string $message, bool $is_missing): void)|null $on_artifact_unusable see read()
     * @throws \InvalidArgumentException see read()
     * @throws RuntimeException see read()
     */
    public function readCompiled(
        string $ilias_absolute_path,
        LanguageFileDirectory $directory,
        string $lang_key,
        ?callable $on_artifact_unusable = null
    ): ?CompiledTranslations {
        $shipped_po = MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po';
        $po_modified = @filemtime($shipped_po);
        if ($po_modified === false || !is_file($shipped_po)) {
            return null;
        }

        $artifact = MigratedLanguageFilePaths::shippedArtifactFile($ilias_absolute_path, $directory, $lang_key);
        $artifact_modified = @filemtime($artifact);
        if ($artifact_modified === false) {
            $this->report($on_artifact_unusable, true, sprintf(
                'The compiled language file "%s" is missing (run "php cli/setup.php build") - compiling "%s" instead.',
                $artifact,
                $shipped_po
            ));
        } elseif ($artifact_modified >= $po_modified) {
            try {
                return TranslationCatalog::readMoMessages($artifact);
            } catch (RuntimeException $e) {
                $this->report($on_artifact_unusable, false, sprintf(
                    'The compiled language file "%s" cannot be read (%s) - compiling "%s" instead.',
                    $artifact,
                    $e->getMessage(),
                    $shipped_po
                ));
            }
        }

        return TranslationCatalog::readMoMessagesFromString(
            $this->compile($shipped_po, $directory->getPrefix()),
            $shipped_po
        );
    }

    private function report(?callable $on_artifact_unusable, bool $is_missing, string $message): void
    {
        if ($on_artifact_unusable !== null) {
            $on_artifact_unusable($message, $is_missing);
        }
    }
}
