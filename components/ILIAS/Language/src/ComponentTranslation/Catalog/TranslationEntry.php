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
 * parts ILIAS needs - context, id, singular translation, translator comments (`# ...`), extracted
 * comments (`#. ...`) and flags (`#, ...`). Everything else the library keeps for a loaded message
 * (plural forms, references, previous-message lines) is carried along untouched and written back
 * as loaded.
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
