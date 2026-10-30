<?php

// Throwaway probe for Mantis 48622: does GNU libintl honour LANGUAGE under this environment?
// Args: <dir with messages/LC_MESSAGES/t.de.mo> <fix: none|lc_messages|lc_all|lang>

declare(strict_types=1);

$fix = $argv[2] ?? 'none';
$env_before = [];
foreach (['LC_ALL', 'LC_MESSAGES', 'LC_CTYPE', 'LANG', 'LANGUAGE'] as $name) {
    $env_before[$name] = getenv($name);
}
match ($fix) {
    'lc_messages' => putenv('LC_MESSAGES=en_US.UTF-8'),
    'lc_all' => putenv('LC_ALL=en_US.UTF-8'),
    'lang' => putenv('LANG=en_US.UTF-8'),
    default => null,
};
$locale = setlocale(LC_MESSAGES, 'en_US.UTF-8');
putenv('LANGUAGE=messages');
bindtextdomain('t.de', $argv[1]);
bind_textdomain_codeset('t.de', 'UTF-8');
$value = dgettext('t.de', 'hello');

echo json_encode([
    'fix' => $fix,
    'env' => array_filter($env_before, static fn($v): bool => $v !== false),
    'lc_messages' => $locale,
    'value' => $value,
    'ok' => $value === 'Hallo',
], JSON_UNESCAPED_SLASHES), "\n";
