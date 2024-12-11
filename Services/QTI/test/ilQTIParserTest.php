<?php

declare(strict_types=1);

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

use ILIAS\DI\Container;
use PHPUnit\Framework\TestCase;

class ilQTIParserTest extends TestCase
{
    public function testConstruct(): void
    {
        $this->assertInstanceOf(ilQTIParser::class, new ilQTIParser('dummy xml file'));
    }

    public function testSetGetQuestionSetType(): void
    {
        $instance = new ilQTIParser('dummy xml file');
        $instance->setQuestionSetType('Some input.');
        $this->assertEquals('Some input.', $instance->getQuestionSetType());
    }

    public function testSetTestObject(): void
    {
        $id = 8098;
        $test = $this->getMockBuilder(ilObjTest::class)->disableOriginalConstructor()->getMock();
        $test->expects(self::once())->method('getId')->willReturn($id);
        $instance = new ilQTIParser('dummy xml file');
        $instance->setTestObject($test);
        $this->assertEquals($test, $instance->tst_object);
        $this->assertEquals($id, $instance->tst_id);
    }

    protected function setup(): void
    {
        $GLOBALS['DIC'] = $this->getMockBuilder(Container::class)->disableOriginalConstructor()->getMock();
        $GLOBALS['DIC']->expects(self::any())->method('isDependencyAvailable')->with('language')->willReturn(false);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['DIC']);
    }

    public function testSetGetIliasSourceVersionWithoutPatch(): void
    {
        $this->assertEquals('7.13', $this->fetchNumericVersionFromVersionDateString('7.13 2022-08-31'));
    }

    public function testSetGetIliasSourceVersionWithPatch(): void
    {
        $this->assertEquals('5.4.22', $this->fetchNumericVersionFromVersionDateString('5.4.22 2021-05-14'));
    }

    public function testSetGetIliasSourceVersionWithoutDate(): void
    {
        $this->assertEquals('8.14', $this->fetchNumericVersionFromVersionDateString('8.14'));
    }

    protected function fetchNumericVersionFromVersionDateString(string $version): string
    {
        $instance = new ilQTIParser('dummy xml file');
        $reflection = new ReflectionClass($instance);
        $method = $reflection->getMethod('fetchNumericVersionFromVersionDateString');
        $method->setAccessible(true);
        return $method->invoke($instance, $version);
    }
}
