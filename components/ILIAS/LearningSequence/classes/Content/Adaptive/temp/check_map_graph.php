<?php

/**
 * Temporary check script: dumps the structural graph of an LSO (default:
 * ref_id 118) together with the accessibility flags used by the map, without
 * touching ilCtrl (which is not available on the CLI).
 *
 * docker exec trunk84 php /var/www/html/components/ILIAS/LearningSequence/classes/Content/Adaptive/temp/check_map_graph.php [ref_id]
 */

declare(strict_types=1);

chdir('/var/www/html');

require_once './vendor/composer/vendor/autoload.php';

use ILIAS\LearningSequence\Player\AdaptiveNavigator;

$lso_ref_id = (int) ($argv[1] ?? 118);

$startup = new ILIAS\Cron\CLI\StartUp('default', 'root');
$startup->authenticate();

$lso = ilObjLearningSequence::getInstanceByRefId($lso_ref_id);
$items = $lso->getLocalDI()['learneritems']->getItems();
$navigator = new AdaptiveNavigator();

foreach ($items as $item) {
    $successors = [];
    foreach ($navigator->getStructuralSuccessors($items, $item) as $successor) {
        $successors[] = $successor->getRefId();
    }
    $predecessors = [];
    foreach ($navigator->getPredecessors($items, $item) as $predecessor) {
        $predecessors[] = $predecessor->getRefId();
    }

    $can_access = true;
    if ($predecessors !== []) {
        $can_access = false;
        foreach ($navigator->getPredecessors($items, $item) as $predecessor) {
            if ($navigator->canLeave($predecessor)) {
                $can_access = true;
                break;
            }
        }
    }
    $can_access = $can_access && $navigator->canEnterIgnoringEdges($item);

    // the successors the player really offers (edge condition of the used edge
    // plus the non-edge input-conditions of the target)
    $allowed = [];
    foreach ($navigator->getSuccessors($items, $item) as $successor) {
        $allowed[] = $successor->getRefId();
    }

    printf(
        "%-40s ref=%-4d pred=%-12s succ=%-12s allowed=%-12s can_leave=%d can_access=%d\n",
        $item->getTitle(),
        $item->getRefId(),
        implode(',', $predecessors),
        implode(',', $successors),
        implode(',', $allowed),
        (int) $navigator->canLeave($item),
        (int) $can_access
    );
}

$startup->logout();
