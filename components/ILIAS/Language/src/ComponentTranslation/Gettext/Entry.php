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

namespace ILIAS\Language\ComponentTranslation\Gettext;

/**
 * One message of a gettext catalog (a single `msgctxt`/`msgid`/`msgstr` block of a `.po` file).
 *
 * Only the parts of the PO format the language component actually needs are modelled: context,
 * id, (plural) translations, translator comments (`# ...`), extracted comments (`#. ...`),
 * references (`#: ...`) and flags (`#, ...`). Previous-message lines (`#| ...`) and obsolete
 * messages (`#~ ...`) are not kept - nothing in ILIAS writes or reads them.
 */
final class Entry
{
    /** @var list<string> */
    private array $translator_comments = [];
    /** @var list<string> */
    private array $extracted_comments = [];
    /** @var list<string> */
    private array $references = [];
    /** @var list<string> */
    private array $flags = [];
    /** @var array<int, string> */
    private array $plural_translations = [];
    private ?string $plural = null;
    private string $translation = '';

    public function __construct(
        private readonly ?string $context,
        private readonly string $id
    ) {
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTranslation(): string
    {
        return $this->translation;
    }

    public function translate(string $translation): void
    {
        $this->translation = $translation;
    }

    public function getPlural(): ?string
    {
        return $this->plural;
    }

    public function setPlural(?string $plural): void
    {
        $this->plural = $plural;
    }

    /**
     * @return array<int, string> msgstr[n] for n >= 1, keyed n - 1 and sorted by index; an index that
     *         was never set stays absent (it is not renumbered)
     */
    public function getPluralTranslations(): array
    {
        return $this->plural_translations;
    }

    public function setPluralTranslation(int $index, string $translation): void
    {
        $this->plural_translations[$index - 1] = $translation;
        ksort($this->plural_translations);
    }

    /**
     * @return list<string>
     */
    public function getTranslatorComments(): array
    {
        return $this->translator_comments;
    }

    public function addTranslatorComment(string $comment): void
    {
        $this->translator_comments[] = $comment;
    }

    public function removeTranslatorCommentsStartingWith(string $prefix): void
    {
        $this->translator_comments = array_values(array_filter(
            $this->translator_comments,
            static fn(string $comment): bool => !str_starts_with($comment, $prefix)
        ));
    }

    /**
     * @return list<string>
     */
    public function getExtractedComments(): array
    {
        return $this->extracted_comments;
    }

    /**
     * @param list<string> $comments
     */
    public function setExtractedComments(array $comments): void
    {
        $this->extracted_comments = array_values($comments);
    }

    public function addExtractedComment(string $comment): void
    {
        $this->extracted_comments[] = $comment;
    }

    /**
     * @return list<string>
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    public function addReference(string $reference): void
    {
        $this->references[] = $reference;
    }

    /**
     * @return list<string>
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    public function addFlag(string $flag): void
    {
        if ($flag !== '' && !$this->hasFlag($flag)) {
            $this->flags[] = $flag;
        }
    }

    public function removeFlag(string $flag): void
    {
        $this->flags = array_values(array_filter(
            $this->flags,
            static fn(string $existing): bool => $existing !== $flag
        ));
    }
}
