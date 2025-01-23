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

use ILIAS\Setup\Environment;
use ILIAS\Setup\Migration;

class ilContentStyle9Migration implements Migration
{
    protected ilDBInterface $db;

    public function getLabel(): string
    {
        return "Migrate content styles to ILIAS 9.";
    }

    public function getDefaultAmountOfStepsPerRun(): int
    {
        return 50000;
    }

    public function getPreconditions(Environment $environment): array
    {
        return [
            new ilIniFilesLoadedObjective(),
            new ilDatabaseInitializedObjective(),
            new ilDatabaseUpdatedObjective(),
            new ilDatabaseUpdateStepsExecutedObjective(new ilResourceStorageDB90())
        ];
    }

    public function prepare(Environment $environment): void
    {
        $this->db = $environment->getResource(Environment::RESOURCE_DATABASE);
        /*
        $ilias_ini = $environment->getResource(Environment::RESOURCE_ILIAS_INI);
        $client_id = $environment->getResource(Environment::RESOURCE_CLIENT_ID);
        $data_dir = $ilias_ini->readVariable('clients', 'datadir');
        $client_data_dir = "{$data_dir}/{$client_id}";
        if (!defined("CLIENT_WEB_DIR")) {
            define("CLIENT_WEB_DIR", dirname(__DIR__, 4) . "/data/" . $client_id);
        }
        if (!defined("ILIAS_WEB_DIR")) {
            define("ILIAS_WEB_DIR", dirname(__DIR__, 4));
        }
        if (!defined("CLIENT_ID")) {
            define("CLIENT_ID", $client_id);
        }
        if (!defined("ILIAS_DATA_DIR")) {
            define("ILIAS_DATA_DIR", $data_dir);
        }*/
    }

    public function step(Environment $environment): void
    {
        $set = $this->db->queryF(
            "SELECT * FROM style_parameter " .
            " WHERE type = %s AND tag = %s LIMIT 1",
            ["text", "text"],
            ["text_block", "div"]
        );
        if ($rec = $this->db->fetchAssoc($set)) {
            // check, if a similar parameter is not already been set
            $set2 = $this->db->queryF(
                "SELECT * FROM style_parameter " .
                " WHERE style_id = %s AND tag = %s AND class = %s AND type = %s AND parameter = %s",
                ["integer", "text", "text", "text", "text"],
                [$rec["style_id"], "p", $rec["class"], "text_block", $rec["parameter"]]
            );
            if (!$this->db->fetchAssoc($set2)) {
                $this->db->update(
                    "style_parameter",
                    [
                    "tag" => ["text", "p"]
                ],
                    [    // where
                        "id" => ["integer", $rec["id"]]
                    ]
                );

                $this->db->update(
                    "style_data",
                    [
                    "uptodate" => ["integer", 0]
                ],
                    [    // where
                        "id" => ["integer", $rec["style_id"]]
                    ]
                );
            } else {
                $this->db->manipulateF(
                    "DELETE FROM style_parameter WHERE " .
                    " id = %s",
                    ["integer"],
                    [$rec["id"]]
                );
            }
        }
    }

    public function getRemainingAmountOfSteps(): int
    {
        $set = $this->db->queryF(
            "SELECT count(*) as amount FROM style_parameter " .
            " WHERE type = %s AND tag = %s",
            ["text", "text"],
            ["text_block", "div"]
        );
        if ($rec = $this->db->fetchAssoc($set)) {
            return (int) $rec["amount"];
        }

        return 0;
    }
}
