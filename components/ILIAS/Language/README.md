Language Service
================

*document in progress*

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


## Supported HTML Tags in Language Files
Only a defined set of HTML tags are allowed to be used within the `text_content` of a language entry:

* All tags allowed by `getSecureTags` from `ilUtil`: `a`, `b`, `bdo`, `code`, `div`, `em`, `gap`, `i`, `img`, `li`, `ol`, `p`, `pre`, `strike`, `strong`, `sub`, `sup`, `u` and `ul`
* In addition: `span` and `br`

All other HTML tags are unsupported and will be removed by `ilUtil::stripSlashes`.
