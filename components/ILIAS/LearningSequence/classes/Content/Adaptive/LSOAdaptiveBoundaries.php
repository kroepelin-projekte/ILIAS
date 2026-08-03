<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Adaptive;

use ilDBInterface;

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

class LSOAdaptiveBoundaries
{
    public const string TABLE_NAME = 'lso_item_boundaries';

    protected ilDBInterface $db;

    public function __construct(ilDBInterface $db)
    {
        $this->db = $db;
    }

    public function getBoundariesFor(int $lso_obj_id): array
    {
        $query = "SELECT start_ref_id, end_ref_id FROM " . self::TABLE_NAME . " WHERE obj_id = " . $this->db->quote($lso_obj_id, 'integer');
        $res = $this->db->query($query);
        $row = $this->db->fetchAssoc($res);
        if ($row) {
            return [
                'start_ref_id' => (int) ($row['start_ref_id'] ?? 0),
                'end_ref_id' => (int) ($row['end_ref_id'] ?? 0)
            ];
        }
        return ['start_ref_id' => 0, 'end_ref_id' => 0];
    }

    public function setStartRefId(int $lso_obj_id, int $start_ref_id): void
    {
        $boundaries = $this->getBoundariesFor($lso_obj_id);
        if ($boundaries['start_ref_id'] === 0 && $boundaries['end_ref_id'] === 0) {
            $this->db->insert(self::TABLE_NAME, [
                'obj_id' => ['integer', $lso_obj_id],
                'start_ref_id' => ['integer', $start_ref_id],
                'end_ref_id' => ['integer', null]
            ]);
        } else {
            $this->db->update(
                self::TABLE_NAME,
                ['start_ref_id' => ['integer', $start_ref_id]],
                ['obj_id' => ['integer', $lso_obj_id]]
            );
        }
    }

    public function setEndRefId(int $lso_obj_id, int $end_ref_id): void
    {
        $boundaries = $this->getBoundariesFor($lso_obj_id);
        if ($boundaries['start_ref_id'] === 0 && $boundaries['end_ref_id'] === 0) {
            $this->db->insert(self::TABLE_NAME, [
                'obj_id' => ['integer', $lso_obj_id],
                'start_ref_id' => ['integer', null],
                'end_ref_id' => ['integer', $end_ref_id]
            ]);
        } else {
            $this->db->update(
                self::TABLE_NAME,
                ['end_ref_id' => ['integer', $end_ref_id]],
                ['obj_id' => ['integer', $lso_obj_id]]
            );
        }
    }

    public function unsetStartRefId(int $lso_obj_id): void
    {
        $this->db->update(
            self::TABLE_NAME,
            ['start_ref_id' => ['integer', null]],
            ['obj_id' => ['integer', $lso_obj_id]]
        );
        $this->cleanupIfEmpty($lso_obj_id);
    }

    public function unsetEndRefId(int $lso_obj_id): void
    {
        $this->db->update(
            self::TABLE_NAME,
            ['end_ref_id' => ['integer', null]],
            ['obj_id' => ['integer', $lso_obj_id]]
        );
        $this->cleanupIfEmpty($lso_obj_id);
    }

    protected function cleanupIfEmpty(int $lso_obj_id): void
    {
        $boundaries = $this->getBoundariesFor($lso_obj_id);
        if ($boundaries['start_ref_id'] === 0 && $boundaries['end_ref_id'] === 0) {
            $this->db->manipulate("DELETE FROM " . self::TABLE_NAME . " WHERE obj_id = " . $this->db->quote($lso_obj_id, 'integer'));
        }
    }
}
