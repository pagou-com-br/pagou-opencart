<?php
namespace Opencart\Catalog\Model\Extension\Pagou\Payment;

require_once DIR_EXTENSION . 'pagou/system/library/sdk/autoload.php';

use Pagou\OpenCart\Engine;
use Pagou\OpenCart\Order;
use Pagou\OpenCart\Repository;
use Pagou\OpenCart\Status;

/**
 * Pagou para OpenCart 4 (4.0.2+): ponte entre o SDK e a loja.
 * Mesma regra do pacote 3.x; muda só a API do OpenCart (namespaces, addHistory, payment_method em array).
 */
class Pagou extends \Opencart\System\Engine\Model {
	private $engine;

	public function settings(): array {
		$keys = array(
			'environment', 'api_key_sandbox', 'api_key_production', 'document_field', 'number_field', 'complement_field',
			'neighborhood_field', 'restrict', 'restrict_ips', 'restrict_emails', 'ip_header', 'debug', 'secret', 'cron_token',
			'status_pending', 'status_paid', 'status_canceled', 'status_expired', 'status_refunded', 'status_failed', 'status_chargeback',
		);
		$s = array('platform' => 'OpenCart ' . VERSION);
		foreach ($keys as $k) {
			$s[$k] = $this->config->get('payment_pagou_' . $k);
		}
		foreach (array('pix_expiration' => 'payment_pagou_pix_expiration', 'boleto_due_days' => 'payment_pagou_boleto_due_days',
			'boleto_grace_period' => 'payment_pagou_boleto_grace_period', 'boleto_fine' => 'payment_pagou_boleto_fine',
			'boleto_interest' => 'payment_pagou_boleto_interest') as $k => $configKey) {
			$s[$k] = $this->config->get($configKey);
		}
		return $s;
	}

	public function engine(): Engine {
		if (!$this->engine) {
			$settings = $this->settings();
			$log = !empty($settings['debug']) ? new \Opencart\System\Library\Log('pagou.log') : null;
			$this->engine = new Engine(
				new Repository($this->db, DB_PREFIX),
				$settings,
				array($this, 'applyStatus'),
				$log ? function ($m) use ($log) { $log->write($m); } : null
			);
		}
		return $this->engine;
	}

	public function repository(): Repository {
		return $this->engine()->repository();
	}

	public function environment(): string {
		return $this->config->get('payment_pagou_environment') === 'production' ? 'production' : 'sandbox';
	}

	public function hasCredentials(): bool {
		return trim((string)$this->config->get('payment_pagou_api_key_' . $this->environment())) !== '';
	}

	public function available(string $code, array $address, float $total): bool {
		if (!$this->config->get('payment_' . $code . '_status') || !$this->hasCredentials() || !$this->currency->has('BRL')) {
			return false; // a Pagou cobra em reais: sem a moeda BRL cadastrada não há conversão confiável
		}
		if ($this->config->get('payment_' . $code . '_geo_zone_id')) {
			if (empty($address['country_id'])) {
				return false;
			}
			$query = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int)$this->config->get('payment_' . $code . '_geo_zone_id') . "' AND `country_id` = '" . (int)$address['country_id'] . "' AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')");
			if (!$query->num_rows) {
				return false;
			}
		}
		$min = (float)$this->config->get('payment_' . $code . '_total');
		if ($min > 0 && $total < $min) {
			return false;
		}
		if ($code === 'pagou_boleto' && $this->toBrl($total) < 5) {
			return false;
		}
		return $this->visibleToCurrentVisitor();
	}

	public function visibleToCurrentVisitor(): bool {
		if (!$this->config->get('payment_pagou_restrict')) {
			return true;
		}
		$ips = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$this->config->get('payment_pagou_restrict_ips'))));
		$emails = array_filter(array_map('strtolower', array_map('trim', preg_split('/[\s,;]+/', (string)$this->config->get('payment_pagou_restrict_emails')))));
		$ip = $this->clientIp();
		if ($ip !== '' && in_array($ip, $ips, true)) {
			return true;
		}
		$email = '';
		if ($this->customer->isLogged()) {
			$email = $this->customer->getEmail();
		} elseif (!empty($this->session->data['customer']['email'])) {
			$email = $this->session->data['customer']['email'];
		}
		return $email !== '' && in_array(strtolower($email), $emails, true);
	}

	public function clientIp(): string {
		return Order::clientIp($this->request->server, (string)$this->config->get('payment_pagou_ip_header'));
	}

	public function toBrl($amount): float {
		return (float)$this->currency->format((float)$amount, 'BRL', 0, false);
	}

	public function orderForSdk(array $orderInfo): array {
		if (($orderInfo['currency_code'] ?? '') === 'BRL' && (float)($orderInfo['currency_value'] ?? 0) > 0) {
			$orderInfo['total'] = round((float)$orderInfo['total'] * (float)$orderInfo['currency_value'], 2);
		} else {
			$orderInfo['total'] = $this->toBrl($orderInfo['total']);
		}
		return $orderInfo;
	}

	public function payer(array $orderInfo, string $documentOverride = ''): array {
		$neighborhood = (string)$this->config->get('payment_pagou_neighborhood_field');
		return Order::payer($orderInfo, array(
			'document_field' => (string)$this->config->get('payment_pagou_document_field'),
			'number_field' => (string)$this->config->get('payment_pagou_number_field'),
			'complement_field' => (string)$this->config->get('payment_pagou_complement_field'),
			'neighborhood_field' => $neighborhood !== '' ? $neighborhood : 'address_2',
		), $documentOverride);
	}

	public function needsDocument(array $orderInfo): bool {
		return $this->payer($orderInfo)['document_type'] === '';
	}

	// ---- URLs -----------------------------------------------------------

	private function base(): string {
		return rtrim((string)($this->config->get('config_url') ?: HTTP_SERVER), '/') . '/';
	}

	public function notificationUrl(): string {
		return $this->base() . 'index.php?route=extension/pagou/payment/pagou.webhook';
	}

	public function token(int $orderId): string {
		return hash_hmac('sha256', 'pay|' . $orderId, (string)$this->config->get('payment_pagou_secret'));
	}

	public function payUrl(int $orderId): string {
		return $this->base() . 'index.php?route=extension/pagou/payment/pagou.pay&order_id=' . $orderId . '&token=' . $this->token($orderId);
	}

	public function statusUrl(int $orderId): string {
		return $this->base() . 'index.php?route=extension/pagou/payment/pagou.status&order_id=' . $orderId . '&token=' . $this->token($orderId);
	}

	public function validToken(int $orderId, $token): bool {
		return is_string($token) && $token !== '' && $this->config->get('payment_pagou_secret') && hash_equals($this->token($orderId), $token);
	}

	// ---- Status do pedido ----------------------------------------------

	public function orderStatusId(string $status): int {
		$map = array(
			Status::PENDING => 'status_pending', Status::AUTHORIZED => 'status_pending', Status::UNKNOWN => 'status_pending',
			Status::PAID => 'status_paid', Status::CANCELED => 'status_canceled', Status::REJECTED => 'status_canceled',
			Status::EXPIRED => 'status_expired', Status::REFUNDED => 'status_refunded', Status::FAILED => 'status_failed',
			Status::CHARGEBACK => 'status_chargeback',
		);
		return isset($map[$status]) ? (int)$this->config->get('payment_pagou_' . $map[$status]) : 0;
	}

	public function applyStatus($orderId, $status, $comment, $notify): void {
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder((int)$orderId);
		if (!$order) {
			return;
		}
		$statusId = $status === 'comment' ? 0 : $this->orderStatusId($status);
		// Cancelar/expirar/recusar só vale para pedido que ainda aguarda pagamento.
		$awaiting = array(0, (int)$this->config->get('payment_pagou_status_pending'));
		if (in_array($status, array(Status::CANCELED, Status::REJECTED, Status::EXPIRED, Status::FAILED), true) && !in_array((int)$order['order_status_id'], $awaiting, true)) {
			$statusId = 0;
		}
		if (!$statusId) {
			if ((int)$order['order_status_id'] > 0) {
				$this->history((int)$orderId, (int)$order['order_status_id'], $comment, false);
			}
			return;
		}
		$this->history((int)$orderId, $statusId, $comment, (bool)$notify);
	}

	public function markPending(int $orderId, string $comment): void {
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($orderId);
		$statusId = (int)$this->config->get('payment_pagou_status_pending');
		if (!$order || !$statusId) {
			return;
		}
		if ((int)$order['order_status_id'] === $statusId) {
			$this->history($orderId, $statusId, $comment, false);
		} elseif ((int)$order['order_status_id'] === 0) {
			$this->history($orderId, $statusId, $comment, true);
		}
	}

	/**
	 * Grava o histórico do pedido. O OpenCart grava status + histórico e só depois dispara o e-mail
	 * (evento "after"): se o e-mail falhar, o pedido JÁ mudou. Nesse caso a exceção é engolida,
	 * para o Engine não reverter a cobrança e reaplicar o mesmo histórico a cada webhook/cron.
	 */
	private function history(int $orderId, int $statusId, string $comment, bool $notify): void {
		try {
			$this->model_checkout_order->addHistory((int)$orderId, (int)$statusId, $comment, (bool)$notify);
		} catch (\Throwable $e) {
			$last = $this->db->query("SELECT `order_status_id`, `comment` FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = '" . (int)$orderId . "' ORDER BY `order_history_id` DESC LIMIT 1")->row;
			if ($last && (int)$last['order_status_id'] === (int)$statusId && (string)$last['comment'] === (string)$comment) {
				$this->debug('histórico do pedido ' . (int)$orderId . ' gravado, mas houve erro depois (ex.: e-mail): ' . $e->getMessage());
				return;
			}
			throw $e;
		}
	}

	// ---- Checkout ------------------------------------------------------

	/** Código do meio no pedido: 4.0.2+ guarda payment_method como array ['code' => 'pagou_pix.pagou_pix']. */
	private static function methodCode($paymentMethod): string {
		$code = is_array($paymentMethod) ? (string)($paymentMethod['code'] ?? '') : (string)$paymentMethod;
		return explode('.', $code)[0];
	}

	public function sessionOrder(string $code): array {
		$this->load->language('extension/pagou/payment/pagou');
		if (self::methodCode($this->session->data['payment_method'] ?? '') !== $code) {
			return array(null, $this->language->get('error_method'));
		}
		if (empty($this->session->data['order_id'])) {
			return array(null, $this->language->get('error_order'));
		}
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);
		$orderCode = isset($order['payment_method']) ? self::methodCode($order['payment_method']) : (string)($order['payment_code'] ?? '');
		if (!$order || $orderCode !== $code) {
			return array(null, $this->language->get('error_order'));
		}
		return array($order, '');
	}

	public function validPayer(array $order, string $method, string $document): array {
		$this->load->language('extension/pagou/payment/pagou');
		$document = trim($document);
		$payer = $this->payer($order, $document);
		if ($document !== '' && $payer['document_type'] === '') {
			return array(null, $this->language->get('error_document'));
		}
		$missing = Order::missing($payer, $method);
		if ($missing) {
			if ($missing === array('document')) {
				return array(null, $this->language->get('error_document'));
			}
			$labels = array();
			foreach ($missing as $m) {
				$labels[] = $this->language->get('field_' . $m);
			}
			return array(null, sprintf($this->language->get('error_missing'), implode(', ', $labels)));
		}
		return array($payer, '');
	}

	public function confirmOffline(string $code, string $document): array {
		$method = $code === 'pagou_pix' ? 'pix' : 'boleto';
		[$order, $error] = $this->sessionOrder($code);
		if ($error) {
			return array('error' => $error);
		}
		[$payer, $error] = $this->validPayer($order, $method, $document);
		if ($error) {
			return array('error' => $error);
		}
		try {
			$sdkOrder = $this->orderForSdk($order);
			if ($method === 'pix') {
				$this->engine()->createPix($sdkOrder, $payer, $this->notificationUrl());
			} else {
				$this->engine()->createBoleto($sdkOrder, $payer, $this->notificationUrl());
			}
		} catch (\Pagou\OpenCart\TransportException $e) {
			return array('error' => $this->language->get('error_indeterminate'));
		} catch (\Exception $e) {
			$this->debug('confirm ' . $code . ' pedido ' . $order['order_id'] . ': ' . $e->getMessage());
			return array('error' => sprintf($this->language->get('error_api'), $e->getMessage()));
		}
		$this->markPending((int)$order['order_id'], sprintf($this->language->get('text_history_link'), $this->payUrl((int)$order['order_id'])));
		return array('redirect' => $this->payUrl((int)$order['order_id']));
	}

	public function debug(string $message): void {
		if ($this->config->get('payment_pagou_debug')) {
			(new \Opencart\System\Library\Log('pagou.log'))->write('[pagou] ' . $message);
		}
	}

	public function clearCheckoutSession(int $orderId): void {
		if (isset($this->session->data['order_id']) && (int)$this->session->data['order_id'] === $orderId) {
			$this->cart->clear();
			foreach (array('shipping_method', 'shipping_methods', 'payment_method', 'payment_methods', 'comment', 'order_id', 'coupon', 'reward', 'voucher', 'vouchers', 'totals') as $k) {
				unset($this->session->data[$k]);
			}
		}
	}

	public function publicView($t): ?array {
		if (!$t) {
			return null;
		}
		$view = array(
			'method' => $t['method'],
			'status' => $t['status'],
			'paid' => $t['status'] === Status::PAID,
			'final' => Status::isFinal($t['status']),
			'sandbox' => $t['environment'] === 'sandbox',
			'amount' => (float)$t['amount'],
			'installments' => (int)$t['installments'],
			'pix_code' => $t['data']['pix_code'] ?? '',
			'pix_image' => $t['data']['pix_image'] ?? '',
			'expires_at' => $t['expires_at'] ? strtotime($t['expires_at'] . ' UTC') : 0,
			'boleto_line' => $t['data']['boleto_line'] ?? '',
			'boleto_due' => $t['data']['due_date'] ?? '',
			'boleto_html' => '',
			'boleto_pdf' => '',
			'failure' => $t['data']['failure_reason'] ?? '',
		);
		if ($t['method'] === 'boleto' && $view['boleto_line'] !== '') {
			$links = Engine::boletoLinks($t['pagou_id']);
			$view['boleto_html'] = $links['html'];
			$view['boleto_pdf'] = $links['pdf'];
		}
		return $view;
	}
}
