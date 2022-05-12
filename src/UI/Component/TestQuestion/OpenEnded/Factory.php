<?php declare(strict_types=1);

namespace ILIAS\UI\Component\TestQuestion\OpenEnded;

interface Factory
{
    public function clozeQuestionTextGap() : ClozeQuestionTextGap;
    
    public function essay() : Essay;
    
    public function fileUpload() : FileUpload; //todo: Es gibt bereits Interfaces mit dem gleichen Namen! Daher eher umbenennen
    
    public function formula() : Formula; //todo: Es gibt bereits Interfaces mit dem gleichen Namen! Daher eher umbenennen
    
    public function numeric() : Numeric; //todo: Es gibt bereits Interfaces mit dem gleichen Namen! Daher eher umbenennen
    
    public function textSubset() : TextSubset;
}