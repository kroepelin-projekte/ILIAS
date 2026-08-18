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

namespace ILIAS\LearningSequence\Content\Condition;

final class StaticInputConfigurationAnalyzer
{
    /**
     * @param array<int, AbstractCondition[]> $conditions_by_ref_id
     * @param array<string, mixed> $context
     * @return StaticInputConfigurationIssue[]
     */
    public function getIssues(array $conditions_by_ref_id, array $context = []): array
    {
        $issues = [];

        foreach ($conditions_by_ref_id as $conditions) {
            foreach ($conditions as $condition) {
                foreach ($condition->getStaticInputConfigurationIssues($context) as $issue) {
                    $issues[] = $issue;
                }
            }

            if (!$this->hasContradictoryStaticConstraints($conditions)) {
                continue;
            }

            $affected_ref_ids = $this->getOwningRefIds($conditions);
            if ($affected_ref_ids === []) {
                continue;
            }

            $issues[] = new StaticInputConfigurationIssue(
                'contradictory_static_input_constraints',
                $affected_ref_ids
            );
        }

        return $issues;
    }

    /**
     * @param StaticInputConfigurationIssue[] $issues
     * @return int[]
     */
    public function getAffectedRefIds(array $issues): array
    {
        $ref_ids = [];
        foreach ($issues as $issue) {
            foreach ($issue->affected_ref_ids as $ref_id) {
                $ref_ids[$ref_id] = $ref_id;
            }
        }

        sort($ref_ids);

        return array_values($ref_ids);
    }

    /**
     * @param AbstractCondition[] $conditions
     */
    protected function hasContradictoryStaticConstraints(array $conditions): bool
    {
        $requires_completed = [];
        $requires_not_completed = [];
        $any_completed_clauses = [];
        $has_constraints = false;

        foreach ($conditions as $condition) {
            foreach ($condition->getStaticInputConditionConstraints() as $constraint) {
                $has_constraints = true;
                $kind = (string) $constraint['kind'];
                $ref_ids = array_values(array_unique(array_map(
                    'intval',
                    $constraint['ref_ids']
                )));

                if ($kind === 'all_completed') {
                    foreach ($ref_ids as $ref_id) {
                        $requires_completed[$ref_id] = true;
                    }
                    continue;
                }

                if ($kind === 'none_completed') {
                    foreach ($ref_ids as $ref_id) {
                        $requires_not_completed[$ref_id] = true;
                    }
                    continue;
                }

                if ($kind === 'any_completed') {
                    $any_completed_clauses[] = $ref_ids;
                }
            }
        }

        if (!$has_constraints) {
            return false;
        }

        if (array_intersect_key($requires_completed, $requires_not_completed) !== []) {
            return true;
        }

        foreach ($any_completed_clauses as $clause) {
            if (
                $clause === []
                || array_all(
                    $clause,
                    static fn(int $ref_id): bool => isset($requires_not_completed[$ref_id])
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param AbstractCondition[] $conditions
     * @return int[]
     */
    protected function getOwningRefIds(array $conditions): array
    {
        $ref_ids = [];

        foreach ($conditions as $condition) {
            $ref_id = (int) $condition->getObjRefId();
            if ($ref_id > 0) {
                $ref_ids[$ref_id] = $ref_id;
            }
        }

        sort($ref_ids);

        return array_values($ref_ids);
    }
}
