<?php declare(strict_types=1);

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 ********************************************************************
 */

use ILIAS\BackgroundTasks\Implementation\Tasks\AbstractJob;
use ILIAS\BackgroundTasks\Observer;
use ILIAS\BackgroundTasks\Types\SingleType;
use ILIAS\BackgroundTasks\Implementation\Values\ScalarValues\IntegerValue;
use ILIAS\BackgroundTasks\Implementation\Values\ScalarValues\BooleanValue;
use ILIAS\BackgroundTasks\Types\Type;
use ILIAS\BackgroundTasks\Value;

class ilLangRefreshJob extends AbstractJob
{
    public function run(array $input, Observer $observer) : Value
    {
        $output = new BooleanValue();
        $output->setValue(false);
        $id = $input[0];
        $lng = new ilObjLanguage($id->getValue(), false);
        if ($lng->isInstalled() && $lng->check()) {
            $lng->flush("keep_local");
            $lng->insert();
            $lng->setTitle($lng->getKey());
            $lng->setDescription($lng->getStatus());
            $lng->update();

            if ($lng->isLocal() && $lng->check("local")) {
                $lng->insert("local");
                $lng->setTitle($lng->getKey());
                $lng->setDescription($lng->getStatus());
                $lng->update();
            }
            $output->setValue(true);
        }
        return $output;
    }

    public function isStateless() : bool
    {
        return true;
    }

    public function getExpectedTimeOfTaskInSeconds() : int
    {
        return 60;
    }

    public function getInputTypes() : array
    {
        return [new SingleType(IntegerValue::class)];
    }

    public function getOutputType() : Type
    {
        return new SingleType(BooleanValue::class);
    }
}