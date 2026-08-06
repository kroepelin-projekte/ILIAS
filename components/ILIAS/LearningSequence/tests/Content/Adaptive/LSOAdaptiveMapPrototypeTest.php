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

require_once __DIR__ . '/../../../classes/Content/Adaptive/LSOAdaptiveMapPrototype.php';

use ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveMapPrototype;
use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\ConditionFactory;
use ILIAS\LearningSequence\Player\Map\LSMap;
use ILIAS\LearningSequence\Player\Map\LSMapNode;
use ILIAS\LearningSequence\Player\Map\LSMapViewMode;
use PHPUnit\Framework\TestCase;

class LSOAdaptiveMapPrototypeTest extends TestCase
{
    public function testRenderShowsStartEndCurrentAndConditions(): void
    {
        $condition = $this->createMock(AbstractCondition::class);
        $condition->method('getName')->willReturn('Learning Progress');

        $factory = $this->createMock(ConditionFactory::class);
        $factory->method('getConditionInstanceById')->willReturn($condition);

        $map = new LSMap(
            99,
            6,
            LSMapViewMode::MODE_FULL_ROUTE,
            10,
            30,
            [
                10 => new LSMapNode(
                    10,
                    'Startobjekt',
                    '',
                    '/goto.php?target=10',
                    true,
                    true,
                    true,
                    'start',
                    [20],
                    [],
                    [1001],
                    1,
                    1722948000,
                    false,
                    true,
                    0
                ),
                20 => new LSMapNode(
                    20,
                    'Zwischenobjekt',
                    'Noch offen',
                    '/goto.php?target=20',
                    true,
                    true,
                    false,
                    'straight',
                    [30],
                    [1002],
                    [],
                    2,
                    1722951600,
                    true,
                    true,
                    1
                ),
                30 => new LSMapNode(
                    30,
                    'Endobjekt',
                    '',
                    null,
                    false,
                    false,
                    false,
                    'end',
                    [],
                    [1003],
                    [],
                    0,
                    null,
                    false,
                    false,
                    2
                ),
            ]
        );

        $html = (new LSOAdaptiveMapPrototype($map, $factory))->render();

        $this->assertStringContainsString('Pfadkarte (Prototyp)', $html);
        $this->assertStringContainsString('Visualisierung aller LSO-Objekte', $html);
        $this->assertStringContainsString('data-obj-id="20"', $html);
        $this->assertStringContainsString('alp-ls-map-node--current', $html);
        $this->assertStringContainsString('alp-ls-map-node--blocked', $html);
        $this->assertStringContainsString('Learning Progress', $html);
        $this->assertStringContainsString('Start', $html);
        $this->assertStringContainsString('Ende', $html);
    }
}
