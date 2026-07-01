<?php

/**
 * DTO: Daten für die rechte "Information"-Box.
 */

declare(strict_types=1);

final class ilLearningSequenceALPInformationDTO
{
    public function __construct(
        public readonly string $online,
        public readonly string $start_object,
        public readonly string $end_object,
        public readonly string $previous_object,
        public readonly string $next_object
    ) {
    }
}
