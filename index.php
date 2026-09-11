<?php
/**
 * Fallback na raiz do document root (app.integrasoft.eng.br/).
 * O .htaccess reescreve para public_html/; isto cobre o DirectoryIndex.
 */
declare(strict_types=1);

$target = __DIR__ . '/public_html/index.php';
if (!is_file($target)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro: public_html/index.php não encontrado.\n";
    exit;
}
require $target;
