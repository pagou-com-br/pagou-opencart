<?php
/**
 * Pagou para OpenCart 3: ponte entre o SDK (system/library/pagou) e a loja.
 * Usado pelos três meios (pagou_pix, pagou_boleto, pagou_card) e pelo controller pagou
 * (webhook, cron, página de pagamento e consulta de status).
 */
require_once DIR_SYSTEM . 'library/pagou/autoload.php';

use Pagou\OpenCart\Engine;
use Pagou\OpenCart\Order;
use Pagou\OpenCart\Repository;
use Pagou\OpenCart\Status;

class ModelExtensionPaymentPagou extends Model {
	private $engine;

	/** Configuração compartilhada sem o prefixo payment_pagou_. */
	public function settings() {
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

	public function engine() {
		if (!$this->engine) {
			$settings = $this->settings();
			$log = !empty($settings['debug']) ? new Log('pagou.log') : null;
			$this->engine = new Engine(
				new Repository($this->db, DB_PREFIX),
				$settings,
				array($this, 'applyStatus'),
				$log ? function ($m) use ($log) { $log->write($m); } : null
			);
		}
		return $this->engine;
	}

	public function repository() {
		return $this->engine()->repository();
	}

	public function environment() {
		return $this->config->get('payment_pagou_environment') === 'production' ? 'production' : 'sandbox';
	}

	public function hasCredentials() {
		return trim((string)$this->config->get('payment_pagou_api_key_' . $this->environment())) !== '';
	}

	/**
	 * Regras comuns de disponibilidade no checkout.
	 * @param string $code pagou_pix | pagou_boleto | pagou_card
	 */
	public function available($code, $address, $total) {
		if (!$this->config->get('payment_' . $code . '_status') || !$this->hasCredentials() || !$this->currency->has('BRL')) {
			return false; // a Pagou cobra em reais: sem a moeda BRL cadastrada não há conversão confiável
		}
		if ($this->config->get('payment_' . $code . '_geo_zone_id')) {
			$query = $this->db->query("SELECT 1 FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_' . $code . '_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
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

	/** Modo de teste: só IPs/e-mails liberados enxergam os meios da Pagou. */
	public function visibleToCurrentVisitor() {
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
		} elseif (!empty($this->session->data['guest']['email'])) {
			$email = $this->session->data['guest']['email'];
		}
		return $email !== '' && in_array(strtolower($email), $emails, true);
	}

	public function clientIp() {
		return Order::clientIp($this->request->server, (string)$this->config->get('payment_pagou_ip_header'));
	}

	public function toBrl($amount) {
		return (float)$this->currency->format($amount, 'BRL', '', false);
	}

	/** Pedido no formato do SDK (valor em BRL). Pedido feito em BRL usa a taxa gravada no próprio pedido. */
	public function orderForSdk($orderInfo) {
		if (isset($orderInfo['currency_code']) && $orderInfo['currency_code'] === 'BRL' && (float)$orderInfo['currency_value'] > 0) {
			$orderInfo['total'] = round((float)$orderInfo['total'] * (float)$orderInfo['currency_value'], 2);
		} else {
			$orderInfo['total'] = $this->toBrl($orderInfo['total']);
		}
		return $orderInfo;
	}

	public function payer($orderInfo, $documentOverride = '') {
		return Order::payer($orderInfo, array(
			'document_field' => (string)$this->config->get('payment_pagou_document_field'),
			'number_field' => (string)$this->config->get('payment_pagou_number_field'),
			'complement_field' => (string)$this->config->get('payment_pagou_complement_field'),
			'neighborhood_field' => $this->config->get('payment_pagou_neighborhood_field') !== null && $this->config->get('payment_pagou_neighborhood_field') !== '' ? (string)$this->config->get('payment_pagou_neighborhood_field') : 'address_2',
		), $documentOverride);
	}

	/** O CPF/CNPJ da loja já está no pedido? Se não, o passo de pagamento pede. */
	public function needsDocument($orderInfo) {
		$payer = $this->payer($orderInfo);
		return $payer['document_type'] === '';
	}

	// ---- URLs -----------------------------------------------------------

	private function base() {
		$base = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : HTTPS_SERVER;
		return rtrim($base, '/') . '/';
	}

	public function notificationUrl() {
		// Sem SEO URL de propósito: o webhook não pode depender de regra de reescrita.
		return $this->base() . 'index.php?route=extension/payment/pagou/webhook';
	}

	public function token($orderId) {
		return hash_hmac('sha256', 'pay|' . (int)$orderId, (string)$this->config->get('payment_pagou_secret'));
	}

	public function payUrl($orderId) {
		return $this->base() . 'index.php?route=extension/payment/pagou/pay&order_id=' . (int)$orderId . '&token=' . $this->token($orderId);
	}

	public function statusUrl($orderId) {
		return $this->base() . 'index.php?route=extension/payment/pagou/status&order_id=' . (int)$orderId . '&token=' . $this->token($orderId);
	}

	public function validToken($orderId, $token) {
		return is_string($token) && $token !== '' && $this->config->get('payment_pagou_secret') && hash_equals($this->token($orderId), $token);
	}

	// ---- Status do pedido ----------------------------------------------

	/** Status normalizado -> order_status_id configurado. */
	public function orderStatusId($status) {
		$map = array(
			Status::PENDING => 'status_pending', Status::AUTHORIZED => 'status_pending', Status::UNKNOWN => 'status_pending',
			Status::PAID => 'status_paid', Status::CANCELED => 'status_canceled', Status::REJECTED => 'status_canceled',
			Status::EXPIRED => 'status_expired', Status::REFUNDED => 'status_refunded', Status::FAILED => 'status_failed',
			Status::CHARGEBACK => 'status_chargeback',
		);
		return isset($map[$status]) ? (int)$this->config->get('payment_pagou_' . $map[$status]) : 0;
	}

	/** Callback do Engine: grava no histórico do pedido (com e-mail ao cliente quando $notify). */
	public function applyStatus($orderId, $status, $comment, $notify) {
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($orderId);
		if (!$order) {
			return;
		}
		if ($status === 'comment') {
			if ((int)$order['order_status_id'] > 0) {
				$this->history($orderId, (int)$order['order_status_id'], $comment, false);
			}
			return;
		}
		$statusId = $this->orderStatusId($status);
		// Cancelar/expirar/recusar só vale para pedido que ainda aguarda pagamento: não derruba pedido
		// que o lojista já avançou (Processando, Enviado...) nem pedido pago por outro meio.
		$awaiting = array(0, (int)$this->config->get('payment_pagou_status_pending'));
		if (in_array($status, array(Status::CANCELED, Status::REJECTED, Status::EXPIRED, Status::FAILED), true) && !in_array((int)$order['order_status_id'], $awaiting, true)) {
			$statusId = 0;
		}
		if (!$statusId) {
			// Status sem mapeamento (ex.: expirado = "não alterar"): só registra o comentário.
			if ((int)$order['order_status_id'] > 0) {
				$this->history($orderId, (int)$order['order_status_id'], $comment, false);
			}
			return;
		}
		$this->history($orderId, $statusId, $comment, (bool)$notify);
	}

	/**
	 * Primeiro status do pedido ao gerar Pix/boleto ou ao enviar o cartão.
	 * Não repete o histórico se o cliente gerar a cobrança de novo.
	 */
	public function markPending($orderId, $comment) {
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($orderId);
		$statusId = (int)$this->config->get('payment_pagou_status_pending');
		if (!$order || !$statusId) {
			return;
		}
		if ((int)$order['order_status_id'] === $statusId) {
			$this->history($orderId, $statusId, $comment, false);
			return;
		}
		if ((int)$order['order_status_id'] === 0) {
			$this->history($orderId, $statusId, $comment, true);
		}
	}

	/**
	 * Grava o histórico do pedido. O OpenCart grava status + histórico e só depois dispara o e-mail
	 * (evento "after"): se o e-mail falhar, o pedido JÁ mudou. Nesse caso a exceção é engolida,
	 * para o Engine não reverter a cobrança e reaplicar o mesmo histórico a cada webhook/cron.
	 */
	private function history($orderId, $statusId, $comment, $notify) {
		try {
			$this->model_checkout_order->addOrderHistory((int)$orderId, (int)$statusId, $comment, (bool)$notify);
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

	/** Pedido da sessão, validado contra o meio escolhido. Retorna [order_info, erro]. */
	public function sessionOrder($code) {
		$this->load->language('extension/payment/pagou');
		if (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] !== $code) {
			return array(null, $this->language->get('error_method'));
		}
		if (empty($this->session->data['order_id'])) {
			return array(null, $this->language->get('error_order'));
		}
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);
		if (!$order || $order['payment_code'] !== $code) {
			return array(null, $this->language->get('error_order'));
		}
		return array($order, '');
	}

	/** Pagador validado para o meio; retorna [payer, erro]. */
	public function validPayer($order, $method, $document) {
		$this->load->language('extension/payment/pagou');
		$document = trim((string)$document);
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

	/** Gera Pix ou boleto do pedido da sessão. @return array json para o checkout */
	public function confirmOffline($code, $document) {
		$method = $code === 'pagou_pix' ? 'pix' : 'boleto';
		list($order, $error) = $this->sessionOrder($code);
		if ($error) {
			return array('error' => $error);
		}
		list($payer, $error) = $this->validPayer($order, $method, $document);
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
		$this->markPending($order['order_id'], sprintf($this->language->get('text_history_link'), $this->payUrl($order['order_id'])));
		return array('redirect' => $this->payUrl($order['order_id']));
	}

	public function debug($message) {
		if ($this->config->get('payment_pagou_debug')) {
			$log = new Log('pagou.log');
			$log->write('[pagou] ' . $message);
		}
	}

	/** Mesma limpeza do checkout/success, para a página de pagamento. */
	public function clearCheckoutSession($orderId) {
		if (isset($this->session->data['order_id']) && (int)$this->session->data['order_id'] === (int)$orderId) {
			$this->cart->clear();
			foreach (array('shipping_method', 'shipping_methods', 'payment_method', 'payment_methods', 'guest', 'comment', 'order_id', 'coupon', 'reward', 'voucher', 'vouchers', 'totals') as $k) {
				unset($this->session->data[$k]);
			}
		}
	}

	/** Dados públicos da cobrança para a página de pagamento e o polling. */
	public function publicView($t) {
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
			'pix_code' => isset($t['data']['pix_code']) ? $t['data']['pix_code'] : '',
			'pix_image' => isset($t['data']['pix_image']) ? $t['data']['pix_image'] : '',
			'expires_at' => $t['expires_at'] ? strtotime($t['expires_at'] . ' UTC') : 0,
			'boleto_line' => isset($t['data']['boleto_line']) ? $t['data']['boleto_line'] : '',
			'boleto_due' => isset($t['data']['due_date']) ? $t['data']['due_date'] : '',
			'boleto_html' => '',
			'boleto_pdf' => '',
			'failure' => isset($t['data']['failure_reason']) ? $t['data']['failure_reason'] : '',
		);
		if ($t['method'] === 'boleto' && $view['boleto_line'] !== '') {
			$links = Engine::boletoLinks($t['pagou_id']);
			$view['boleto_html'] = $links['html'];
			$view['boleto_pdf'] = $links['pdf'];
		}
		return $view;
	}
}
