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

use ILIAS\LearningSequence\Player\LSNavigator;
use ILIAS\LearningSequence\Content\Adaptive\LSOItemPath;
use ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveBoundaries;
use ilDBInterface;

/**
 * Encapsulates the whole "where is the learner currently within an adaptive
 * learning sequence" logic.
 *
 * This class knows nothing about rendering; it purely answers questions about
 * the learner's position in the object graph based on the walked path
 * (LSOItemPath), the sequence boundaries (start/end object) and the navigation
 * rules (LSNavigator). It also performs the path mutations for the four
 * navigation cases (advance, back, choose a branch).
 *
 * It is intentionally kept separate from the player so it can be reused later,
 * most notably by the map view, which needs to know the current position and
 * the possible next objects without driving the kiosk mode player.
 */
class LSAdaptivePosition
{
    // The five situations an object can be in for the adaptive player.
    public const SIT_END = 'end';
    public const SIT_BLOCKED = 'blocked';
    public const SIT_DEADEND = 'deadend';
    public const SIT_BRANCH = 'branch';
    public const SIT_STRAIGHT = 'straight';

    /**
     * Append-only visit log table. In contrast to LSOItemPath (a stack that
     * only reflects the currently active path and pops entries on "back"), this
     * log keeps every visit a learner ever made within the learning sequence,
     * including branches that were entered and later abandoned via "back". It is
     * kept here on purpose so all "where is / how did the learner move" logic
     * lives in one place (reusable by the upcoming map view).
     */
    public const VISITS_TABLE = 'lso_item_visits';

    public function __construct(
        protected LSNavigator $navigator,
        protected LSOItemPath $item_path,
        protected LSOAdaptiveBoundaries $boundaries,
        protected int $lso_obj_id,
        protected int $usr_id,
        protected ?ilDBInterface $db = null
    ) {
    }

    /**
     * Records a visit of the given object in the append-only visit log (if a
     * database connection was injected). Always inserts a new row (revisits are
     * recorded as additional entries), so the log never loses information -
     * this is called whenever the learner actually enters an object, so the log
     * keeps the full history including branches that are later abandoned via
     * "back".
     */
    protected function recordVisit(int $ref_id): void
    {
        if ($this->db === null) {
            return;
        }
        $query = "SELECT MAX(position) AS max_position FROM " . self::VISITS_TABLE
            . " WHERE usr_id = " . $this->db->quote($this->usr_id, 'integer')
            . " AND lso_obj_id = " . $this->db->quote($this->lso_obj_id, 'integer');
        $row = $this->db->fetchAssoc($this->db->query($query));
        $next_position = (!$row || $row['max_position'] === null) ? 0 : ((int) $row['max_position']) + 1;

        $this->db->insert(self::VISITS_TABLE, [
            'usr_id' => ['integer', $this->usr_id],
            'lso_obj_id' => ['integer', $this->lso_obj_id],
            'position' => ['integer', $next_position],
            'ref_id' => ['integer', $ref_id],
            'visited_ts' => ['integer', time()]
        ]);
    }

    /**
     * The ref_id of the configured start object (0 if none). Kept internal
     * because the public API of this class always speaks obj_id; the ref_id is
     * only an implementation detail needed to address the item inside the tree.
     */
    protected function getStartRefId(): int
    {
        $boundaries = $this->boundaries->getBoundariesFor($this->lso_obj_id);
        return (int) ($boundaries['start_ref_id'] ?? 0);
    }

    /**
     * The ref_id of the configured end object (0 if none). Internal only, see
     * getStartRefId().
     */
    protected function getEndRefId(): int
    {
        $boundaries = $this->boundaries->getBoundariesFor($this->lso_obj_id);
        return (int) ($boundaries['end_ref_id'] ?? 0);
    }

    /**
     * The obj_id of the configured start object within the LSO (0 if none).
     */
    public function getStartObjId(): int
    {
        $start_ref_id = $this->getStartRefId();
        if ($start_ref_id === 0) {
            return 0;
        }
        return \ilObject::_lookupObjId($start_ref_id);
    }

    /**
     * The obj_id of the configured end object within the LSO (0 if none).
     */
    public function getEndObjId(): int
    {
        $end_ref_id = $this->getEndRefId();
        if ($end_ref_id === 0) {
            return 0;
        }
        return \ilObject::_lookupObjId($end_ref_id);
    }

    /**
     * Determines the current object from the walked path. On the first visit
     * the start object is pushed onto the path. Path entries pointing to no
     * longer existing objects are dropped.
     *
     * @param \LSLearnerItem[] $items
     */
    public function getCurrentItem(array $items): ?\LSLearnerItem
    {
        if (count($items) === 0) {
            return null;
        }
        $valid_ref_ids = array_map(fn($i) => $i->getRefId(), array_values($items));

        $current_ref_id = $this->item_path->getCurrent($this->usr_id, $this->lso_obj_id);
        while ($current_ref_id !== null && !in_array($current_ref_id, $valid_ref_ids, true)) {
            $this->item_path->pop($this->usr_id, $this->lso_obj_id);
            $current_ref_id = $this->item_path->getCurrent($this->usr_id, $this->lso_obj_id);
        }

        if ($current_ref_id === null) {
            $start_ref_id = $this->getStartRefId();
            if ($start_ref_id === 0 || !in_array($start_ref_id, $valid_ref_ids, true)) {
                $start_ref_id = $items[0]->getRefId();
            }
            $this->item_path->push($this->usr_id, $this->lso_obj_id, $start_ref_id);
            $this->recordVisit($start_ref_id);
            $current_ref_id = $start_ref_id;
        }

        return $this->findItemByRefId($items, $current_ref_id);
    }

    /**
     * Classifies the situation of the given object:
     * end, blocked, deadend, branch or straight.
     *
     * @param \LSLearnerItem[] $items
     */
    public function getSituation(array $items, \LSLearnerItem $item): string
    {
        if ($this->getEndRefId() !== 0 && $item->getRefId() === $this->getEndRefId()) {
            return self::SIT_END;
        }
        if (!$this->navigator->canLeave($item)) {
            return self::SIT_BLOCKED;
        }
        $count = count($this->getSuccessors($items, $item));
        if ($count === 0) {
            return self::SIT_DEADEND;
        }
        if ($count === 1) {
            return self::SIT_STRAIGHT;
        }
        return self::SIT_BRANCH;
    }

    /**
     * Same classification as getSituation(), but based on the configured graph
     * instead of the currently enterable successors. The map must not turn a
     * branch into a dead end just because its successors are still blocked.
     *
     * @param \LSLearnerItem[] $items
     */
    public function getStructuralSituation(array $items, \LSLearnerItem $item): string
    {
        if ($this->getEndRefId() !== 0 && $item->getRefId() === $this->getEndRefId()) {
            return self::SIT_END;
        }
        if (!$this->navigator->canLeave($item)) {
            return self::SIT_BLOCKED;
        }
        $count = count($this->getStructuralSuccessors($items, $item));
        if ($count === 0) {
            return self::SIT_DEADEND;
        }
        if ($count === 1) {
            return self::SIT_STRAIGHT;
        }
        return self::SIT_BRANCH;
    }

    /**
     * The objects that may currently be entered from the given object.
     *
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getSuccessors(array $items, \LSLearnerItem $item): array
    {
        return $this->navigator->getSuccessors($items, $item);
    }

    /**
     * All objects an edge leads to, no matter whether they may currently be
     * entered. This is the graph as it was configured and therefore the basis
     * for the map, which has to show blocked objects, too.
     *
     * @param \LSLearnerItem[] $items
     * @return \LSLearnerItem[]
     */
    public function getStructuralSuccessors(array $items, \LSLearnerItem $item): array
    {
        return $this->navigator->getStructuralSuccessors($items, $item);
    }

    /**
     * Handles "next"/"back". A negative direction pops the path (back); a
     * positive direction advances if the current object may be left and there
     * is exactly one allowed successor.
     *
     * @param \LSLearnerItem[] $items
     */
    public function advance(array $items, \LSLearnerItem $current_item, ?int $direction): \LSLearnerItem
    {
        if ($direction !== null && $direction < 0) {
            if ($this->getPathLength() > 1) {
                $this->item_path->pop($this->usr_id, $this->lso_obj_id);
            }
            return $this->getCurrentItem($items);
        }

        if (!$this->navigator->canLeave($current_item)) {
            return $current_item;
        }
        $successors = $this->getSuccessors($items, $current_item);
        if (count($successors) === 1) {
            $successor = array_values($successors)[0];
            $this->item_path->push($this->usr_id, $this->lso_obj_id, $successor->getRefId());
            $this->recordVisit($successor->getRefId());
            return $successor;
        }
        return $current_item;
    }

    /**
     * Handles the choice on a branch: only allowed successors may be selected;
     * the selection is addressed by the obj_id of the target object within the
     * LSO and is pushed onto the path.
     *
     * @param \LSLearnerItem[] $items
     */
    public function goTo(array $items, \LSLearnerItem $current_item, ?int $obj_id): \LSLearnerItem
    {
        if ($obj_id === null || $obj_id === 0 || !$this->navigator->canLeave($current_item)) {
            return $current_item;
        }
        foreach ($this->getSuccessors($items, $current_item) as $successor) {
            if (\ilObject::_lookupObjId($successor->getRefId()) === $obj_id) {
                $this->item_path->push($this->usr_id, $this->lso_obj_id, $successor->getRefId());
                $this->recordVisit($successor->getRefId());
                return $successor;
            }
        }
        return $current_item;
    }

    /**
     * The ordered list of visited ref_ids (oldest first). Internal only; the
     * public API exposes the walked path as obj_ids (getWalkedObjIds()).
     *
     * @return int[]
     */
    protected function getPath(): array
    {
        return $this->item_path->getPath($this->usr_id, $this->lso_obj_id);
    }

    public function getPathLength(): int
    {
        return count($this->getPath());
    }

    /**
     * The ref_id of the object the learner is currently positioned on (top of
     * the walked path). Returns 0 if the path is still empty. Internal only;
     * the public counterpart is getCurrentObjId().
     */
    protected function getCurrentRefId(): int
    {
        $current_ref_id = $this->item_path->getCurrent($this->usr_id, $this->lso_obj_id);
        return $current_ref_id ?? 0;
    }

    /**
     * "Wo ist der User gerade?" – the obj_id of the object the learner is
     * currently positioned on. Returns 0 if the path is still empty.
     */
    public function getCurrentObjId(): int
    {
        $current_ref_id = $this->getCurrentRefId();
        if ($current_ref_id === 0) {
            return 0;
        }
        return \ilObject::_lookupObjId($current_ref_id);
    }

    /**
     * The ordered list of visited ref_ids (oldest first). Internal only; the
     * public counterpart is getWalkedObjIds().
     *
     * @return int[]
     */
    protected function getWalkedRefIds(): array
    {
        return $this->getPath();
    }

    /**
     * "Wie ist der User gelaufen?" – the ordered list of visited obj_ids
     * (oldest first), resolved from the walked ref_ids. The order reflects the
     * actual path the learner took through the sequence.
     *
     * @return int[]
     */
    public function getWalkedObjIds(): array
    {
        return array_map(
            fn(int $ref_id): int => \ilObject::_lookupObjId($ref_id),
            $this->getWalkedRefIds()
        );
    }

    /**
     * The complete, append-only visit log (oldest first) including branches
     * that were later abandoned via "back". Each entry is an associative array
     * with the keys "obj_id" (the obj_id of the visited object within the LSO)
     * and "visited_ts". Empty if no visit log repository was injected.
     *
     * @return array<int, array{obj_id: int, visited_ts: int}>
     */
    public function getVisitLog(): array
    {
        return array_map(
            fn(array $entry): array => [
                'obj_id' => \ilObject::_lookupObjId($entry['ref_id']),
                'visited_ts' => $entry['visited_ts']
            ],
            $this->getRawVisitLog()
        );
    }

    /**
     * The raw append-only visit log (oldest first) as stored, keyed by the
     * internal ref_id. Internal only; the public getVisitLog() resolves these
     * to obj_ids.
     *
     * @return array<int, array{ref_id: int, visited_ts: int}>
     */
    protected function getRawVisitLog(): array
    {
        if ($this->db === null) {
            return [];
        }
        $query = "SELECT ref_id, visited_ts FROM " . self::VISITS_TABLE
            . " WHERE usr_id = " . $this->db->quote($this->usr_id, 'integer')
            . " AND lso_obj_id = " . $this->db->quote($this->lso_obj_id, 'integer')
            . " ORDER BY position ASC";
        $res = $this->db->query($query);

        $log = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $log[] = [
                'ref_id' => (int) $row['ref_id'],
                'visited_ts' => (int) $row['visited_ts']
            ];
        }
        return $log;
    }

    /**
     * Every ref_id the learner has ever visited (de-duplicated, order of first
     * visit), including objects on branches that were later abandoned. Internal
     * only; the public counterpart is getEverVisitedObjIds().
     *
     * @return int[]
     */
    protected function getEverVisitedRefIds(): array
    {
        $ref_ids = [];
        foreach ($this->getRawVisitLog() as $entry) {
            if (!in_array($entry['ref_id'], $ref_ids, true)) {
                $ref_ids[] = $entry['ref_id'];
            }
        }
        return $ref_ids;
    }

    /**
     * How often the learner has visited the object with the given obj_id
     * within this learning sequence.
     */
    public function getVisitCount(int $obj_id): int
    {
        $count = 0;
        foreach ($this->getVisitLog() as $entry) {
            if ($entry['obj_id'] === $obj_id) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * "Wann war der User zuletzt hier?" – the timestamp (Unix seconds) of the
     * most recent visit of the object with the given obj_id, or null if the
     * learner has never visited it. Uses the append-only visit log, so it also
     * covers objects on branches that were later abandoned via "back".
     */
    public function getLastVisitTs(int $obj_id): ?int
    {
        $last = null;
        foreach ($this->getVisitLog() as $entry) {
            if ($entry['obj_id'] === $obj_id) {
                $last = $entry['visited_ts'];
            }
        }
        return $last;
    }

    /**
     * Every obj_id the learner has ever visited (de-duplicated, order of first
     * visit), resolved from the ever-visited ref_ids.
     *
     * @return int[]
     */
    public function getEverVisitedObjIds(): array
    {
        return array_map(
            fn(int $ref_id): int => \ilObject::_lookupObjId($ref_id),
            $this->getEverVisitedRefIds()
        );
    }

    /**
     * "Hat besucht?" – whether the learner has ever visited the object with the
     * given obj_id within this learning sequence. Uses the append-only visit
     * log, so it also returns true for objects on branches that were later
     * abandoned via "back".
     */
    public function hasVisited(int $obj_id): bool
    {
        return in_array($obj_id, $this->getEverVisitedObjIds(), true);
    }

    /**
     * "Hat abgeschlossen?" – whether the learner may leave/advance from the
     * object with the given obj_id according to its conditions (i.e. all of the
     * object's output-conditions are fulfilled). This deliberately does NOT ask
     * the learning-progress subsystem; "done" is defined purely by the adaptive
     * conditions, exactly like the player uses to decide whether "next" is
     * allowed. Requires the item list so the obj_id can be resolved to the
     * corresponding learner item.
     *
     * @param \LSLearnerItem[] $items
     */
    public function hasCompleted(array $items, int $obj_id): bool
    {
        if ($obj_id === 0) {
            return false;
        }
        foreach ($items as $item) {
            if (\ilObject::_lookupObjId($item->getRefId()) === $obj_id) {
                return $this->navigator->canLeave($item);
            }
        }
        return false;
    }

    /**
     * @param \LSLearnerItem[] $items
     */
    protected function findItemByRefId(array $items, int $ref_id): ?\LSLearnerItem
    {
        foreach ($items as $item) {
            if ($item->getRefId() === $ref_id) {
                return $item;
            }
        }
        return null;
    }
}
