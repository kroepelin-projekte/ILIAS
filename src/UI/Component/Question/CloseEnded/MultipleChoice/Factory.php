<?php declare(strict_types=1);

namespace ILIAS\UI\Component\Question\CloseEnded\MultipleChoice;

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
     *
     * @param string $questionStem
     * @param array $questionCanvas
     * @return \ILIAS\UI\Component\Question\CloseEnded\MultipleChoice\SingleAnswer
     */
    public function singleAnswer($questionStem, $questionCanvas) : SingleAnswer;
    
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
     *
     * @return \ILIAS\UI\Component\Question\CloseEnded\MultipleChoice\MultipleAnswer
     */
    public function multipleAnswer() : MultipleAnswer;
    
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
     *
     * @return \ILIAS\UI\Component\Question\CloseEnded\MultipleChoice\KprimAnswer
     */
    public function kprimAnswer() : KprimAnswer;
}
