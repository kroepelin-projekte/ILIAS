<?php

/**
 * Temporary restore script: the SimpleChoiceInputConditions that used to encode
 * the graph edges of the adaptive test learning sequences were dropped together
 * with the table lso_c_simple_choice. This script re-creates every edge as a
 * LearningProgressInputCondition with subtype "completed" (idempotent).
 *
 * docker exec trunk84 php /var/www/html/components/ILIAS/LearningSequence/classes/Content/Adaptive/temp/restore_adaptive_edges.php
 */

declare(strict_types=1);

chdir('/var/www/html');

require_once './vendor/composer/vendor/autoload.php';

$startup = new ILIAS\Cron\CLI\StartUp('default', 'root');
$startup->authenticate();

global $DIC;
$db = $DIC->database();

const LP_INPUT_SUBTYPE = 'completed';

/** lso_ref_id => list of [predecessor_ref_id, successor_ref_id] */
$edges_per_lso = [
    // LSO-ADAPTIV-GERADE: A -> B -> C -> D -> E
    112 => [
        [113, 114],
        [114, 115],
        [115, 116],
        [116, 117],
    ],
    // LSO-ADAPTIV-VERZWEIGT: branch S -> P1/P2 -> Z, blocked branch V1 -> G1 -> G2 -> Z, dead end D
    118 => [
        [119, 120],
        [119, 121],
        [120, 122],
        [121, 122],
        [119, 130],
        [130, 131],
        [131, 132],
        [132, 122],
        [120, 133],
    ],
    // LSO-ADAPTIV-BLOCKADE: X -> Y
    123 => [
        [124, 125],
    ],
    // LSO-ADAPTIV-SACKGASSE: S -> M -> T
    126 => [
        [127, 128],
        [128, 129],
    ],
];

function typeId(string $condition_name): int
{
    global $db;
    $res = $db->queryF(
        'SELECT type_id FROM lso_condition_types WHERE condition_name = %s',
        ['text'],
        [$condition_name]
    );
    $row = $db->fetchAssoc($res);
    if ($row === null) {
        throw new RuntimeException("condition type '$condition_name' is not registered");
    }
    return (int) $row['type_id'];
}

function edgeExists(int $lso_ref_id, int $successor_ref_id, int $predecessor_ref_id): bool
{
    global $db;
    $res = $db->queryF(
        'SELECT c.condition_id FROM lso_conditions c'
        . ' JOIN lso_c_learning_progress_input s ON s.condition_id = c.condition_id'
        . ' WHERE c.lso_ref_id = %s AND c.obj_ref_id = %s AND c.type_id = %s AND s.target_ref_id = %s',
        ['integer', 'integer', 'integer', 'integer'],
        [$lso_ref_id, $successor_ref_id, typeId('LearningProgressInput'), $predecessor_ref_id]
    );
    return $db->fetchAssoc($res) !== null;
}

/** edge: predecessor -> successor, stored as an input condition of the successor */
function addEdge(int $lso_ref_id, int $predecessor_ref_id, int $successor_ref_id): void
{
    global $db;
    if (edgeExists($lso_ref_id, $successor_ref_id, $predecessor_ref_id)) {
        echo "edge exists: $predecessor_ref_id -> $successor_ref_id\n";
        return;
    }

    $condition_id = (int) $db->nextId('lso_conditions');
    $db->insert('lso_conditions', [
        'condition_id' => ['integer', $condition_id],
        'lso_ref_id' => ['integer', $lso_ref_id],
        'obj_ref_id' => ['integer', $successor_ref_id],
        'type_id' => ['integer', typeId('LearningProgressInput')]
    ]);
    $db->insert('lso_c_learning_progress_input', [
        'condition_id' => ['integer', $condition_id],
        'subtype' => ['text', LP_INPUT_SUBTYPE],
        'target_ref_id' => ['integer', $predecessor_ref_id]
    ]);
    echo "edge added: $predecessor_ref_id -> $successor_ref_id (condition $condition_id)\n";
}

foreach ($edges_per_lso as $lso_ref_id => $edges) {
    echo "--- LSO $lso_ref_id ---\n";
    foreach ($edges as [$predecessor_ref_id, $successor_ref_id]) {
        addEdge((int) $lso_ref_id, $predecessor_ref_id, $successor_ref_id);
    }
}

echo "done\n";
$startup->logout();
