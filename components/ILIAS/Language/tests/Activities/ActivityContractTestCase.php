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

namespace ILIAS\Language\Tests\Activities;

use ILIAS\Language\Tests\Activities\RealFieldsUiFactory;
use ILIAS\Component\Activities\Activity;
use ILIAS\Component\Activities\ActivityType;
use ilLanguageBaseTestCase;

/**
 * Shared contract tests mixed into every Activity test case in this
 * component: getName() must equal the activity's own fully qualified class
 * name, getType() must report the expected ActivityType (Command unless
 * overridden), and getDescription() must return a non-empty markdown
 * document. Subclasses only supply a ready-to-use default activity instance
 * via createDefaultActivity() - see
 * ActivityWithPerformResultContractTestCase for the additional
 * getOutputDescription()-vs-perform() contract test shared by the four
 * Activities that resolve `language_keys` via toLanguageKeyList().
 */
abstract class ActivityContractTestCase extends ilLanguageBaseTestCase
{
    use RealFieldsUiFactory;

    abstract protected function createDefaultActivity(): Activity;

    protected function expectedActivityType(): ActivityType
    {
        return ActivityType::Command;
    }

    public function testGetNameIsTheFullyQualifiedClassName(): void
    {
        $activity = $this->createDefaultActivity();

        $this->assertSame($activity::class, (string) $activity->getName());
    }

    public function testGetTypeIsCommand(): void
    {
        $this->assertSame($this->expectedActivityType(), $this->createDefaultActivity()->getType());
    }

    public function testGetDescriptionReturnsANonEmptyMarkdownDocument(): void
    {
        $this->assertNotSame('', $this->createDefaultActivity()->getDescription()->getRawRepresentation());
    }
}
