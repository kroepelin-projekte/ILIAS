<?php

// Throwaway web check for Mantis 48622, served by `php -S` or php-fpm so several requests share one process.
// ?admin=before|after simulates ilInitialisation::initLocale() with de_DE.UTF-8 before or after the first lookup.

declare(strict_types=1);

use ILIAS\Language\ComponentTranslation\MigratedTranslations;
use ILIAS\Language\ComponentTranslation\NativeGettext;
use ILIAS\Language\ComponentTranslation\TranslationGettextRepository;

define('ILIAS_ABSOLUTE_PATH', dirname(__DIR__, 2));
require ILIAS_ABSOLUTE_PATH . '/vendor/composer/vendor/autoload.php';

$expected = ['de' => 'Speichern', 'en' => 'Save', 'fr' => 'Enregistrer'];
$order = ($_GET['order'] ?? '') === 'reverse' ? array_reverse($expected, true) : $expected;
$repository = new TranslationGettextRepository();
$admin = $_GET['admin'] ?? '';
$init_locale = static function (): void {
    setlocale(LC_ALL, ['de_DE.UTF-8']);
    setlocale(LC_NUMERIC, 'C');
};
if ($admin === 'before') {
    $init_locale();
}
$result = [
    'sapi' => PHP_SAPI,
    'pid' => getmypid(),
    'os' => PHP_OS_FAMILY,
    'messages_at_start' => setlocale(LC_MESSAGES, '0'),
    'values' => [],
];

foreach ($order as $lang => $value) {
    $result['values'][$lang] = $repository->getText('common', $lang, 'save', null);
}
if ($admin === 'after') {
    $init_locale();
    $result['de_after_init_locale'] = $repository->getText('common', 'de', 'save', null);
}
$result['locale_after_lookup'] = setlocale(LC_MESSAGES, '0');
$result['icu_default'] = function_exists('locale_get_default') ? locale_get_default() : null;
$result['active_after_lookup'] = NativeGettext::isActive();

// what ilInitialisation::initLocale() does mid-request
setlocale(LC_ALL, 'C');
$result['de_after_lc_all_c'] = $repository->getText('common', 'de', 'save', null);

$result['problem'] = MigratedTranslations::getProblem(null);
$result['ok'] = $result['values'] === array_intersect_key($order, $result['values'])
    && $result['de_after_lc_all_c'] === $expected['de']
    && $result['problem'] === null
    && ($admin === '' || $result['locale_after_lookup'] === 'de_DE.UTF-8')
    && ($admin !== 'after' || $result['de_after_init_locale'] === $expected['de']);

header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
