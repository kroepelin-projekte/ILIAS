<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\OpenEnded;
use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\OpenEnded\Factory
{
    public function clozeQuestionTextGap() : ClozeQuestionTextGap
    {
        return new ClozeQuestionTextGap();
    }
    
    public function essay() : Essay
    {
        return new Essay();
    }
    
    public function fileUpload() : FileUpload
    {
        return new FileUpload();
    }
    
    public function formula() : Formula
    {
        return new Formula();
    }
    
    public function numeric() : Numeric
    {
        return new Numeric();
    }
    
    public function textSubset() : TextSubset
    {
        return new TextSubset();
    }
}