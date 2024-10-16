<?php

/* Copyright (c) 1998-2019 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * Exporter class for html learning modules
 *
 * @author Stefan Meyer <meyer@leifos.com>
 * @version $Id: $
 * @ingroup ModulesLearningModule
 */
class ilLearningModuleExporter extends ilXmlExporter
{
    private $ds;
    /**
     * @var ilLearningModuleExportConfig
     */
    private $config;

    /**
     * Initialisation
     */
    public function init()
    {
        $this->ds = new ilLearningModuleDataSet();
        $this->ds->setExportDirectories($this->dir_relative, $this->dir_absolute);
        $this->ds->setDSPrefix("ds");
        $this->config = $this->getExport()->getConfig("Modules/LearningModule");
        if ($this->config->getMasterLanguageOnly()) {
            $conf = $this->getExport()->getConfig("Services/COPage");
            $conf->setMasterLanguageOnly(true, $this->config->getIncludeMedia());
            $this->ds->setMasterLanguageOnly(true);
        }
    }

    /**
     * Get tail dependencies
     *
     * @param		string		entity
     * @param		string		target release
     * @param		array		ids
     * @return		array		array of array with keys "component", entity", "ids"
     */
    public function getXmlExportTailDependencies($a_entity, $a_target_release, $a_ids)
    {
        $deps = array();

        if ($a_entity == "lm") {
            $md_ids = array();

            // lm related ids
            foreach ($a_ids as $id) {
                $md_ids[] = $id . ":0:lm";
            }

            // chapter related ids
            foreach ($a_ids as $id) {
                $chaps = ilLMObject::getObjectList($id, "st");
                foreach ($chaps as $c) {
                    $md_ids[] = $id . ":" . $c["obj_id"] . ":st";
                }
            }

            // page related ids
            $pg_ids = array();
            foreach ($a_ids as $id) {
                $pages = ilLMPageObject::getPageList($id);
                foreach ($pages as $p) {
                    $pg_ids[] = "lm:" . $p["obj_id"];
                    $md_ids[] = $id . ":" . $p["obj_id"] . ":pg";
                }
            }

            // style, multilang, metadata per page/chap?

            $deps = array(
                array(
                    "component" => "Services/COPage",
                    "entity" => "pg",
                    "ids" => $pg_ids),
                array(
                    "component" => "Services/MetaData",
                    "entity" => "md",
                    "ids" => $md_ids),
            );

            if (!$this->config->getMasterLanguageOnly()) {
                $deps[] = array(
                    "component" => "Services/Object",
                    "entity" => "transl",
                    "ids" => $md_ids);
                $deps[] = array(
                    "component" => "Services/Object",
                    "entity" => "transl_entry",
                    "ids" => $a_ids);
            }
            $deps[] = array(
                "component" => "Services/Object",
                "entity" => "tile",
                "ids" => $a_ids);

            // help export
            foreach ($a_ids as $id) {
                if (ilObjContentObject::isOnlineHelpModule($id, true)) {
                    $deps[] = array(
                        "component" => "Services/Help",
                        "entity" => "help",
                        "ids" => array($id));
                }
            }

            // style
            foreach ($a_ids as $id) {
                if (($s = ilObjContentObject::_lookupStyleSheetId($id)) > 0) {
                    $deps[] = array(
                        "component" => "Services/Style",
                        "entity" => "sty",
                        "ids" => $s
                    );
                }
            }
        }

        return $deps;
    }



    /**
     * Get xml representation
     *
     * @param	string		entity
     * @param	string		target release
     * @param	string		id
     * @return	string		xml string
     */
    public function getXmlRepresentation($a_entity, $a_schema_version, $a_id)
    {
        // workaround: old question export
        $q_ids = array();
        $pages = ilLMPageObject::getPageList($a_id);
        foreach ($pages as $p) {
            $langs = array("-");
            if (!$this->config->getMasterLanguageOnly()) {
                $trans = ilPageObject::lookupTranslations("lm", $p["obj_id"]);
                foreach ($trans as $t) {
                    if ($t != "-") {
                        $langs[] = $t;
                    }
                }
            }
            foreach ($langs as $l) {
                // collect questions
                foreach (ilPCQuestion::_getQuestionIdsForPage("lm", $p["obj_id"], $l) as $q_id) {
                    $q_ids[$q_id] = $q_id;
                }
            }
        }
        if (count($q_ids) > 0) {
            $dir = $this->getExport()->export_run_dir;
            $qti_file = fopen($dir . "/qti.xml", "w");
            $pool = new ilObjQuestionPool();
            fwrite($qti_file, $pool->questionsToXML($q_ids));
            fclose($qti_file);
        }

        return $this->ds->getXmlRepresentation($a_entity, $a_schema_version, $a_id, "", true, true);
    }

    /**
     * Returns schema versions that the component can export to.
     * ILIAS chooses the first one, that has min/max constraints which
     * fit to the target release. Please put the newest on top.
     *
     * @return
     */
    public function getValidSchemaVersions($a_entity)
    {
        return array(
            "5.4.0" => array(
                "namespace" => "http://www.ilias.de/Modules/LearningModule/lm/5_4",
                "xsd_file" => "ilias_lm_5_4.xsd",
                "uses_dataset" => true,
                "min" => "5.4.0",
                "max" => ""),
            "5.1.0" => array(
                "namespace" => "http://www.ilias.de/Modules/LearningModule/lm/5_1",
                "xsd_file" => "ilias_lm_5_1.xsd",
                "uses_dataset" => true,
                "min" => "5.1.0",
                "max" => ""),
            "4.1.0" => array(
                "namespace" => "http://www.ilias.de/Modules/LearningModule/lm/4_1",
                "xsd_file" => "ilias_lm_4_1.xsd",
                "uses_dataset" => false,
                "min" => "4.1.0",
                "max" => "")
        );
    }
}
