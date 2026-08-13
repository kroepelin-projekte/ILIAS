<?php

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
 *********************************************************************/

declare(strict_types=1);

use ILIAS\LearningSequence\Content\Condition\InputCondition\SubsetInputCondition\SubsetInputCondition;
use PHPUnit\Framework\TestCase;

class SubsetInputConditionStaticConflictTest extends TestCase
{
    public function testMissingReferencedObjectIsReportedAsStaticConflict(): void
    {
        $condition = (new ReflectionClass(SubsetInputCondition::class))
            ->newInstanceWithoutConstructor();
        $condition->setObjectRefIds([151, 999]);
        $condition->setSubset(1);

        $this->assertTrue($condition->hasStaticInputConfigurationConflict([
            'valid_ref_ids' => [151, 152],
        ]));
    }
}
