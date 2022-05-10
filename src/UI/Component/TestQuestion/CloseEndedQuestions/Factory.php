<?php declare(strict_types=1);

namespace ILIAS\UI\Component\TestQuestion\CloseEndedQuestions;

use ILIAS\UI\Component\Tree\TreeRecursion;

/**
 * Close-ended Questions factory
 */
interface Factory
{
    /**
     * ---
     * description:
     *   purpose: >
     *     A Multiple Choice Question (Single Answer) asks respondents
     *     to choose exactly one answer option.
     *   composition: >
     *     Radio button is used to select an answer.
     * rules:
     *   interaction:
     *     1: Users select an answer option by clicking on the radio button.
     *   accessibility:
     *     1: https://www.w3.org/TR/wai-aria-practices/#radiobutton
     *     2: All non-decorative media has an alt-text.
     * ---
     * @return \ILIAS\UI\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceSingleAnswer
     */
    public function single() : MultipleChoiceSingleAnswer;
    
    /**
     * ---
     * description:
     *   purpose: >
     *     A Multiple Choice Question (Multiple Answer) asks respondents
     *     to choose one or more answer options.
     *   composition: >
     *     Checkboxes are used to select the answer.
     * rules:
     *   interaction:
     *     1: Users select an answer option by clicking on the checkboxes.
     *   accessibility:
     *     1: All non-decorative media has an alt-text.
     * ---
     * @return \ILIAS\UI\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceMultipleAnswer
     */
    public function multiple() : MultipleChoiceMultipleAnswer;
    
    /**
     * ---
     * description:
     *   purpose: >
     *     A Multiple Choice Question (Kprim Answer) asks respondents
     *     to choose ...
     *   composition: >
     *
     * rules:
     *   interaction:
     *   accessibility:
     *     1: All non-decorative media has an alt-text.
     * ---
     * @return \ILIAS\UI\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceKprimAnswer
     */
    public function kprim() : MultipleChoiceKprimAnswer;
}