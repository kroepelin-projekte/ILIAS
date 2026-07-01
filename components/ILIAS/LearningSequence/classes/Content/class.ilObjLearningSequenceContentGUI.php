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

use ILIAS\HTTP\Wrapper\ArrayBasedRequestWrapper;
use ILIAS\Refinery\Factory;

class ilObjLearningSequenceContentGUI
{
    public const CMD_MANAGE_CONTENT = "manageContent";
    public const CMD_SAVE = "save";
    public const CMD_DELETE = "delete";
    public const CMD_CONFIRM_DELETE = "confirmDelete";
    public const CMD_CANCEL = "cancel";

    public const FIELD_ORDER = 'f_order';
    public const FIELD_ONLINE = 'f_online';
    public const FIELD_POSTCONDITION_TYPE = 'f_pct';

    public function __construct(
        protected ilObjLearningSequenceGUI $parent_gui,
        protected ilCtrl $ctrl,
        protected ilGlobalTemplateInterface $tpl,
        protected ilLanguage $lng,
        protected ilAccess $access,
        protected ilConfirmationGUI $confirmation_gui,
        protected LSItemOnlineStatus $ls_item_online_status,
        protected ArrayBasedRequestWrapper $post_wrapper,
        protected Factory $refinery,
        protected ILIAS\UI\Factory $ui_factory,
        protected ILIAS\UI\Renderer $ui_renderer
    ) {
    }

    public function executeCommand(): void
    {
        if (!$this->access->checkAccess("read", '', $this->parent_gui->getRefId())) {
            $this->tpl->setOnScreenMessage('info', sprintf(
                $this->lng->txt('msg_no_perm_read_item'),
                $this->parent_gui->getObjTitle()
            ), true);

            $this->ctrl->redirect($this->parent_gui, 'view');
        }

        $cmd = $this->ctrl->getCmd();

        switch ($cmd) {
            case self::CMD_CANCEL:
                $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
                break;
            case 'view':
                $cmd = self::CMD_MANAGE_CONTENT;
                // no break
            case self::CMD_MANAGE_CONTENT:
            case self::CMD_SAVE:
            case self::CMD_DELETE:
            case self::CMD_CONFIRM_DELETE:
                $this->$cmd();
                break;
            default:
                throw new ilException("ilObjLearningSequenceContentGUI: Command not supported: $cmd");
        }
    }

    protected function manageContent(): void
    {
        // Adds a btn to the gui which allows adding possible objects.
        $this->parent_gui->showPossibleSubObjects();

        $data = $this->parent_gui->getObject()->getLSItems();
        // Sadly, ilTable2 only wants an array for fillRow, so we need to wrap this...
        $data = array_map(fn($s) => [$s], $data);
        $this->renderTable($data);
    }

    protected function renderTable(array $ls_items): void
    {
        $alert_icon = $this->ui_renderer->render(
            $this->ui_factory->symbol()->icon()
                ->custom(ilUtil::getImagePath("standard/icon_alert.svg"), $this->lng->txt("warning"))
                ->withSize('small')
        );
        $table = new ilObjLearningSequenceContentTableGUI(
            $this,
            $this->parent_gui,
            self::CMD_MANAGE_CONTENT,
            $this->ctrl,
            $this->lng,
            $this->access,
            $this->ui_factory,
            $this->ui_renderer,
            $this->ls_item_online_status,
            $alert_icon
        );

        $table->setData($ls_items);
        $table->addMultiCommand(self::CMD_CONFIRM_DELETE, $this->lng->txt("delete"));

        $table->addCommandButton(self::CMD_SAVE, $this->lng->txt("save"));

        // -----------------------------------------------------------------
        // Ticket-Anforderung:
        // Unterhalb der bestehenden (alten) Content-Management-Tabelle soll
        // zusätzlich eine Kitchen-Sink Presentation-Table mit Dummy-Daten
        // angezeigt werden.
        //
        // Wichtig:
        // - Wir ersetzen die bestehende Tabelle NICHT.
        // - Wir hängen lediglich zusätzlich HTML darunter.
        // -----------------------------------------------------------------
        // Table + DTOs laden.
        // Hinweis: In diesem Prototypen verwenden wir bewusst include_once,
        // damit klar ist, woher die Klassen kommen (und weil es Dummy-Code ist).
        include_once __DIR__ . '/../ALP/class.ilLearningSequenceALPConditionDTO.php';
        include_once __DIR__ . '/../ALP/class.ilLearningSequenceALPInformationDTO.php';
        include_once __DIR__ . '/../ALP/class.ilLearningSequenceALPContentManagementObjectDTO.php';
        include_once __DIR__ . '/../ALP/class.ilLearningSequenceALPContentManagementPresentationTable.php';

        // Ticket-Anforderung:
        // Das CSS liegt im Component-Ressourcen-Bereich und wird über LearningSequence.php
        // als PublicAsset registriert. Dadurch landet es (wie andere Assets auch) unter
        // /assets/css/...
        $this->tpl->addCss('assets/css/alp_content_management_presentation.css');

        // -------------------------------------------------------------
        // Ticket-Anforderung: Datenquelle aufbrechen
        // - Die GUI baut mehrere DTOs nach Best Practice.
        // - Die Table bekommt NUR noch DTOs übergeben und rendert diese.
        // -------------------------------------------------------------

        // Dummy-DTOs: später werden diese Daten aus dem echten Modell/DB kommen.
        $objects = [
            new ilLearningSequenceALPContentManagementObjectDTO(
                title: 'Object A',
                link: '#',
                description: 'Dummy Beschreibung für Object A. Hier steht ein kurzer Beispieltext.',
                icon_path: ilUtil::getImagePath('standard/icon_tst.svg'),
                selected_number: 2,
                input_conditions: [
                    // Logic Gates: Wert soll aus and/or/not stammen.
                    new ilLearningSequenceALPConditionDTO('Logic Gates', 'and'),
                    // passed Subset: Zahl + Dummy-Objektnamen.
                    new ilLearningSequenceALPConditionDTO('passed Subset', '2 (Object A, Object B)'),
                ],
                output_conditions: [
                    // Output als Key-Value: z.B. "LP => passed".
                    new ilLearningSequenceALPConditionDTO('LP', 'passed'),
                    new ilLearningSequenceALPConditionDTO('Tutor', 'yes'),
                ],
                information: new ilLearningSequenceALPInformationDTO(
                    online: 'yes',
                    start_object: 'yes',
                    end_object: 'no',
                    previous_object: 'Object A',
                    next_object: 'Object C'
                ),
                // Option A: nur dieses Objekt ist Start.
                is_start_object: true,
                is_end_object: false,
                // Für das Action-Menü (Set Online/Set Offline)
                is_online: true
            ),
            new ilLearningSequenceALPContentManagementObjectDTO(
                title: 'Object B',
                link: '#',
                description: 'Dummy Beschreibung für Object B. Hier steht ein weiterer Beispieltext.',
                icon_path: ilUtil::getImagePath('standard/icon_file.svg'),
                selected_number: 1,
                input_conditions: [
                    // point allocation: Zahl.
                    new ilLearningSequenceALPConditionDTO('point allocation', '15'),
                ],
                output_conditions: [
                    new ilLearningSequenceALPConditionDTO('Point Allocation', '30'),
                ],
                information: new ilLearningSequenceALPInformationDTO(
                    online: 'no',
                    start_object: 'no',
                    end_object: 'no',
                    previous_object: 'Object A',
                    next_object: 'Object C'
                ),
                is_start_object: false,
                is_end_object: false,
                is_online: false
            ),
            new ilLearningSequenceALPContentManagementObjectDTO(
                title: 'Object C',
                link: '#',
                description: 'Dummy Beschreibung für Object C. Noch ein Beispieltext zur Einordnung.',
                icon_path: ilUtil::getImagePath('standard/icon_pg.svg'),
                selected_number: 3,
                input_conditions: [
                    new ilLearningSequenceALPConditionDTO('Logic Gates', 'or'),
                    new ilLearningSequenceALPConditionDTO('passed Subset', '3 (Object A, Object B, Object C)'),
                    new ilLearningSequenceALPConditionDTO('point allocation', '7'),
                ],
                output_conditions: [
                    new ilLearningSequenceALPConditionDTO('Always', 'true'),
                ],
                information: new ilLearningSequenceALPInformationDTO(
                    online: 'yes',
                    start_object: 'no',
                    end_object: 'yes',
                    previous_object: 'Object A',
                    next_object: 'Object C'
                ),
                // Option A: nur dieses Objekt ist Ende.
                is_start_object: false,
                is_end_object: true,
                is_online: true
            ),
        ];

        $dummy_table = new ilLearningSequenceALPContentManagementPresentationTable(
            $this->ui_factory,
            $this->ui_renderer,
            $objects
        );

        $html = $table->getHtml();
        $html .= $dummy_table->render();

        $this->tpl->setContent($html);
    }

    /**
     * Handle the confirmDelete command
     */
    protected function confirmDelete(): void
    {
        $this->parent_gui->deleteObject();
    }

    protected function delete(): void
    {
        $ref_ids = $this->post_wrapper->retrieve(
            "id",
            $this->refinery->kindlyTo()->listOf($this->refinery->kindlyTo()->int())
        );

        $this->parent_gui->getObject()->deletePostConditionsForSubObjects($ref_ids);

        $this->tpl->setOnScreenMessage("success", $this->lng->txt('entries_deleted'), true);
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }

    /**
     * @return array<"value" => "option_text">
     */
    public function getPossiblePostConditionsForType(string $type): array
    {
        return $this->parent_gui->getObject()->getPossiblePostConditionsForType($type);
    }


    public function getFieldName(string $field_name, int $ref_id): string
    {
        return implode('_', [$field_name, (string) $ref_id]);
    }

    protected function save(): void
    {
        $data = $this->parent_gui->getObject()->getLSItems();
        $r = $this->refinery;

        $updated = [];
        foreach ($data as $lsitem) {
            $ref_id = $lsitem->getRefId();
            $online = $this->getFieldName(self::FIELD_ONLINE, $ref_id);
            $order = $this->getFieldName(self::FIELD_ORDER, $ref_id);
            $condition_type = $this->getFieldName(self::FIELD_POSTCONDITION_TYPE, $ref_id);

            $condition_type = $this->post_wrapper->retrieve($condition_type, $r->kindlyTo()->string());
            $online = $this->post_wrapper->retrieve($online, $r->byTrying([$r->kindlyTo()->bool(), $r->always(false)]));
            $order = $this->post_wrapper->retrieve(
                $order,
                $r->in()->series([
                    $r->kindlyTo()->string(),
                    $r->custom()->transformation(fn($v) => ltrim($v, '0')),
                    $r->kindlyTo()->int()
                ])
            );

            $condition = $lsitem->getPostCondition()
                ->withConditionOperator($condition_type);
            $updated[] = $lsitem
                ->withOnline($online)
                ->withOrderNumber($order)
                ->withPostCondition($condition);
        }

        $this->parent_gui->getObject()->storeLSItems($updated);
        $this->tpl->setOnScreenMessage("success", $this->lng->txt('entries_updated'), true);
        $this->ctrl->redirect($this, self::CMD_MANAGE_CONTENT);
    }
}
