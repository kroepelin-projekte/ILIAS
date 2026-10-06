Language Service
================

*document in progress*

# General Information

## Guidelines
There are a couple of guidelines defined in the [language.md](../../../docs/development/language.md) that have to be respected when adding language variables to the lang files of ILIAS or editing them.

## Using the Language Service
Component-revision code should depend on `ILIAS\Language\Language`. During the legacy bootstrap this interface is
provided lazily, so early component construction does not access the language service before the legacy container has
been initialised. After initialisation it delegates to the active `ilLanguage` runtime instance.

For components which have not yet been migrated, the active runtime language remains available through `$DIC['lng']`
or `$DIC->language()`. These access paths are a temporary compatibility layer and must not be used for new code.

Setup code has a separate language implementation, `ilSetupLanguage`, which is constructed without runtime user,
session, or container state. It must not be used as the runtime language service.

        $language->loadLanguageModule("frm");
        $tpl->setVariable("TEXT", $language->txt("frm_new_posting"));

## Installing and Managing Languages
Which languages are installed/available (`ILIAS\Language\Setup\InstalledLanguageRepository`) and installing, flushing
or registering a language (`ILIAS\Language\Setup\LanguageInstallationManager`) are separate, narrower services -
following [the repository pattern](../../../docs/development/repository-pattern.md) - rather than part of
`ilSetupLanguage` itself. New code that only needs to install or inspect languages should depend on these two
instead of on `ilSetupLanguage`, which still exists (delegating to both) as the concrete `ILIAS\Language\Language`
implementation used during Setup and as a stable entry point for callers not yet wired through `Language.php`.


## Language File Directories
Where language files are looked up - the global `lang` directories, a component's own language
files, and the Customizing overrides - is resolved through `ILIAS\Language\ComponentTranslation\*`
(`src/ComponentTranslation/`): `LanguageFileDirectory` is the interface each source implements
(`MainLanguageFileDirectory`, `ComponentLanguageFileDirectory`, `CustomizingLanguageFileDirectory`),
and `LanguageFileDirectoryManager` aggregates all of them, contributed by other components via
`$contribute[LanguageFileDirectory::class]` and gathered in `Language.php` via
`$seek[LanguageFileDirectory::class]`. `InstalledLanguageDatabaseRepository` and
`LanguageInstallationManager` both depend on this manager rather than hardcoding paths.

## Modules Maintained in PO Files (PO/MO Pilot)
A component may ship a module as gettext files instead of `.lang` lines: it contributes a
`LanguageFileDirectory` with the module as prefix and ships `<module>_<lang>.po` there (currently
`TermsOfService`, module `tos`, and `Poll`, module `poll`). For such a module the shipped `.po` is
the only source of its shipped values - its lines in `lang/ilias_<lang>.lang` are ignored when installing/updating and are
not the default the administration GUI compares with. Each installation keeps only its local
changes in files (the "overlay", `<client data dir>/lang/<module>/<lang>/`: the `.po` with the
bookkeeping, `current` naming the compiled revision `r-<hash>/` served at runtime): the entries whose value
differs from the shipped `.po` or whose key it does not ship (customizing values, "add new
variable"), with their `original`/`local_change` bookkeeping. An entry set back to the shipped
value (or to an empty value) leaves the overlay, an empty overlay is deleted; installing a language
without local changes writes no file. An overlay of an earlier version holding every entry shrinks
to this delta with the next update or reinstall. Every write path maintains the overlay; the
database tables are still written as a rollback-safe fallback. At runtime `ilLanguage` serves a
migrated module only through native gettext (PHP extension `gettext`, see
`ILIAS\Language\ComponentTranslation\MigratedTranslations` and `NativeGettext`): the build with
the overlay on top (an overlay value wins), never from the database - for installed languages
only; for a language that is not installed it falls back to `lng_modules` like for a module that
is not migrated. If native gettext is not available, the texts of migrated modules are shown as
`-identifier-`, the problem is logged and shown in the language administration.

The shipped state is built by Setup's build (`php cli/setup.php build`, run by `composer install`
and `composer dump-autoload`, `ILIAS\Language\ComponentTranslation\ShippedTranslationsBuild`):
`artifacts/language/current.json` names the build `artifacts/language/<build>/` with
`messages/LC_MESSAGES/<module>.<lang>.mo`, `keys/<module>.php` and its own locale (a copy of the C
library's `C.utf8`, so no system locale per language is needed). The build checks native gettext
with a real lookup and **aborts if the extension `gettext` or `C.utf8` is missing**; it switches
`current.json` only once the new build is complete and keeps the previous build. The check runs in
the CLI process only: the web server needs the extension `gettext` as well and the same C library
(glibc with `C.utf8`; musl ignores `LOCPATH`). Only Setup builds - the web server never writes
`artifacts/`, "merge" in the translation mode only writes the shipped `.po` (its values stay in the
overlay until `setup build` and `setup update` ran).

Behaviour change against the `.lang`/database path: an identifier the migrated module loaded last
does not translate for the language gives `-identifier-`, not the value of a module loaded before.

**Deployment requirement: run `php cli/setup.php build` after every change of a shipped `.po`**
(`composer install`/`composer dump-autoload` do so). The runtime never compiles a `.po` itself: a
language file that is not in the build is not served (`-identifier-`).

Except for the runtime lookups, the `.po`/`.mo` files are read and written with the library
`gettext/gettext`, which MUST only be used through the adapter in `src/ComponentTranslation/Catalog/`
(`ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog`/`TranslationEntry`): only these
two classes import from `Gettext\...`, and the library's `Scanner` is not used, so a change of the
library version stays local and the adapter's safeguards always apply.

**Operating requirement: Setup MUST be run as the web server user** (the owner of the client data
directory); running it as root is not supported. Both Setup and the administration GUI (i.e. the
web server) write the overlay; files created by another user (e.g. root) cannot be replaced by the
web server later on. Setup never changes ownership itself and never aborts because of this: before
writing it checks the overlay directories - when it runs as root or as another user than the owner
of the client data directory, for writability by that owner - and prints a warning naming the
affected directories and the repair command (`chown -R <owner> <client data dir>/lang`). It also
warns when no client data directory exists yet (e.g. during `setup install`): the overlay is then
created by the next `setup update`. The GUI shows a warning instead of a plain success message
whenever an overlay could not be written or removed.

Further rules of the pilot:
* Writes to a module's database row and its overlay are serialized per module and language by an
  exclusive lock on `<overlay>.lock` next to the overlay; partial writes (saving or deleting single
  entries, "add new variable") are applied to the current overlay content read under that lock.
  The `.lock` files are kept (also when an overlay is removed because no local change is left);
  uninstalling a language (or plugin) removes them together with the overlay, while holding the
  lock (a process that was waiting for it
  notices the removal and locks the new file instead; if the lock file keeps being removed or
  replaced, it gives up after a few attempts, logs a warning and writes without the lock).
* Setup, "remove local changes" and "apply local changes" lock every migrated module that has an
  overlay for the whole run, so its overlay is read, reconciled and written on one state. A module
  without overlay is not locked (no file is created for it); if an administrator creates its overlay
  during the run, that overlay is left untouched and the module is reported as not written.
  Known limitation: `lng_data`/`lng_modules` are written in one batch for all modules, so the
  database row of such a concurrent edit can still be overwritten by the run (the overlay, which is
  served, keeps the edit).
* Symbolic links below `<client data dir>/lang` are never followed: an overlay write, lock or
  removal through one is refused and reported like any other overlay write failure.
* Known limitation (accepted residual risk): the symbolic link checks work on path names - PHP has
  no `openat()`/`O_NOFOLLOW` -, so a link swapped in between the check and the file operation is
  still followed. That requires write access below `<client data dir>/lang` (the web server user).
  An overlay write is re-checked after its `rename()`: if its directory then resolves outside of
  the overlay root, the write fails, and the file is removed if it is the one just written (never a
  foreign file the link points to). This re-check is best effort - a link swapped in and restored
  again before it stays undetected -, and opening the lock file can create an empty file at the
  target of a link swapped in after the check.
* A module counts as migrated for a language only while its shipped `<module>_<lang>.po` exists. If
  it is removed, the overlay is no longer read (the database is served instead) but is kept
  unchanged - no write, neither an update nor a GUI save, touches it. Once the `.po` is back, the
  next update reconciles the overlay three-way with its preserved `original` values. Otherwise an
  overlay is deleted when no local change is left or its language is uninstalled (also when Setup
  uninstalls a deselected language).
* Known limitation: a local change that only exists in the kept overlay - i.e. it was removed from
  the database (e.g. "remove local changes") while the shipped `.po` was missing - comes back with
  the next update after the `.po` has returned, because that reconciliation still finds it in the
  overlay.
* Install, update, "apply local changes" (`install_local`) and "remove local changes" all reconcile
  migrated modules with the shipped `.po` (see `LanguageInstallationManager::resolveMigratedModule()`);
  "apply local changes" only re-seeds the local changes of migrated modules and applies the
  customizing file on top.
* A key removed from the shipped `.po` is removed from `lng_data`, `lng_modules` and the overlay
  if its value is still the one it was last shipped with (the overlay's `original`); a real local
  change of such a key - or one whose `original` is unknown - is kept as a local change. (The legacy
  `.lang` update behaves the same way for locally changed keys; unchanged ones disappear with the
  flush.)
* Shipped `.po` files are generated with `tools/po-migration/convert_module_to_po.php
  [--pattern=<pattern>] [--skip-unmigratable-keys] [--remove-from-lang] <module> [referenceLanguage]
  [outputDir]` (never by hand; the reference language, `en` by default, gives the msgids and the
  `.pot`, and a language that lacks one of its keys or has it empty gets the reference value, flagged
  `#, fuzzy`; it fails if a language has keys the reference language lacks, an empty
  key or a duplicate key - with `--skip-unmigratable-keys` such keys are left out and exact duplicate
  lines (same value and comment) collapsed, with a warning each - and verifies every written entry;
  `--remove-from-lang` removes the module's lines from `lang/ilias_*.lang` after a fully successful
  run, only into an existing `components/<Vendor>/<Component>/lang` whose component already
  contributes the module's `ComponentLanguageFileDirectory` - register the contribution first, see
  `tools/po-migration/README.md`). `msgid` is the
  language key, there is no `msgctxt` (the module is the one of the directory; files with the module
  as `msgctxt` are still read); a dated "new variable" comment becomes `#, fuzzy`, other comments
  `#.` comments. The overlay is written without `msgctxt` as well; an older overlay entry with the
  module as `msgctxt` loses it with its next write.
* Only UTF-8 `.po` files are accepted.
* A module's shipped files are named `<module>_<lang>.po` by default. A `ComponentLanguageFileDirectory`
  may name them differently (4th constructor argument, e.g. `'ilias_%s'` for `ilias_<lang>.po`;
  exactly one `%s` for the language key, no `/` or `..`), so the file name need not contain the
  module name. The overlay and the build artifact are always named by the module.
* An entry without `msgctxt` belongs to the module of its directory (as if it had the module as
  context); an entry with another `msgctxt` - also an explicit empty one, `msgctxt ""` - is ignored.
  If an identifier occurs both with the module as context and without context, the former wins.
* Plugins (until they become components): a plugin may ship `lang/ilias_<lang>.po` instead of
  `lang/ilias_<lang>.lang`; if both exist, the `.po` is used. `msgid` is the key without the plugin
  prefix (as in the `.lang` file), there is no `msgctxt` (entries with one are ignored and logged) -
  so the file stays valid when the plugin later becomes a component with another module name.
  The values go into the database as before (markup that is not allowed is removed and logged,
  comments are not stored); no overlay or artifact is written for plugins. A `.po` that cannot be
  read skips that language of the plugin (logged).

## User Settings Contribution
This component contributes a personal "language" setting to the user settings framework
(`ILIAS\Language\UserSettings\Settings`, wired in `Language.php` via
`$contribute[User\Settings\UserSettings::class]`).

## Activities
This component provides seven [Activities](../Component/src/Activities/README.md)
(`ILIAS\Language\Activities\*`), each wired into `Language.php` (`$internal`/`$provide`/
`$contribute`) and used by this component's own GUI classes via `maybePerformAs()`. All seven
are `Command`s (they change installation/configuration state; none of them queries data without
side effects). See each class's own `getDescription()` for its authoritative, up-to-date
description rather than a copy here, which would drift out of sync:

* **InstallLanguage** (`src/Activities/InstallLanguage.php`) - installs/re-applies one or more
  languages, depending on the chosen mode. Mode `install` (`MODE_INSTALL`) fully installs a
  language that is not installed yet and is a no-op for an installed one. Mode `install_local`
  (`MODE_INSTALL_LOCAL`) only applies to already installed languages: it re-applies just the
  customizing/local file (`ilias_<lang>.lang.local`) on top of the stored data; not installed
  languages are skipped (reported as `not_installed_language_keys`). This is intended.
* **UpdateLanguage** (`src/Activities/UpdateLanguage.php`) - refreshes one or more already
  installed languages from the current language files. For a module migrated to PO/MO (currently
  `tos` and `poll`, see "Modules Maintained in PO Files" above) the shipped `.po` is the only source of shipped
  values, and each entry is reconciled three-way: an unchanged entry, or one whose local value equals
  the new or the previously shipped value, takes the new shipped value; a genuine local change is
  kept, even if the shipped value changed too ("remove local changes" then resets it to the current
  shipped value). Values from the customizing file always stay local.
* **UninstallLanguage** (`src/Activities/UninstallLanguage.php`) - uninstalls one or more already
  installed languages.
* **RemoveLocalLanguageChanges** (`src/Activities/RemoveLocalLanguageChanges.php`) - removes all
  local changes of one or more already installed languages and reinstalls them from the
  global/component language files.
* **AddLanguageEntry** (`src/Activities/AddLanguageEntry.php`) - adds one new "adjust language
  variables" entry to every currently installed language for which a value was given.
* **SetLanguageDetectionEnabled** (`src/Activities/SetLanguageDetectionEnabled.php`) - enables or
  disables the system-wide automatic language detection from the browser's Accept-Language
  header.
* **SetLanguageTranslationEnabled** (`src/Activities/SetLanguageTranslationEnabled.php`) - enables
  or disables the "page translation" feature for one specific language.

### Known, accepted deviations from the Activity contract

* **AddLanguageEntry::perform()** additionally accepts an optional `usr_id` (int) key in its
  `$parameters`, even though `getInputDescription()`'s `FormInput` never produces one - it is
  supplied out-of-band by `maybePerformAs()` (never spoofable via form data) and recorded as the
  author of the local change made to every written entry. A caller that omits it (e.g. a generic
  caller following the plain `getInputDescription()` -> `withInput()` -> `getContent()` ->
  `perform()` contract) is not rejected; the entries are simply written without an attributed
  author.
* **AddLanguageEntry::getInputDescription()** builds per-language field labels via
  `$this->lng->txt('meta_l_' . $lang_key)`, which requires the caller to have already called
  `loadLanguageModule('meta')` beforehand. This component's own GUI does so already (see
  `classes/class.ilObjLanguageExtGUI.php`'s constructor). A caller that skips this gets the raw,
  untranslated placeholder (e.g. `-meta_l_de-`) as the label instead of a crash. `getInputDescription()`
  deliberately does not load the module itself, since `loadLanguageModule()` merges the module's
  keys, unnamespaced, into the shared `$this->lng` state - doing so implicitly here could clobber
  keys for an unrelated module if a future generic caller iterates over several Activities sharing
  one `Language`/`ilLanguage` instance.
* **AddLanguageEntry::getInputDescription()** resolves the current set of installed languages from
  `InstalledLanguageRepository::getInstalledLanguages()` on every call, rather than being a pure,
  static description of the input shape. Within one `maybePerformAs()` call, `getInputDescription()`
  and `perform()` can therefore observe different snapshots if a language is installed/uninstalled
  concurrently between the two calls: a newly installed optional language simply ends up in
  `skipped_empty_language_keys`; a newly installed `de`/`en` makes `perform()` reject the whole
  request (fail-closed, since no value could have been submitted for it); a language uninstalled
  in the meantime has its submitted value silently dropped rather than written or reported. No
  case crashes or writes partial data.

## Supported HTML Tags in Language Files
Only a defined set of HTML tags are allowed to be used within the `text_content` of a language entry.

The rules are decided in one place, `ILIAS\Language\ComponentTranslation\TranslationMarkupPolicy`
(an HTML5 parser, no regular expressions), for every module - migrated or not:

* Tags: `a`, `b`, `bdo`, `br`, `code`, `div`, `em`, `gap`, `h3`, `i`, `img`, `li`, `ol`, `p`, `pre`,
  `s`, `small`, `span`, `strike`, `strong`, `sub`, `sup`, `u`, `ul`.
* Attributes (names case-insensitive): `a[href|target|rel|title]`, `img[src|alt]`,
  `span[class|style]`, `p[align]`, `div[align]`; all other tags without attributes. `href`/`src`
  must be relative (including `#...`) or use `http`/`https` (`href` also `mailto`), checked on the
  decoded value. An attribute value must not contain `<`. Comments, and a value that ends inside an
  unfinished tag, are not allowed.
* Text that is no tag to an HTML parser (`a <= b`, `<<`) is allowed; an allowed value stays
  byte-identical.

Where the rules apply:
* Build (shipped `.po`): a value that breaks them is compiled without the offending tags (their
  text stays), attributes and comments, and a warning with file, module, key and what was removed
  is printed; the build does not abort.
* Local write paths reject such values instead of cleaning them:
  * the administration's "edit" form (`ilObjLanguageExtGUI::saveObject()`; line breaks still become
    `<br />` and `<<` becomes `«` before the check) and the import of an uploaded language file
    (`ilObjLanguageExt::importLanguageFile()`): nothing is saved if any value is rejected, and all
    affected keys are named;
  * "add new variable" (`AddLanguageEntry`): the whole request is rejected, the affected languages
    are named;
  * the customizing file (`lang/customizing/ilias_<lang>.lang.local`) at installation, update,
    "apply local changes" and "load local file": the affected entries are left out and named
    (Setup output, administration message, PHP error log); the language is installed regardless.
* Plugin language files are not checked yet.
* The values of the shipped `lang/ilias_<lang>.lang` files are not checked ("clear local changes"
  imports them as they are).

## Further Reading
* [use-language-object.md](use-language-object.md) and [use-language-logging.md](use-language-logging.md)
  document the older, still widely used `$lng`/`ilLanguage` access pattern. They predate the
  `ILIAS\Language\Language` interface and the Activities described above and have not been updated
  to reference them; prefer the "Using the Language Service" section above for new,
  component-revision code.
* [`tests/LanguageComponentGraphTest.php`](tests/LanguageComponentGraphTest.php) is a contract test
  for this component's `$define`/`$implement`/`$use`/`$contribute`/`$seek`/`$provide`/`$pull`/`$internal`
  wiring in `Language.php` and is a good place to check the current, authoritative wiring behaviour
  (e.g. singleton guarantees, contribute ordering) rather than relying on prose descriptions here.
