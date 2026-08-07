<?php

/**
 * Temporary seeding script: adds blocked paths and a dead end to the
 * learning sequence "LSO-ADAPTIV-VERZWEIGT".
 */

declare(strict_types=1);

chdir(__DIR__);

require_once './vendor/composer/vendor/autoload.php';

$startup = new ILIAS\Cron\CLI\StartUp('default', 'root');
$startup->authenticate();

global $DIC;
$db = $DIC->database();
$tree = $DIC->repositoryTree();

const LSO_REF_ID = 118;
const LP_INPUT_SUBTYPE = 'completed';

function findChildByTitle(int $parent_ref_id, string $title): int
{
    global $DIC;
    foreach ($DIC->repositoryTree()->getChilds($parent_ref_id) as $child) {
        if ($child['title'] === $title) {
            return (int) $child['ref_id'];
        }
    }
    return 0;
}

function createPage(int $parent_ref_id, string $title, string $description): int
{
    $existing = findChildByTitle($parent_ref_id, $title);
    if ($existing > 0) {
        setOnline($existing);
        echo "exists: $title (ref_id $existing)\n";
        return $existing;
    }

    $page = new ilObjContentPage();
    $page->setTitle($title);
    $page->setDescription($description);
    $page->create();
    $page->createReference();
    $page->putInTree($parent_ref_id);
    $page->setPermissions($parent_ref_id);

    $ref_id = (int) $page->getRefId();
    setOnline($ref_id);
    echo "created: $title (ref_id $ref_id)\n";
    return $ref_id;
}

function setOnline(int $ref_id): void
{
    (new LSItemOnlineStatus())->setOnlineStatus($ref_id, true);
}

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

function conditionExists(int $obj_ref_id, int $type_id, ?int $target_ref_id): bool
{
    global $db;
    if ($target_ref_id === null) {
        $res = $db->queryF(
            'SELECT condition_id FROM lso_conditions WHERE lso_ref_id = %s AND obj_ref_id = %s AND type_id = %s',
            ['integer', 'integer', 'integer'],
            [LSO_REF_ID, $obj_ref_id, $type_id]
        );
        return $db->fetchAssoc($res) !== null;
    }

    $res = $db->queryF(
        'SELECT c.condition_id FROM lso_conditions c'
        . ' JOIN lso_c_learning_progress_input s ON s.condition_id = c.condition_id'
        . ' WHERE c.lso_ref_id = %s AND c.obj_ref_id = %s AND c.type_id = %s AND s.target_ref_id = %s',
        ['integer', 'integer', 'integer', 'integer'],
        [LSO_REF_ID, $obj_ref_id, $type_id, $target_ref_id]
    );
    return $db->fetchAssoc($res) !== null;
}

function addCondition(int $obj_ref_id, int $type_id): int
{
    global $db;
    $condition_id = (int) $db->nextId('lso_conditions');
    $db->insert('lso_conditions', [
        'condition_id' => ['integer', $condition_id],
        'lso_ref_id' => ['integer', LSO_REF_ID],
        'obj_ref_id' => ['integer', $obj_ref_id],
        'type_id' => ['integer', $type_id]
    ]);
    return $condition_id;
}

/** edge: predecessor -> successor, stored as an input condition of the successor */
function addEdge(int $predecessor_ref_id, int $successor_ref_id): void
{
    global $db;
    if (conditionExists($successor_ref_id, typeId('LearningProgressInput'), $predecessor_ref_id)) {
        echo "edge exists: $predecessor_ref_id -> $successor_ref_id\n";
        return;
    }
    $condition_id = addCondition($successor_ref_id, typeId('LearningProgressInput'));
    $db->insert('lso_c_learning_progress_input', [
        'condition_id' => ['integer', $condition_id],
        'subtype' => ['text', LP_INPUT_SUBTYPE],
        'target_ref_id' => ['integer', $predecessor_ref_id]
    ]);
    echo "edge added: $predecessor_ref_id -> $successor_ref_id (condition $condition_id)\n";
}

/** output condition: the object may only be left when it is completed */
function addLpOutput(int $obj_ref_id): void
{
    global $db;
    if (conditionExists($obj_ref_id, typeId('LearningProgressOutput'), null)) {
        echo "lp output exists: $obj_ref_id\n";
        return;
    }
    $condition_id = addCondition($obj_ref_id, typeId('LearningProgressOutput'));
    $db->insert('lso_c_learning_progress_output', [
        'condition_id' => ['integer', $condition_id],
        'subtype' => ['text', 'completed']
    ]);
    echo "lp output added: $obj_ref_id (condition $condition_id)\n";
}

$start_ref_id = 119; // S - Start/Weiche
$p1_ref_id = 120;    // P1 - Pfad 1
$goal_ref_id = 122;  // Z - Ziel

$v1 = createPage(
    LSO_REF_ID,
    'V1 - Vorbedingung (verzweigt)',
    'Muss abgeschlossen werden, sonst bleibt der Zweig G1/G2 gesperrt.'
);
$g1 = createPage(
    LSO_REF_ID,
    'G1 - Gesperrter Zweig (verzweigt)',
    'Erst nach Abschluss von V1 zugaenglich.'
);
$g2 = createPage(
    LSO_REF_ID,
    'G2 - Gesperrte Vertiefung (verzweigt)',
    'Erst nach Abschluss von G1 zugaenglich.'
);
$dead_end = createPage(
    LSO_REF_ID,
    'D - Sackgasse (verzweigt)',
    'Fuehrt nicht zum Zielobjekt.'
);

addEdge($start_ref_id, $v1);
addEdge($v1, $g1);
addEdge($g1, $g2);
addEdge($g2, $goal_ref_id);
addEdge($p1_ref_id, $dead_end);

addLpOutput($v1);
addLpOutput($g1);

echo "done\n";
$startup->logout();
