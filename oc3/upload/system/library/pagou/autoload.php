<?php
// Carrega o SDK da integração Pagou x OpenCart (sem Composer, para instalar por .ocmod.zip).
if (!class_exists('Pagou\\OpenCart\\Client', false)) {
	require_once __DIR__ . '/Client.php';
	require_once __DIR__ . '/Webhook.php';
	require_once __DIR__ . '/Payer.php';
	require_once __DIR__ . '/Status.php';
	require_once __DIR__ . '/Order.php';
	require_once __DIR__ . '/Repository.php';
	require_once __DIR__ . '/Engine.php';
}
