<?php declare(strict_types=1);

namespace ILIAS\UI\Component\Question\CloseEnded;

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
     *     Radio inputs are used to select an answer.
     * rules:
     *   interaction:
     *     1: Users SHOULD select one answer option by clicking on the radio input.
     *   accessibility:
     *     1: is inherited from radio input
     * ---
     *
     * @param string $questionStem
     * @param array $questionCanvas
     * @return \ILIAS\UI\Component\Question\CloseEnded\SingleAnswer
     */
    public function singleAnswer($questionStem, $questionCanvas) : SingleAnswer;
}
