<?php declare(strict_types=1);
/* Copyright (c) 1998-2010 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
* Exercise Verification
*
* @author Jörg Lützenkirchen <luetzenkirchen@leifos.com>
*
*
* @ingroup ModulesExercise
*/
class ilObjExerciseVerification extends ilVerificationObject
{
    protected function initType() : void
    {
        $this->type = "excv";
    }

    protected function getPropertyMap() : array
    {
        return array("issued_on" => self::TYPE_DATE,
            "file" => self::TYPE_STRING
            /*
            "success" => self::TYPE_BOOL,
            "mark" => self::TYPE_STRING,
            "comment" => self::TYPE_STRING
            */
            );
    }
}
