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

/**
 * Class ilPluginLanguageTest
 * @author  Christian Knof <christian.knof@kroepelin-projekte.de>
 */
class ilPluginLanguageTest extends ilLanguageBaseTest
{
    protected ilPluginLanguage $pluginLanguage;
    
    protected function setUp() : void
    {
        $pluginInfo = $this->createMock(ilPluginInfo::class);
        $pluginInfo->method('getPath')->willReturn('../../../');
        $pluginInfo->method('getId')->willReturn('16');
        
        $component = $this->createMock(ilComponentInfo::class);
        $component->method('getId')->willReturn('23');
        $pluginInfo->method('getComponent')->willReturn($component);
        
        $slot = $this->createMock(ilPluginSlotInfo::class);
        $slot->method('getId')->willReturn('42');
        $pluginInfo->method('getPluginSlot')->willReturn($slot);
        
        $this->pluginLanguage = new ilPluginLanguage($pluginInfo);
    }
    
    public function testHasAvailableLangFiles() {
        $this->assertTrue($this->pluginLanguage->hasAvailableLangFiles());
    }
    
    public function testGetAvailableLangFiles() {
        $result = $this->pluginLanguage->getAvailableLangFiles();
        $this->assertCount(29, $result);
    }
    
    public function testGetPrefix() {
        $this->assertEquals('23_42_16', $this->pluginLanguage->getPrefix());
    }
}
