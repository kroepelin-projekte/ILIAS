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
use ILIAS\Data\ReferenceId;

/**
 * @author Fabian Schmid <fabian@sr.solutions>
 */
class Download extends BaseCheck implements Check
{
    public $static_url;
    protected ?\ilObjFileInfo $info = null;

    public function __construct()
    {
        parent::__construct();
    }

    public function canUnlock(): Capabilities
    {
        return Capabilities::DOWNLOAD;
    }

    public function maybeUnlock(
        Capability $capability,
        CheckHelpers $helpers,
        \ilObjFileInfo $info,
        int $ref_id,
    ): Capability {
        $this->info = $info;
        return $capability->withUnlocked($this->hasPermission($helpers, $ref_id, $capability->getPermission()));
    }

    public function maybeBuildURI(Capability $capability, CheckHelpers $helpers, int $ref_id): Capability
    {
        if (!$capability->isUnlocked()) {
            return $capability;
        }

        return $capability->withURI(
            $helpers->fromTarget(
                $helpers->ctrl->getLinkTargetByClass(
                    [\ilRepositoryGUI::class, \ilObjFileGUI::class],
                    Capabilities::DOWNLOAD->value
                )
            )
        );

        return $capability->withURI(
            $this->static_url->build(
                'file',
                new ReferenceId($ref_id),
                ['download']
            )
        );
    }

}
