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

namespace ILIAS\Language\ComponentTranslation\Catalog;

use Gettext\Translation;

/**
 * One message of a TranslationCatalog (a single `msgctxt`/`msgid`/`msgstr` block of a `.po` file).
 *
 * Together with TranslationCatalog the only place of the language component that knows the
 * gettext/gettext library: this is a narrow adapter around Gettext\Translation exposing just the
 * parts ILIAS needs - context, id, singular translation, plural id and forms (`msgid_plural`,
 * `msgstr[n]`), translator comments (`# ...`), extracted comments (`#. ...`) and flags (`#, ...`).
 * Everything else the library keeps for a loaded message (references, previous-message lines) is
 * carried along untouched and written back as loaded.
 *
 * For a plural message getTranslation() is msgstr[0], exactly as in the file; which form serves a
 * quantity or stands in for the whole message is decided by ILIAS\Language\ComponentTranslation\PluralForms.
 *
 * Library behaviour callers should be aware of: comments are stored as a set, so adding a comment
 * that equals (in PHP's loose comparison) an existing one of the same kind is a no-op, and flags are
 * kept sorted.
 */
final class TranslationEntry
{
    private Translation $translation;

    public function __construct(?string $context, string $id)
    {
        $this->translation = Translation::create($context, $id);
    }

    /**
     * @internal for TranslationCatalog only: wraps a message the library created while loading
     */
    public static function fromGettext(Translation $translation): self
    {
        $entry = new self($translation->getContext(), $translation->getOriginal());
        $entry->translation = $translation;

        return $entry;
    }

    /**
     * @internal for TranslationCatalog only: the wrapped message itself, not a copy - so an entry
     *           taken from one catalog and added to another stays the same message in both
     */
    public function toGettext(): Translation
    {
        return $this->translation;
    }

    /**
     * A copy of this entry with $context (translation, comments, flags unchanged) - not added to any
     * catalog.
     */
    public function withContext(?string $context): self
    {
        return self::fromGettext($this->translation->withContext($context));
    }

    public function getContext(): ?string
    {
        return $this->translation->getContext();
    }

    public function getId(): string
    {
        return $this->translation->getOriginal();
    }

    public function getTranslation(): string
    {
        return $this->translation->getTranslation() ?? '';
    }

    public function translate(string $translation): void
    {
        $this->translation->translate($translation);
    }

    /**
     * The `msgid_plural` of a plural message, `null` for a singular one.
     */
    public function getPluralId(): ?string
    {
        return $this->translation->getPlural();
    }

    public function isPlural(): bool
    {
        return $this->translation->getPlural() !== null;
    }

    /**
     * Every form of a plural message as stored: msgstr[0] (= getTranslation()), msgstr[1], ... -
     * `[getTranslation()]` for a singular message. A `.po` loaded with fewer forms than its
     * "Plural-Forms" header declares has fewer here; callers pad with '' where they need all.
     *
     * @return list<string>
     */
    public function getPluralTranslations(): array
    {
        return [$this->getTranslation(), ...array_values($this->translation->getPluralTranslations())];
    }

    /**
     * Makes this a plural message with the `msgid_plural` $plural_id and the forms $translations
     * (msgstr[0], msgstr[1], ...).
     *
     * @param array<int, string> $translations at least one form, in the order msgstr[0], msgstr[1], ...
     * @throws \InvalidArgumentException for an empty $plural_id or no form at all
     */
    public function setPlural(string $plural_id, array $translations): void
    {
        if ($plural_id === '' || $translations === []) {
            throw new \InvalidArgumentException(sprintf('The plural message "%s" needs a plural id and at least one form.', $this->getId()));
        }
        $translations = array_values($translations);
        $this->translation->setPlural($plural_id);
        $this->translation->translate(array_shift($translations));
        $this->translation->translatePlural(...$translations);
    }

    /**
     * @return list<string>
     */
    public function getTranslatorComments(): array
    {
        return $this->translation->getComments()->toArray();
    }

    public function addTranslatorComment(string $comment): void
    {
        $this->translation->getComments()->add(self::singleLine($comment));
    }

    public function removeTranslatorCommentsStartingWith(string $prefix): void
    {
        $comments = $this->translation->getComments();
        $kept = array_filter(
            $comments->toArray(),
            static fn(string $comment): bool => !str_starts_with($comment, $prefix)
        );
        // Comments::delete() matches loosely - removing everything and re-adding what is kept
        // cannot hit the wrong comment
        $comments->delete(...$comments->toArray());
        $comments->add(...$kept);
    }

    /**
     * @return list<string>
     */
    public function getExtractedComments(): array
    {
        return $this->translation->getExtractedComments()->toArray();
    }

    /**
     * @param list<string> $comments
     */
    public function setExtractedComments(array $comments): void
    {
        $extracted_comments = $this->translation->getExtractedComments();
        $extracted_comments->delete(...$extracted_comments->toArray());
        foreach ($comments as $comment) {
            $extracted_comments->add(self::singleLine($comment));
        }
    }

    public function addExtractedComment(string $comment): void
    {
        $this->translation->getExtractedComments()->add(self::singleLine($comment));
    }

    /**
     * @return list<string>
     */
    public function getFlags(): array
    {
        return $this->translation->getFlags()->toArray();
    }

    public function hasFlag(string $flag): bool
    {
        return $this->translation->getFlags()->has($flag);
    }

    public function addFlag(string $flag): void
    {
        if ($flag !== '') {
            $this->translation->getFlags()->add($flag);
        }
    }

    public function removeFlag(string $flag): void
    {
        $this->translation->getFlags()->delete($flag);
    }

    /**
     * A comment is a single PO line and PoGenerator writes it verbatim - a line break inside it
     * would end the comment and corrupt the file, so it is replaced by a space.
     */
    private static function singleLine(string $comment): string
    {
        return str_replace(["\r\n", "\n", "\r"], ' ', $comment);
    }
}
