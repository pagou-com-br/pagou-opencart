<?php
/**
 * Base dos controllers de admin da Pagou no OpenCart 3 (pagou_pix, pagou_boleto, pagou_card).
 *
 * Configuração compartilhada (credenciais, mapeamento de campos, status, modo de teste) fica no
 * código de setting "payment_pagou" e aparece igual nas três telas; a do meio fica em
 * "payment_pagou_<meio>".
 */
require_once __DIR__ . '/autoload.php';

use Pagou\OpenCart\Client;
use Pagou\OpenCart\Repository;

abstract class PagouOc3AdminController extends Controller {
	/** @var string pagou_pix | pagou_boleto | pagou_card */
	protected $code;
	/** @var string pix | boleto | card */
	protected $method;
	private $error = array();

	/** Campos específicos do meio: [chave sem prefixo => [tipo, padrão]]. */
	abstract protected function methodFields();

	protected function sharedKeys() {
		return array(
			'environment', 'api_key_sandbox', 'api_key_production', 'restrict', 'restrict_ips', 'restrict_emails',
			'document_field', 'number_field', 'complement_field', 'neighborhood_field', 'ip_header', 'debug',
			'status_pending', 'status_paid', 'status_canceled', 'status_expired', 'status_refunded', 'status_failed', 'status_chargeback',
		);
	}

	protected function commonMethodFields() {
		return array(
			'status' => array('toggle', 0),
			'title' => array('text', ''),
			'instructions' => array('textarea', ''),
			'geo_zone_id' => array('geo_zone', 0),
			'total' => array('number', ''),
			'sort_order' => array('number', ''),
		);
	}

	public function index() {
		$this->load->language('extension/payment/pagou');
		$this->load->language('extension/payment/' . $this->code);
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
			$this->save($this->request->post);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}

		$token = 'user_token=' . $this->session->data['user_token'];
		$data = array();
		foreach (array('heading_title', 'text_edit', 'text_enabled', 'text_disabled', 'text_all_zones', 'text_none', 'button_save', 'button_cancel') as $k) {
			$data[$k] = $this->language->get($k);
		}
		$data['lang'] = $this->language->all();
		$data['method'] = $this->method;
		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token, true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/payment/' . $this->code, $token, true)),
		);
		$data['action'] = $this->url->link('extension/payment/' . $this->code, $token, true);
		$data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=payment', true);
		$data['test_url'] = html_entity_decode($this->url->link('extension/payment/' . $this->code . '/test', $token, true), ENT_QUOTES, 'UTF-8');

		// Valores: POST (erro de validação) > config salvo > padrão
		$source = $this->request->server['REQUEST_METHOD'] == 'POST' ? $this->request->post : null;
		$value = function ($key, $default) use ($source) {
			if ($source !== null) {
				return isset($source[$key]) ? $source[$key] : $default;
			}
			$v = $this->config->get($key);
			return $v === null ? $default : $v;
		};
		$data['v'] = array();
		foreach ($this->sharedKeys() as $k) {
			$data['v']['payment_pagou_' . $k] = $value('payment_pagou_' . $k, '');
		}
		// A chave nunca volta ao navegador: o campo vem vazio e "vazio" ao salvar mantém a atual.
		foreach (array('sandbox', 'production') as $env) {
			$data['has_key'][$env] = trim((string)$this->config->get('payment_pagou_api_key_' . $env)) !== '';
			$data['v']['payment_pagou_api_key_' . $env] = '';
		}
		foreach (array_merge($this->commonMethodFields(), $this->methodFields()) as $k => $def) {
			$data['v']['payment_' . $this->code . '_' . $k] = $value('payment_' . $this->code . '_' . $k, $def[1]);
		}
		if ($data['v']['payment_pagou_environment'] === '') {
			$data['v']['payment_pagou_environment'] = 'sandbox';
		}
		$data['method_fields'] = array();
		foreach ($this->methodFields() as $k => $def) {
			$data['method_fields'][] = array('key' => 'payment_' . $this->code . '_' . $k, 'type' => $def[0], 'label' => $this->language->get('entry_' . $k), 'help' => $this->language->get('help_' . $k) !== 'help_' . $k ? $this->language->get('help_' . $k) : '');
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

		$catalog = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG;
		$data['webhook_url'] = $catalog . 'index.php?route=extension/payment/pagou/webhook';
		$data['cron_url'] = $this->config->get('payment_pagou_cron_token') ? $catalog . 'index.php?route=extension/payment/pagou/cron&token=' . $this->config->get('payment_pagou_cron_token') : '';
		$data['version'] = Client::VERSION;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/payment/pagou_form', $data));
	}

	private function save(array $post) {
		$shared = array();
		foreach ($this->sharedKeys() as $k) {
			$key = 'payment_pagou_' . $k;
			$shared[$key] = isset($post[$key]) ? (is_string($post[$key]) ? trim($post[$key]) : $post[$key]) : '';
		}
		foreach (array('sandbox', 'production') as $env) {
			if ($shared['payment_pagou_api_key_' . $env] === '') {
				$shared['payment_pagou_api_key_' . $env] = (string)$this->config->get('payment_pagou_api_key_' . $env);
			}
		}
		$shared['payment_pagou_environment'] = $shared['payment_pagou_environment'] === 'production' ? 'production' : 'sandbox';
		// Segredos gerados na instalação nunca vêm do formulário
		$shared['payment_pagou_secret'] = $this->config->get('payment_pagou_secret') ?: bin2hex(random_bytes(32));
		$shared['payment_pagou_cron_token'] = $this->config->get('payment_pagou_cron_token') ?: bin2hex(random_bytes(16));
		$this->model_setting_setting->editSetting('payment_pagou', $shared);

		$own = array();
		foreach (array_merge($this->commonMethodFields(), $this->methodFields()) as $k => $def) {
			$key = 'payment_' . $this->code . '_' . $k;
			$own[$key] = isset($post[$key]) ? trim((string)$post[$key]) : $def[1];
		}
		$this->model_setting_setting->editSetting('payment_' . $this->code, $own);
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/' . $this->code)) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		$env = isset($this->request->post['payment_pagou_environment']) ? $this->request->post['payment_pagou_environment'] : 'sandbox';
		$enabled = !empty($this->request->post['payment_' . $this->code . '_status']);
		$posted = trim((string)(isset($this->request->post['payment_pagou_api_key_' . $env]) ? $this->request->post['payment_pagou_api_key_' . $env] : ''));
		if (!$this->error && $enabled && $posted === '' && trim((string)$this->config->get('payment_pagou_api_key_' . $env)) === '') {
			$this->error['warning'] = $this->language->get('error_api_key');
		}
		return !$this->error;
	}

	/** Testa a chave informada no formulário (sem salvar). */
	public function test() {
		$this->load->language('extension/payment/pagou');
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/payment/' . $this->code)) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$env = isset($this->request->post['environment']) && $this->request->post['environment'] === 'production' ? 'production' : 'sandbox';
			$key = isset($this->request->post['api_key']) ? trim((string)$this->request->post['api_key']) : '';
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

	public function install() {
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

	public function uninstall() {
		// Mantém as tabelas e a configuração compartilhada: histórico de cobranças não pode sumir.
	}

	/** Aba "Pagou" na tela do pedido (sale/order/info). */
	public function order() {
		$orderId = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		$repo = new Repository($this->db, DB_PREFIX);
		$rows = $orderId ? $repo->allForOrder($orderId) : array();
		if (!$rows) {
			return '';
		}
		$this->load->language('extension/payment/pagou', 'pagou_order');
		$lang = $this->language->get('pagou_order');
		$catalog = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG;
		$token = hash_hmac('sha256', 'pay|' . $orderId, (string)$this->config->get('payment_pagou_secret'));
		$data = array(
			'lang' => $lang->all(),
			'transactions' => array(),
			'pay_url' => $catalog . 'index.php?route=extension/payment/pagou/pay&order_id=' . $orderId . '&token=' . $token,
			'status_url' => $catalog . 'index.php?route=extension/payment/pagou/status&order_id=' . $orderId . '&token=' . $token,
		);
		$methods = array('pix' => 'Pix', 'boleto' => 'Boleto', 'card' => 'Cartão');
		foreach ($rows as $r) {
			$extra = array();
			if (!empty($r['data']['boleto_line'])) {
				$extra[] = $r['data']['boleto_line'];
			}
			if (!empty($r['data']['failure_reason'])) {
				$extra[] = $r['data']['failure_reason'];
			}
			if (!empty($r['data']['error'])) {
				$extra[] = $r['data']['error'];
			}
			$data['transactions'][] = array(
				'method' => isset($methods[$r['method']]) ? $methods[$r['method']] : $r['method'],
				'environment' => $r['environment'] === 'production' ? 'Produção' : 'Sandbox',
				'pagou_id' => $r['pagou_id'] ?: '-',
				'status' => $lang->get('status_' . $r['status']) !== 'status_' . $r['status'] ? $lang->get('status_' . $r['status']) : $r['status'],
				'amount' => $this->currency->format($r['amount'], 'BRL', 1),
				'installments' => (int)$r['installments'],
				'date_added' => date('d/m/Y H:i', strtotime($r['date_added'] . ' UTC')),
				'checked_at' => $r['checked_at'] ? date('d/m/Y H:i', strtotime($r['checked_at'] . ' UTC')) : '-',
				'extra' => implode(' | ', $extra),
			);
		}
		return $this->load->view('extension/payment/pagou_order', $data);
	}
}
