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

namespace ILIAS\LearningSequence\Player\Map;

/**
 * The view modes that control how much of the map is exposed to the UI.
 *
 * The mode only filters the *view*, never the permissions: whether a node's
 * player link is clickable is always governed by LSMapNode::$can_access,
 * regardless of the selected mode.
 */
final class LSMapViewMode
{
    /**
     * The complete route including all branches (dead ends and abandoned
     * branches included).
     */
    public const MODE_FULL_ROUTE = 1;

    /**
     * Only the nodes the learner can currently walk to, i.e. nodes with
     * can_access = true. Backwards reachable nodes ("Ehrenrunden") are shown as
     * reachable as well.
     */
    public const MODE_REACHABLE_ONLY = 2;

    /**
     * Focus on the learning progress: visited/completed nodes are highlighted.
     * Technically all nodes are kept; the mode is a hint for the UI.
     */
    public const MODE_PROGRESS = 3;

    /**
     * @return int[] all valid mode values
     */
    public static function all(): array
    {
        return [
            self::MODE_FULL_ROUTE,
            self::MODE_REACHABLE_ONLY,
            self::MODE_PROGRESS
        ];
    }

    public static function isValid(int $mode): bool
    {
        return in_array($mode, self::all(), true);
    }
}
