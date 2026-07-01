<?php

/**
 * DTO: Ein Eintrag/Objekt in der Presentation-Table.
 */

declare(strict_types=1);

final class ilLearningSequenceALPContentManagementObjectDTO
{
    /**
     * @param ilLearningSequenceALPConditionDTO[] $input_conditions
     * @param ilLearningSequenceALPConditionDTO[] $output_conditions
     */
    public function __construct(
        public readonly string $title,
        public readonly string $link,
        public readonly string $description,
        public readonly string $icon_path,
        public readonly int $selected_number,
        public readonly array $input_conditions,
        public readonly array $output_conditions,
        public readonly ilLearningSequenceALPInformationDTO $information,
        // ------------------------------------------------------------
        // Ticket-Anforderung (Option A):
        // Start/Ende sollen NICHT mehr als redundante yes/no-Zeile in jeder
        // Info-Box erscheinen, sondern als Badge direkt beim Titel.
        //
        // Wichtig:
        // - Nur EIN Objekt kann Start sein.
        // - Nur EIN Objekt kann End sein.
        //
        // Daher modellieren wir das als Flags am Objekt.
        // ------------------------------------------------------------
        public readonly bool $is_start_object,
        public readonly bool $is_end_object,

        // Ticket-Anforderung (Action-Menü):
        // Im Action-Menü soll es einen Toggle geben (Set Online/Set Offline).
        // Damit wir das sauber (ohne String-Vergleiche) bauen können,
        // speichern wir den Online-Status zusätzlich als bool.
        public readonly bool $is_online
    ) {
    }
}
