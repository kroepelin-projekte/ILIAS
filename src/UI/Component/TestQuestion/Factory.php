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
     *
     * @return \ILIAS\UI\Component\TestQuestion\CloseEnded\Factory
     */
    public function closeEnded() : CloseEnded\Factory;
    
    /**
     * ---
     * description:
     *   purpose: Open-ended Questions ask respondents to prepare their own response.
     *   composition: >
     *     Open-ended Questions offer actively putting in a response.
     *     Controls for putting in a response comprise text input fields
     *     and file upload controls.
     *
     * rules:
     *   interaction:
     *     1: Users actively produces a response into the respective control.
     * ---
     *
     * @return \ILIAS\UI\Component\TestQuestion\OpenEnded\Factory
     */
    //public function openEnded() : OpenEnded\Factory;
    
    /**
     * ---
     * description:
     *   purpose: >
     *     Sorting / draggable Questions ask respondents to
     *     produce order or relationships.
     *   composition: >
     *     Sorting / draggable Questions offer elements to be sorted.
     *     Elements can be dragged and dropped.
     *
     * rules:
     *   interaction:
     *     1: Users actively produce a an order or match by relationship.
     * ---
     *
     * @return \ILIAS\UI\Component\TestQuestion\Sorting\Factory
     */
    //public function sorting() : Sorting\Factory;
}