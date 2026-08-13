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
