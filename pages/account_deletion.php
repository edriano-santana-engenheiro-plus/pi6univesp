<?php
declare(strict_types=1);

use Delivery\Utils\View;

$store = [];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $store = $pdo->query('SELECT * FROM store_settings WHERE id=1')->fetch() ?: [];
    } catch (Throwable) {
        $store = [];
    }
}
if ($store === []) {
    $store = [
        'legal_name' => 'Panificadora Santana',
        'address' => 'Av. Ouvide de Abreu, 137 — Cohab, Itamogi/MG',
        'phone' => '',
        'whatsapp' => '',
    ];
}

View::render(
    'Exclusão de conta e dados',
    'pages/account_deletion.php',
    [
        'pageSubtitle' => 'Santana Cliente e Santana Motoboy',
        'store' => $store,
        'publicTrack' => true,
        'hideSiteFooter' => true,
        'updatedAt' => '25/07/2026',
    ]
);
