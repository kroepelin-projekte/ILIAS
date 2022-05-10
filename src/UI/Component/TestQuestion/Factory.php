<?php declare(strict_types=1);

namespace ILIAS\UI\Component\TestQuestion;

/**
 * TestQuestion factory
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
     *     1: Users select an answer option by clicking on the respective control.
     *   style:
     *     1: Selected answer options are slightly highlighted (i.e. outline is boldened)
     *   accessibility:
     *     1: >
     *        All answer options can be reached and left using the keyboard only.
     *        Keyboard interfaces are specified in specific question types.
     *     2: Users are able to tab into a question and away from the question.
     *     3: All non-decorative media has an alt-text.
     * ---
     * @return \ILIAS\UI\Component\TestQuestion\CloseEndedQuestions\Factory
     */
    public function closeEndedQuestions() : CloseEndedQuestions\Factory;
}