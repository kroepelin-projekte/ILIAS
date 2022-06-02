<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

use ILIAS\UI\Component\Question as I;
use ILIAS\UI\Implementation\Component\Question\Question;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class SingleAnswer extends Question implements I\CloseEnded\SingleAnswer
{
    use ComponentHelper;
    
    protected ?int $checkedID = null;

    public function __construct(string $questionStem, array $answers, int $checkedID = null)
    {
        if (isset($checkedID)) {
            $this->checkedID = $checkedID;
        }
        parent::__construct($questionStem, $answers);
    }
    
    public function getCheckedId() : ?int
    {
        return $this->checkedID;
    }
}
