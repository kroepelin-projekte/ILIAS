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

use ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveContent;
use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\InputCondition\PointsInputCondition\PointsInputCondition;
use ILIAS\LearningSequence\Player\AdaptiveNavigator;
use PHPUnit\Framework\TestCase;

class LSOAdaptiveContentStaticConflictTest extends TestCase
{
    public function testDetectsContradictoryStaticInputConstraints(): void
    {
        $content = new class () extends LSOAdaptiveContent {
            public function __construct()
            {
            }

            /**
             * @param AbstractCondition[] $conditions
             * @param array<string, mixed> $context
             */
            public function detect(array $conditions, array $context): bool
            {
                return $this->hasConflictingInputConfiguration($conditions, $context);
            }
        };

        $this->assertTrue($content->detect([
            $this->mockCondition([
                ['kind' => 'all_completed', 'ref_ids' => [151, 152]],
            ]),
            $this->mockCondition([
                ['kind' => 'none_completed', 'ref_ids' => [151]],
            ]),
        ], []));
    }

    public function testAcceptsCompatibleStaticInputConstraints(): void
    {
        $content = new class () extends LSOAdaptiveContent {
            public function __construct()
            {
            }

            /**
             * @param AbstractCondition[] $conditions
             * @param array<string, mixed> $context
             */
            public function detect(array $conditions, array $context): bool
            {
                return $this->hasConflictingInputConfiguration($conditions, $context);
            }
        };

        $this->assertFalse($content->detect([
            $this->mockCondition([
                ['kind' => 'all_completed', 'ref_ids' => [151]],
                ['kind' => 'any_completed', 'ref_ids' => [151, 152]],
            ]),
            $this->mockCondition([
                ['kind' => 'none_completed', 'ref_ids' => [152]],
            ]),
        ], []));
    }

    public function testCollectsReferencedObjectsWithoutPointsOutput(): void
    {
        $content = new class () extends LSOAdaptiveContent {
            public function __construct()
            {
            }

            /**
             * @param \LSItem[] $items
             * @return int[]
             */
            public function missingPointsOutputs(array $items, AdaptiveNavigator $navigator): array
            {
                return $this->getPointsInputSourceRefIdsWithoutPointsOutput($items, $navigator);
            }
        };

        $item_a = $this->createStub(\LSItem::class);
        $item_a->method('getRefId')->willReturn(201);
        $item_b = $this->createStub(\LSItem::class);
        $item_b->method('getRefId')->willReturn(202);
        $points_condition = (new ReflectionClass(PointsInputCondition::class))
            ->newInstanceWithoutConstructor();
        $points_condition->setSourceRefIds([150, 151, 150]);
        $other_condition = $this->mockCondition([]);

        $navigator = $this->createMock(AdaptiveNavigator::class);
        $navigator->expects($this->once())
            ->method('getPointsOutputRefIds')
            ->with([$item_a, $item_b])
            ->willReturn([151]);
        $navigator->expects($this->exactly(2))
            ->method('getInputConditions')
            ->willReturnCallback(
                static fn(\LSItem $item): array => match ($item->getRefId()) {
                    201 => [$points_condition],
                    202 => [$other_condition],
                    default => [],
                }
            );

        $this->assertSame([150], $content->missingPointsOutputs([$item_a, $item_b], $navigator));
    }

    /**
     * @param array<int, array{kind: string, ref_ids: int[]}> $constraints
     */
    private function mockCondition(array $constraints): AbstractCondition
    {
        return new class ($constraints) extends AbstractCondition {
            public function __construct(private array $constraints)
            {
            }

            public function check(): bool
            {
                return true;
            }

            public function getStaticInputConditionConstraints(): array
            {
                return $this->constraints;
            }
        };
    }
}
