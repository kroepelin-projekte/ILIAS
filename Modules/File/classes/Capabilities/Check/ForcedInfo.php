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

namespace ILIAS\File\Capabilities\Check;

use ILIAS\File\Capabilities\Capability;
use ILIAS\File\Capabilities\Capabilities;
use ILIAS\File\Capabilities\Permissions;

/**
 * @author Fabian Schmid <fabian@sr.solutions>
 */
class ForcedInfo extends Info implements Check
{
    public function canUnlock(): Capabilities
    {
        return Capabilities::FORCED_INFO_PAGE;
    }

    public function maybeUnlock(
        Capability $capability,
        CheckHelpers $helpers,
        \ilObjFileInfo $info,
        int $ref_id,
    ): Capability {
        return $capability->withUnlocked(
            !$info->shouldDownloadDirectly() && $this->hasPermission(
                $helpers,
                $ref_id,
                Permissions::VISIBLE,
                Permissions::READ,
                Permissions::WRITE,
                Permissions::VIEW_CONTENT,
                Permissions::EDIT_CONTENT
            )
        );
    }

}
