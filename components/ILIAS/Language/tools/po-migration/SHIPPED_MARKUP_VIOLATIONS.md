# Unzulässiges Markup in den mitgelieferten Sprachdateien (`lang/ilias_*.lang`)

Hinweis an die Pflege der Sprachdateien. Die Werte unten verstoßen gegen die Liste der in
Sprachtexten zulässigen Tags und Attribute (`TranslationMarkupPolicy`, siehe `README.md`,
Abschnitt "Markup-Prüfung"). Sie blockieren nichts: Beim Build werden sie für migrierte Module
bereinigt, lokale Schreibpfade prüfen nur geänderte Werte. Sie sollten aber in den Quelldateien
korrigiert werden, bevor die betroffenen Module nach `.po` migriert werden.

Stand 2026-09-25: 158 Werte in 42 Keys. Typische Ursachen:

- `<br \>` statt `<br />` (wird als Attribut `\` gelesen), v. a. `file#:#copyright_inherited_info`
  und die `prg#:#…_mail_body`-Keys in fast allen Sprachen,
- das Attribut `folder` (nur alter Internet Explorer) in `common#:#enable_webdav_info` u. a.,
- **`onclick` in `common#:#apache_auth` (`ilias_et.lang`) – eingebautes JavaScript, vorrangig
  korrigieren,**
- kaputte schließende Tags wie `</ b>` oder `</ i>` (ein Browser liest sie als Kommentar), v. a. in
  `ilias_tr.lang` und `ilias_ka.lang`,
- Tippfehler (`<br.>`, `<h>`, `<gp>`, `<verplicht>`, `<ahref=…>`) und Werte, die in einem
  unvollständigen Tag enden.

Neu erzeugen (im CLI-Container, aus dem ILIAS-Root): `php components/ILIAS/Language/tools/po-migration/scan_shipped_markup.php`

| Key | Sprachen | Verstoß |
|---|---|---|
| `administration#:#language_former_file_equal` | tr | comment " b" |
| `administration#:#language_former_file_missing` | tr | comment " b" |
| `assessment#:#close_text_hint` | zh | tag &lt;gp&gt; |
| `chatroom#:#chat_connection_disconnected` | tr | comment " b" |
| `chatroom#:#chat_connection_established` | tr | comment " b" |
| `chatroom#:#osc_browser_noti_no_support_error` | bg, sl | attribute "noreferrer&amp;quot;" of &lt;a&gt;; incomplete tag at the end |
| `chatroom#:#server_further_information` | ka, tr | comment "ა"; attribute "sunucusu" of &lt;a&gt;; attribute "yapılandırma" of &lt;a&gt;; attribute "hakkında" of &lt;a&gt;; attribute "daha" of &lt;a&gt;; attribute "fazla" of &lt;a&gt;; attribute "bilgi" of &lt;a&gt;; attribute "bulabilirsiniz" of &lt;a&gt;; attribute "&lt;" of &lt;a&gt;; attribute "a" of &lt;a&gt; |
| `chatroom#:#user_invited_self` | tr | comment " b" |
| `chatroom#:#user_kicked` | tr | comment " b" |
| `common#:#apache_auth` | et | attribute "onclick" of &lt;img&gt; |
| `common#:#cannot_unzip_file` | pt | attribute "folder" of &lt;a&gt; |
| `common#:#enable_webdav_info` | fast alle (29) | attribute "folder" of &lt;a&gt;; comment "ა"; comment " a" |
| `common#:#group_req_registration_msg` | sq | tag &lt;br.&gt; |
| `common#:#inline_file_extensions_info` | tr | comment " b" |
| `common#:#preconditions_optional_hint` | tr | comment " b" |
| `common#:#session_reminder_lead_time_info` | pl, sl | attribute "\" of &lt;br&gt;; incomplete tag at the end |
| `common#:#webfolder_dir_info` | ka, tr | comment "ა"; comment " a" |
| `common#:#webfolder_instructions_text` | hr, tr | tag &lt;h&gt;; comment " P"; comment " h3"; comment " b"; comment " li"; attribute "girin" of &lt;br&gt;; comment " ol"; attribute "&lt;br" of &lt;b&gt;; comment " Li"; comment " h3 ile Linux için &lt;h3"; comment " a"; comment " p" |
| `common#:#webfolder_mount_dir_with` | tr | comment " a" |
| `dcl#:#dcl_prop_expression_info` | hu | comment " br" |
| `file#:#copyright_inherited_info` | fast alle (31) | attribute "\" of &lt;br&gt; |
| `jscalendar#:#about_calendar_long` | ja | incomplete tag at the end |
| `ldap#:#ldap_filter_info` | tr | comment " strong" |
| `ldap#:#ldap_global_role_info` | tr | comment " span"; comment " b" |
| `ldap#:#ldap_group_filter_info` | tr | comment " strong" |
| `ldap#:#ldap_group_optional_info` | tr | comment " strong" |
| `logging#:#log_error_message_send_mail` | sl | incomplete tag at the end |
| `news#:#news_block_information` | tr | comment " b" |
| `prg#:#info_to_re_assign_mail_body` | ar, bg, cs, da, el, et, fa, fr, ka, lt, nl, pl, pt, ro, ru, sk, sq, sr, tr, uk, vi, zh | attribute "\" of &lt;br&gt; |
| `prg#:#re_assigned_mail_body` | ar, bg, cs, da, el, et, fa, fr, it, ka, lt, nl, pl, pt, ro, ru, sk, sq, sr, tr, uk, vi, zh | attribute "\" of &lt;br&gt; |
| `rbac#:#rbac_role_delete_self` | ka, tr | comment "ძლიერი"; comment " strong" |
| `rbac#:#root_read` | tr | comment " i" |
| `rbac#:#root_visible` | tr | comment " i" |
| `rbac#:#root_write` | tr | comment " i" |
| `registration#:#reg_confirmation_link_successful` | ja, ka, sl, tr | attribute "タイトル" of &lt;a&gt;; comment "ა"; tag &lt;ahref="%s"title="loginscreen"&gt;; attribute "içinde" of &lt;br&gt;; attribute "onay" of &lt;br&gt;; attribute "linkine" of &lt;br&gt;; attribute "tıklayarak" of &lt;br&gt;; attribute "kaydınızı" of &lt;br&gt;; attribute "onaylayın" of &lt;br&gt;; attribute "yönlendirileceksiniz" of &lt;a&gt;; comment " a" |
| `rep#:#rep_intro3` | ka, tr, zh | comment "ი"; comment " i"; tag &lt;i%s&lt;&gt; |
| `rep#:#rep_intro4` | ka, tr | comment "ი"; comment " i" |
| `search#:#search_no_match_hint` | pl, tr | comment " b" |
| `shib#:#shib_ilias_role` | it | incomplete tag at the end |
| `survey#:#save_obligatory_state` | nl | tag &lt;verplicht&gt; |
| `survey#:#survey_code_url_name` | tr | comment " small" |
| `survey#:#survey_previous` | sq, sr, tr | incomplete tag at the end |
