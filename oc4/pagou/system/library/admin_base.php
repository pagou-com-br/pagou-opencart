<?php
namespace Opencart\System\Library\Extension\Pagou;

require_once __DIR__ . '/sdk/autoload.php';

use Pagou\OpenCart\Client;
use Pagou\OpenCart\Repository;

/**
 * Base dos controllers de admin da Pagou no OpenCart 4 (pagou_pix, pagou_boleto, pagou_card).
 * Configuração compartilhada em "payment_pagou"; a do meio em "payment_pagou_<meio>".
 */
abstract class AdminBase extends \Opencart\System\Engine\Controller {
	protected string $code = '';
	protected string $method = '';

	abstract protected function methodFields(): array;

	protected function sharedKeys(): array {
		return array(
			'environment', 'api_key_sandbox', 'api_key_production', 'restrict', 'restrict_ips', 'restrict_emails',
			'document_field', 'number_field', 'complement_field', 'neighborhood_field', 'ip_header', 'debug',
			'status_pending', 'status_paid', 'status_canceled', 'status_expired', 'status_refunded', 'status_failed', 'status_chargeback',
		);
	}

	protected function commonMethodFields(): array {
		return array(
			'status' => array('toggle', 0),
			'title' => array('text', ''),
			'instructions' => array('textarea', ''),
			'geo_zone_id' => array('geo_zone', 0),
			'total' => array('number', ''),
			'sort_order' => array('number', ''),
		);
	}

	private function route(): string {
		return 'extension/pagou/payment/' . $this->code;
	}

	public function index(): void {
		$this->load->language('extension/pagou/payment/pagou');
		$this->load->language('extension/pagou/payment/' . $this->code);
		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];
		$data = array();
		$data['lang'] = $this->language->all();
		$data['heading_title'] = $this->language->get('heading_title');
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link($this->route(), $token)),
		);
		$data['save'] = $this->url->link($this->route() . '.save', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');
		$data['test_url'] = $this->url->link($this->route() . '.test', $token, true); // usado em JS: sem &amp;

		$data['v'] = array();
		foreach ($this->sharedKeys() as $k) {
			$v = $this->config->get('payment_pagou_' . $k);
			$data['v']['payment_pagou_' . $k] = $v === null ? '' : $v;
		}
		foreach (array_merge($this->commonMethodFields(), $this->methodFields()) as $k => $def) {
			$v = $this->config->get('payment_' . $this->code . '_' . $k);
			$data['v']['payment_' . $this->code . '_' . $k] = $v === null ? $def[1] : $v;
		}
		foreach (array('sandbox', 'production') as $env) {
			$data['has_key'][$env] = trim((string)$this->config->get('payment_pagou_api_key_' . $env)) !== '';
			$data['v']['payment_pagou_api_key_' . $env] = '';
		}
		if ($data['v']['payment_pagou_environment'] === '') {
			$data['v']['payment_pagou_environment'] = 'sandbox';
		}
		$data['method_fields'] = array();
		foreach ($this->methodFields() as $k => $def) {
			$help = $this->language->get('help_' . $k);
			$data['method_fields'][] = array('key' => 'payment_' . $this->code . '_' . $k, 'type' => $def[0], 'label' => $this->language->get('entry_' . $k), 'help' => $help !== 'help_' . $k ? $help : '');
		}
		$data['prefix'] = 'payment_' . $this->code . '_';

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
		$this->load->model('customer/custom_field');
		$data['custom_fields'] = array();
		foreach ($this->model_customer_custom_field->getCustomFields() as $cf) {
			$data['custom_fields'][] = array('id' => $cf['custom_field_id'], 'name' => $cf['name'] . ' (' . $cf['location'] . ')');
		}

		$data['webhook_url'] = HTTP_CATALOG . 'index.php?route=extension/pagou/payment/pagou.webhook';
		$data['cron_url'] = $this->config->get('payment_pagou_cron_token') ? HTTP_CATALOG . 'index.php?route=extension/pagou/payment/pagou.cron&token=' . $this->config->get('payment_pagou_cron_token') : '';
		$data['version'] = Client::VERSION;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/pagou/payment/pagou_form', $data));
	}

	public function save(): void {
		$this->load->language('extension/pagou/payment/pagou');
		$json = array();
		$post = $this->request->post;

		if (!$this->user->hasPermission('modify', $this->route())) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}
		$env = isset($post['payment_pagou_environment']) && $post['payment_pagou_environment'] === 'production' ? 'production' : 'sandbox';
		if (!$json && !empty($post['payment_' . $this->code . '_status']) && trim((string)($post['payment_pagou_api_key_' . $env] ?? '')) === '' && trim((string)$this->config->get('payment_pagou_api_key_' . $env)) === '') {
			$json['error']['warning'] = $this->language->get('error_api_key');
		}

		if (!$json) {
			$this->load->model('setting/setting');
			$shared = array();
			foreach ($this->sharedKeys() as $k) {
				$key = 'payment_pagou_' . $k;
				$shared[$key] = isset($post[$key]) ? (is_string($post[$key]) ? trim($post[$key]) : $post[$key]) : '';
			}
			$shared['payment_pagou_environment'] = $env;
			foreach (array('sandbox', 'production') as $e) {
				if ($shared['payment_pagou_api_key_' . $e] === '') {
					$shared['payment_pagou_api_key_' . $e] = (string)$this->config->get('payment_pagou_api_key_' . $e);
				}
			}
			$shared['payment_pagou_secret'] = $this->config->get('payment_pagou_secret') ?: bin2hex(random_bytes(32));
			$shared['payment_pagou_cron_token'] = $this->config->get('payment_pagou_cron_token') ?: bin2hex(random_bytes(16));
			$this->model_setting_setting->editSetting('payment_pagou', $shared);

			$own = array();
			foreach (array_merge($this->commonMethodFields(), $this->methodFields()) as $k => $def) {
				$key = 'payment_' . $this->code . '_' . $k;
				$own[$key] = isset($post[$key]) ? trim((string)$post[$key]) : $def[1];
			}
			$this->model_setting_setting->editSetting('payment_' . $this->code, $own);
			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function test(): void {
		$this->load->language('extension/pagou/payment/pagou');
		$json = array();
		if (!$this->user->hasPermission('modify', $this->route())) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$env = ($this->request->post['environment'] ?? '') === 'production' ? 'production' : 'sandbox';
			$key = trim((string)($this->request->post['api_key'] ?? ''));
			if ($key === '') {
				$key = (string)$this->config->get('payment_pagou_api_key_' . $env); // testa a chave salva
			}
			try {
				(new Client($key, $env === 'production'))->balance();
				$json['success'] = sprintf($this->language->get('text_test_ok'), $env === 'production' ? 'Produção' : 'Sandbox');
			} catch (\Exception $e) {
				$json['error'] = sprintf($this->language->get('error_test'), $e->getMessage());
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install(): void {
		(new Repository($this->db, DB_PREFIX))->install();
		$this->load->model('setting/setting');
		if (!$this->config->get('payment_pagou_secret') && !$this->model_setting_setting->getSetting('payment_pagou')) {
			$processing = (array)$this->config->get('config_processing_status');
			$this->model_setting_setting->editSetting('payment_pagou', array(
				'payment_pagou_environment' => 'sandbox',
				'payment_pagou_restrict' => 1,
				'payment_pagou_neighborhood_field' => 'address_2',
				'payment_pagou_status_pending' => (int)$this->config->get('config_order_status_id') ?: 1,
				'payment_pagou_status_paid' => $processing ? (int)reset($processing) : 2,
				'payment_pagou_status_canceled' => 7,
				'payment_pagou_status_expired' => 14,
				'payment_pagou_status_refunded' => 11,
				'payment_pagou_status_failed' => 10,
				'payment_pagou_status_chargeback' => 13,
				'payment_pagou_secret' => bin2hex(random_bytes(32)),
				'payment_pagou_cron_token' => bin2hex(random_bytes(16)),
			));
		}
		$defaults = array();
		foreach (array_merge($this->commonMethodFields(), $this->methodFields()) as $k => $def) {
			$defaults['payment_' . $this->code . '_' . $k] = $def[1];
		}
		$this->model_setting_setting->editSetting('payment_' . $this->code, $defaults);
	}

	public function uninstall(): void {
		// Mantém tabelas e configuração compartilhada: o histórico de cobranças não pode sumir.
	}

	/** Aba "Pagou" na tela do pedido. */
	public function order(): string {
		$orderId = (int)($this->request->get['order_id'] ?? 0);
		$rows = $orderId ? (new Repository($this->db, DB_PREFIX))->allForOrder($orderId) : array();
		if (!$rows) {
			return '';
		}
		// Prefixo isola os textos da Pagou dos da tela do pedido
		$this->load->language('extension/pagou/payment/pagou', 'pagou');
		$lang = array();
		foreach ($this->language->all() as $k => $v) {
			if (strpos($k, 'pagou_') === 0) {
				$lang[substr($k, 6)] = $v;
			}
		}
		$token = hash_hmac('sha256', 'pay|' . $orderId, (string)$this->config->get('payment_pagou_secret'));
		$data = array(
			'lang' => $lang,
			'transactions' => array(),
			'pay_url' => HTTP_CATALOG . 'index.php?route=extension/pagou/payment/pagou.pay&order_id=' . $orderId . '&token=' . $token,
			'status_url' => HTTP_CATALOG . 'index.php?route=extension/pagou/payment/pagou.status&order_id=' . $orderId . '&token=' . $token,
		);
		$methods = array('pix' => 'Pix', 'boleto' => 'Boleto', 'card' => 'Cartão');
		foreach ($rows as $r) {
			$extra = array_filter(array($r['data']['boleto_line'] ?? '', $r['data']['failure_reason'] ?? '', $r['data']['error'] ?? ''));
			$label = $lang['status_' . $r['status']] ?? $r['status'];
			$data['transactions'][] = array(
				'method' => $methods[$r['method']] ?? $r['method'],
				'environment' => $r['environment'] === 'production' ? 'Produção' : 'Sandbox',
				'pagou_id' => $r['pagou_id'] ?: '-',
				'status' => $label,
				'amount' => $this->currency->format((float)$r['amount'], 'BRL', 1.0),
				'installments' => (int)$r['installments'],
				'date_added' => date('d/m/Y H:i', strtotime($r['date_added'] . ' UTC')),
				'checked_at' => $r['checked_at'] ? date('d/m/Y H:i', strtotime($r['checked_at'] . ' UTC')) : '-',
				'extra' => implode(' | ', $extra),
			);
		}
		return $this->load->view('extension/pagou/payment/pagou_order', $data);
	}
}
