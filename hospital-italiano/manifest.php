<?php
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/manifest+json; charset=utf-8');

$pdo = obtenerConexion();
$nombre = nombreInstitucion($pdo);

echo json_encode([
    'name' => "$nombre — Historial Clínico",
    'short_name' => $nombre,
    'description' => 'Historial clínico digital para consultorios de salud.',
    'start_url' => './index.html',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#FDFBF6',
    'theme_color' => '#FF7A2E',
    'lang' => 'es-AR',
    'icons' => [
        ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
