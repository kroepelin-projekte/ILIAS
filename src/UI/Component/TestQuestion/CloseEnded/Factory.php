<?php declare(strict_types=1);

namespace ILIAS\UI\Component\TestQuestion\CloseEnded;

/**
 * Close-ended Questions factory
 */
interface Factory
{
    public function clozeQuestionSelectGap() : ClozeQuestionSelectGap;
    
    public function errorText() : ErrorText;
    
    public function imageMap() : ImageMap;
    
    public function multipleChoice() : MultipleChoice\Factory;
}