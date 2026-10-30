<?php

// Throwaway php-fpm probe for beyondcode/herd-community#1656: plain dgettext in a forked worker, ?env=lc_all sets LC_ALL first.

declare(strict_types=1);

if (($_GET['env'] ?? '') === 'lc_all') {
    putenv('LC_ALL=en_US.UTF-8');
}
setlocale(LC_MESSAGES, 'en_US.UTF-8');
putenv('LANGUAGE=messages');
bindtextdomain('t.de', (string) ($_GET['dir'] ?? ''));
bind_textdomain_codeset('t.de', 'UTF-8');
echo json_encode(['pid' => getmypid(), 'env' => $_GET['env'] ?? 'none', 'value' => dgettext('t.de', 'hello')]), "\n";
