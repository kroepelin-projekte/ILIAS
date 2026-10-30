<?php

// Throwaway check for Mantis 48622: lookups through ILIAS from the current build, under the caller's environment.

declare(strict_types=1);

use ILIAS\Language\ComponentTranslation\MigratedTranslations;
use ILIAS\Language\ComponentTranslation\NativeGettext;
use ILIAS\Language\ComponentTranslation\TranslationGettextRepository;

define('ILIAS_ABSOLUTE_PATH', dirname(__DIR__, 2));
require ILIAS_ABSOLUTE_PATH . '/vendor/composer/vendor/autoload.php';

$repository = new TranslationGettextRepository();
$values = [
    'acc.ar' => $repository->getText('acc', 'ar', 'acc_add_document_btn_label', null),
    'common.de' => $repository->getText('common', 'de', 'save', null),
];

echo json_encode([
    'env' => array_filter(
        ['LC_ALL' => getenv('LC_ALL'), 'LC_MESSAGES' => getenv('LC_MESSAGES'), 'LANG' => getenv('LANG')],
        static fn($v): bool => $v !== false
    ),
    'values' => $values,
    'lc_messages' => setlocale(LC_MESSAGES, '0'),
    'problem' => MigratedTranslations::getProblem(null),
    'gettext_problem' => NativeGettext::getProblem(),
    'ok' => $values === ['acc.ar' => 'Add Document', 'common.de' => 'Speichern'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
