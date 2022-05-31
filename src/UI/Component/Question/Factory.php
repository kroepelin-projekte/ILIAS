<?php declare(strict_types=1);

namespace ILIAS\UI\Component\Question;

/**
 * Question factory
 */
interface Factory
{
    /**
     * ---
     * description:
     *   purpose: >
     *     Close-ended Questions ask respondents to choose from
     *     a distinct set of pre-defined responses.
     *   composition: >
     *     Close-ended Questions offer controls to options
     *     that can comprise text, images or both.
     *     Controls for picking an answer option comprise checkboxes,
     *     radio buttons, text parts, image parts or drop downs.
     *     After it was checked the Cloze Question Select Gap optionally
     *     presents an instant feedback message including points attained
     *     and a feedback on the fully correct answer.
     *     In case the question was graded manually, the feedback is shown, too.
     *   effect: >
     *     On-click the answer option is selected by the user.
     *
     * rules:
     *   interaction:
     *     1: For Non-Compulsory Close-ended Questions Users SHOULD select an answer option by clicking on the respective control.
     *     2: For Compulsory Close-ended Questions Users MUST select an answer option by clicking on the respective control.
     *   style:
     *     1: Selected answer options MUST be visually highlighted
     *   accessibility:
     *     1: >
     *        All answer options MUST be reached and left using the keyboard only.
     *        Keyboard interfaces are specified in specific question types.
     *     2: Users MUST be able to tab into a question and away from the question.
     *     3: Upon opening Close-ended Question the focus MUST be placed at the beginning of the Question text.
     *     4: All non-decorative media has an alt-text. ISSUE KS-article can only add information available from the question editors.
     * ---
     *
     * @return \ILIAS\UI\Component\Question\CloseEnded\Factory
     */
    public function closeEnded() : CloseEnded\Factory;
}
