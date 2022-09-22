<?php

use ILIAS\BackgroundTasks\Implementation\Tasks\AbstractUserInteraction;
use ILIAS\BackgroundTasks\Task\UserInteraction\Option;
use ILIAS\BackgroundTasks\Types\SingleType;
use ILIAS\BackgroundTasks\Implementation\Values\ScalarValues\StringValue;
use ILIAS\BackgroundTasks\Implementation\Tasks\UserInteraction\UserInteractionOption;
use ILIAS\BackgroundTasks\Types\Type;
use ILIAS\BackgroundTasks\Value;

class ilLangRefreshJobUserInteraction extends AbstractUserInteraction
{
    public const OPTION_CANCEL = 'cancel';
    
    public function getRemoveOption() : Option
    {
        return new UserInteractionOption('remove', self::OPTION_CANCEL);
    }
    
    /**
     * @inheritDoc
     */
    public function getInputTypes() : array
    {
        return [];
    }
    
    public function getOutputType() : Type
    {
        return new SingleType(StringValue::class);
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(array $input) : array
    {
        return [];
    }
    
    /**
     * @inheritDoc
     */
    public function interaction(
        array $input,
        \ILIAS\BackgroundTasks\Task\UserInteraction\Option $user_selected_option,
        \ILIAS\BackgroundTasks\Bucket $bucket
    ) : Value {
        return $input[0];
    }
    
    public function getMessage(array $input) : string
    {
        return '';
    }
    
    public function canBeSkipped(array $input) : bool
    {
        return true;
    }
}