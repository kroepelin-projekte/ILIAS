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

namespace ILIAS\LearningSequence\LearningMap;

use ILIAS\LearningSequence\Player\LSNavigator;
use ILIAS\LearningSequence\Content\Adaptive\LSOItemPath;
use ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveBoundaries;
use ilDBInterface;

class LSOLearningMapPosition
{
    public const SIT_END = 'end';
    public const SIT_BLOCKED = 'blocked';
    public const SIT_DEADEND = 'deadend';
    public const SIT_BRANCH = 'branch';
    public const SIT_STRAIGHT = 'straight';
    public const VISITS_TABLE = 'lso_item_visits';
    protected ?array $raw_visit_log = null;
    protected ?array $visit_stats = null;
    protected array $obj_id_by_ref_id = [];

    public function __construct(
        protected LSNavigator $navigator,
        protected LSOItemPath $item_path,
        protected LSOAdaptiveBoundaries $boundaries,
        protected int $lso_obj_id,
        protected int $usr_id,
        protected ?ilDBInterface $db = null
    ) {
    }
    public function prepareForItems(array $items): void
    {
    }
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

        $this->raw_visit_log = null;
        $this->visit_stats = null;
    }
    protected function lookupObjId(int $ref_id): int
    {
        return $this->obj_id_by_ref_id[$ref_id] ??= \ilObject::_lookupObjId($ref_id);
    }
    protected function getStartRefId(): int
    {
        $boundaries = $this->boundaries->getBoundariesFor($this->lso_obj_id);
        return (int) ($boundaries['start_ref_id'] ?? 0);
    }
    protected function getEndRefId(): int
    {
        $boundaries = $this->boundaries->getBoundariesFor($this->lso_obj_id);
        return (int) ($boundaries['end_ref_id'] ?? 0);
    }
    public function getStartObjId(): int
    {
        $start_ref_id = $this->getStartRefId();
        if ($start_ref_id === 0) {
            return 0;
        }
        return $this->lookupObjId($start_ref_id);
    }
    public function getEndObjId(): int
    {
        $end_ref_id = $this->getEndRefId();
        if ($end_ref_id === 0) {
            return 0;
        }
        return $this->lookupObjId($end_ref_id);
    }
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
    public function getSuccessors(array $items, \LSLearnerItem $item): array
    {
        return $this->navigator->getSuccessors($items, $item);
    }
    public function getStructuralSuccessors(array $items, \LSLearnerItem $item): array
    {
        return $this->navigator->getStructuralSuccessors($items, $item);
    }
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
    public function jumpTo(array $items, \LSLearnerItem $current_item, ?int $obj_id): \LSLearnerItem
    {
        if ($obj_id === null || $obj_id === 0) {
            return $current_item;
        }
        foreach ($items as $item) {
            if ($this->lookupObjId($item->getRefId()) !== $obj_id) {
                continue;
            }
            if ($item->getRefId() === $current_item->getRefId()) {
                return $current_item;
            }
            if (!$this->mayAccess($items, $item)) {
                return $current_item;
            }
            $this->item_path->push($this->usr_id, $this->lso_obj_id, $item->getRefId());
            $this->recordVisit($item->getRefId());
            return $item;
        }
        return $current_item;
    }
    protected function mayAccess(array $items, \LSLearnerItem $item): bool
    {
        if ($this->getStartRefId() !== 0 && $item->getRefId() === $this->getStartRefId()) {
            return true;
        }
        if (!$this->navigator->canEnterIgnoringEdges($item)) {
            return false;
        }
        $predecessors = $this->navigator->getPredecessors($items, $item);
        if ($predecessors === []) {
            return true;
        }
        foreach ($predecessors as $predecessor) {
            if ($this->navigator->canLeave($predecessor)) {
                return true;
            }
        }
        return false;
    }
    public function goTo(array $items, \LSLearnerItem $current_item, ?int $obj_id): \LSLearnerItem
    {
        if ($obj_id === null || $obj_id === 0 || !$this->navigator->canLeave($current_item)) {
            return $current_item;
        }
        foreach ($this->getSuccessors($items, $current_item) as $successor) {
            if ($this->lookupObjId($successor->getRefId()) === $obj_id) {
                $this->item_path->push($this->usr_id, $this->lso_obj_id, $successor->getRefId());
                $this->recordVisit($successor->getRefId());
                return $successor;
            }
        }
        return $current_item;
    }

    protected function getPath(): array
    {
        return $this->item_path->getPath($this->usr_id, $this->lso_obj_id);
    }

    public function getPathLength(): int
    {
        return count($this->getPath());
    }

    protected function getCurrentRefId(): int
    {
        $current_ref_id = $this->item_path->getCurrent($this->usr_id, $this->lso_obj_id);
        return $current_ref_id ?? 0;
    }

    public function getCurrentObjId(): int
    {
        $current_ref_id = $this->getCurrentRefId();
        if ($current_ref_id === 0) {
            return 0;
        }
        return $this->lookupObjId($current_ref_id);
    }

    protected function getWalkedRefIds(): array
    {
        return $this->getPath();
    }

    public function getWalkedObjIds(): array
    {
        return array_map(
            fn(int $ref_id): int => $this->lookupObjId($ref_id),
            $this->getWalkedRefIds()
        );
    }

    public function getVisitLog(): array
    {
        return array_map(
            fn(array $entry): array => [
                'obj_id' => $this->lookupObjId($entry['ref_id']),
                'visited_ts' => $entry['visited_ts']
            ],
            $this->getRawVisitLog()
        );
    }

    protected function getRawVisitLog(): array
    {
        if ($this->raw_visit_log !== null) {
            return $this->raw_visit_log;
        }
        if ($this->db === null) {
            return $this->raw_visit_log = [];
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

        $this->raw_visit_log = $log;
        return $log;
    }

    protected function getVisitStats(): array
    {
        if ($this->visit_stats !== null) {
            return $this->visit_stats;
        }

        $stats = [];
        foreach ($this->getRawVisitLog() as $entry) {
            $obj_id = $this->lookupObjId($entry['ref_id']);
            if (!isset($stats[$obj_id])) {
                $stats[$obj_id] = ['count' => 0, 'last_ts' => (int) $entry['visited_ts']];
            }
            $stats[$obj_id]['count']++;
            $stats[$obj_id]['last_ts'] = (int) $entry['visited_ts'];
        }

        $this->visit_stats = $stats;
        return $stats;
    }

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

    public function getVisitCount(int $obj_id): int
    {
        return $this->getVisitStats()[$obj_id]['count'] ?? 0;
    }

    public function getLastVisitTs(int $obj_id): ?int
    {
        return $this->getVisitStats()[$obj_id]['last_ts'] ?? null;
    }

    public function getEverVisitedObjIds(): array
    {
        return array_map(
            fn(int $ref_id): int => $this->lookupObjId($ref_id),
            $this->getEverVisitedRefIds()
        );
    }

    public function hasVisited(int $obj_id): bool
    {
        return isset($this->getVisitStats()[$obj_id]);
    }


    public function hasCompleted(array $items, int $obj_id): bool
    {
        if ($obj_id === 0) {
            return false;
        }
        foreach ($items as $item) {
            if ($this->lookupObjId($item->getRefId()) !== $obj_id) {
                continue;
            }
            if ($this->navigator->getOutputConditionIds($item) === []) {
                return $this->hasLearningProgressCompleted($item, $obj_id);
            }
            return $this->navigator->canLeave($item);
        }
        return false;
    }

    protected function hasLearningProgressCompleted(\LSLearnerItem $item, int $obj_id): bool
    {
        $status = $item->getLearningProgressStatus();
        if ($status !== 0) {
            return $status === \ilLPStatus::LP_STATUS_COMPLETED_NUM;
        }

        try {
            return \ilLPStatus::_hasUserCompleted($obj_id, $this->usr_id);
        } catch (\Throwable $t) {
            return false;
        }
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
