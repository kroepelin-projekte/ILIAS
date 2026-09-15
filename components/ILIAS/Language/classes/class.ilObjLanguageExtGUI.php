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

use ILIAS\FileUpload\DTO\ProcessingStatus;
use ILIAS\FileUpload\Location;
use ILIAS\Language\Activities\AddLanguageEntry;
use ILIAS\Language\Activities\SetLanguageTranslationEnabled;
use ILIAS\Language\Activities\SafeToDisplayActivityError;
use ILIAS\Language\RendersActivityErrors;
use ILIAS\UI\URLBuilder;
use ILIAS\UI\URLBuilderToken;
use ILIAS\UI\Component\Input\Container\Filter\Filter as ilFilter;
use ILIAS\UI\Implementation\Component\Table as DataTable;

/**
* Class ilObjLanguageExtGUI
*
* This class is a replacement for ilObjLanguageGUI
* which is currently not used in ILIAS.
*
* @author Fred Neumann <fred.neumann@fim.uni-erlangen.de>
* @version $Id: class.ilObjLanguageExtGUI.php $
*
* @ilCtrl_Calls ilObjLanguageExtGUI:
* @ilCtrl_IsCalledBy ilObjLanguageExtGUI: ilDashboardGUI
*
* @ingroup ServicesLanguage
*/
class ilObjLanguageExtGUI extends ilObjectGUI
{
    use RendersActivityErrors;

    private const string ILIAS_LANGUAGE_MODULE = "components/ILIAS/Language";
    private string $langmode;
    private readonly AddLanguageEntry $add_language_entry;
    private readonly SetLanguageTranslationEnabled $set_language_translation_enabled;
    private URLBuilder $url_builder;
    private URLBuilderToken $action_token;
    private URLBuilderToken $row_id_token;
    private ilLanguageEntriesTable $entries_table;

    /**
    * Constructor
    *
    * Note:
    * The GET param 'obj_id' is the language object id
    * The GET param 'ref_id' is the language folder (if present)
    *
    * @param    mixed       $a_data (ignored)
    * $a_id         id (ignored)
    * $a_call_by_reference     call-by-reference (ignored)
    */
    public function __construct($a_data, int $a_id = 0, bool $a_call_by_reference = false)
    {
        global $DIC;
        $ilClientIniFile = $DIC->clientIni();
        $ilCtrl = $DIC->ctrl();
        $lng = $DIC->language();
        $this->http = $DIC['http'];
        $this->refinery = $DIC['refinery'];
        // Resolved once here, per the constructor-injection idiom already
        // used by sibling GUI classes in this component (see
        // ilObjLanguageFolderGUI::__construct()) - action methods must not
        // reach into `global $DIC` themselves.
        $this->add_language_entry = $DIC[AddLanguageEntry::class];
        $this->set_language_translation_enabled = $DIC[SetLanguageTranslationEnabled::class];
        // Used exclusively by activityErrorMessage() (see RendersActivityErrors) -
        // resolved once here, per the same idiom as the Activities above.
        $this->activity_error_logger = $DIC->logger()->lang();

        // language maintenance strings are defined in administration
        $lng->loadLanguageModule("administration");
        $lng->loadLanguageModule("meta");

        //  view mode ('translate' or empty) determins available table filters
        $ilCtrl->saveParameter($this, "view_mode");

        // type and id of get the bound object
        $this->type = "lng";
        $obj_id_get = 0;

        if ($this->http->wrapper()->query()->has("obj_id")) {
            $obj_id_get = $this->http->wrapper()->query()->retrieve("obj_id", $this->refinery->kindlyTo()->int());
        } elseif ($this->http->wrapper()->query()->has("language_folder_obj_ids")) {
            $obj_id_get = $this->http->wrapper()->query()->retrieve("language_folder_obj_ids", $this->refinery->kindlyTo()->int());
        }

        if (!$this->id = $obj_id_get) {
            $this->id = ilObjLanguageAccess::_lookupId($lng->getUserLanguage());
        }

        // do all generic GUI initialisations
        parent::__construct($a_data, $this->id, false, true);

        // initialize the array to store session variables for extended language maintenance
        if (!is_array($this->getSession())) {
            ilSession::set("lang_ext_maintenance", array());
        }

        // read the lang mode
        $this->langmode = $ilClientIniFile->readVariable("system", "LANGMODE");

        // URL/action wiring for the KS Data Table's row action (see
        // ilLanguageEntriesTable) and the query-token dispatch in
        // executeCommand() - same idiom as ilObjLanguageFolderGUI.
        $here_uri = new ILIAS\Data\Factory()->uri($this->request->getUri()->__toString());
        $url_builder = new URLBuilder($here_uri);
        [$url_builder, $action_token, $row_id_token] = $url_builder->acquireParameters(
            ['lang_entries'],
            "table_action", //this is the actions's parameter name
            "entry_names"   //this is the parameter name to be used for row-ids
        );
        $this->url_builder = $url_builder;
        $this->action_token = $action_token;
        $this->row_id_token = $row_id_token;

        /** @var ilObjLanguageExt $obj */
        $obj = $this->object;

        $this->entries_table = new ilLanguageEntriesTable(
            $obj,
            $lng,
            $this->ui_factory,
            $url_builder,
            $action_token,
            $row_id_token
        );
    }

    /**
    * Assign the extended language object
    *
    * Overwritten from ilObjectGUI to use the extended language object.
    * (Will be deleted when ilObjLanguageExt is merged with ilObjLanguage)
    */
    protected function assignObject(): void
    {
        $this->object = new ilObjLanguageExt($this->id);
    }

    /**
     * get the language object id (needed for filter serialization)
     * Return language object id
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
    * execute command
    */
    public function executeCommand(): void
    {
        global $DIC;
        $ilHelp = $DIC->help();

        if (!ilObjLanguageAccess::_checkMaintenance()) {
            $this->error->raiseError($this->lng->txt("permission_denied"), $this->error->MESSAGE);
            exit;
        }

        // Query-token dispatch for the entries table's row/multi action
        // (see ilLanguageEntriesTable::getTable()), analog to
        // ilObjLanguageFolderGUI::executeCommand(). Unlike that dispatch,
        // this returns immediately instead of falling through to the
        // regular $cmd below: editSelectedObject() does not always
        // redirect (it may render the edit form directly), so falling
        // through would overwrite that content with the default "view".
        if ($action = $this->getCommandFromQueryToken($this->action_token->getName())) {
            switch ($action) {
                case 'edit':
                    $names = $this->getIdsFromQueryToken();
                    $this->editSelectedObject($names);
                    $ilHelp->setScreenIdComponent("lng");
                    return;
            }
        }

        $cmd = $this->ctrl->getCmd("view") . "Object";
        $this->$cmd();

        $ilHelp->setScreenIdComponent("lng");
    }

    private function getCommandFromQueryToken(string $param): ?string
    {
        if (!$this->request_wrapper->has($param)) {
            return null;
        }
        $trafo = $this->refinery->byTrying([
            $this->refinery->kindlyTo()->null(),
            $this->refinery->kindlyTo()->string()
        ]);
        return $this->request_wrapper->retrieve($param, $trafo);
    }

    /**
     * The raw lang_entries_entry_names[] query values, normalised to a
     * list<string> - never the untransformed raw value. A genuine array
     * (the expected shape, from the table's own row/multi-action links) is
     * mapped element-wise to string; a bare string (e.g.
     * "...&lang_entries_entry_names=foo" without "[]") is wrapped into a
     * single-element list instead of causing a TypeError further down;
     * anything else (missing, or a shape that is neither) resolves to [].
     * This only normalises the *shape* - the individual values are still
     * validated against actually-existing entries in
     * initEditEntriesForm(), which is what actually closes the reflected-
     * XSS hole a raw, unvalidated name would otherwise open there (see
     * that method's own docblock).
     */
    private function getIdsFromQueryToken(): array
    {
        if (!$this->request_wrapper->has($this->row_id_token->getName())) {
            return [];
        }

        return $this->request_wrapper->retrieve(
            $this->row_id_token->getName(),
            $this->refinery->byTrying([
                $this->refinery->container()->mapValues(
                    $this->refinery->kindlyTo()->string()
                ),
                $this->refinery->custom()->transformation(
                    static function ($value): array {
                        if (!is_string($value)) {
                            throw new \UnexpectedValueException('Not a string.');
                        }
                        return [$value];
                    }
                ),
                $this->refinery->always([]),
            ])
        );
    }

    /**
    * Cancel the current action
    */
    public function cancelObject(): void
    {
        $this->viewObject();
    }

    /**
     * Build the Filter used both to render the entries table (view) and to
     * resolve the ALL_OBJECTS multi-action selection (editSelectedObject()) -
     * same $filter_id in both cases, so the latter picks up the very same
     * values the former was last rendered with. The actual mechanism is
     * ilUIFilterService::standard(): every call for a given $filter_id
     * re-applies each input's previously stored value from the session
     * (`$this->session->getValue($filter_id, $input_id)`, written back by
     * writeFilterStatusToSession()/handleApplyAndToggle() on the request
     * that applied/toggled the filter) - independent of which URL or
     * request triggered this particular call. This is genuinely
     * session-persisted filter state, not something carried in the edit
     * action's own URL/query string (that URL, built via $this->url_builder
     * in the constructor, only ever carries the "table_action"/"entry_names"
     * tokens - it has no filter value in it at all). Fields 1-4
     * (pattern/module/identifier/mode) are only relevant in the manual
     * filtering view mode - page translation mode filters by a fixed list
     * of modules/topics instead (see
     * ilLanguageEntriesTable::resolveModulesAndTopics()), so those fields
     * are not offered there.
     *
     * $filter_id/table id use ilLanguageEntriesTable::tableId() - the single
     * source of truth for this id, also used by ilLanguageEntriesTable::
     * getTable() itself and by resetEntriesTableRangeIfRequested() below.
     * It keys on ilObjLanguageAccess::_isPageTranslation() (the actual,
     * request-dependent page-translation/admin distinction), not
     * $this->langmode - langmode is an installation-wide constant (see the
     * constructor), so keying the id on it would merge the admin and
     * page-translation views into a single table/filter state instead of
     * keeping them separate, as the pre-KS ilLanguageExtTableGUI::setId()
     * deliberately did.
     *
     * global $DIC remains here as documented remaining debt of this
     * component's ongoing Component Revision (see ROADMAP.md) - not
     * addressed by this change.
     */
    private function initFilter(): ilFilter
    {
        global $DIC;

        $field_factory = $this->ui_factory->input()->field();
        $filter_id = ilLanguageEntriesTable::tableId();
        $fields = [];

        if (!ilObjLanguageAccess::_isPageTranslation()) {
            $fields['pattern'] = $field_factory->text($this->lng->txt('search'));

            $module_options = ['all' => $this->lng->txt('language_all_modules')];
            foreach (ilObjLanguageExt::_getModules($this->lng->getLangKey()) as $module) {
                $module_options[$module] = $module;
            }
            $fields['module'] = $field_factory
                ->select(ucfirst($this->lng->txt('module')), $module_options)
                ->withValue('administration');

            $fields['identifier'] = $field_factory->text(ucfirst($this->lng->txt('identifier')));

            $mode_options = [
                'all' => $this->lng->txt('language_scope_global'),
                'changed' => $this->lng->txt('language_scope_local'),
            ];
            if ($this->langmode) {
                $mode_options['added'] = $this->lng->txt('language_scope_added');
            }
            $mode_options['unchanged'] = $this->lng->txt('language_scope_unchanged');
            $mode_options['equal'] = $this->lng->txt('language_scope_equal');
            $mode_options['different'] = $this->lng->txt('language_scope_different');
            $mode_options['commented'] = $this->lng->txt('language_scope_commented');
            if ($this->langmode) {
                $mode_options['dbremarks'] = $this->lng->txt('language_scope_dbremarks');
            }
            $mode_options['conflicts'] = $this->lng->txt('language_scope_conflicts');

            $fields['mode'] = $field_factory
                ->select($this->lng->txt('filter'), $mode_options)
                ->withValue('all');
        }

        $compare_options = [];
        foreach ($this->lng->getInstalledLanguages() as $lang_key) {
            $compare_options[$lang_key] = $this->lng->txt('meta_l_' . $lang_key);
        }
        $fields['compare'] = $field_factory
            ->select($this->lng->txt('language_compare'), $compare_options)
            ->withValue($this->lng->getDefaultLanguage());

        return $DIC->uiService()->filter()->standard(
            $filter_id,
            $this->ctrl->getLinkTarget($this, 'view'),
            $fields,
            array_fill(0, count($fields), true),
            true,
            true
        );
    }

    /**
     * getInputs()/getData() on the Filter itself are not gated by
     * isActivated() at all (see the concrete Filter\Standard
     * implementation) - only ilUIFilterService::getData() is (`if
     * ($filter->isActivated()) { ...$i->getValue()... }`, `null`
     * otherwise). Rather than route through that service (which would
     * additionally reshape $filter_data from array<string, FormInput> to
     * array<string, mixed>, a shape ilLanguageEntriesTable::filterValue()
     * and its whole test suite are built around), this reproduces the
     * exact same activation gating directly: a deactivated filter resolves
     * to `null`, which filterValue()'s existing `!is_array($filter_data)`
     * guard already treats as "field missing -> use the default" for
     * every field - i.e. effectively no filter applied, matching KS's own
     * standard behaviour for a deactivated filter.
     */
    private function resolveFilterData(ilFilter $filter): ?array
    {
        return $filter->isActivated() ? $filter->getInputs() : null;
    }

    /**
    * Show the edit screen
    */
    public function viewObject(): void
    {
        $filter = $this->initFilter();
        $filter_data = $this->resolveFilterData($filter);

        $this->resetEntriesTableRangeIfRequested();

        $notice = $this->entries_table->getConflictsNotice($filter_data);
        if ($notice !== null) {
            $this->tpl->setOnScreenMessage($notice['type'], $notice['text'], false);
        }

        $table = $this->entries_table->getTable($filter_data)
            ->withFilter($filter_data)
            ->withRequest($this->request);

        // page translation mode: the missing-entries list below the table
        // is still derived from the modules/topics saved in the session,
        // exactly like before.
        $missing_entries = [];
        if (ilObjLanguageAccess::_isPageTranslation()) {
            $modules = ilObjLanguageAccess::_getSavedModules();
            $topics = ilObjLanguageAccess::_getSavedTopics();

            $translations = ilObjLanguageExt::_getValues($this->object->key, $modules, $topics);
            $db_found = [];
            foreach ($translations as $name => $translation) {
                $keys = explode($this->lng->separator, $name);
                $db_found[] = $keys[1];
            }
            $missing_entries = array_diff($topics, $db_found);
        }

        $this->tpl->setContent(
            $this->ui_renderer->render([$filter, $table]) . $this->buildMissingEntries($missing_entries)
        );
    }

    /**
     * ilObjLanguageAccess::_getTranslationLink() (the page-footer link that
     * leads here) appends "&reset_offset=true" - the pre-KS
     * ilLanguageExtTableGUI/ilTable2GUI evaluated this via
     * $table_gui->resetOffset(). KS Data Table has no equivalent public API:
     * its whole per-table view-control state (range/order/column selection)
     * is persisted as one array under one session key,
     * Table\Data::STORAGE_ID_PREFIX . <table id> (see the
     * ILIAS\UI\Storage implementation wired in
     * components/ILIAS/Authentication/Authentication.php, backed by plain
     * ilSession::get/set/clear()) - there is no finer-grained accessor for
     * just the stored range. Clearing that whole key is therefore the
     * least invasive reproduction available: it also drops the stored
     * order/column selection, not just the range, which is accepted here
     * (this table's sort order is not worth preserving across a fresh
     * "translate this page" link). Only relevant in page-translation mode -
     * the only mode _getTranslationLink() ever leads to (so
     * ilLanguageEntriesTable::tableId() below always resolves to the
     * "...trans" id in this branch).
     */
    private function resetEntriesTableRangeIfRequested(): void
    {
        if (!ilObjLanguageAccess::_isPageTranslation()) {
            return;
        }

        $reset_offset = $this->request_wrapper->has('reset_offset')
            && $this->request_wrapper->retrieve('reset_offset', $this->refinery->kindlyTo()->bool());

        if ($reset_offset) {
            ilSession::clear(DataTable\Data::STORAGE_ID_PREFIX . ilLanguageEntriesTable::tableId());
        }
    }

    /**
     * Show the edit form for a set of entries selected in the entries
     * table (single row action or checkbox multi-action, see
     * ilLanguageEntriesTable::getTable()). ALL_OBJECTS is resolved against
     * the same, session-persisted filter state the table itself was last
     * rendered with (see initFilter()'s own docblock) - using the same
     * "deactivated filter -> null filter data" resolution as viewObject()
     * (see resolveFilterData()'s own docblock), so a deactivated filter
     * resolves ALL_OBJECTS against the unfiltered entry set here exactly
     * as it does for the table itself.
     *
     * Also guards against building a form that could not be saved back
     * anyway: a large ALL_OBJECTS selection can require far more POST
     * fields than PHP's max_input_vars allows, which
     * saveEditedEntriesObject()'s own guard would then reject as a whole -
     * exceedsMaxInputVars() below re-applies that same check here, before
     * the form is even rendered, so a user is not left filling in a form
     * that structurally cannot be saved.
     */
    protected function editSelectedObject(array $names): void
    {
        if (in_array('ALL_OBJECTS', $names, true)) {
            $filter = $this->initFilter();
            $names = $this->entries_table->getFilteredEntryNames($this->resolveFilterData($filter));
        }

        if ($names === []) {
            $this->tpl->setOnScreenMessage('failure', $this->lng->txt('no_checkbox'), true);
            $this->ctrl->redirect($this, 'view');
            return;
        }

        if ($this->exceedsMaxInputVars(count($names))) {
            $this->tpl->setOnScreenMessage('failure', $this->lng->txt('language_too_many_entries_selected'), true);
            $this->ctrl->redirect($this, 'view');
            return;
        }

        $this->tpl->setContent($this->initEditEntriesForm($names)->getHTML());
    }

    /**
     * Whether initEditEntriesForm() would render more fields for
     * $entry_count entries than PHP's max_input_vars allows to submit back -
     * the same 3-fields-per-entry ("entry_name[$idx]"/"translation[$idx]"/
     * "comment[$idx]", see that method's own docblock) plus one for the
     * hidden "expected_entry_count" field itself, checked here BEFORE
     * rendering (see editSelectedObject()) rather than only after
     * submission (see saveEditedEntriesObject()'s own guard, which this
     * mirrors). Falls back to a conservative 1000 if max_input_vars is not
     * readable at all (empty/false ini_get() result).
     */
    private function exceedsMaxInputVars(int $entry_count): bool
    {
        $max_input_vars = ini_get('max_input_vars');
        $limit = ($max_input_vars === false || $max_input_vars === '') ? 1000 : (int) $max_input_vars;

        return ($entry_count * 3 + 1) > $limit;
    }

    /**
     * One translation field (plus a comment field in langmode) per
     * selected entry, all saved by a single "saveEditedEntries" submit -
     * see saveEditedEntriesObject(). The postvars are deliberately real
     * array field names ("translation[$idx]" etc., rendered as such by
     * ilFormPropertyGUI) rather than a single multi-value field, so
     * saveEditedEntriesObject() can read them back as genuine PHP arrays
     * without the historic "_POSTDOT_"/"_POSTSPACE_" workaround (Mantis
     * #25237) the old row-name-as-postvar approach needed.
     *
     * $names is filtered against the entries that actually exist
     * ($translations' keys, from getAllValues()) before anything is
     * rendered - an unknown name (e.g. a forged
     * lang_entries_entry_names[] query value that never went through
     * getFilteredEntryNames()) is silently dropped rather than becoming a
     * field title, which is otherwise rendered unescaped by
     * ilPropertyFormGUI.
     *
     * Also carries a hidden "expected_entry_count" - the number of fields
     * this form actually renders - so saveEditedEntriesObject() can detect
     * a POST silently truncated by PHP's max_input_vars (see that
     * method's own docblock) instead of silently saving a partial
     * selection.
     *
     * The comment field always travels with the form, even outside
     * langmode: as a hidden input carrying the entry's existing remark
     * unchanged, so saveEditedEntriesObject() (which unconditionally
     * writes back whatever "comment[$idx]" it receives) does not wipe out
     * a remark that was never meant to be editable on this screen.
     */
    protected function initEditEntriesForm(array $names): ilPropertyFormGUI
    {
        $translations = $this->object->getAllValues();
        $remarks = $this->object->getAllRemarks();

        $names = array_values(array_filter(
            $names,
            static fn(string $name): bool => array_key_exists($name, $translations)
        ));

        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this, 'saveEditedEntries'));
        $form->setTitle($this->lng->txt('edit'));

        $expected_count = new ilHiddenInputGUI('expected_entry_count');
        $expected_count->setValue((string) count($names));
        $form->addItem($expected_count);

        foreach (array_values($names) as $idx => $name) {
            $keys = explode($this->lng->separator, $name);
            $module = $keys[0] ?? '';
            $topic = $keys[1] ?? '';

            $hidden = new ilHiddenInputGUI("entry_name[$idx]");
            $hidden->setValue($name);
            $form->addItem($hidden);

            $ti = new ilTextAreaInputGUI($module . ' / ' . $topic, "translation[$idx]");
            $ti->setValue($translations[$name] ?? '');
            $form->addItem($ti);

            if ($this->langmode) {
                // A single-line field with the same length limit
                // ilObjLanguage::replaceLangEntry() silently truncates
                // comments to (250) - not a textarea: a comment containing a
                // line break would break ilLanguageFile's strictly
                // line-based format on export/merge (unlike the translation
                // value above, whose line breaks are deliberately converted
                // to "<br />" before saving, see saveEditedEntriesObject()).
                $ci = new ilTextInputGUI($this->lng->txt('comment'), "comment[$idx]");
                $ci->setMaxLength(250);
                $ci->setSize(30);
                $ci->setValue($remarks[$name] ?? '');
                $form->addItem($ci);
            } else {
                $ci = new ilHiddenInputGUI("comment[$idx]");
                $ci->setValue($remarks[$name] ?? '');
                $form->addItem($ci);
            }
        }

        $form->addCommandButton('saveEditedEntries', $this->lng->txt('save'));
        $form->addCommandButton('view', $this->lng->txt('cancel'));

        return $form;
    }

    /**
    * Save the changed translations
    *
    * Guards against a silent partial save: initEditEntriesForm() renders
    * one hidden "expected_entry_count" field carrying the number of
    * entries it actually put into the form. A large ALL_OBJECTS selection
    * can render thousands of fields, and PHP's max_input_vars silently
    * truncates a POST array beyond that point - without this check, only
    * the truncated subset would be saved while the request still reports
    * success. All three of "entry_name"/"translation"/"comment" are
    * checked, not just "entry_name": initEditEntriesForm() always renders
    * exactly one of each per entry (the comment field unconditionally
    * travels with the form even outside langmode, see that method's own
    * docblock), so max_input_vars can just as well cut the POST off in the
    * middle of the last entry's "translation[$idx]"/"comment[$idx]" while
    * "entry_name[$idx]" itself still made it through - which a count check
    * on "entry_name" alone would not catch, silently saving an empty
    * translation and wiping the last entry's remark. A mismatch aborts
    * without saving anything and sends the user back to "view" (not "edit" -
    * the original selection is not carried across this redirect, which is
    * acceptable here: the important part is that nothing gets silently
    * half-saved).
    */
    public function saveEditedEntriesObject(): void
    {
        $post = (array) ($this->http->request()->getParsedBody() ?? []);
        $entry_names = (array) ($post['entry_name'] ?? []);
        $translations = (array) ($post['translation'] ?? []);
        $comments = (array) ($post['comment'] ?? []);
        $expected_entry_count = (int) ($post['expected_entry_count'] ?? -1);

        if (count($entry_names) !== $expected_entry_count
            || count($translations) !== $expected_entry_count
            || count($comments) !== $expected_entry_count
        ) {
            $this->tpl->setOnScreenMessage('failure', $this->lng->txt('language_too_many_entries_selected'), true);
            $this->ctrl->redirect($this, 'view');
            return;
        }

        $save_array = [];
        $remarks_array = [];

        foreach ($entry_names as $idx => $key) {
            $key = ilUtil::stripSlashes((string) $key);

            // example key of variable: 'common#:#access'
            $keys = explode($this->lng->separator, $key);
            if (count($keys) !== 2) {
                continue;
            }

            $value = (string) ($translations[$idx] ?? '');
            // avoid line breaks
            $value = preg_replace("/(\015\012)|(\015)|(\012)/", "<br />", $value);
            $value = str_replace("<<", "«", $value);
            $value = ilUtil::stripSlashes($value, true, "<strong><em><u><strike><ol><li><ul><p><div><i><b><code><sup><pre><gap><a><img><bdo><br><span>");
            $save_array[$key] = $value;

            // the comment has the same index as the translation it belongs to
            $remarks_array[$key] = (string) ($comments[$idx] ?? '');
        }

        // save the translations - skipped entirely when there is nothing to
        // save (e.g. an empty selection, expected_entry_count 0), avoiding an
        // unnecessary ilCachedLanguage::flush() and re-read of the global
        // language file for a no-op save.
        if ($save_array !== []) {
            ilObjLanguageExt::_saveValues($this->object->key, $save_array, $remarks_array);
        }

        $this->tpl->setOnScreenMessage('success', $this->lng->txt('language_variables_saved'), true);
        $this->ctrl->redirect($this, 'view');
    }

    /**
    * Show the screen to import a language file
    */
    public function importObject(): void
    {
        $form = $this->initNewImportForm();
        $this->tpl->setContent($form->getHTML());
    }

    protected function initNewImportForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this));
        $form->setTitle($this->lng->txt("language_import_file"));
        $form->addCommandButton("upload", $this->lng->txt("upload"));

        $fu = new ilFileInputGUI($this->lng->txt("file"), "userfile");
        $fu->setRequired(true);
        $form->addItem($fu);

        $rg = new ilRadioGroupInputGUI($this->lng->txt("language_mode_existing"), "mode_existing");
        $ro = new ilRadioOption($this->lng->txt("language_mode_existing_keepall"), "keepall");
        $ro->setInfo($this->lng->txt("language_mode_existing_keepall_info"));
        $rg->addOption($ro);
        $ro = new ilRadioOption($this->lng->txt("language_mode_existing_keepnew"), "keepnew");
        $ro->setInfo($this->lng->txt("language_mode_existing_keepnew_info"));
        $rg->addOption($ro);
        $ro = new ilRadioOption($this->lng->txt("language_mode_existing_replace"), "replace");
        $ro->setInfo($this->lng->txt("language_mode_existing_replace_info"));
        $rg->addOption($ro);
        $ro = new ilRadioOption($this->lng->txt("language_mode_existing_delete"), "delete");
        $ro->setInfo($this->lng->txt("language_mode_existing_delete_info"));
        $rg->addOption($ro);
        $rg->setValue($this->getSession()["import"]["mode_existing"] ?? "keepall");
        $form->addItem($rg);

        return $form;
    }

    /**
    * Process an uploaded language file
    */
    public function uploadObject(): void
    {
        global $DIC;

        $form = $this->initNewImportForm();
        if ($form->checkInput()) {
            $post_mode_existing = $this->http->request()->getParsedBody()['mode_existing'] ?? "";
            // save form inputs for next display
            $tmp["import"]["mode_existing"] = ilUtil::stripSlashes($post_mode_existing);
            ilSession::set("lang_ext_maintenance", $tmp);
            try {
                $upload = $DIC->upload();
                //mantis #41617
                if (!$upload->hasBeenProcessed()) {
                    $upload->process();
                }


                if (!$upload->hasUploads()) {
                    throw new ilException($DIC->language()->txt("upload_error_file_not_found"));
                }
                $UploadResult = $upload->getResults()[$_FILES["userfile"]["tmp_name"]];

                $ProcessingStatus = $UploadResult->getStatus();
                if ($ProcessingStatus->getCode() === ProcessingStatus::REJECTED) {
                    throw new ilException($ProcessingStatus->getMessage());
                }

                // todo: refactor when importLanguageFile() is able to work with the new Filesystem service
                $tempfile = ilFileUtils::ilTempnam() . ".sec";
                $upload->moveOneFileTo($UploadResult, '', Location::TEMPORARY, basename($tempfile), true);
                $this->object->importLanguageFile($tempfile, $post_mode_existing);

                $tempfs = $DIC->filesystem()->temp();
                $tempfs->delete(basename($tempfile));
            } catch (Exception $e) {
                $this->tpl->setOnScreenMessage('failure', $e->getMessage(), true);
                $this->ctrl->redirect($this, 'import');
            }

            $this->tpl->setOnScreenMessage(
                'success',
                sprintf($this->lng->txt("language_file_imported"), $_FILES["userfile"]["name"]),
                true
            );
            $this->ctrl->redirect($this, "import");
        }

        $form->setValuesByPost();
        $this->tpl->setContent($form->getHTML());
    }

    /**
    * Show the screen to export a language file
    */
    public function exportObject(): void
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this));
        $form->setTitle($this->lng->txt("language_export_file"));
        $form->setPreventDoubleSubmission(false);
        $form->addCommandButton("download", $this->lng->txt("download"));

        $rg = new ilRadioGroupInputGUI($this->lng->txt("language_file_scope"), "scope");
        $ro = new ilRadioOption($this->lng->txt("language_scope_global"), "global");
        $ro->setInfo($this->lng->txt("language_scope_global_info"));
        $rg->addOption($ro);
        $ro = new ilRadioOption($this->lng->txt("language_scope_local"), "local");
        $ro->setInfo($this->lng->txt("language_scope_local_info"));
        $rg->addOption($ro);
        if ($this->langmode) {
            $ro = new ilRadioOption($this->lng->txt("language_scope_added"), "added");
            $ro->setInfo($this->lng->txt("language_scope_added_info"));
            $rg->addOption($ro);
        }
        $ro = new ilRadioOption($this->lng->txt("language_scope_unchanged"), "unchanged");
        $ro->setInfo($this->lng->txt("language_scope_unchanged_info"));
        $rg->addOption($ro);
        if ($this->langmode) {
            $ro = new ilRadioOption($this->lng->txt("language_scope_merged"), "merged");
            $ro->setInfo($this->lng->txt("language_scope_merged_info"));
            $rg->addOption($ro);
        }

        $rg->setValue($this->getSession()["export"]["scope"] ?? "global");
        $form->addItem($rg);

        $this->tpl->setContent($form->getHTML());
    }

    /**
    * Download a language file
    */
    public function downloadObject(): void
    {
        $post_scope = $this->http->request()->getParsedBody()['scope'] ?? "";
        // save the selected scope
        $tmp["export"]["scope"] = ilUtil::stripSlashes($post_scope);
        ilSession::set("lang_ext_maintenance", $tmp);

        $pos = strpos(ILIAS_VERSION, " ");
        $pos = $pos === false ? null : $pos;
        $filename = "ilias_" . $this->object->key . '_'
        . str_replace(".", "_", substr(ILIAS_VERSION, 0, $pos))
        . "-" . gmdate("Y-m-d")
        . ".lang." . $this->getSession()["export"]["scope"];

        $global_file_obj = $this->object->getGlobalLanguageFile();
        $local_file_obj = new ilLanguageFile($filename, $this->object->key, $post_scope);

        if ($post_scope === "global") {
            $local_file_obj->setParam("author", $global_file_obj->getParam("author"));
            $local_file_obj->setParam("version", $global_file_obj->getParam("version"));
            $local_file_obj->setAllValues($this->object->getAllValues());
            if ($this->langmode) {
                $local_file_obj->setAllComments($this->object->getAllRemarks());
            }
        } elseif ($post_scope === "local") {
            $local_file_obj->setParam("based_on", $global_file_obj->getParam("version"));
            $local_file_obj->setAllValues($this->object->getChangedValues());
            if ($this->langmode) {
                $local_file_obj->setAllComments($this->object->getAllRemarks());
            }
        } elseif ($post_scope === "added") { // langmode only
            $local_file_obj->setParam("author", $global_file_obj->getParam("author"));
            $local_file_obj->setParam("version", $global_file_obj->getParam("version"));
            $local_file_obj->setAllValues($this->object->getAddedValues());
            $local_file_obj->setAllComments($this->object->getAllRemarks());
        } elseif ($post_scope === "unchanged") {
            $local_file_obj->setParam("author", $global_file_obj->getParam("author"));
            $local_file_obj->setParam("version", $global_file_obj->getParam("version"));
            $local_file_obj->setAllValues($this->object->getUnchangedValues());
            if ($this->langmode) {
                $local_file_obj->setAllComments($this->object->getAllRemarks());
            }
        } elseif ($post_scope === "merged") { // langmode only
            $local_file_obj->setParam("author", $global_file_obj->getParam("author"));
            $local_file_obj->setParam("version", $global_file_obj->getParam("version"));
            $local_file_obj->setAllValues($this->object->getMergedValues());
            $local_file_obj->setAllComments($this->object->getMergedRemarks());
        }

        ilUtil::deliverData($local_file_obj->build(), $filename);
    }

    /**
    * Process the language maintenance
    */
    public function maintainObject(): void
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this));
        $form->setTitle($this->lng->txt("language_maintenance"));
        $form->setPreventDoubleSubmission(false);
        $form->addCommandButton("maintainExecute", $this->lng->txt("language_process_maintenance"));

        $rg = new ilRadioGroupInputGUI($this->lng->txt("language_maintain_local_changes"), "maintain");
        $ro = new ilRadioOption($this->lng->txt("language_load_local_changes"), "load");
        $ro->setInfo(sprintf($this->lng->txt("language_load_local_changes_info"), $this->object->key));
        $rg->addOption($ro);
        $ro = new ilRadioOption($this->lng->txt("language_clear_local_changes"), "clear");
        $ro->setInfo(sprintf($this->lng->txt("language_clear_local_changes_info"), $this->object->key));
        $rg->addOption($ro);
        if ($this->langmode) {
            $ro = new ilRadioOption($this->lng->txt("language_delete_local_additions"), "delete_added");
            $ro->setInfo(sprintf($this->lng->txt("language_delete_local_additions_info"), $this->object->key));
            $rg->addOption($ro);
            $ro = new ilRadioOption($this->lng->txt("language_remove_local_file"), "remove_local_file");
            $ro->setInfo(sprintf($this->lng->txt("language_remove_local_file_info"), $this->object->key));
            $rg->addOption($ro);
            $ro = new ilRadioOption($this->lng->txt("language_merge_local_changes"), "merge");
            $ro->setInfo(sprintf($this->lng->txt("language_merge_local_changes_info"), $this->object->key));
            $rg->addOption($ro);
        }
        $ro = new ilRadioOption($this->lng->txt("language_save_dist"), "save_dist");
        $ro->setInfo(sprintf($this->lng->txt("language_save_dist_info"), $this->object->key));
        $rg->addOption($ro);
        $rg->setValue($this->getSession()["maintain"] ?? "load");
        $form->addItem($rg);

        $this->tpl->setContent($form->getHTML());
    }

    public function maintainExecuteObject(): void
    {
        $post_maintain = $this->http->request()->getParsedBody()['maintain'] ?? "";
        if (isset($post_maintain)) {
            $tmp["maintain"] = ilUtil::stripSlashes($post_maintain);
            ilSession::set("lang_ext_maintenance", $tmp);
        }

        switch ($post_maintain) {
            // save the global language file for merge after
            case "save_dist":
                // save a copy of the distributed language file
                $orig_file = $this->object->getLangPath() . "/ilias_" . $this->object->key . ".lang";
                $copy_file = $this->object->getDataPath() . "/ilias_" . $this->object->key . ".lang";
                if (@copy($orig_file, $copy_file)) {
                    $this->tpl->setOnScreenMessage('success', $this->lng->txt("language_saved_dist"), true);
                } else {
                    $this->tpl->setOnScreenMessage('failure', $this->lng->txt("language_save_dist_failed"), true);
                }
                break;

                // load the content of the local language file
            case "load":
                $lang_file = $this->object->getCustLangPath() . "/ilias_" . $this->object->key . ".lang.local";
                if (is_file($lang_file) and is_readable($lang_file)) {
                    $this->object->importLanguageFile($lang_file, "replace");
                    $this->object->setLocal(true);
                    $this->tpl->setOnScreenMessage('success', $this->lng->txt("language_loaded_local"), true);
                } else {
                    $this->tpl->setOnScreenMessage('failure', $this->lng->txt("language_error_read_local"), true);
                }
                break;

                // revert the database to the default language file
            case "clear":
                $lang_file = $this->object->getLangPath() . "/ilias_" . $this->object->key . ".lang";
                if (is_file($lang_file) and is_readable($lang_file)) {
                    $this->object->importLanguageFile($lang_file, "replace");
                    $this->object->setLocal(false);
                    $this->tpl->setOnScreenMessage('success', $this->lng->txt("language_cleared_local"), true);
                } else {
                    $this->tpl->setOnScreenMessage('failure', $this->lng->txt("language_error_clear_local"), true);
                }
                break;

                // delete local additions in the datavase (langmode only)
            case "delete_added":
                ilObjLanguageExt::_deleteValues($this->object->key, $this->object->getAddedValues());
                break;

                // merge local changes back to the global language file (langmode only)
            case "merge":
                $orig_file = $this->object->getLangPath() . "/ilias_" . $this->object->key . ".lang";
                $copy_file = $this->object->getCustLangPath() . "/ilias_" . $this->object->key . ".lang";

                if (is_file($orig_file) and is_writable($orig_file)) {
                    // save a copy of the global language file
                    @copy($orig_file, $copy_file);

                    // modify and write the new global file
                    $global_file_obj = $this->object->getGlobalLanguageFile();
                    $global_file_obj->setAllValues($this->object->getMergedValues());
                    $global_file_obj->setAllComments($this->object->getMergedRemarks());
                    $global_file_obj->write();
                    $this->tpl->setOnScreenMessage('success', $this->lng->txt("language_merged_global"), true);
                } else {
                    $this->tpl->setOnScreenMessage('failure', $this->lng->txt("language_error_write_global"), true);
                }
                break;

                // remove the local language file (langmode only)
            case "remove_local_file":
                $lang_file = $this->object->getCustLangPath() . "/ilias_" . $this->object->key . ".lang.local";

                if (!is_file($lang_file)) {
                    $this->object->setLocal(false);
                    $this->tpl->setOnScreenMessage('failure', $this->lng->txt("language_error_local_missed"), true);
                } elseif (@unlink($lang_file)) {
                    $this->object->setLocal(false);
                    $this->tpl->setOnScreenMessage('success', $this->lng->txt("language_local_file_deleted"), true);
                } else {
                    $this->tpl->setOnScreenMessage('failure', $this->lng->txt("language_error_delete_local"), true);
                }
                break;
        }

        $this->ctrl->redirect($this, "maintain");
    }

    /**
     * View the language settings
     */
    public function settingsObject(): void
    {
        $form = $this->initNewSettingsForm();
        $this->tpl->setContent($form->getHTML());
    }

    /**
    * Set the language settings - see SetLanguageTranslationEnabled.
    *
    * An unchecked HTML checkbox is not submitted at all, so a missing/empty
    * "translation" POST value means "disabled", exactly as the extracted
    * code's `?? ""` default did. SetLanguageTranslationEnabled::perform()
    * requires `enabled` as a strict bool and itself derives the setting's
    * current state via `(bool) $this->settings->get($translate_key, '0')` -
    * the same true/false convention the raw stored string already encoded -
    * so this strict bool is equivalent to the raw value the form used to
    * carry directly.
    */
    public function saveSettingsObject(): void
    {
        // $this->user/$this->ctrl (set up by the parent ilObjectGUI
        // constructor) are used here rather than reaching into global $DIC -
        // see the comment on the constructor-injected Activities above for
        // why action methods must not reach into it themselves.
        $ilUser = $this->user;

        $post_translation = $this->http->request()->getParsedBody()['translation'] ?? null;
        $enabled = $post_translation !== null && $post_translation !== '';

        $result = $this->set_language_translation_enabled->maybePerformAs(
            $ilUser->getId(),
            [
                'language_key' => $this->object->key,
                'enabled' => $enabled,
            ]
        );

        if ($result->isError()) {
            $error = $result->error();
            $error_message = $this->activityErrorMessage($error);

            $this->tpl->setOnScreenMessage('failure', $error_message);
        } elseif ($result->value()['changed']) {
            // Only shown if the value actually changed - exactly reproducing
            // the extracted code's original behaviour: 'changed' comes from
            // SetLanguageTranslationEnabled::perform()'s own strict bool
            // comparison against the previously stored setting.
            $this->tpl->setOnScreenMessage('success', $this->lng->txt("settings_saved"));
        }

        $form = $this->initNewSettingsForm();

        // Unlike installObject()/uninstallObject()/refreshSelectedObject(),
        // which redirect after a persisted message, this preserves the
        // extracted code's original behaviour: render the settings form
        // directly with a non-persisted message, rather than an HTTP
        // redirect.
        $this->tpl->setContent($form->getHTML());
    }

    protected function initNewSettingsForm(): ilPropertyFormGUI
    {
        global $DIC;
        $ilSetting = $DIC->settings();
        $translate_key = "lang_translate_" . $this->object->key;
        $translate = (bool) $ilSetting->get($translate_key, '0');

        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this));
        $form->setTitle($this->lng->txt("language_settings"));
        $form->setPreventDoubleSubmission(false);
        $form->addCommandButton('saveSettings', $this->lng->txt("language_change_settings"));

        $ci = new ilCheckboxInputGUI($this->lng->txt("language_translation_enabled"), "translation");
        $ci->setChecked($translate);
        $ci->setInfo($this->lng->txt("language_note_translation"));
        $form->addItem($ci);

        return $form;
    }

    /**
    * Print out statistics about the language
    */
    public function statisticsObject(): void
    {
        $statisticsTable = (new ilLanguageStatisticsTable(
            $this->object,
            $this->ui_factory,
            $this->lng
        ))->getTable();

        $this->tpl->setContent($this->ui_renderer->render($statisticsTable->withRequest($this->request)));
    }

    /**
     * Get tabs for admin mode
     *(Overwritten from ilObjectGUI, called by prepareOutput)
     */
    public function getAdminTabs(): void
    {
        global $DIC;
        $ilCtrl = $DIC->ctrl();
        $cmd = $ilCtrl->getCmd();

        if (!ilObjLanguageAccess::_isPageTranslation()) {
            $this->tabs_gui->setBackTarget(
                $this->lng->txt("back"),
                $this->ctrl->getLinkTargetByClass("ilObjLanguageFolderGUI")
            );

            $this->ctrl->setParameter($this, "obj_id", $this->id);
            $this->tabs_gui->addTab(
                "edit",
                $this->lng->txt("edit"),
                $this->ctrl->getLinkTarget($this, "view")
            );

            $this->tabs_gui->addTab(
                "export",
                $this->lng->txt('export'),
                $this->ctrl->getLinkTarget($this, "export")
            );

            $this->tabs_gui->addTab(
                "import",
                $this->lng->txt("import"),
                $this->ctrl->getLinkTarget($this, "import")
            );

            $this->tabs_gui->addTab(
                "maintain",
                $this->lng->txt("language_maintain"),
                $this->ctrl->getLinkTarget($this, "maintain")
            );

            $this->tabs_gui->addTab(
                "settings",
                $this->lng->txt("settings"),
                $this->ctrl->getLinkTarget($this, "settings")
            );

            $this->tabs_gui->addTab(
                "statistics",
                $this->lng->txt("language_statistics"),
                $this->ctrl->getLinkTarget($this, "statistics")
            );

            switch ($cmd) {
                case "":
                case "view":
                case "saveEditedEntries":
                    $this->tabs_gui->activateTab("edit");
                    break;
                default:
                    $this->tabs_gui->activateTab($cmd);
            }
        }
    }

    /**
     * Set the locator for admin mode
     *(Overwritten from ilObjectGUI, called by prepareOutput)
     */
    protected function addAdminLocatorItems(bool $do_not_add_object = false): void
    {
        global $DIC;
        $ilLocator = $DIC["ilLocator"];

        if (!ilObjLanguageAccess::_isPageTranslation()) {
            parent::addAdminLocatorItems(true); // #13881

            $ilLocator->addItem(
                $this->lng->txt("languages"),
                $this->ctrl->getLinkTargetByClass("ilobjlanguagefoldergui", "")
            );

            $ilLocator->addItem(
                $this->lng->txt("meta_l_" . $this->object->getTitle()),
                $this->ctrl->getLinkTarget($this, "view")
            );
        }
    }

    /**
     * Set the Title and the description
     * (Overwritten from ilObjectGUI, called by prepareOutput)
     */
    protected function setTitleAndDescription(): void
    {
        if (ilObjLanguageAccess::_isPageTranslation()) {
            $this->tpl->setHeaderPageTitle($this->lng->txt("translation"));
            $this->tpl->setTitle($this->lng->txt("translation") . " " . $this->lng->txt("meta_l_" . $this->object->key));
        } else {
            $this->tpl->setTitle($this->lng->txt("meta_l_" . $this->object->key));
        }
        $this->tpl->setTitleIcon(ilUtil::getImagePath("standard/icon_lngf.svg"), $this->lng->txt("obj_" . $this->object->getType()));
    }


    //
    // new entries
    //

    protected function buildMissingEntries(?array $a_missing = null): string
    {
        global $DIC;
        $ilCtrl = $DIC->ctrl();

        if (!count($a_missing)) {
            return '';
        }

        $res = array("<h3>" . $this->lng->txt("adm_missing_entries") . "</h3>", "<ul>");

        foreach ($a_missing as $entry) {
            $ilCtrl->setParameter($this, "eid", $entry);
            $res[] = '<li>' . $entry .
                ' <a href="' . $ilCtrl->getLinkTarget($this, "addNewEntry") .
                '">' . $this->lng->txt("adm_missing_entry_add_action") . '</a></li>';
            $ilCtrl->setParameter($this, "eid", "");
        }

        $res[] = "</ul>";

        return implode("\n", $res);
    }

    public function addNewEntryObject(?ilPropertyFormGUI $a_form = null): void
    {
        global $DIC;
        $tpl = $DIC["tpl"];

        $id = "";
        if ($this->http->wrapper()->query()->has("eid")) {
            $id = trim($this->http->wrapper()->query()->retrieve("eid", $this->refinery->kindlyTo()->string()));
        }
        if (!$a_form) {
            $a_form = $this->initAddNewEntryForm($id);
        }

        $tpl->setContent($a_form->getHTML());
    }

    protected function initAddNewEntryForm(?string $a_id = null): ilPropertyFormGUI
    {
        global $DIC;
        $ilCtrl = $DIC->ctrl();

        if (!$a_id) {
            $a_id = $this->http->request()->getParsedBody()['id'] ?? "";
        }

        if (!$a_id ||
            !in_array($a_id, ilObjLanguageAccess::_getSavedTopics())) {
            $ilCtrl->redirect($this, "view");
        }

        $form = new ilPropertyFormGUI();
        $form->setFormAction($ilCtrl->getFormAction($this, "saveNewEntry"));
        $form->setTitle($this->lng->txt("adm_missing_entry_add"));

        $mods = ilObjLanguageAccess::_getSavedModules();
        $options = array_combine($mods, $mods);

        $mod = new ilSelectInputGUI(ucfirst($this->lng->txt("module")), "mod");
        $mod->setOptions(array("" => $this->lng->txt("please_select")) + $options);
        $mod->setRequired(true);
        $form->addItem($mod);

        $id = new ilTextInputGUI(ucfirst($this->lng->txt("identifier")), "id");
        $id->setValue($a_id);
        $id->setDisabled(true);
        $form->addItem($id);

        foreach ($this->lng->getInstalledLanguages() as $lang_key) {
            $trans = new ilTextInputGUI($this->lng->txt("meta_l_" . $lang_key), "trans_" . $lang_key);
            if (in_array($lang_key, array("de", "en"))) {
                $trans->setRequired(true);
            }
            $form->addItem($trans);
        }

        $form->addCommandButton("saveNewEntry", $this->lng->txt("save"));
        $form->addCommandButton("view", $this->lng->txt("cancel"));

        return $form;
    }

    public function saveNewEntryObject(): void
    {
        // $this->ctrl/$this->user (set up by the parent ilObjectGUI
        // constructor) are used here rather than reaching into global $DIC -
        // see the comment on the constructor-injected Activities above for
        // why action methods must not reach into it themselves.
        $ilCtrl = $this->ctrl;
        $ilUser = $this->user;

        $form = $this->initAddNewEntryForm();
        if ($form->checkInput()) {
            $mod = $form->getInput("mod");
            $id = $form->getInput("id");

            // One value per installed language - an installed language left
            // blank by the user is skipped entirely by AddLanguageEntry,
            // exactly as this loop used to skip it via `if ($trans) {...}`.
            $translations = [];
            foreach ($this->lng->getInstalledLanguages() as $lang_key) {
                $translations[$lang_key] = trim((string) $form->getInput("trans_" . $lang_key));
            }

            $result = $this->add_language_entry->maybePerformAs(
                $ilUser->getId(),
                [
                    'module' => $mod,
                    'identifier' => $id,
                    'translations' => $translations,
                ]
            );

            if ($result->isError()) {
                $error = $result->error();
                $error_message = $this->activityErrorMessage($error);

                if ($error instanceof SafeToDisplayActivityError) {
                    // A genuine input rejection (e.g. a missing/blank "de"/
                    // "en" translation - AddLanguageEntry::perform() rejects
                    // the whole request if either is blank, mirroring the
                    // legacy ilPropertyFormGUI's setRequired(true) on these
                    // two fields; other languages left blank are merely
                    // skipped) - re-render the same form with the values
                    // the user already entered still filled in, exactly like
                    // the $form->checkInput() === false branch below does,
                    // instead of redirecting to "view" and discarding them.
                    $this->tpl->setOnScreenMessage('failure', $error_message);
                    $form->setValuesByPost();
                    $this->addNewEntryObject($form);
                    return;
                }

                // An unexpected internal failure (already logged by
                // activityErrorMessage()) - redirecting away, losing the
                // entered values, matches every other Activity error path in
                // this component.
                $this->tpl->setOnScreenMessage('failure', $error_message, true);
                $ilCtrl->redirect($this, "view");
                return;
            }

            $this->tpl->setOnScreenMessage('success', $this->lng->txt("settings_saved"), true);
            $ilCtrl->redirect($this, "view");
            return;
        }

        $form->setValuesByPost();
        $this->addNewEntryObject($form);
    }

    private function getSession(): array
    {
        return ilSession::get("lang_ext_maintenance") ?? [];
    }
} // END class.ilObjLanguageExtGUI
