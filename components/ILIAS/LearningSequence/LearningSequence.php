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

namespace ILIAS;

class LearningSequence implements Component\Component
{
    public function init(
        array | \ArrayAccess &$define,
        array | \ArrayAccess &$implement,
        array | \ArrayAccess &$use,
        array | \ArrayAccess &$contribute,
        array | \ArrayAccess &$seek,
        array | \ArrayAccess &$provide,
        array | \ArrayAccess &$pull,
        array | \ArrayAccess &$internal,
    ): void {
        $contribute[\ILIAS\Setup\Agent::class] = static fn() =>
            new \ilLearningSequenceSetupAgent(
                $pull[\ILIAS\Refinery\Factory::class]
            );

        $contribute[Component\Resource\PublicAsset::class] = fn() =>
            new Component\Resource\ComponentJS($this, "js/lso_kiosk_rating.js");

        // Demo-/ALP-Anforderung:
        // Unser CSS für die Dummy-"Content Managent"-Presentation-Table muss als PublicAsset
        // registriert werden (analog zu lso_kiosk_rating.js), damit es unter /assets/css
        // ausgeliefert wird und vom Template via addCss() gefunden werden kann.
        $contribute[Component\Resource\PublicAsset::class] = fn() =>
            new Component\Resource\ComponentCSS($this, "css/alp_content_management_presentation.css");
    }
}
