# Roadmap of Language Service

This roadmap has two tracks: the ongoing **Component Revision** of this component (migration onto the
`$define`/`$implement`/`$use`/`$contribute`/`$seek`/`$provide`/`$pull`/`$internal` dependency graph described in
[docs/development/components-and-directories.md](../../../docs/development/components-and-directories.md), and
extraction of [Activities](../Component/src/Activities/README.md)), and the component's regular, functional
development. See [README.md](README.md) for the current state in detail.

## Already Implemented

### Component Revision & Activities

* `ILIAS\Language\Language` defined and used as the service interface for component-revision code
  ([Language.php](Language.php), `$define`/`$use`), with two bootstrap-entry-point-specific implementations
  (`ilSetupLanguage` for Setup, `LanguageLegacyInitialisationAdapter` for Init/runtime).
* Read (`InstalledLanguageRepository`) and write (`LanguageInstallationManager`) access to the language
  installation domain split out of `ilSetupLanguage`, following the repository pattern, and `$provide`d to the rest
  of the system.
* Seven Activities extracted from the GUI classes and wired into the component graph: `InstallLanguage`,
  `UpdateLanguage`, `UninstallLanguage`, `RemoveLocalLanguageChanges`, `AddLanguageEntry`,
  `SetLanguageDetectionEnabled`, `SetLanguageTranslationEnabled` - each `$provide`d under its concrete class and
  `$contribute`d to `\ILIAS\Component\Activities\Activity::class`.
* Shared Activity infrastructure: `LanguageActivity` base class, form-input grinding/parsing traits
  (`GrindsFormInput`, `DeclaresLanguageKeysOnlyInput`, `ParsesLanguageKeyList`, `ResolvesLanguageKeysToObjIds`), and
  the `RendersActivityErrors` trait / `SafeToDisplayActivityError` marker for uniform, safe error display in the
  consuming GUI classes.
* Test coverage for the graph wiring (`tests/LanguageComponentGraphTest.php`), the Activities themselves
  (`tests/Activities/`) and the shared error rendering (`tests/RendersActivityErrorsTest.php`).

### Functional

* Accelerated language update (no more need to introduce background tasks)
* Removed language installation in config (only English is installed by Setup)
* Substituted LegacyUI Button by KS Button
* Replaced the deprecated `ilLanguageExtTableGUI` (`ilTable2GUI`) with a read-only KS Data Table
  (`ilLanguageEntriesTable`) plus a separate `ilPropertyFormGUI` edit screen for the selected entries (KS Data Table
  has no editable column type) - see `ilObjLanguageExtGUI::editSelectedObject()`/`initEditEntriesForm()`/
  `saveEditedEntriesObject()`.

## Component Revision: Next Steps

The remaining migration is currently blocked on Core/bootstrap support, not on anything specific to this component:

* `LanguageLegacyInitialisationAdapter` still proxies every call to `$GLOBALS['DIC']->language()` at call time,
  because component construction happens before the legacy container is fully initialised - there is no fully
  booted `$DIC` yet to resolve a real instance against at graph-build time (see
  [src/LanguageLegacyInitialisationAdapter.php](src/LanguageLegacyInitialisationAdapter.php)). **Once Core provides
  a way to resolve the component graph without this legacy-container detour**, this proxy should be replaced by a
  runtime implementation wired natively through `$use`/`$pull`, and this roadmap item should be revisited together
  with whoever completes that Core work.
* Once that is possible, re-evaluate whether `Language.php` still needs two separate `$implement[...]` entries for
  `ILIAS\Language\Language`, disambiguated only by which bootstrap entry point resolves them
  (`components/ILIAS/Setup/resources/dependency_resolution.php` vs.
  `components/ILIAS/Init/resources/dependency_resolution.php`) - see the comment block above those two assignments
  in `Language.php`.
* Continue migrating the remaining `global $DIC` / `$GLOBALS['DIC']` / `$this->ilias` access in
  `ilObjLanguageFolderGUI` and `ilObjLanguageExtGUI` onto the component graph, alongside further Activity extraction
  below (most of the remaining direct `$DIC` access sits exactly in the not-yet-extracted command methods).
* Remaining `$DIC` access: `ilObjLanguageExtGUI::initFilter()` still reaches into `global $DIC` for
  `$DIC->uiService()->filter()->standard(...)` to build the entries table's Filter - not addressed by the KS Data
  Table migration above, kept as-is and documented in that method's own docblock.

## Further Activity Extraction

Concrete candidates identified in the current GUI classes, not yet covered by an Activity. Each should be scoped,
implemented and reviewed individually against the specification in
[Component/src/Activities/Activity.php](../Component/src/Activities/Activity.php) /
[Component/src/Activities/README.md](../Component/src/Activities/README.md) - not copied from the shape of an
existing, still-young Activity without re-checking it against that spec first.

**Commands:**

* `ilObjLanguageFolderGUI::setUserLanguageObject()` - set the current user's language → `SetUserLanguage`.
* `ilObjLanguageFolderGUI::setSystemLanguageObject()` - set the installation's default language → `SetSystemLanguage`.
* `ilObjLanguageExtGUI::saveEditedEntriesObject()` - bulk-save translated entries of one language → e.g.
  `SaveTranslatedLanguageEntries` (distinct from the existing single-entry `AddLanguageEntry`).
* `ilObjLanguageExtGUI::uploadObject()` / `importObject()` - import a `.lang` file → `ImportLanguageFile`.
* `ilObjLanguageExtGUI::maintainExecuteObject()` - six maintenance operations behind one `maintain` switch
  (`save_dist`, `load`, `clear`, `delete_added`, `merge`, `remove_local_file`). Needs a design decision first: one
  Activity per operation (matching "an Activity is one thing a user wants to do"), or one parametrised Activity -
  clarify with the maintainers before implementing.

**Queries:**

* `ilObjLanguageFolderGUI::checkLanguageObject()` (→ `ilObjLanguageFolder::checkAllLanguages()`) - syntax-check
  installed language files → `CheckLanguageFiles`.
* `ilObjLanguageFolderGUI::listDeprecatedObject()` / `downloadDeprecatedObject()` - list/export deprecated language
  variables → `ListDeprecatedLanguageVariables` / `ExportDeprecatedLanguageVariables`.
* `ilObjLanguageExtGUI::downloadObject()` (→ `exportObject()` for the form) - export a `.lang` file for a chosen
  scope → `ExportLanguageFile`.
* `ilObjLanguageExtGUI::statisticsObject()` - language translation statistics table → `GetLanguageStatistics`.

Recommended process, matching how the previous seven Activities were built: implement via the
`language-activity-engineer` agent (checks Command vs. Query, wires `Language.php`/`Init.php`/
`AllModernComponents.php`, pulls the extraction into the GUI caller), delegate all test work to the `test-engineer`
agent, and hand finished work to `code-review-critic` before merging.

## Functional Development

### Short Term

* Fixing PHP 8.2 issues
* Analysing use of language variables on test9
* GitHook for preventing duplicate use of same variable_ID in language files

### Mid Term

* Remove unused language variables from language files
* Improving export and import of customised language files
* Improving online translation tool

### Long Term

* Introducing RFC 5646 language coding scheme for language and region to allow multiple versions per language, see [https://datatracker.ietf.org/doc/html/rfc5646](https://datatracker.ietf.org/doc/html/rfc5646)
* Separating language service from language files
