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

use ILIAS\UI\Component\Table as DataTable;
use ILIAS\Data\Range;
use ILIAS\Data\Order;
use ILIAS\UI\URLBuilder;
use ILIAS\UI\URLBuilderToken;

/**
 * Read-only KS Data Table listing the language entries of one
 * ilObjLanguageExt, filtered by the "lang_ext_entries_*" Filter built in
 * ilObjLanguageExtGUI::initFilter(). Editing happens on a separate screen
 * (see ilObjLanguageExtGUI::editSelectedObject()/initEditEntriesForm()) -
 * this class is deliberately read-only, KS Data Table has no editable
 * column type.
 */
class ilLanguageEntriesTable implements DataTable\DataRetrieval
{
    /**
     * Per-instance memoization: getTotalRowCount() and getRows() each call
     * getFilteredEntries() once per KS Data Table render, and
     * getConflictsNotice() (called separately by the GUI) triggers
     * resolveConflictsCheck() again on top of whatever getFilteredEntries()
     * itself already triggered in "conflicts" mode - each of those calls
     * previously re-ran the same DB queries / re-parsed the same .lang
     * file from scratch. An ilLanguageEntriesTable instance lives only for
     * the duration of one request and $filter_data does not change within
     * it, so a simple, key-less instance cache is sufficient - no need to
     * key it by $filter_data.
     */
    private ?array $filtered_entries_cache = null;
    private ?array $conflicts_check_cache = null;
    private ?array $remarks_cache = null;
    private ?array $compare_content_cache = null;

    public function __construct(
        protected ilObjLanguageExt $object,
        protected ilLanguage $lng,
        protected ILIAS\UI\Factory $ui_factory,
        protected URLBuilder $url_builder,
        protected URLBuilderToken $action_token,
        protected URLBuilderToken $row_id_token
    ) {
    }

    /**
     * The single source of truth for this table's id (and, since KS Data
     * Table has no separate filter-id concept, the Filter's id too - see
     * ilObjLanguageExtGUI::initFilter()) - also reused by
     * ilObjLanguageExtGUI::resetEntriesTableRangeIfRequested() to address
     * the same view-control session key. Keyed on
     * ilObjLanguageAccess::_isPageTranslation() (the actual, request-dependent
     * page-translation/admin distinction), not on langmode - see this
     * class' former constructor docblock/ilObjLanguageExtGUI::initFilter()
     * for why.
     */
    public static function tableId(): string
    {
        return 'lang_ext_entries_' . (ilObjLanguageAccess::_isPageTranslation() ? 'trans' : 'admin');
    }

    public function getTable(mixed $filter_data): DataTable\Data
    {
        return $this->ui_factory->table()->data(
            $this,
            '',
            $this->getColumns($filter_data)
        )->withId(self::tableId())
            ->withActions([
                'edit' => $this->ui_factory->table()->action()->standard(
                    $this->lng->txt('edit'),
                    $this->url_builder->withParameter($this->action_token, 'edit'),
                    $this->row_id_token
                ),
            ]);
    }

    /**
     * "translation"'s title names the language actually being edited
     * (fixed for the whole request, $this->object->key) - "default"'s
     * title names the current "compare" filter value instead (defaulting
     * like getCompareContent() does, so the two never disagree), plus the
     * "these are the same" hint once compare equals the object's own
     * language - exactly as the pre-KS ilLanguageExtTableGUI::__construct()
     * built both column headers.
     */
    protected function getColumns(mixed $filter_data): array
    {
        $f = $this->ui_factory->table()->column();

        $compare = (string) $this->filterValue($filter_data, 'compare', $this->lng->getDefaultLanguage());
        $default_title = $this->lng->txt('meta_l_' . $compare);
        if ($compare === $this->object->key) {
            $default_title .= ' ' . $this->lng->txt('language_default_entries');
        }

        return [
            'module' => $f->text(ucfirst($this->lng->txt('module'))),
            'topic' => $f->text(ucfirst($this->lng->txt('identifier'))),
            'translation' => $f->text($this->lng->txt('meta_l_' . $this->object->key)),
            'default' => $f->text($default_title),
            'commented' => $f->boolean(
                $this->lng->txt('comment'),
                $this->ui_factory->symbol()->icon()->custom(
                    'assets/images/standard/icon_checked.svg',
                    $this->lng->txt('yes'),
                    'small'
                ),
                $this->ui_factory->symbol()->icon()->custom(
                    'assets/images/standard/icon_unchecked.svg',
                    $this->lng->txt('no'),
                    'small'
                )
            )->withIsSortable(false),
        ];
    }

    /**
     * All entry names (module . separator . topic) matching the current
     * filter, in full (no range/pagination applied) - used both by
     * getRows()/getTotalRowCount() and, from the GUI, to resolve the
     * ALL_OBJECTS multi-action selection (see
     * ilObjLanguageExtGUI::editSelectedObject()).
     *
     * @return list<string>
     */
    public function getFilteredEntryNames(mixed $filter_data): array
    {
        return array_keys($this->getFilteredEntries($filter_data));
    }

    /**
     * Whether the current filter selects the "conflicts" mode and, if so,
     * whether the comparison against the former distributed language file
     * can actually be shown - independently re-derives the same file check
     * getFilteredEntries() uses for the "conflicts" case, rather than
     * relying on it having been called before (resolveConflictsCheck()
     * itself memoizes the result per instance either way, see its own
     * docblock, so calling both from the same request/instance re-parses
     * the former file at most once). Never sets an on-screen message
     * itself - the caller (ilObjLanguageExtGUI::viewObject()) does that
     * with the exact original wording.
     *
     * @return array{type: string, text: string}|null
     */
    public function getConflictsNotice(mixed $filter_data): ?array
    {
        if ((string) $this->filterValue($filter_data, 'mode', 'all') !== 'conflicts') {
            return null;
        }

        $conflicts = $this->resolveConflictsCheck();

        return match ($conflicts['status']) {
            'missing' => [
                'type' => 'failure',
                'text' => sprintf($this->lng->txt('language_former_file_missing'), $conflicts['former_file'])
                    . '<br />' . $this->lng->txt('language_former_file_description'),
            ],
            'equal' => [
                'type' => 'info',
                'text' => sprintf($this->lng->txt('language_former_file_equal'), $conflicts['former_file'])
                    . '<br />' . $this->lng->txt('language_former_file_description'),
            ],
            default => null,
        };
    }

    public function getRows(
        \ILIAS\UI\Component\Table\DataRowBuilder $row_builder,
        array $visible_column_ids,
        Range $range,
        Order $order,
        mixed $additional_viewcontrol_data,
        mixed $filter_data,
        mixed $additional_parameters
    ): Generator {
        $entries = $this->getFilteredEntries($filter_data);

        [$modules, $topics] = $this->resolveModulesAndTopics($filter_data);
        $compare_content = $this->getCompareContent($filter_data, $modules, $topics);
        $remarks = $this->getRemarks();

        $names = $this->sortEntryNames(array_keys($entries), $entries, $compare_content, $order);
        $names = array_slice($names, $range->getStart(), $range->getLength());

        foreach ($names as $name) {
            $keys = explode($this->lng->separator, $name);

            yield $row_builder->buildDataRow($name, [
                'module' => $keys[0] ?? '',
                'topic' => $keys[1] ?? '',
                'translation' => $entries[$name] ?? '',
                'default' => $compare_content[$name] ?? '',
                'commented' => array_key_exists($name, $remarks),
            ]);
        }
    }

    /**
     * Sorts the full (unpaginated) list of entry names by the requested
     * column before getRows() slices out the requested Range - analogous
     * to ilLanguageStatisticsTable::getItems()'s own Order handling
     * (join()'d down to a single [field, direction] pair, since this table,
     * like that one, never sorts by more than one column at a time).
     * "module"/"topic" sort on the corresponding half of the entry name
     * itself (cheaper than re-exploding per comparison would suggest, but
     * consistent with how getRows() derives the same values for display);
     * "translation"/"default" sort on the already-resolved value maps
     * getRows() built anyway - no separate data source is queried for
     * sorting.
     *
     * @param list<string> $names
     */
    private function sortEntryNames(array $names, array $entries, array $compare_content, Order $order): array
    {
        [$order_field, $order_direction] = $order->join([], static fn($ret, $key, $value): array => [$key, $value]);

        $aspect = function (string $name) use ($order_field, $entries, $compare_content): string {
            $keys = explode($this->lng->separator, $name);
            return match ($order_field) {
                'topic' => $keys[1] ?? '',
                'translation' => (string) ($entries[$name] ?? ''),
                'default' => (string) ($compare_content[$name] ?? ''),
                default => $keys[0] ?? '', // 'module' and any unknown field
            };
        };

        // The comparator itself is flipped for DESC, rather than sorting ASC
        // and array_reverse()-ing the result afterwards: with a stable sort
        // (usort() since PHP 8.0), reversing the ASC result also inverts the
        // relative order of equal-key elements, which is not what "sort
        // descending" means for ties (e.g. sorting by "module" descending
        // must not also reverse the order of same-module entries).
        $comparator = $order_direction === Order::DESC
            ? static fn(string $a, string $b): int => $aspect($b) <=> $aspect($a)
            : static fn(string $a, string $b): int => $aspect($a) <=> $aspect($b);

        usort($names, $comparator);

        return $names;
    }

    public function getTotalRowCount(
        mixed $additional_viewcontrol_data,
        mixed $filter_data,
        mixed $additional_parameters
    ): ?int {
        return count($this->getFilteredEntryNames($filter_data));
    }

    /**
     * The actual entry dispatch, module.separator.topic => value - backs
     * both getFilteredEntryNames() (keys only) and getRows() (keys and
     * values, so the translation value does not have to be fetched a
     * second time for the rows actually shown).
     *
     * This is the switch($filter_mode) that used to live in
     * ilObjLanguageExtGUI::viewObject(), moved here unchanged (same method
     * calls on $this->object, same array_intersect_key/array_intersect_assoc/
     * array_diff_assoc combinations), plus the page-translation branch that
     * used to sit next to it in the same method.
     */
    private function getFilteredEntries(mixed $filter_data): array
    {
        if ($this->filtered_entries_cache !== null) {
            return $this->filtered_entries_cache;
        }

        return $this->filtered_entries_cache = $this->computeFilteredEntries($filter_data);
    }

    private function computeFilteredEntries(mixed $filter_data): array
    {
        [$modules, $topics] = $this->resolveModulesAndTopics($filter_data);

        if (ilObjLanguageAccess::_isPageTranslation()) {
            return ilObjLanguageExt::_getValues($this->object->key, $modules, $topics);
        }

        $filter_mode = (string) $this->filterValue($filter_data, 'mode', 'all');
        $filter_pattern = (string) $this->filterValue($filter_data, 'pattern', '');

        switch ($filter_mode) {
            case 'changed':
                return $this->object->getChangedValues($modules, $filter_pattern, $topics);

            case 'added':   // langmode only
                return $this->object->getAddedValues($modules, $filter_pattern, $topics);

            case 'unchanged':
                return $this->object->getUnchangedValues($modules, $filter_pattern, $topics);

            case 'commented':
                return $this->object->getCommentedValues($modules, $filter_pattern, $topics);

            case 'dbremarks':
                $translations = $this->object->getAllValues($modules, $filter_pattern, $topics);
                return array_intersect_key($translations, $this->getRemarks());

            case 'equal':
                $translations = $this->object->getAllValues($modules, $filter_pattern, $topics);
                return array_intersect_assoc(
                    $translations,
                    $this->getCompareContent($filter_data, $modules, $topics)
                );

            case 'different':
                $translations = $this->object->getAllValues($modules, $filter_pattern, $topics);
                return array_diff_assoc(
                    $translations,
                    $this->getCompareContent($filter_data, $modules, $topics)
                );

            case 'conflicts':
                $conflicts = $this->resolveConflictsCheck();
                if ($conflicts['status'] !== 'ok') {
                    return [];
                }
                $translations = $this->object->getChangedValues($modules, $filter_pattern, $topics);
                return array_intersect_key($translations, $conflicts['global_changes']);

            case 'all':
            default:
                return $this->object->getAllValues($modules, $filter_pattern, $topics);
        }
    }

    /**
     * $this->object->getAllRemarks() (a DB call), memoized per instance -
     * both the "dbremarks" filter mode and getRows()'s "commented"
     * indicator column need the full remarks map, so without this a
     * "dbremarks"-filtered render would otherwise fetch it twice.
     */
    private function getRemarks(): array
    {
        if ($this->remarks_cache !== null) {
            return $this->remarks_cache;
        }

        return $this->remarks_cache = $this->object->getAllRemarks();
    }

    /**
     * The comparison ("default") language's values for the current scope -
     * the global language file's values if the compare language is the
     * language being edited itself (exactly like the extracted code), the
     * database values of the compare language otherwise.
     *
     * Memoized per instance, like $filtered_entries_cache/
     * $conflicts_check_cache/$remarks_cache above: in "equal"/"different"
     * mode this is called once from computeFilteredEntries() and again from
     * getRows() (for the "default" column) - both with the same
     * $filter_data/$modules/$topics for a given instance/request, so a
     * simple key-less cache is sufficient (see those other caches' own
     * docblock for the same reasoning).
     */
    private function getCompareContent(mixed $filter_data, array $modules, array $topics): array
    {
        if ($this->compare_content_cache !== null) {
            return $this->compare_content_cache;
        }

        $compare = (string) $this->filterValue($filter_data, 'compare', $this->lng->getDefaultLanguage());

        if ($compare === $this->object->key) {
            return $this->compare_content_cache = $this->object->getGlobalLanguageFile()->getAllValues();
        }

        return $this->compare_content_cache = ilObjLanguageExt::_getValues($compare, $modules, $topics);
    }

    /**
     * Page-translation mode filters by the modules/topics saved in the
     * session (see ilObjLanguageAccess::_getSavedModules()/
     * _getSavedTopics()) instead of by the "module"/"identifier" filter
     * fields, which are not even rendered in that mode (see
     * ilObjLanguageExtGUI::initFilter()).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function resolveModulesAndTopics(mixed $filter_data): array
    {
        if (ilObjLanguageAccess::_isPageTranslation()) {
            return [ilObjLanguageAccess::_getSavedModules(), ilObjLanguageAccess::_getSavedTopics()];
        }

        $filter_module = (string) $this->filterValue($filter_data, 'module', 'all');
        $filter_module = $filter_module === 'all' ? '' : $filter_module;
        $filter_modules = $filter_module !== '' ? [$filter_module] : [];

        $filter_identifier = (string) $this->filterValue($filter_data, 'identifier', '');
        $filter_topics = $filter_identifier !== '' ? [$filter_identifier] : [];

        return [$filter_modules, $filter_topics];
    }

    /**
     * The "conflicts" mode's file-based check, factored out so both
     * getFilteredEntries() (deciding which entries to show) and
     * getConflictsNotice() (deciding which message the GUI should show, if
     * any) can each call it independently. Memoized per instance - a
     * request touching "conflicts" mode calls this from both places, and
     * without caching each would re-read and re-parse the former
     * distributed language file from disk (real file I/O) a second time.
     *
     * @return array{status: 'missing'|'equal'|'ok', former_file: string, global_changes?: array}
     */
    private function resolveConflictsCheck(): array
    {
        if ($this->conflicts_check_cache !== null) {
            return $this->conflicts_check_cache;
        }

        return $this->conflicts_check_cache = $this->computeConflictsCheck();
    }

    /**
     * @return array{status: 'missing'|'equal'|'ok', former_file: string, global_changes?: array}
     */
    private function computeConflictsCheck(): array
    {
        $former_file = $this->object->getDataPath() . '/ilias_' . $this->object->key . '.lang';

        if (!is_readable($former_file)) {
            return ['status' => 'missing', 'former_file' => $former_file];
        }

        $global_file_obj = $this->object->getGlobalLanguageFile();
        $former_file_obj = new ilLanguageFile($former_file);
        $former_file_obj->read();
        $global_changes = array_diff_assoc(
            $global_file_obj->getAllValues(),
            $former_file_obj->getAllValues()
        );

        if (count($global_changes) === 0) {
            return ['status' => 'equal', 'former_file' => $former_file];
        }

        return ['status' => 'ok', 'former_file' => $former_file, 'global_changes' => $global_changes];
    }

    /**
     * Reads one field's current value out of $filter_data (as returned by
     * Filter::getInputs(), an array<string, FormInput>), falling back to
     * $default for a missing field (e.g. "mode"/"pattern"/"module"/
     * "identifier" in page-translation mode, where those filter fields are
     * not built at all - see initFilter()) or an empty value. $filter_data
     * itself is `null` whenever the GUI's Filter is deactivated (see
     * ilObjLanguageExtGUI::resolveFilterData()) - the `!is_array()` guard
     * below then falls back to $default for every field, which is exactly
     * the desired "no filter applied" behaviour.
     */
    private function filterValue(mixed $filter_data, string $key, mixed $default = null): mixed
    {
        if (!is_array($filter_data) || !isset($filter_data[$key])) {
            return $default;
        }

        $value = $filter_data[$key]->getValue();

        return ($value === null || $value === '') ? $default : $value;
    }
}
