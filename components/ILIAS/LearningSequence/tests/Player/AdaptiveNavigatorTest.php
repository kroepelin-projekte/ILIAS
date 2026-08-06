<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 */

declare(strict_types=1);

use ILIAS\LearningSequence\Player\AdaptiveNavigator;
use PHPUnit\Framework\TestCase;

class AdaptiveNavigatorTest extends TestCase
{
    public function testGraphSuccessorsKeepBlockedBranchesForMapTraversal(): void
    {
        $current = $this->mockLearnerItem(100);
        $reachable = $this->mockLearnerItem(200);
        $blocked = $this->mockLearnerItem(300);
        $unrelated = $this->mockLearnerItem(400);

        $navigator = new class ([100 => [200, 300]], [200 => true, 300 => false]) extends AdaptiveNavigator {
            /**
             * @param array<int, int[]> $edges
             * @param array<int, bool> $enterable
             */
            public function __construct(
                private array $edges,
                private array $enterable
            ) {
            }

            protected function isEdge(int $from_ref_id, int $to_ref_id): bool
            {
                return in_array($to_ref_id, $this->edges[$from_ref_id] ?? [], true);
            }

            public function canEnter(\LSLearnerItem $target): bool
            {
                return $this->enterable[$target->getRefId()] ?? false;
            }
        };

        $reachable_successors = $navigator->getSuccessors(
            [$current, $reachable, $blocked, $unrelated],
            $current
        );
        $graph_successors = $navigator->getGraphSuccessors(
            [$current, $reachable, $blocked, $unrelated],
            $current
        );

        $this->assertSame([200], array_map(static fn(\LSLearnerItem $item): int => $item->getRefId(), $reachable_successors));
        $this->assertSame([200, 300], array_map(static fn(\LSLearnerItem $item): int => $item->getRefId(), $graph_successors));
    }

    private function mockLearnerItem(int $ref_id): \LSLearnerItem
    {
        $item = $this->getMockBuilder(\LSLearnerItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRefId'])
            ->getMock();
        $item->method('getRefId')->willReturn($ref_id);

        return $item;
    }
}
