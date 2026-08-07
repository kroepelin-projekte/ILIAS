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

/**
 * Immutable container describing the whole map (the object graph plus its
 * state) for one learner within one learning sequence.
 *
 * The graph is modelled as a flat node list (indexed by obj_id) plus edges
 * (each node's $successors). Converging paths are simply multiple edges
 * pointing at the same node - no node is ever duplicated.
 */
final class LSOLearningMap
{
    /**
     * The start and end object are exposed as first-class fields on the map so
     * the (JS) UI can address them directly without having to scan the node
     * list for situation = 'start'/'end'. Both are addressed by their obj_id
     * within the LSO (0 if none is configured). Internally the LSO boundaries
     * are stored as ref_id; the resolution to obj_id happens in the builder
     * (via LSOLearningMapPosition::getStartObjId()/getEndObjId()). The nodes are
     * additionally marked via LSOLearningMapNode::$situation ('start'/'end').
     *
     * @param int          $lso_obj_id   the LSO the map belongs to
     * @param int          $usr_id       the learner the map was built for
     * @param int          $mode         the LSOLearningMapViewMode the map was built with
     * @param int          $start_obj_id obj_id of the start object (0 if none)
     * @param int          $end_obj_id   obj_id of the end object (0 if none)
     * @param LSOLearningMapNode[]  $nodes        all nodes, indexed by obj_id
     */
    public function __construct(
        public readonly int $lso_obj_id,
        public readonly int $usr_id,
        public readonly int $mode,
        public readonly int $start_obj_id,
        public readonly int $end_obj_id,
        public readonly array $nodes
    ) {
    }

    public function getNode(int $obj_id): ?LSOLearningMapNode
    {
        return $this->nodes[$obj_id] ?? null;
    }

    /**
     * Plain-array representation, ready to be json_encode()'d and handed to the
     * JS map library. The nodes are emitted as a plain list (array_values) so
     * they arrive as a JSON array, not an object keyed by obj_id.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lso_obj_id' => $this->lso_obj_id,
            'usr_id' => $this->usr_id,
            'mode' => $this->mode,
            'start_obj_id' => $this->start_obj_id,
            'end_obj_id' => $this->end_obj_id,
            'nodes' => array_values(array_map(
                static fn(LSOLearningMapNode $node): array => $node->toArray(),
                $this->nodes
            ))
        ];
    }
}
