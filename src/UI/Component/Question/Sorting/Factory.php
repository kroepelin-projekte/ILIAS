<?php declare(strict_types=1);

namespace ILIAS\UI\Component\Question\Sorting;

interface Factory
{
    
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
     * @return HorizontalOrdering
     */
    public function horizontalOrdering() : HorizontalOrdering;
    
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
     * @return Matching
     */
    public function matching() : Matching;
    
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
     * @return VerticalOrdering
     */
    public function verticalOrdering() : VerticalOrdering;
}
