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

use ILIAS\DI\Container;

/**
 * Class ilLanguageTest
 * @author  Christian Knof <christian.knof@kroepelin-projekte.de>
 */
class ilLanguageTest extends ilLanguageBaseTest
{
    private ilLanguage $languageEn;
    private ilLanguage $languageAr;
    public static ilLogger $logger;
    
    protected function setUp() : void
    {
        parent::setUp();
    
        $this->setGlobalVariable('ilClientIniFile', $this->createMock(ilIniFile::class));
        
        $logger = $this->createMock(ilLogger::class);
        self::$logger = $logger;
        $logger_factory = new class extends ilLoggerFactory {
            public function __construct()
            {
            }
    
            public static function getRootLogger() : ilLogger
            {
                return ilLanguageTest::$logger;
            }
    
            public function getComponentLogger(string $a_component_id) : ilLogger
            {
                return ilLanguageTest::$logger;
            }
        };
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);
    
        $this->setGlobalVariable('ilDB', $this->createMock(ilDBInterface::class));
        
        $ilCachedLanguageMock = function() {
            return $this->getMockBuilder(ilCachedLanguage::class)
                        ->disableAutoload()
                        ->setMethods(['isActive'])
                        ->getMock();
        };
    
        $myUser = $this->createMock(ilObjUser::class);
        
        $myUser->prefs['language'] = 'en';
        $this->setGlobalVariable('ilUser', $myUser);
        
        $this->languageEn = new ilLanguage('en', $ilCachedLanguageMock);
    
        $myUser->prefs['language'] = 'ar';
        $this->setGlobalVariable('ilUser', $myUser);
        
        $this->languageAr = new ilLanguage('ar', $ilCachedLanguageMock);
    }
    
    public function testRetrieveLanguageKey() {
        $this->assertEquals('en', $this->languageEn->getLangKey());
        $this->assertEquals('en', $this->languageAr->getLangKey());
    }
    
    public function testRetrieveDefaultLanguage() {
        $this->assertEquals('en', $this->languageEn->getDefaultLanguage());
        $this->assertEquals('en', $this->languageAr->getDefaultLanguage());
    }
    
    public function testRetrieveContentLanguage() {
        $this->assertEquals('en', $this->languageEn->getContentLanguage());
        $this->assertEquals('ar', $this->languageAr->getContentLanguage());
    }
    
    public function testTxtReturnsEmptyStringIfTopicIsEmpty() {
        $topic = '';
        $this->assertEquals('', $this->languageEn->txt($topic));
        $this->assertEquals('', $this->languageAr->txt($topic));
    }
    
    public function testTxtReturnsTranslation() {
        $topic = 'cat_add';
        
        $this->languageEn->text = ['cat_add' => 'Add Category'];
        $this->assertEquals('Add Category', $this->languageEn->txt($topic));
    
        $this->languageAr->text = ['cat_add' => 'اضافة فئة'];
        $this->assertEquals('اضافة فئة', $this->languageAr->txt($topic));
    }
    
    public function testTxtReturnsTopicItselfWhenTranslationWasNotFound() {
        $topic = 'cat_add';
        $this->assertEquals('-cat_add-', $this->languageEn->txt($topic));
        $this->assertEquals('-cat_add-', $this->languageAr->txt($topic));
    }
    
    public function testGetTextDirection() {
        $this->assertEquals('ltr', $this->languageEn->getTextDirection());
        $this->assertEquals('rtl', $this->languageAr->getTextDirection());
    }
}
