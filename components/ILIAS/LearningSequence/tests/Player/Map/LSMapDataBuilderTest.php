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
 */

declare(strict_types=1);

use ILIAS\LearningSequence\Player\AdaptiveNavigator;
use ILIAS\LearningSequence\Player\Map\LSAdaptivePosition;
use ILIAS\LearningSequence\Player\Map\LSMap;
use ILIAS\LearningSequence\Player\Map\LSMapDataBuilder;
use ILIAS\LearningSequence\Player\Map\LSMapNode;
use ILIAS\LearningSequence\Player\Map\LSMapViewMode;
use PHPUnit\Framework\TestCase;

class LSMapDataBuilderTest extends TestCase
{
    public function testBuildKeepsDisconnectedItemsInFullRouteMap(): void
    {
        $navigator = new class ([10 => [20], 30 => [40]]) extends AdaptiveNavigator {
            /**
             * @param array<int, int[]> $edges
             */
            public function __construct(
                private array $edges
            ) {
            }

            public function getGraphSuccessors(array $items, \LSLearnerItem $current): array
            {
                $successor_ref_ids = $this->edges[$current->getRefId()] ?? [];

                return array_values(array_filter(
                    $items,
                    static fn(\LSLearnerItem $item): bool => in_array($item->getRefId(), $successor_ref_ids, true)
                ));
            }

            public function getPredecessors(array $items, \LSLearnerItem $current): array
            {
                $predecessor_ref_ids = [];
                foreach ($this->edges as $from_ref_id => $targets) {
                    if (in_array($current->getRefId(), $targets, true)) {
                        $predecessor_ref_ids[] = $from_ref_id;
                    }
                }

                return array_values(array_filter(
                    $items,
                    static fn(\LSLearnerItem $item): bool => in_array($item->getRefId(), $predecessor_ref_ids, true)
                ));
            }
        };

        $position = $this->getMockBuilder(LSAdaptivePosition::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStartObjId', 'getEndObjId', 'getCurrentObjId', 'getWalkedObjIds'])
            ->getMock();
        $position->method('getStartObjId')->willReturn(10);
        $position->method('getEndObjId')->willReturn(40);
        $position->method('getCurrentObjId')->willReturn(10);
        $position->method('getWalkedObjIds')->willReturn([10]);

        $items = [
            $this->mockLearnerItem(10, 1, 'Start'),
            $this->mockLearnerItem(20, 2, 'A'),
            $this->mockLearnerItem(30, 3, 'B'),
            $this->mockLearnerItem(40, 4, 'End'),
        ];

        $builder = new class (
            $navigator,
            $this->createMock(\LSUrlBuilder::class),
            'goto',
            99,
            7,
            static fn(): LSAdaptivePosition => $position,
            static fn(): array => $items
        ) extends LSMapDataBuilder {
            protected function resolveObjId(\LSLearnerItem $item): int
            {
                return $item->getRefId();
            }

            protected function canAccess(array $items, \LSLearnerItem $item, int $obj_id, int $start_obj_id): bool
            {
                return true;
            }

            protected function buildNode(
                LSAdaptivePosition $position,
                array $items,
                \LSLearnerItem $item,
                int $obj_id,
                array $successor_obj_ids,
                int $start_obj_id,
                int $end_obj_id,
                int $current_obj_id,
                array $walked_obj_ids,
                int $depth
            ): LSMapNode {
                return new LSMapNode(
                    obj_id: $obj_id,
                    title: $item->getTitle(),
                    description: '',
                    player_link: null,
                    can_access: true,
                    has_visited: false,
                    has_completed: false,
                    situation: $obj_id === $start_obj_id ? 'start' : 'straight',
                    successors: $successor_obj_ids,
                    input_condition_ids: [],
                    output_condition_ids: [],
                    visit_count: 0,
                    last_visited_ts: null,
                    is_current: $obj_id === $current_obj_id,
                    is_on_walked_path: in_array($obj_id, $walked_obj_ids, true),
                    depth: $depth
                );
            }
        };

        $map = $builder->build(LSMapViewMode::MODE_FULL_ROUTE);

        $this->assertInstanceOf(LSMap::class, $map);
        $this->assertSame([10, 20, 30, 40], array_keys($map->nodes));
        $this->assertSame([20], $map->nodes[10]->successors);
        $this->assertSame([40], $map->nodes[30]->successors);
        $this->assertSame(0, $map->nodes[10]->depth);
        $this->assertSame(1, $map->nodes[20]->depth);
        $this->assertSame(2, $map->nodes[30]->depth);
        $this->assertSame(3, $map->nodes[40]->depth);
    }

    private function mockLearnerItem(int $ref_id, int $order, string $title): \LSLearnerItem
    {
        $item = $this->getMockBuilder(\LSLearnerItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRefId', 'getOrderNumber', 'getTitle'])
            ->getMock();
        $item->method('getRefId')->willReturn($ref_id);
        $item->method('getOrderNumber')->willReturn($order);
        $item->method('getTitle')->willReturn($title);

        return $item;
    }
}
