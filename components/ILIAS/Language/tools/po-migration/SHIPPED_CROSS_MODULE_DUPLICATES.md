# Identifier in mehreren Modulen (`lang/ilias_*.lang`)

Hinweis an die Pflege der Sprachdateien. Die Identifier unten stehen in mehr als einem Modul.
`txt($topic)` kennt kein Modul: `ilLanguage::loadLanguageModule()` führt alle geladenen Module per
`array_merge` in ein flaches Array zusammen, das **zuletzt geladene Modul gewinnt**. `common` wird im
Konstruktor zuerst geladen und verliert daher immer. Welcher Text erscheint, hängt also davon ab,
welche Module eine Seite in welcher Reihenfolge lädt. Beispiel: Eine Seite, die `bibl` und `irss`
lädt, zeigt in einem der beiden Sortier-Menüs die falschen Einträge (`sorting_3` = „By Author“ bzw.
„By File Size“).

Die PO-Umstellung ändert daran nichts: Der migrierte Pfad führt an derselben Stelle per
`array_merge` zusammen, jedes Modul hat eigene Dateien ohne `msgctxt`. Was das für den Rollout
bedeutet, steht in README.md unter „Uniqueness: Kollisionen zwischen Modulen“.

Spalten:
- **Art**: „Bedeutung (en)“ = die englischen Werte unterscheiden sich inhaltlich; „kosmetisch (en)“ =
  nur in Groß-/Kleinschreibung, Leerraum oder Satzzeichen; „nur Übersetzung“ = en gleich, andere
  Sprachen verschieden übersetzt; „gleich“ = in allen Sprachen derselbe Wert (harmlos).
- **(po)**: das Modul ist schon auf `.po` umgestellt. Die Werte stammen trotzdem aus den
  `.lang`-Dateien.
- **Aufrufer** zählt nur literale `txt('…')`/`txt("…")`. Eine 0 heißt nicht „ungenutzt“: Die
  `<typ>_copy`-Keys aus `rbac` etwa werden dynamisch aus Objekttyp und Operation zusammengesetzt.

Empfehlung: Bei „Bedeutung (en)“ je Paar einen der beiden Keys umbenennen und dessen Aufrufer
anpassen (Muster: `cont_Link` → `cont_char_link` in `82d36f3a296`). Bei „nur Übersetzung“ und
„kosmetisch“ die Werte angleichen. Bei „gleich“ ist nichts zu tun.

Erzeugt mit `php components/ILIAS/Language/tools/po-migration/scan_shipped_cross_module_duplicates.php`
(Stand 2026-09-30):

241 Identifier in mehr als einem Modul (von 17968)

| Art | Anzahl |
|---|---|
| Bedeutung (en) | 85 |
| kosmetisch (en) | 15 |
| nur Übersetzung | 130 |
| gleich | 11 |

Häufigste Modulkombinationen (ab 3 Identifiern)

| Module | Anzahl |
|---|---|
| `cmix` + `lti` | 75 |
| `assessment` + `survey` | 33 |
| `crs` + `grp` | 9 |
| `assessment` + `common` | 7 |
| `orgu` + `rbac` | 7 |
| `bibl` + `irss` | 6 |
| `administration` + `common` | 5 |
| `common` + `orgu` | 5 |
| `gsfo` + `irss` | 3 |
| `common` + `dateplaner` | 3 |

| Identifier | Module | Art | Abweichende Sprachen | en-Werte | Aufrufer (`txt('…')`) |
|---|---|---|---|---|---|
| `activity_id` | `cmix`, `lti` | kosmetisch (en) | fast alle (28) | `cmix`: Activity-ID<br>`lti`: Activity ID | 2 |
| `activity_id_info` | `cmix`, `lti` | Bedeutung (en) | fast alle (31) | `cmix`: This ID is primarily used for the display of data from the LRS. You receive this ID from the resource provider.<br>`lti`: This Activity ID is used by the LTI Provider to identify Statements. | 2 |
| `answer` | `assessment`, `survey` | nur Übersetzung | zh | `assessment`: Answer<br>`survey`: Answer | 12 |
| `bibl_copy` | `bibl`, `rbac` | nur Übersetzung | bg, es, fr, hu, nl, pt | `bibl`: Copy Bibliography<br>`rbac`: Copy Bibliography | 0 |
| `blog_copy` | `blog`, `rbac` | Bedeutung (en) | fast alle (26) | `blog`: Copy Blog<br>`rbac`: User can copy blog | 0 |
| `book_copy` | `book`, `rbac` | Bedeutung (en) | fast alle (27) | `book`: Copy Booking Pool<br>`rbac`: User can copy booking pool | 0 |
| `cal_type_tals` | `dateplaner`, `etal` | gleich | – | `dateplaner`: Talks<br>`etal`: Talks | 0 |
| `cat_copy` | `cat`, `rbac` | Bedeutung (en) | bg, el, en, es, fa, fr, hr, hu, it, ja, ka, nl, pt, ru, sk, tr, zh | `cat`: Copy Category<br>`rbac`: User can copy category | 0 |
| `category` | `assessment`, `prg`, `survey` | Bedeutung (en) | fast alle (30) | `assessment`: Category<br>`prg`: Category<br>`survey`: Answer | 1 |
| `client_settings` | `chatroom_adm`, `notifications_adm` | Bedeutung (en) | de, en, hu, it, pt | `chatroom_adm`: General Settings<br>`notifications_adm`: Client Settings | 1 |
| `cmix_copy` | `cmix`, `rbac` | Bedeutung (en) | fast alle (31) | `cmix`: Copy xAPI/cmi5 Object<br>`rbac`: User can copy xAPI/cmi5 Object | 0 |
| `concatenation` | `assessment`, `survey` | nur Übersetzung | bg, cs, da, de, et, hr, hu, ja, ka, lt, pl, sk, sq, sr, uk, vi | `assessment`: Concatenation<br>`survey`: Concatenation | 0 |
| `conf_privacy_ident` | `cmix`, `lti` | kosmetisch (en) | bg, de, en, hu, it | `cmix`: User Identification<br>`lti`: User identification | 6 |
| `conf_privacy_ident_il_uuid_ext_account` | `cmix`, `lti` | nur Übersetzung | bg, de, es, fr, it, ja, pt, sl | `cmix`: External User ID combined with a unique ILIAS platform id formatted as an E-Mail address<br>`lti`: External User ID combined with a unique ILIAS platform id formatted as an E-Mail address | 4 |
| `conf_privacy_ident_il_uuid_ext_account_info` | `cmix`, `lti` | nur Übersetzung | bg, de, es, it, ja, sv | `cmix`: This is identical to each call, but may allow a direct conclusion about the user.<br>`lti`: This is identical to each call, but may allow a direct conclusion about the user. | 4 |
| `conf_privacy_ident_il_uuid_login` | `cmix`, `lti` | nur Übersetzung | bg, de, es, fr, hu, it, ja, pt, sl, sv | `cmix`: ILIAS Login combined with a unique ILIAS platform id formatted as an E-Mail address<br>`lti`: ILIAS Login combined with a unique ILIAS platform id formatted as an E-Mail address | 4 |
| `conf_privacy_ident_il_uuid_login_info` | `cmix`, `lti` | nur Übersetzung | bg, de, es, hu, it, ja, sv | `cmix`: Sends the login name. This is identical to each call, but may allow a direct conclusion about the ILIAS user.<br>`lti`: Sends the login name. This is identical to each call, but may allow a direct conclusion about the ILIAS user. | 4 |
| `conf_privacy_ident_il_uuid_random` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja, sv | `cmix`: Random ID combined with a unique ILIAS platform ID formatted as an E-Mail address<br>`lti`: Random ID combined with a unique ILIAS platform ID formatted as an E-Mail address | 4 |
| `conf_privacy_ident_il_uuid_random_info` | `cmix`, `lti` | nur Übersetzung | bg, de, es, it, ja, sv | `cmix`: For each ILIAS object and ILIAS user a random ID is generated which remains identical for each call. Conclusions about a user are very limited because it is practically impossible to create user profiles across objects.<br>`lti`: For each ILIAS object and ILIAS user a random ID is generated which remains identical for each call. Conclusions about a user are very limited because it is practically impossible to create user profiles across objects. | 4 |
| `conf_privacy_ident_il_uuid_sha256` | `cmix`, `lti` | gleich | – | `cmix`: Hash combined with a unique ILIAS platform id formatted as an E-Mail address | 4 |
| `conf_privacy_ident_il_uuid_sha256_info` | `cmix`, `lti` | gleich | – | `cmix`: This is identical to each call, but does not permit any direct conclusions about the ILIAS user. | 4 |
| `conf_privacy_ident_il_uuid_sha256url` | `cmix`, `lti` | kosmetisch (en) | fast alle (28) | `cmix`: Hash combined with the ILIAS Domain formatted as an E-Mail address<br>`lti`: Hash combined with the ILIAS Domain formatted as an E-Mail address. | 4 |
| `conf_privacy_ident_il_uuid_sha256url_info` | `cmix`, `lti` | nur Übersetzung | es, ja, pt | `cmix`: This is identical to each call, with with a maximum of 80 characters significantly shorter than the variant with the ILIAS platform ID and allows only very limited conclusions about the ILIAS user.<br>`lti`: This is identical to each call, with with a maximum of 80 characters significantly shorter than the variant with the ILIAS platform ID and allows only very limited conclusions about the ILIAS user. | 4 |
| `conf_privacy_ident_il_uuid_user_id` | `cmix`, `lti` | nur Übersetzung | bg, de, es, fr, it, ja, pt, sl, sv | `cmix`: ILIAS user id combined with a unique ILIAS platform id formatted as an E-Mail address<br>`lti`: ILIAS user id combined with a unique ILIAS platform id formatted as an E-Mail address | 4 |
| `conf_privacy_ident_il_uuid_user_id_info` | `cmix`, `lti` | nur Übersetzung | bg, de, es, it, ja | `cmix`: Sends the internal numeric user id. This is identical to each call, but may allow conclusions about the ILIAS user.<br>`lti`: Sends the internal numeric user id. This is identical to each call, but may allow conclusions about the ILIAS user. | 4 |
| `conf_privacy_ident_info` | `cmix`, `lti` | kosmetisch (en) | fast alle (25) | `cmix`: Standard is frequently the E-Mail address. The unique ILIAS platform id is:<br>`lti`: Standard is frequently the email address. The unique ILIAS platform id is: | 4 |
| `conf_privacy_ident_real_email` | `cmix`, `lti` | nur Übersetzung | bg, de, it | `cmix`: E-Mail Address<br>`lti`: E-Mail Address | 4 |
| `conf_privacy_ident_real_email_info` | `cmix`, `lti` | nur Übersetzung | bg, de, es, hu, it, ja | `cmix`: Sends E-Mail Address of user as identification (Warning: an E-Mail Address might be used by multiple users!)<br>`lti`: Sends E-Mail Address of user as identification (Warning: an E-Mail Address might be used by multiple users!) | 4 |
| `conf_privacy_name` | `cmix`, `lti` | nur Übersetzung | bg, de, it, ja | `cmix`: User name<br>`lti`: User name | 6 |
| `conf_privacy_name_firstname` | `cmix`, `lti` | nur Übersetzung | it, ja | `cmix`: First name<br>`lti`: First name | 4 |
| `conf_privacy_name_firstname_info` | `cmix`, `lti` | nur Übersetzung | bg, de, it, ja, sv | `cmix`: Sends the first name of the user name from ILIAS<br>`lti`: Sends the first name of the user name from ILIAS | 2 |
| `conf_privacy_name_fullname` | `cmix`, `lti` | nur Übersetzung | bg, it, ja | `cmix`: Entire name<br>`lti`: Entire name | 4 |
| `conf_privacy_name_fullname_info` | `cmix`, `lti` | nur Übersetzung | bg, de, es, it, ja | `cmix`: Sends title, first name and last name<br>`lti`: Sends title, first name and last name | 2 |
| `conf_privacy_name_info` | `cmix`, `lti` | nur Übersetzung | bg, de, it, ja | `cmix`: Sending an user name is usually not required.<br>`lti`: Sending an user name is usually not required. | 4 |
| `conf_privacy_name_lastname` | `cmix`, `lti` | nur Übersetzung | bg, es, it, ja | `cmix`: Title and last name<br>`lti`: Title and last name | 4 |
| `conf_privacy_name_lastname_info` | `cmix`, `lti` | nur Übersetzung | bg, de, it, ja | `cmix`: Sends Mister or Ms/Mrs. (unless otherwise specified) and the last name<br>`lti`: Sends Mister or Ms/Mrs. (unless otherwise specified) and the last name | 2 |
| `conf_privacy_name_none` | `cmix`, `lti` | nur Übersetzung | es, it, ja | `cmix`: No one<br>`lti`: No one | 4 |
| `conf_privacy_name_none_info` | `cmix`, `lti` | nur Übersetzung | bg, de, it, ja | `cmix`: Sends '-' instead of a name<br>`lti`: Sends '-' instead of a name | 4 |
| `conf_user_ident` | `cmix`, `lti` | nur Übersetzung | de, hu, ja | `cmix`: User identification<br>`lti`: User identification | 0 |
| `conf_user_ident_il_uuid_ext_account` | `cmix`, `lti` | Bedeutung (en) | bg, de, en, es, fr, hr, hu, it, ja, pt, sl | `cmix`: External User ID combined with a unique ILIAS platform id formatted as an E-Mail adress<br>`lti`: External User ID combined with a unique ILIAS platform id formated as an email adress. | 0 |
| `conf_user_ident_il_uuid_ext_account_info` | `cmix`, `lti` | nur Übersetzung | de, es, ja, sv | `cmix`: This is identical to each call, but may allow a direct conclusion about the user.<br>`lti`: This is identical to each call, but may allow a direct conclusion about the user. | 0 |
| `conf_user_ident_il_uuid_login` | `cmix`, `lti` | Bedeutung (en) | bg, de, en, es, fr, hr, hu, it, ja, pt, sl, sv | `cmix`: ILIAS Login combined with a unique ILIAS platform id formatted as an E-Mail adress<br>`lti`: ILIAS Login combined with a unique ILIAS platform id formated as an email adress. | 0 |
| `conf_user_ident_il_uuid_login_info` | `cmix`, `lti` | nur Übersetzung | de, es, ja, sv | `cmix`: This is identical to each call, but may allow a direct conclusion about the ILIAS user.<br>`lti`: This is identical to each call, but may allow a direct conclusion about the ILIAS user. | 0 |
| `conf_user_ident_il_uuid_user_id` | `cmix`, `lti` | Bedeutung (en) | bg, de, en, es, fr, hr, it, ja, pt, sl | `cmix`: ILIAS user id combined with a unique ILIAS platform id formatted as an E-Mail adress<br>`lti`: ILIAS user id combined with a unique ILIAS platform id formated as an email adress. | 0 |
| `conf_user_ident_il_uuid_user_id_info` | `cmix`, `lti` | nur Übersetzung | de, ja | `cmix`: This is identical to each call, but doesn't allow a direct conclusion about the ILIAS user.<br>`lti`: This is identical to each call, but doesn't allow a direct conclusion about the ILIAS user. | 0 |
| `conf_user_ident_info` | `cmix`, `lti` | nur Übersetzung | es, ja | `cmix`: Standard is frequently the email address. The unique ILIAS platform id is:<br>`lti`: Standard is frequently the email address. The unique ILIAS platform id is: | 0 |
| `conf_user_ident_real_email` | `cmix`, `lti` | nur Übersetzung | de, hu, ja | `cmix`: E-Mail Address<br>`lti`: E-Mail Address | 0 |
| `conf_user_ident_real_email_info` | `cmix`, `lti` | nur Übersetzung | de, es, ja | `cmix`: Sends E-Mail Address of user as identification (Warning: an E-Mail Address might be used by multiple users!)<br>`lti`: Sends E-Mail Address of user as identification (Warning: an E-Mail Address might be used by multiple users!) | 0 |
| `conf_user_name` | `cmix`, `lti` | kosmetisch (en) | en, pt | `cmix`: User Name<br>`lti`: User name | 1 |
| `conf_user_name_firstname` | `cmix`, `lti` | kosmetisch (en) | en, pt | `cmix`: First Name<br>`lti`: First name | 0 |
| `conf_user_name_firstname_info` | `cmix`, `lti` | nur Übersetzung | de, ja, sv | `cmix`: Sends the first name of the user name from ILIAS<br>`lti`: Sends the first name of the user name from ILIAS | 0 |
| `conf_user_name_fullname` | `cmix`, `lti` | kosmetisch (en) | en, ja, pt | `cmix`: Entire Name<br>`lti`: Entire name | 0 |
| `conf_user_name_fullname_info` | `cmix`, `lti` | nur Übersetzung | es, ja | `cmix`: Sends title, first name and last name<br>`lti`: Sends title, first name and last name | 0 |
| `conf_user_name_info` | `cmix`, `lti` | nur Übersetzung | es, ja | `cmix`: Sending an user name is usually not required.<br>`lti`: Sending an user name is usually not required. | 0 |
| `conf_user_name_lastname` | `cmix`, `lti` | kosmetisch (en) | en, ja, pt | `cmix`: Title and Last Name<br>`lti`: Title and last name | 0 |
| `conf_user_name_lastname_info` | `cmix`, `lti` | nur Übersetzung | ja | `cmix`: Sends Mister or Ms/Mrs. (unless otherwise specified) and the last name<br>`lti`: Sends Mister or Ms/Mrs. (unless otherwise specified) and the last name | 0 |
| `conf_user_name_none` | `cmix`, `lti` | gleich | – | `cmix`: No one<br>`lti`: No one | 0 |
| `conf_user_name_none_info` | `cmix`, `lti` | nur Übersetzung | hu, ja | `cmix`: Sends '-' instead of a name<br>`lti`: Sends '-' instead of a name | 0 |
| `confirm_sync_questions` | `assessment`, `survey` | Bedeutung (en) | fast alle (31) | `assessment`: The question you changed is a copy which has been created for use with the active test. Do you want to change the original of the question too?<br>`survey`: The question you have changed is a copy which has been created for use with the current survey. Do you also want to change the original version of the question? | 2 |
| `continue` | `common`, `survey` | kosmetisch (en) | fast alle (31) | `common`: Continue<br>`survey`: Continue → | 4 |
| `copa_copy` | `copa`, `rbac` | nur Übersetzung | es, hr, it, pt | `copa`: Copy Content Page<br>`rbac`: Copy Content Page | 0 |
| `counter` | `assessment`, `scormtrac` | kosmetisch (en) | fast alle (26) | `assessment`: Counter<br>`scormtrac`: counter | 1 |
| `create_new` | `assessment`, `survey` | nur Übersetzung | da, et, fa, fr, it, ka, nl, pl, ru, sr, zh | `assessment`: Create New<br>`survey`: Create New | 2 |
| `created` | `assessment`, `common` | Bedeutung (en) | fast alle (31) | `assessment`: Created<br>`common`: Creation Date | 8 |
| `criteria` | `badge`, `user` | nur Übersetzung | cs, fr, it, ja, pl, pt, sv | `badge`: Criteria<br>`user`: Criteria | 2 |
| `crs_add_grouping` | `crs`, `grp` | nur Übersetzung | ar, bg, cs, da, el, et, fa, it, ja, ka, lt, nl, pl, pt, ro, ru, sk, sq, sr, uk, vi, zh | `crs`: Add Membership Limitation<br>`grp`: Add Membership Limitation | 2 |
| `crs_copy` | `crs`, `rbac` | Bedeutung (en) | en, es, fa, fr, hr, hu, it, ja, ka, nl, pt, ru, sk, tr | `crs`: Copy Course<br>`rbac`: User can copy course | 0 |
| `crs_grouping_delete_sure` | `crs`, `grp` | Bedeutung (en) | fast alle (31) | `crs`: Do you really want to delete the following membership limitations?<br>`grp`: Are you sure you want to delete this membership limitation ? | 1 |
| `crs_grouping_deleted` | `crs`, `grp` | Bedeutung (en) | fast alle (31) | `crs`: Deleted course grouping.<br>`grp`: Deleted membership limitation. | 1 |
| `crs_grouping_select_one` | `crs`, `grp` | Bedeutung (en) | fast alle (31) | `crs`: Please select a course grouping.<br>`grp`: Please select one membership limitation. | 1 |
| `crs_grp_added_grouping` | `crs`, `grp` | nur Übersetzung | da, fa, fr, hr, hu, it, ka, pl, sl, zh | `crs`: Added new membership limitation.<br>`grp`: Added new membership limitation. | 1 |
| `crs_grp_assign_crs` | `crs`, `grp` | Bedeutung (en) | fast alle (31) | `crs`: Assigned Objects of Membership Limitation<br>`grp`: Assignment of Groups | 1 |
| `crs_grp_no_courses_assigned` | `crs`, `grp` | Bedeutung (en) | fast alle (31) | `crs`: None<br>`grp`: No Groups Assigned | 1 |
| `crsv_create` | `crsv`, `scov` | Bedeutung (en) | fast alle (31) | `crsv`: Create Course Certificate<br>`scov`: Create SCORM Certificate | 1 |
| `crsv_create_info` | `crsv`, `scov` | Bedeutung (en) | fast alle (31) | `crsv`: Select a completed course to generate a certificate for it<br>`scov`: Select a completed learning module to generate a certificate for it | 1 |
| `dcl_copy` | `dcl`, `rbac` | Bedeutung (en) | fast alle (27) | `dcl`: Copy Data Collection<br>`rbac`: User can copy data collection | 0 |
| `dcl_visible` | `dcl`, `rbac` | Bedeutung (en) | fast alle (31) | `dcl`: Visible for all users<br>`rbac`: Data Collection is visible | 0 |
| `description_info` | `cmix`, `lti` | nur Übersetzung | bg, it | `cmix`: The description will be shown below the title.<br>`lti`: The description will be shown below the title. | 2 |
| `dont_use_questionpool` | `assessment`, `survey` | nur Übersetzung | fast alle (27) | `assessment`: Don't insert the questions in a question pool (only available in this test) | 0 |
| `download_certificate` | `certificate`, `cmix` | nur Übersetzung | ar, cs, et, fr, hr, it, ka, nl, pl, pt, sk, sl, tr, zh | `certificate`: Download Certificate<br>`cmix`: Download Certificate | 12 |
| `duplicate` | `assessment`, `survey` | nur Übersetzung | el, fr, hr, ja, lt, ro, sl, sr, tr, uk | `assessment`: Duplicate<br>`survey`: Duplicate | 0 |
| `edit_questions` | `cntr`, `obj` | nur Übersetzung | bg, cs, da, et, fa, ja, ka, nl, tr, zh | `cntr`: Edit Questions<br>`obj`: Edit Questions | 0 |
| `end_date` | `dateplaner`, `survey` | nur Übersetzung | cs, da, de, es, et, fa, hu, it, lt, pl, pt, ro, ru, sk, sl, sq, sv, uk, vi, zh | `dateplaner`: End Date<br>`survey`: End Date | 1 |
| `enlarge` | `assessment`, `common` | nur Übersetzung | cs, da, de, el, es, et, fr, hr, hu, it, ka, lt, nl, pl, pt, ro, ru, sk, sl, sq, sr, sv, tr, zh | `assessment`: Enlarge<br>`common`: Enlarge | 1 |
| `entries_target_group` | `gsfo`, `irss` | nur Übersetzung | hu | `gsfo`: Select target group<br>`irss`: Select target group | 0 |
| `entry_deleted_failed` | `gsfo`, `irss` | nur Übersetzung | hu | `gsfo`: Deletion not possible<br>`irss`: Deletion not possible | 0 |
| `error_creating_certificate_pdf` | `cert`, `wsp` | Bedeutung (en) | bg, en, es, hr, ja, pt, sl | `cert`: The certificate could not be created. Please contact your installation’s technical support and ask for the certificate server to be checked.<br>`wsp`: The certificate could not be created. Please contact the administrator to check the certificate server. | 23 |
| `events` | `crs`, `grp` | nur Übersetzung | cs, da, el, et, fa, ka, lt, pl, ro, sk, sq, sr | `crs`: Sessions<br>`grp`: Sessions | 0 |
| `exc_copy` | `exc`, `rbac` | Bedeutung (en) | en, es, fa, fr, hr, hu, ja, ka, nl, pt, ru, sk, tr, zh | `exc`: Copy Exercise<br>`rbac`: User can copy exercise | 0 |
| `exp_type_excel` | `assessment`, `survey` | nur Übersetzung | el, fr, pl, uk, zh | `assessment`: Microsoft Excel<br>`survey`: Microsoft Excel | 2 |
| `file_copy` | `file`, `rbac` | Bedeutung (en) | en, es, fa, fr, hr, hu, ja, ka, nl, pt, ru, sk, tr | `file`: Copy File<br>`rbac`: User can copy file | 0 |
| `filter_all_question_types` | `assessment`, `survey` | nur Übersetzung | et, fa, fr, ja, ka, nl, ru, sk, sq, sr, uk | `assessment`: All Question Types<br>`survey`: All Question Types | 5 |
| `filter_all_questionpools` | `assessment`, `survey` | nur Übersetzung | bg, el, es, et, fa, ja, ka, lt, pt, ru, sk, uk, vi | `assessment`: All Question Pools<br>`survey`: All Question Pools | 1 |
| `fold_copy` | `fold`, `rbac` | Bedeutung (en) | en, es, fa, fr, hr, hu, it, ja, ka, nl, pt, sk, tr | `fold`: Copy Folder<br>`rbac`: User can copy folder | 0 |
| `form_msg_file_wrong_file_type` | `form`, `prg` | nur Übersetzung | cs, da, de, et, fr, it, ka, nl, sk, sl, sv, tr, zh | `form`: Wrong file type.<br>`prg`: Wrong file type. | 9 |
| `frm_copy` | `forum`, `rbac` | Bedeutung (en) | en, es, fa, fr, hr, hu, ja, ka, nl, pt, ru, sk, sv, tr | `forum`: Copy Forum<br>`rbac`: User can copy forum | 0 |
| `glo_copy` | `glo`, `rbac` | Bedeutung (en) | en, es, fa, fr, hr, hu, it, ja, ka, nl, pt, sk, tr, zh | `glo`: Copy Glossary<br>`rbac`: User can copy glossary | 0 |
| `glossary_term` | `assessment`, `survey` | nur Übersetzung | bg, cs, da, el, fa, fr, hr, nl, ru, sk, sq, tr, vi | `assessment`: Glossary Term<br>`survey`: Glossary Term | 1 |
| `grouping_change_assignment` | `crs`, `grp` | nur Übersetzung | cs, de, fa, hr, it, nl, pl, sk, sl, zh | `crs`: Change Assignment<br>`grp`: Change Assignment | 2 |
| `grp_copy` | `grp`, `rbac` | Bedeutung (en) | en, es, fa, fr, hr, hu, it, ja, ka, nl, pt, ru, sk, tr | `grp`: Copy Group<br>`rbac`: User can copy group | 0 |
| `highscore_achieved_ts` | `cmix`, `lti` | nur Übersetzung | bg | `cmix`: Date<br>`lti`: Date | 2 |
| `highscore_achieved_ts_description` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: A column containing the date will be included in the ranking.<br>`lti`: A column containing the date will be included in the ranking. | 2 |
| `highscore_all_tables` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: Participant's Own Rank and Top Ranking<br>`lti`: Participant's Own Rank and Top Ranking | 2 |
| `highscore_all_tables_description` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: Participants get information about the top ranking and their own position in the ranking.<br>`lti`: Participants get information about the top ranking and their own position in the ranking. | 2 |
| `highscore_description` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: The names of other users could be displayed if the right 'View learning experiences of other users' is set.<br>`lti`: The names of other users could be displayed if the right 'View learning experiences of other users' is set. | 2 |
| `highscore_enabled` | `cmix`, `lti` | nur Übersetzung | hu, it | `cmix`: Ranking<br>`lti`: Ranking | 2 |
| `highscore_mode` | `cmix`, `lti` | nur Übersetzung | bg | `cmix`: Mode<br>`lti`: Mode | 2 |
| `highscore_own_table` | `cmix`, `lti` | nur Übersetzung | bg, it | `cmix`: Participant's Own Rank<br>`lti`: Participant's Own Rank | 2 |
| `highscore_own_table_description` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: Participants are advised of their own position in the ranking.<br>`lti`: Participants are advised of their own position in the ranking. | 2 |
| `highscore_percentage` | `cmix`, `lti` | gleich | – | `cmix`: Percentage<br>`lti`: Percentage | 2 |
| `highscore_percentage_description` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: A column containing the score as percentage will be included in the ranking.<br>`lti`: A column containing the score as percentage will be included in the ranking. | 2 |
| `highscore_score` | `cmix`, `lti` | gleich | – | `cmix`: Score<br>`lti`: Score | 0 |
| `highscore_score_description` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: A column containing the score will be included in the ranking.<br>`lti`: A column containing the score will be included in the ranking. | 0 |
| `highscore_top_num` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it | `cmix`: Length of Top Ranking<br>`lti`: Length of Top Ranking | 2 |
| `highscore_top_num_description` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: Specify how many ranks are to be included in the top ranking list.<br>`lti`: Specify how many ranks are to be included in the top ranking list. | 2 |
| `highscore_top_num_unit` | `cmix`, `lti` | nur Übersetzung | it | `cmix`: entries<br>`lti`: entries | 2 |
| `highscore_top_table` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it | `cmix`: Top Ranking<br>`lti`: Top Ranking | 2 |
| `highscore_top_table_description` | `cmix`, `lti` | nur Übersetzung | bg, es, hu, it, ja | `cmix`: Participants are presented with a table containing the top rankings.<br>`lti`: Participants are presented with a table containing the top rankings. | 2 |
| `highscore_wtime` | `cmix`, `lti` | gleich | – | `cmix`: Duration<br>`lti`: Duration | 2 |
| `highscore_wtime_description` | `cmix`, `lti` | nur Übersetzung | bg, de, es, hu, it, sv | `cmix`: A column containing the duration will be included in the ranking.<br>`lti`: A column containing the duration will be included in the ranking. | 2 |
| `iass_copy` | `iass`, `rbac` | Bedeutung (en) | fast alle (25) | `iass`: Copy Individual Assessment<br>`rbac`: Copy an Individual Assessment | 0 |
| `identifier` | `bibl`, `common` | nur Übersetzung | ar, bg, cs, da, de, el, et, fa, fr, hu, ka, lt, nl, pl, pt, ro, ru, sk, sq, sr, tr, uk, vi, zh | `bibl`: Identifier<br>`common`: Identifier | 7 |
| `il_sess_participant` | `rbac`, `sess` | Bedeutung (en) | fast alle (28) | `rbac`: Session Participant<br>`sess`: Session Participants | 1 |
| `import_question` | `assessment`, `survey` | nur Übersetzung | cs, el, et, fr, ja, ka, nl, ro, ru, sk, sq, sr, tr | `assessment`: Import Question(s)<br>`survey`: Import Question(s) | 2 |
| `insert_after` | `assessment`, `survey` | nur Übersetzung | ja, ka, ru, sr, vi | `assessment`: Insert After<br>`survey`: Insert After | 1 |
| `insert_before` | `assessment`, `survey` | nur Übersetzung | hr, hu, ka, ru, sq, sr, vi | `assessment`: Insert Before<br>`survey`: Insert Before | 1 |
| `item_moved` | `gsfo`, `irss` | nur Übersetzung | hu | `gsfo`: Entry moved<br>`irss`: Entry moved | 0 |
| `lm_copy` | `lm`, `rbac` | Bedeutung (en) | fast alle (27) | `lm`: Copy Learning Module<br>`rbac`: User can copy ILIAS learning module | 0 |
| `lm_only_one_download_per_type` | `content`, `lti` | Bedeutung (en) | de, en, es, hu, it, ja, pl, sl, sv | `content`: Only one file per type (XML, HTML) can be released publicly.<br>`lti`: Only one file per type (XML, HTML, SCORM) can be released publicly. | 0 |
| `lso_edit_permission` | `lso`, `rbac` | Bedeutung (en) | fast alle (28) | `lso`: User can change permission settings<br>`rbac`: User can change permission settings of Learning Sequence | 0 |
| `lso_read` | `lso`, `rbac` | Bedeutung (en) | fast alle (30) | `lso`: User has read access to Learning Sequence<br>`rbac`: Users can access the Learning Sequence | 0 |
| `lti_copy` | `lti`, `rbac` | Bedeutung (en) | fast alle (31) | `lti`: Copy Consumer<br>`rbac`: User can copy LTI consumer | 0 |
| `main` | `adn`, `chatroom`, `mme` | Bedeutung (en) | fast alle (31) | `adn`: Notifications<br>`chatroom`: Main Room<br>`mme`: Main Menu | 1 |
| `maintenance` | `assessment`, `survey` | Bedeutung (en) | de, en, es, et, fr, hr, hu, it, ja, ka, pl, pt, ru, sl, sq, sv, tr, vi, zh | `assessment`: Maintenance<br>`survey`: Participants | 0 |
| `maxchars` | `assessment`, `survey` | nur Übersetzung | da, de, et, fa, hr, nl, ru, sl, sv, tr, vi, zh | `assessment`: Maximum Number of Characters<br>`survey`: Maximum Number of Characters | 3 |
| `mcst_copy` | `mcst`, `rbac` | Bedeutung (en) | fast alle (26) | `mcst`: Copy Mediacast<br>`rbac`: User can copy mediacast | 0 |
| `meta_typical_learning_time` | `meta`, `trac` | kosmetisch (en) | ar, bg, cs, da, de, el, en, fa, fr, hr, it, lt, pl, pt, ro, ru, sk, sq, sr, tr, uk, zh | `meta`: Typical Learning Time<br>`trac`: Typical learning time | 4 |
| `news_add_news` | `news`, `rbac` | nur Übersetzung | ar, cs, de, es, et, fr, hr, ka, nl, pl, sk, sl, sv, tr, zh | `news`: Add News<br>`rbac`: Add News | 2 |
| `no_question_selected_for_move` | `assessment`, `survey` | Bedeutung (en) | cs, da, de, el, en, es, et, fr, hr, it, ja, ka, lt, ro, ru, sk, sl, sq, sr, tr, uk, vi, zh | `assessment`: Please check at least one question to move it!<br>`survey`: Please select at least one question that you would like to move. | 1 |
| `no_target_selected_for_move` | `assessment`, `survey` | nur Übersetzung | bg, da, el, et, fr, hr, it, ja, ka, lt, nl, pl, ru, sq, sr, uk, vi, zh | `assessment`: You must select a target position!<br>`survey`: You must select a target position! | 1 |
| `not_a_string` | `validation`, `violation` | Bedeutung (en) | fast alle (31) | `validation`: Value of type '%s' is not a string.<br>`violation`: Given value is not a String | 0 |
| `notification_settings` | `forum`, `notifications_adm` | nur Übersetzung | es, hu | `forum`: Notification Settings<br>`notifications_adm`: Notification Settings | 5 |
| `obj_gsfo` | `administration`, `common` | nur Übersetzung | ja | `administration`: Footer<br>`common`: Footer | 0 |
| `obj_gsfo_desc` | `administration`, `common` | nur Übersetzung | ja | `administration`: Administrate Footer Layout and Content<br>`common`: Administrate Footer Layout and Content | 0 |
| `obj_prg_select` | `common`, `prg` | Bedeutung (en) | fast alle (31) | `common`: -- Please select one study programme --<br>`prg`: -- Please select a study programme -- | 0 |
| `obj_tile_image_info` | `lti`, `obj` | Bedeutung (en) | fast alle (31) | `lti`: Use an Image in Square Format<br>`obj`: This image will be used as a thumbnail-style image (called a tile) to represent this object in the container (e.g. the category, folder group etc.) in which it is located. The tile is only used if the parent container sets its ‘Content Display Options' to ‘Tiles’ (rather than ‘List’). | 2 |
| `online_info` | `cmix`, `lti` | nur Übersetzung | bg, hu, ja | `cmix`: This makes the object visible and usable for the users.<br>`lti`: This makes the object visible and usable for the users. | 2 |
| `or` | `assessment`, `survey` | nur Übersetzung | el, it, ja, lt | `assessment`: or<br>`survey`: or | 2 |
| `order` | `assessment`, `bibl` | nur Übersetzung | cs, hr, it, ja, pt, sl | `assessment`: Order<br>`bibl`: Order | 1 |
| `org_op_access_enrolments` | `common`, `orgu` | Bedeutung (en) | fast alle (28) | `common`: View Course Membership Status<br>`orgu`: View Course Memberships | 0 |
| `org_op_access_results` | `orgu`, `rbac` | Bedeutung (en) | fast alle (31) | `orgu`: Access Results<br>`rbac`: Access Results of Subordinated Users | 0 |
| `org_op_edit_submissions_grades` | `orgu`, `rbac` | gleich | – | `orgu`: Edit submissions of other users<br>`rbac`: Edit submissions of other users | 0 |
| `org_op_manage_members` | `common`, `orgu` | Bedeutung (en) | fast alle (31) | `common`: Manage Subordinate Members<br>`orgu`: Manage Members | 0 |
| `org_op_manage_participants` | `orgu`, `rbac` | nur Übersetzung | fast alle (26) | `rbac`: Manage Subordinated Participants | 0 |
| `org_op_read_learning_progress` | `common`, `orgu` | Bedeutung (en) | fast alle (31) | `common`: View Learning Progress of Subordinate Users<br>`orgu`: View learning progress of other users | 0 |
| `org_op_score_participants` | `orgu`, `rbac` | nur Übersetzung | fast alle (26) | `rbac`: Score Subordinated Participants | 0 |
| `org_op_view_certificates` | `orgu`, `rbac` | Bedeutung (en) | fast alle (31) | `orgu`: View certificates of other users<br>`rbac`: View certificates of subordinated users | 0 |
| `org_op_view_competences` | `orgu`, `rbac` | Bedeutung (en) | fast alle (31) | `orgu`: View competences of other users<br>`rbac`: View competences of subordinated users | 0 |
| `org_op_write_learning_progress` | `orgu`, `rbac` | Bedeutung (en) | fast alle (31) | `orgu`: Set learning progress of other users<br>`rbac`: Set learning Progress of subordinate users | 0 |
| `participants` | `assessment`, `book` | nur Übersetzung | bg, da, de, el, et, fa, hu, ja, ka, lt, nl, pl, pt, ro, ru, sk, tr, uk, vi, zh | `assessment`: Participants<br>`book`: Participants | 3 |
| `pdf_export` | `common`, `prtf` | nur Übersetzung | ar, cs, de, el, et, fa, fr, hr, hu, ja, ka, lt, pt, ru, sk, sl, tr, zh | `common`: PDF Export<br>`prtf`: PDF Export | 0 |
| `percentage` | `assessment`, `prg` | Bedeutung (en) | fast alle (31) | `assessment`: Percentage<br>`prg`: % | 1 |
| `poll_copy` | `poll` (po), `rbac` | Bedeutung (en) | fast alle (27) | `poll`: Copy Poll<br>`rbac`: User can copy poll | 0 |
| `prg_copy_threads_info` | `common`, `prg` | Bedeutung (en) | fast alle (31) | `common`: Please decide which Study Program elements should be copied, linked or even omitted.<br>`prg`: Please decide which Study Programme elements are to be copied, linked or omitted. | 0 |
| `prg_manage_members` | `prg`, `rbac` | Bedeutung (en) | fast alle (29) | `prg`: Manage Enrolments of Study Programme<br>`rbac`: Manage members of Study Programme | 0 |
| `prtt_copy` | `prtt`, `rbac` | Bedeutung (en) | fast alle (28) | `prtt`: Copy Portfolio Template<br>`rbac`: User can copy portfolio template | 0 |
| `qpl_confirm_delete_questions` | `assessment`, `survey` | Bedeutung (en) | fast alle (28) | `assessment`: Are you sure you want to remove the following questions?<br>`survey`: Are you sure you want to delete the following question(s)? | 3 |
| `qpl_delete_select_none` | `assessment`, `survey` | Bedeutung (en) | fast alle (29) | `assessment`: Please check at least one question to remove it<br>`survey`: You did not select a question for deletion. Please select at least one question that you would like to delete and try again. | 3 |
| `qpl_export_select_none` | `assessment`, `survey` | Bedeutung (en) | da, de, el, en, es, et, fr, hr, hu, it, ja, ka, lt, nl, pl, ro, ru, sl, sq, sr, sv, tr, uk, zh | `assessment`: Please check at least one question to export it<br>`survey`: You did not select a question to be exported. Please select at least one question that you would like to export and try again. | 2 |
| `qpl_questions_deleted` | `assessment`, `survey` | Bedeutung (en) | de, el, en, et, hr, hu, it, ja, ka, pl, pt, ro, sl, sq, sr, sv, tr, uk, zh | `assessment`: Question(s) removed.<br>`survey`: Question(s) deleted. | 2 |
| `question_type` | `assessment`, `survey` | nur Übersetzung | bg, et, fr, ja, lt, nl, sq, uk | `assessment`: Question Type<br>`survey`: Question Type | 20 |
| `questions` | `assessment`, `survey` | nur Übersetzung | bg, el, et, fa, hr, it, ja, ka, lt, nl, pt, ro, ru, sk, sl, sq, sr, sv, uk, vi | `assessment`: Questions<br>`survey`: Questions | 3 |
| `rbac_log` | `ps`, `rbac` | Bedeutung (en) | fast alle (31) | `ps`: Permission Log<br>`rbac`: Log | 2 |
| `remove_question` | `assessment`, `survey` | nur Übersetzung | et, fa, it, ka, ro, tr, uk | `assessment`: Remove<br>`survey`: Remove | 1 |
| `resource_id` | `file`, `irss` | nur Übersetzung | de, hu, sv | `file`: Resource ID<br>`irss`: Resource ID | 2 |
| `restore` | `forum`, `mme` | Bedeutung (en) | fast alle (31) | `forum`: Restore<br>`mme`: Reset Main Menu | 1 |
| `result` | `assessment`, `scormtrac` | kosmetisch (en) | fast alle (30) | `assessment`: Result<br>`scormtrac`: result | 2 |
| `results` | `assessment`, `survey` | nur Übersetzung | da, et, fa, ka, lt, pl, ru, sk, tr, zh | `assessment`: Results<br>`survey`: Results | 5 |
| `roles` | `common`, `dcl` | Bedeutung (en) | fast alle (30) | `common`: Roles<br>`dcl`: Roles With Access | 5 |
| `rpc_pdf_font` | `administration`, `common` | gleich | – | `common`: Fonts | 1 |
| `rpc_pdf_font_info` | `administration`, `common` | gleich | – | `common`: Additional fonts for the generation of PDF files. Other fonts than ‘Helvetica’ and ‘unifont’ must be installed on the ILIAS server. | 1 |
| `rpc_pdf_generation` | `administration`, `common` | gleich | – | `common`: Fonts for PDF Generation | 1 |
| `sahs_copy` | `rbac`, `sahs` | Bedeutung (en) | fast alle (31) | `rbac`: User can copy SCORM learning module<br>`sahs`: Copy SCORM Learning Module | 0 |
| `scope` | `chatroom`, `common`, `orgu`, `scormtrac`, `style` | Bedeutung (en) | fast alle (31) | `chatroom`: Room<br>`common`: Scope<br>`orgu`: In<br>`scormtrac`: scope<br>`style`: Scope | 2 |
| `search_area_info` | `search`, `trac` | Bedeutung (en) | fast alle (31) | `search`: Please select an area where the search should start.<br>`trac`: Please choose one object. | 1 |
| `search_content` | `mail`, `search` | Bedeutung (en) | fast alle (31) | `mail`: Search Result<br>`search`: Page Content | 0 |
| `search_groups` | `assessment`, `survey` | Bedeutung (en) | fast alle (30) | `assessment`: Search Groups<br>`survey`: Found Groups | 0 |
| `search_no_match` | `search`, `wsp` | nur Übersetzung | fast alle (25) | `search`: Your search did not match any results. | 7 |
| `search_term` | `assessment`, `survey` | nur Übersetzung | bg, cs, da, de, el, fr, it, ka, lt, nl, pl, ro, ru, sk, sl, sq, sr, sv, tr, uk, vi, zh | `assessment`: Search Term<br>`survey`: Search Term | 0 |
| `search_user` | `common`, `search` | Bedeutung (en) | fast alle (31) | `common`: Look Up User<br>`search`: Users | 1 |
| `select_max_one_item` | `assessment`, `common` | nur Übersetzung | bg, da, de, el, es, et, fr, hr, hu, it, ja, ka, lt, nl, ro, sl, sr, sv, zh | `assessment`: Please select one item only<br>`common`: Please select one item only | 2 |
| `select_questionpool` | `common`, `survey` | nur Übersetzung | fast alle (27) | `common`: Insert questions into | 1 |
| `select_target_position_for_move_question` | `assessment`, `survey` | Bedeutung (en) | fast alle (25) | `assessment`: Please select a target position to move the question(s) and press one of the insert buttons!<br>`survey`: Please select where you would like to move your question to within the list. To do so, select the appropriate checkbox to the left of a question and either ‘Insert Before’ or ‘Insert After’. | 1 |
| `selection` | `assessment`, `survey` | nur Übersetzung | bg, da, el, hr, hu, it, ka, lt, ro, ru, sq, sr, tr, uk, vi, zh | `assessment`: Selection<br>`survey`: Selection | 2 |
| `sess_copy` | `rbac`, `sess` | Bedeutung (en) | cs, da, en, es, et, fa, fr, hr, hu, it, ja, ka, pt, sk, tr | `rbac`: User can copy session<br>`sess`: Copy Session | 0 |
| `skipped` | `administration`, `survey` | kosmetisch (en) | ar, da, de, el, en, es, et, hr, hu, ja, nl, ro, ru, sk, sl, sq, sv, tr, zh | `administration`: skipped<br>`survey`: Skipped | 11 |
| `sort_by_date_desc` | `badge`, `forum` | Bedeutung (en) | fast alle (31) | `badge`: Date Descending<br>`forum`: The thread is presented in a flat view. The posts are shown in chronological order of creation. | 3 |
| `sorting` | `forum`, `irss` | Bedeutung (en) | fast alle (30) | `forum`: Sorting<br>`irss`: Default Ordering | 0 |
| `sorting_1` | `bibl`, `irss` | nur Übersetzung | ja | `bibl`: By Title (Ascending)<br>`irss`: By Title (Ascending) | 0 |
| `sorting_2` | `bibl`, `irss` | nur Übersetzung | ja | `bibl`: By Title (Descending)<br>`irss`: By Title (Descending) | 0 |
| `sorting_3` | `bibl`, `irss` | Bedeutung (en) | fast alle (31) | `bibl`: By Author (Ascending)<br>`irss`: By File Size (Ascending) | 0 |
| `sorting_4` | `bibl`, `irss` | Bedeutung (en) | fast alle (31) | `bibl`: By Author (Descending)<br>`irss`: By File Size (Descending) | 0 |
| `sorting_5` | `bibl`, `irss` | Bedeutung (en) | fast alle (31) | `bibl`: By Year (Ascending)<br>`irss`: By Creation Date (Ascending) | 0 |
| `sorting_6` | `bibl`, `irss` | Bedeutung (en) | fast alle (31) | `bibl`: By Year (Descending)<br>`irss`: By Creation Date (Descending) | 0 |
| `statistics` | `assessment`, `common` | nur Übersetzung | el, hr, hu, ja, ka, lt, pl, pt, tr, vi | `assessment`: Statistics<br>`common`: Statistics | 8 |
| `storage_id` | `file`, `irss` | nur Übersetzung | hu, ja | `file`: Storage ID<br>`irss`: Storage ID | 2 |
| `svy_copy` | `rbac`, `survey` | Bedeutung (en) | cs, en, es, fa, fr, hr, hu, it, ja, ka, nl, pt, ru, sk, tr | `rbac`: User can copy survey<br>`survey`: Copy Survey | 0 |
| `svy_results` | `obj`, `survey` | nur Übersetzung | hu, nl | `obj`: Results<br>`survey`: Results | 3 |
| `tab_info` | `cmix`, `lti` | nur Übersetzung | hu | `cmix`: Info<br>`lti`: Info | 1 |
| `tab_scoring` | `cmix`, `lti` | nur Übersetzung | hu, it | `cmix`: Ranking<br>`lti`: Ranking | 0 |
| `tab_settings` | `cmix`, `lti` | nur Übersetzung | bg | `cmix`: Settings<br>`lti`: Settings | 0 |
| `tab_statements` | `cmix`, `lti` | nur Übersetzung | bg, ja | `cmix`: Learning Experiences<br>`lti`: Learning Experiences | 0 |
| `talt_etal` | `common`, `etal` | Bedeutung (en) | fast alle (31) | `common`: Talk<br>`etal`: Employee Talk | 0 |
| `term` | `assessment`, `common` | nur Übersetzung | ar, bg, de, el, ka, pt, ro, ru, sl, sq, sr, tr, uk, vi, zh | `assessment`: Term<br>`common`: Term | 8 |
| `text_maximum_chars_allowed` | `assessment`, `survey` | Bedeutung (en) | fast alle (28) | `assessment`: Please do not enter more than a maximum of %s characters. Additional characters won't get cut, but the exceeding might be considered during scoring.<br>`survey`: Please limit your answer to a maximum of %s characters. If you exceed this limit, your answer will not be logged and you will be required to shorten your answer in order to proceed. | 4 |
| `thread` | `common`, `forum` | nur Übersetzung | ar, bg, cs, da, el, et, fa, it, nl, pl, pt, ro, ru, sk, sq, sr, uk, vi, zh | `common`: Thread<br>`forum`: Thread | 1 |
| `tile_view` | `badge`, `common` | nur Übersetzung | de, es, hu, ja | `badge`: Tile View<br>`common`: Tile View | 3 |
| `time` | `common`, `jscalendar` | nur Übersetzung | bg, el, et, ja, pt, sq | `common`: Time<br>`jscalendar`: Time | 9 |
| `title_info` | `cmix`, `lti` | nur Übersetzung | bg, de, hu, it, ja, sv | `cmix`: Give the object a title.<br>`lti`: Give the object a title. | 2 |
| `to` | `common`, `dateplaner` | kosmetisch (en) | fast alle (27) | `common`: To<br>`dateplaner`: to | 10 |
| `today` | `common`, `dateplaner`, `jscalendar` | nur Übersetzung | ar, bg, da, ja, pl, pt, ro, ru, sq, sr, uk, vi, zh | `common`: Today<br>`dateplaner`: Today<br>`jscalendar`: Today | 7 |
| `translate` | `bibl`, `gsfo` | nur Übersetzung | bg, cs, hr, it, ja, pt, sl, sv | `bibl`: Translate<br>`gsfo`: Translate | 1 |
| `tst_copy` | `assessment`, `rbac` | Bedeutung (en) | da, en, es, fa, fr, hr, hu, it, ja, ka, nl, pt, sk, tr | `assessment`: Copy Test<br>`rbac`: User can copy test | 0 |
| `tst_results` | `assessment`, `common` | nur Übersetzung | bg, da, el, et, fr, ja, ka, lt, nl, pl, pt, ro, ru, sk, sl, sq, sr, tr, uk, vi, zh | `assessment`: Test Results<br>`common`: Test Results | 2 |
| `type` | `common`, `irss` | Bedeutung (en) | fast alle (31) | `common`: Type<br>`irss`: File Type | 89 |
| `unknown` | `common`, `scormdebug` | kosmetisch (en) | fast alle (29) | `common`: UNKNOWN<br>`scormdebug`: unknown | 8 |
| `unparticipate` | `common`, `lso` | nur Übersetzung | de, hr, ja, pt, sl, sv | `common`: Unsubscribe<br>`lso`: Unsubscribe | 3 |
| `user_settings` | `notifications_adm`, `user` | nur Übersetzung | es, hu | `notifications_adm`: User Settings<br>`user`: User Settings | 3 |
| `users` | `chatroom`, `common` | nur Übersetzung | ar, el, hr, lt, nl, pt, ro, sk, sl, sq, sr, tr, uk, vi | `chatroom`: Users<br>`common`: Users | 16 |
| `values` | `assessment`, `survey` | nur Übersetzung | bg, el, et, lt, nl, ro, ru, sq, sr, tr, uk, vi, zh | `assessment`: Values<br>`survey`: Values | 4 |
| `view_learning_progress` | `common`, `orgu` | nur Übersetzung | de, es, fr, hr, ja, nl, pl, sl | `common`: View Learning Progress<br>`orgu`: View Learning Progress | 0 |
| `view_learning_progress_rec` | `common`, `orgu` | nur Übersetzung | fast alle (26) | `common`: View Learning Progress of Unit incl. Subunits<br>`orgu`: View Learning Progress of Unit incl. Subunits | 0 |
| `warning_question_not_complete` | `assessment`, `survey` | Bedeutung (en) | cs, da, el, en, es, et, fa, fr, hr, hu, ja, lt, nl, ru, sk, sq, sr, uk, zh | `assessment`: Question is incomplete!<br>`survey`: The question is not complete! | 3 |
| `webr_active` | `common`, `webr` | nur Übersetzung | da, el, hr, it, lt, ru, sl, uk, vi | `common`: Active<br>`webr`: Active | 1 |
| `webr_copy` | `rbac`, `webr` | Bedeutung (en) | fast alle (30) | `rbac`: User can copy weblink<br>`webr`: Copy Weblink | 0 |
| `week` | `common`, `dateplaner` | nur Übersetzung | bg, da, et, pl, pt, ro, ru, sq, sr, uk, vi | `common`: Week<br>`dateplaner`: Week | 14 |
| `width` | `assessment`, `common` | nur Übersetzung | ar, bg, da, el, et, lt, ro, sq, sr, uk, vi | `assessment`: Width<br>`common`: Width | 0 |
| `wiki_copy` | `rbac`, `wiki` | Bedeutung (en) | da, en, es, fa, fr, hr, hu, it, ja, ka, pt, sk, sl, tr, zh | `rbac`: User can copy wiki<br>`wiki`: Copy Wiki | 0 |
| `wiki_html_export` | `rbac`, `wiki` | nur Übersetzung | ar, bg, da, el, et, fa, fr, hr, ja, ka, lt, nl, pt, ro, ru, sk, sl, sq, sr, tr, uk, vi, zh | `rbac`: Export HTML<br>`wiki`: Export HTML | 3 |
| `write` | `common`, `rbac` | Bedeutung (en) | fast alle (31) | `common`: Write<br>`rbac`: Edit Settings | 2 |
| `year` | `common`, `dateplaner` | nur Übersetzung | ar, bg, da, et, fa, ka, pl, pt, ro, ru, sq, sr, tr, uk, vi, zh | `common`: Year<br>`dateplaner`: Year | 7 |
