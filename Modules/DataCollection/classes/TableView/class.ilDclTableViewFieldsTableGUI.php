<?php
include_once('./Services/Table/classes/class.ilTable2GUI.php');
include_once('./Modules/DataCollection/classes/TableView/class.ilDclTableViewFieldSetting.php');
include_once('./Modules/DataCollection/classes/Fields/class.ilDclFieldFactory.php');

/**
 * Class ilDclTableViewEditFieldsTableGUI
 *
 * @author  Theodor Truffer <tt@studer-raimann.ch>
 * @ingroup ModulesDataCollection
 */
class ilDclTableViewEditFieldsTableGUI extends ilTable2GUI
{

    public function __construct(ilDclTableViewEditGUI $a_parent_obj, ilDclTableView $tableview)
    {
        global $lng, $ilCtrl;
        parent::__construct($a_parent_obj);

        $this->setId('dcl_tableviews');
        $this->setTitle($lng->txt('dcl_tableview_fieldsettings'));
        $this->addColumn($lng->txt('dcl_fieldtitle'), NULL, 'auto');
        $this->addColumn($lng->txt('dcl_visible'), NULL, 'auto');
        $this->addColumn($lng->txt('dcl_filter'), NULL, 'auto');
        $this->addColumn($lng->txt('dcl_std_filter'), NULL, 'auto');
        $this->addColumn($lng->txt('dcl_filter_changeable'), NULL, 'auto');


        $ilCtrl->saveParameter($this, 'tableview_id');
        $this->setFormAction($ilCtrl->getFormActionByClass('ildcltablevieweditgui'));
        $this->addCommandButton('saveTable', $lng->txt('dcl_save'));

        $this->setExternalSegmentation(true);
        $this->setExternalSorting(true);

        $this->setRowTemplate('tpl.tableview_fields_row.html', 'Modules/DataCollection');
        $this->setTopCommands(true);
        $this->setEnableHeader(true);
        $this->setShowRowsSelector(false);
        $this->setShowTemplates(false);
        $this->setEnableHeader(true);
        $this->setEnableTitle(true);
        $this->setDefaultOrderDirection('asc');

        $this->setData(ilDclTableViewFieldSetting::getAllForTableViewId($_GET['tableview_id']));
    }

    /**
     * @param ilDclTableViewFieldSetting $a_set
     */
    public function fillRow($a_set)
    {
        $field = $a_set->getFieldObject();
        
        $this->tpl->setVariable('FIELD_TITLE', $field->getTitle());
        $this->tpl->setVariable('ID', $a_set->getId());
        $this->tpl->setVariable('FIELD_ID', $a_set->getField());
        $this->tpl->setVariable('VISIBLE', $a_set->isVisible() ? 'checked' : '');
        if ($field->getDatatypeId() != ilDclDatatype::INPUTFORMAT_MOB
            && $field->getDatatypeId() != ilDclDatatype::INPUTFORMAT_FILE
            && $field->getDatatypeId() != ilDclDatatype::INPUTFORMAT_FORMULA
            && $field->getDatatypeId() != ilDclDatatype::INPUTFORMAT_PLUGIN)    //TODO: find better way, also handle reference field referencing a MOB
        {
            $this->tpl->setVariable('IN_FILTER', $a_set->isInFilter() ? 'checked' : '');
            $this->tpl->setVariable('FILTER_VALUE', $this->getStandardFilterHTML($field, $a_set->getFilterValue()));
            $this->tpl->setVariable('FILTER_CHANGEABLE', $a_set->isFilterChangeable() ? 'checked' : '');
        }
        else
        {
            $this->tpl->setVariable('NO_FILTER', '');
        }

    }

    protected function getStandardFilterHTML(ilDclBaseFieldModel $field, $value)
    {
        $field_representation = ilDclFieldFactory::getFieldRepresentationInstance($field);
        $field_representation->addFilterInputFieldToTable($this);
        $filter = end($this->filters);
        $this->filters = null;
        $this->SetFilterValue($filter, $value);
        return $filter->render();
    }

    /**
     * @param ilFormPropertyGUI $a_item
     * @param mixed $a_value
     */
    protected function SetFilterValue(ilFormPropertyGUI $a_item, $a_value)
    {
        //TODO: find nicer way
        if ($a_item instanceof ilCombinationInputGUI && is_array($a_value))
        {
            foreach ($a_value as $key => $value)
            {
                $subitem = $a_item->getCombinationItem($key);
                $subitem->setParent($a_item);
                $subitem->setValueByArray(array($subitem->getPostVar() => $value));
            }
        }
        else
        {
            parent::SetFilterValue($a_item, $a_value);
        }
    }


}