<?php declare(strict_types=1);

namespace ILIAS\UI\Component\TestQuestion\CloseEnded;

/**
 * Close-ended Questions factory
 */
interface Factory
{
    //public function clozeQuestionSelectGap() : ClozeQuestionSelectGap;
    
    //public function errorText() : ErrorText;
    
    //public function imageMap() : ImageMap;
    
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
     * @return \ILIAS\UI\Component\TestQuestion\CloseEnded\MultipleChoice\Factory
     */
    public function multipleChoice() : MultipleChoice\Factory;
}