<?php

/**
 * DTO: Eine einzelne Condition als Key-Value-Paar.
 *
 * Warum ein DTO?
 * - Wir wollen die Dummy-Daten sauber von der Darstellung trennen.
 * - Die GUI baut die Daten (DTOs) und übergibt sie an die Table.
 * - Die Table rendert nur noch – ohne "wissen" zu müssen, woher Daten kommen.
 */

declare(strict_types=1);

final class ilLearningSequenceALPConditionDTO
{
    public function __construct(
        public readonly string $condition,
        public readonly string $value
    ) {
    }
}
