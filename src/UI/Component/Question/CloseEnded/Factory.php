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
     * @return \ILIAS\UI\Component\Question\CloseEnded\MultipleAnswer
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
     * @return \ILIAS\UI\Component\Question\CloseEnded\KprimAnswer
     */
    public function kprimAnswer() : KprimAnswer;
    
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
     * @return ClozeQuestionSelectGap
     */
    public function clozeQuestionSelectGap() : ClozeQuestionSelectGap;
    
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
     * @return ErrorText
     */
    public function errorText() : ErrorText;
    
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
     * @return ImageMap
     */
    public function imageMap() : ImageMap;
}
