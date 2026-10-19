<?php
namespace Pagou\OpenCart;

/**
 * Autenticação e leitura dos webhooks da Pagou.
 *
 * Assinatura: X-Pagou-Signature = hex(HMAC-SHA256(timestamp . corpo_bruto, api_key)),
 * com X-Pagou-Timestamp em segundos Unix e tolerância de 5 minutos.
 */
class Webhook {
	const TOLERANCE = 300;

	public static function verify($rawBody, $timestamp, $signature, $apiKey, $now = null, $tolerance = self::TOLERANCE) {
		if (!is_string($rawBody) || !is_string($timestamp) || !preg_match('/^\d{1,16}$/', $timestamp)) {
			return false;
		}
		if (!is_string($signature) || !preg_match('/^[0-9a-fA-F]{64}$/', $signature)) {
			return false;
		}
		if (!is_string($apiKey) || $apiKey === '') {
			return false;
		}
		$now = $now === null ? time() : (int)$now;
		if (abs($now - (int)$timestamp) > $tolerance) {
			return false;
		}
		$expected = hash_hmac('sha256', $timestamp . $rawBody, $apiKey);
		return hash_equals($expected, strtolower($signature));
	}

	public static function sign($rawBody, $timestamp, $apiKey) {
		return hash_hmac('sha256', $timestamp . $rawBody, $apiKey);
	}

	/**
	 * Lê os headers da requisição atual de forma tolerante a servidor (nginx/apache/fpm).
	 * @return array{timestamp:string, signature:string}
	 */
	public static function headersFromServer(array $server) {
		$get = function ($name) use ($server) {
			$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
			return isset($server[$key]) ? trim((string)$server[$key]) : '';
		};
		return array(
			'timestamp' => $get('X-Pagou-Timestamp'),
			'signature' => $get('X-Pagou-Signature'),
		);
	}

	/**
	 * Valida o envelope { name, data } e devolve o essencial normalizado.
	 * @return array|null
	 */
	public static function parse($rawBody) {
		$json = json_decode($rawBody, true);
		if (!is_array($json) || !isset($json['name'], $json['data']) || !is_string($json['name']) || !is_array($json['data'])) {
			return null;
		}
		$data = $json['data'];
		if (!isset($data['id']) || !is_string($data['id']) || $data['id'] === '') {
			return null;
		}
		return array(
			'name' => $json['name'],
			'id' => $data['id'],
			'transaction_id' => isset($data['transaction_id']) && is_scalar($data['transaction_id']) ? (string)$data['transaction_id'] : '',
			'data' => $data,
		);
	}

	/** Identidade de deduplicação: evento + cobrança + transação (não só a cobrança). */
	public static function dedupKey(array $event) {
		return hash('sha256', $event['name'] . '|' . $event['id'] . '|' . $event['transaction_id']);
	}
}
