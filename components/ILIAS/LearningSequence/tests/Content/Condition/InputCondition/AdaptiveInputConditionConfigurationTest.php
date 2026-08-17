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

use ILIAS\LearningSequence\Content\Condition\InputCondition\AccruedValueInputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionNavigationAwareInterface;
use ILIAS\LearningSequence\Content\Condition\InputCondition\LearningProgressInputConditions\LearningProgressInputAwareCondition;
use ILIAS\LearningSequence\Content\Condition\InputCondition\PointsInputCondition\PointsInputCondition;
use ILIAS\LearningSequence\Content\Condition\InputCondition\SubsetInputCondition\SubsetInputCondition;
use PHPUnit\Framework\TestCase;

class AdaptiveInputConditionConfigurationTest extends TestCase
{
    public function testPointsInputNormalizesFormDataAndExposesAccruedValueNavigation(): void
    {
        $condition = $this->newCondition(PointsInputCondition::class);
        $condition->setObjRefId(151);

        $condition->applyAdditionalFormData([['149', '150', '150', '0', '151'], '12']);

        $this->assertInstanceOf(AccruedValueInputConditionInterface::class, $condition);
        $this->assertSame('points', $condition->getAccumulationIdentifier());
        $this->assertSame(12, $condition->getRequiredAccumulatedValue());
        $this->assertSame(12, $condition->getPoints());
        $this->assertSame([149, 150], $condition->getSourceRefIds());
        $this->assertSame([149, 150], $condition->getNavigationSourceRefIds());
        $this->assertSame(
            InputConditionNavigationAwareInterface::NAVIGATION_MODE_DEPENDENCY,
            $condition->getNavigationMode()
        );
    }

    public function testPointsInputWithoutConditionIdHasEmptyDefaults(): void
    {
        $condition = $this->newCondition(PointsInputCondition::class);

        $this->assertSame([], $condition->getSourceRefIds());
        $this->assertSame(0, $condition->getPoints());
    }

    public function testPointsInputSetterNormalizesSourceRefIds(): void
    {
        $condition = $this->newCondition(PointsInputCondition::class);
        $condition->setObjRefId(151);

        $condition->setSourceRefIds([149, '150', 150, 0, -1, 151]);

        $this->assertSame([149, 150], $condition->getSourceRefIds());
    }

    public function testPointsInputSetterAcceptsZeroRequiredPoints(): void
    {
        $condition = $this->newCondition(PointsInputCondition::class);

        $condition->setPoints(0);

        $this->assertSame(0, $condition->getPoints());
        $this->assertSame(0, $condition->getRequiredAccumulatedValue());
    }

    public function testPointsInputReportsMissingSourceRefsAsStaticConflict(): void
    {
        $condition = $this->newCondition(PointsInputCondition::class);
        $condition->setSourceRefIds([149, 999]);
        $condition->setPoints(1);

        $this->assertTrue($condition->hasStaticInputConfigurationConflict(['valid_ref_ids' => [149, 150]]));
    }

    public function testPointsInputReportsSourceRefsWithoutPointsOutputFromContext(): void
    {
        $condition = $this->newCondition(PointsInputCondition::class);
        $condition->setSourceRefIds([149, 150, 151]);

        $this->assertSame(
            [150],
            $condition->getSourceRefIdsWithoutPointsOutput(['points_output_ref_ids' => [149, '151']])
        );
    }

    public function testSubsetInputNormalizesFormDataAndExposesDependencyNavigation(): void
    {
        $condition = $this->newCondition(SubsetInputCondition::class);
        $condition->setObjRefId(151);

        $condition->applyAdditionalFormData([['149', '150', '150', '151', '-1'], '2']);

        $this->assertSame(2, $condition->getSubset());
        $this->assertSame([149, 150], $condition->getSourceRefIds());
        $this->assertSame([149, 150], $condition->getNavigationSourceRefIds());
        $this->assertSame(
            InputConditionNavigationAwareInterface::NAVIGATION_MODE_DEPENDENCY,
            $condition->getNavigationMode()
        );
    }

    public function testSubsetInputWithoutConditionIdHasEmptyDefaults(): void
    {
        $condition = $this->newCondition(SubsetInputCondition::class);

        $this->assertSame([], $condition->getSourceRefIds());
        $this->assertSame(0, $condition->getSubset());
    }

    public function testSubsetInputSetterNormalizesSourceRefIds(): void
    {
        $condition = $this->newCondition(SubsetInputCondition::class);
        $condition->setObjRefId(151);

        $condition->setSourceRefIds([149, '150', 150, 0, -1, 151]);

        $this->assertSame([149, 150], $condition->getSourceRefIds());
    }

    public function testSubsetInputReportsMissingSourceRefsAsStaticConflict(): void
    {
        $condition = $this->newCondition(SubsetInputCondition::class);
        $condition->setSourceRefIds([149, 999]);
        $condition->setSubset(1);

        $this->assertTrue($condition->hasStaticInputConfigurationConflict(['valid_ref_ids' => [149, 150]]));
    }

    public function testSubsetInputWithoutMissingSourceRefsHasNoStaticConflict(): void
    {
        $condition = $this->newCondition(SubsetInputCondition::class);
        $condition->setSourceRefIds([149, 150]);
        $condition->setSubset(2);

        $this->assertFalse($condition->hasStaticInputConfigurationConflict(['valid_ref_ids' => [149, 150]]));
    }

    public function testLearningProgressInputExposesConfiguredNavigationSource(): void
    {
        $condition = $this->newCondition(LearningProgressInputAwareCondition::class);
        $condition->setConditionTargetRefId(149);
        $condition->setSubtype('completed');

        $this->assertSame([149], $condition->getNavigationSourceRefIds());
        $this->assertSame(
            InputConditionNavigationAwareInterface::NAVIGATION_MODE_EDGE,
            $condition->getNavigationMode()
        );
        $this->assertSame('completed', $condition->getSubtype());
    }

    public function testLearningProgressInputNormalizesFormTarget(): void
    {
        $condition = $this->newCondition(LearningProgressInputAwareCondition::class);

        $condition->applyAdditionalFormData([['149']]);

        $this->assertSame(149, $condition->getConditionTargetRefId());
        $this->assertSame([149], $condition->getNavigationSourceRefIds());
    }

    public function testLearningProgressInputSupportsAllAdaptiveSubtypes(): void
    {
        $condition = $this->newCondition(LearningProgressInputAwareCondition::class);

        $this->assertSame(
            ['not_attempted', 'in_progress', 'completed', 'failed'],
            $condition->getSupportedSubtypes()
        );
    }

    public function testLearningProgressInputReportsMissingTargetAsStaticConflict(): void
    {
        $condition = $this->newCondition(LearningProgressInputAwareCondition::class);
        $condition->setConditionTargetRefId(999);

        $this->assertTrue($condition->hasStaticInputConfigurationConflict(['valid_ref_ids' => [149, 150]]));
    }

    public function testLearningProgressInputWithoutMissingTargetHasNoStaticConflict(): void
    {
        $condition = $this->newCondition(LearningProgressInputAwareCondition::class);
        $condition->setConditionTargetRefId(149);

        $this->assertFalse($condition->hasStaticInputConfigurationConflict(['valid_ref_ids' => [149, 150]]));
    }

    public function testAdaptiveInputConditionMigrationTablesAreDeclared(): void
    {
        $this->assertSame('lso_c_points_input', PointsInputCondition::migrate()[0]->tableName);
        $this->assertSame('lso_c_points_input_items', PointsInputCondition::migrate()[1]->tableName);
        $this->assertSame('lso_c_subset', SubsetInputCondition::migrate()[0]->tableName);
        $this->assertSame('lso_c_subset_items', SubsetInputCondition::migrate()[1]->tableName);
        $this->assertSame('lso_c_learning_progress_input', LearningProgressInputAwareCondition::migrate()[0]->tableName);
    }

    /**
     * @template T of object
     * @param class-string<T> $class_name
     * @return T
     */
    private function newCondition(string $class_name): object
    {
        return (new ReflectionClass($class_name))->newInstanceWithoutConstructor();
    }
}
