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
     *       Standard item lists present lists of items with similar presentation.
     *       All items are passed by using Item Groups.
     *   composition: >
     *      This Listing is composed of title and a set of Item Groups. Additionally an
     *      optional dropdown to select the number/types of items
     *      to be shown at the top of the Listing.
     * ---
     *
     * @return \ILIAS\UI\Component\Question\CloseEnded\MultipleChoice\Factory
     */
    public function multipleChoice() : MultipleChoice\Factory;
    
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
