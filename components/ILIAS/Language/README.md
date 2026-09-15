Language Service
================

# General Information

## Guidelines
There are a couple of guidelines defined in the [language.md](../../docs/development/language.md) that have to be respected when adding language variables to the lang files of ILIAS or editing them.

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

The component registers two candidate implementations for `ILIAS\Language\Language` in `Language.php`, disambiguated
per bootstrap entry point rather than by overwriting one another:

* `components/ILIAS/Setup/resources/dependency_resolution.php` resolves to `ilSetupLanguage`.
* `components/ILIAS/Init/resources/dependency_resolution.php` resolves to `LanguageLegacyInitialisationAdapter`
  ([src/LanguageLegacyInitialisationAdapter.php](src/LanguageLegacyInitialisationAdapter.php)), which purely proxies
  every call to `$DIC->language()` at call time. This class holds no `\ilLanguage` instance itself - it exists only
  because, at component-graph build time, no fully booted `$DIC` is available yet to resolve to directly. Replacing
  this proxy with a real, container-graph-native implementation is blocked on Core bootstrap support; see
  [ROADMAP.md](ROADMAP.md).

## Installing and Managing Languages
Which languages are installed/available (`ILIAS\Language\Setup\InstalledLanguageRepository`) and installing, flushing
or registering a language (`ILIAS\Language\Setup\LanguageInstallationManager`) are separate, narrower services -
following [the repository pattern](../../../docs/development/repository-pattern.md) - rather than part of
`ilSetupLanguage` itself. New code that only needs to install or inspect languages should depend on these two
instead of on `ilSetupLanguage`, which still exists (delegating to both) as the concrete `ILIAS\Language\Language`
implementation used during Setup and as a stable entry point for callers not yet wired through `Language.php`.

## Activities
Domain actions that used to live directly in this component's GUI classes are being extracted as
[Activities](../Component/src/Activities/README.md) - the component-wide mechanism to make "things a user does" in a
component referenceable, permission-checkable and reusable outside its GUI (e.g. by a future webservice layer).

Seven Activities are implemented so far, all under [src/Activities](src/Activities):

* `InstallLanguage`, `UpdateLanguage` - also used directly by Setup (`ilLanguageSetupAgent`), via a `forSetup()`
  factory for callers not wired through the component graph.
* `UninstallLanguage`, `RemoveLocalLanguageChanges` - runtime-only, no Setup equivalent.
* `AddLanguageEntry` - adds a single language entry across every installed language.
* `SetLanguageDetectionEnabled`, `SetLanguageTranslationEnabled` - toggle single system settings.

They are wired in [Language.php](Language.php) (`$internal`/`$provide`/`$contribute`), each provided under its own
concrete class (so `components/ILIAS/Init/Init.php` can pull a specific one, re-exposed by `AllModernComponents.php`
under the matching legacy `$DIC[<Activity>::class]` key that every GUI class in this component reads) and
contributed generically to `\ILIAS\Component\Activities\Activity::class` so the cross-component `Repository` can
discover them as well. They are consumed from `ilObjLanguageFolderGUI` and `ilObjLanguageExtGUI` via
`maybePerformAs()`.

Shared infrastructure for these Activities, also under `src/Activities`:

* `LanguageActivity` - common base class (RBAC check against the language folder `ref_id`, `maybePerformAs()`
  wiring, markdown description helper).
* `GrindsFormInput`, `DeclaresLanguageKeysOnlyInput`, `ParsesLanguageKeyList`, `ResolvesLanguageKeysToObjIds` - shared
  input parsing/validation building blocks.
* `SafeToDisplayActivityError` - marker interface for exceptions whose message is already safe to render to the
  user; `InvalidInputException` and `AmbiguousLanguageTitleException` implement it.

Every consuming GUI class uses the [`RendersActivityErrors`](src/RendersActivityErrors.php) trait to turn an
Activity's `Result\Error` into a message safe for `setOnScreenMessage()`: plain strings and
`SafeToDisplayActivityError` exceptions are shown (the latter HTML-escaped) to the user, any other `\Throwable` is
logged with its full cause chain via this component's `lang` logger channel and replaced by a generic
`action_aborted` message.

GUI-level domain logic not yet covered by an Activity remains as-is until extracted; see
[ROADMAP.md](ROADMAP.md) for the concrete candidates.

## Supported HTML Tags in Language Files
Only a defined set of HTML tags are allowed to be used within the `text_content` of a language entry:

* All tags allowed by `getSecureTags` from `ilUtil`: `a`, `b`, `bdo`, `code`, `div`, `em`, `gap`, `i`, `img`, `li`, `ol`, `p`, `pre`, `strike`, `strong`, `sub`, `sup`, `u` and `ul`
* In addition: `span` and `br`

All other HTML tags are unsupported and will be removed by `ilUtil::stripSlashes`.
