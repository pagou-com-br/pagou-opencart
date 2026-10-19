<?php
namespace Opencart\Catalog\Controller\Extension\Pagou\Payment;

require_once DIR_EXTENSION . 'pagou/system/library/sdk/autoload.php';

use Pagou\OpenCart\Status;

/**
 * Rotas públicas da integração Pagou:
 *  - webhook  POST  index.php?route=extension/pagou/payment/pagou.webhook
 *  - cron     GET   index.php?route=extension/pagou/payment/pagou.cron&token=...
 *  - pay      GET   index.php?route=extension/pagou/payment/pagou.pay&order_id=..&token=..
 *  - status   GET   index.php?route=extension/pagou/payment/pagou.status&order_id=..&token=..
 *  - simulate POST  (somente Sandbox) paga a cobrança de teste
 */
class Pagou extends \Opencart\System\Engine\Controller {
	public function webhook() {
		$this->load->model('extension/pagou/payment/pagou');
		$raw = file_get_contents('php://input');
		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || $raw === '' || $raw === false) {
			$this->respond(405, array('error' => 'method not allowed'));
			return;
		}
		list($code, $message) = $this->model_extension_pagou_payment_pagou->engine()->handleWebhook($raw, $this->request->server);
		$this->respond($code, array('result' => $message));
	}

	public function cron() {
		$this->load->model('extension/pagou/payment/pagou');
		$token = isset($this->request->get['token']) ? (string)$this->request->get['token'] : '';
		$expected = (string)$this->config->get('payment_pagou_cron_token');
		if ($expected === '' || !hash_equals($expected, $token)) {
			$this->respond(403, array('error' => 'forbidden'));
			return;
		}
		@set_time_limit(120);
		$done = $this->model_extension_pagou_payment_pagou->engine()->reconcile(40);
		$this->respond(200, array('checked' => count($done), 'items' => $done));
	}

	public function status() {
		$this->load->model('extension/pagou/payment/pagou');
		$pagou = $this->model_extension_pagou_payment_pagou;
		$orderId = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		if (!$pagou->validToken($orderId, isset($this->request->get['token']) ? $this->request->get['token'] : '')) {
			$this->respond(403, array('error' => 'forbidden'));
			return;
		}
		$t = $pagou->repository()->latestForOrder($orderId);
		if (!$t) {
			$this->respond(404, array('error' => 'not found'));
			return;
		}
		// Consulta a Pagou no máximo a cada 8 s por cobrança (o webhook é o caminho principal).
		$stale = empty($t['checked_at']) || strtotime($t['checked_at'] . ' UTC') < time() - 8;
		if (!Status::isFinal($t['status']) && $stale) {
			try {
				if (empty($t['pagou_id'])) {
					// Criação sem resposta: procura a cobrança na Pagou (o cron também faz isso).
					$pagou->repository()->touchChecked($t['pagou_transaction_id']);
					$pagou->engine()->recover($t);
				} else {
					$pagou->engine()->refresh($t, true);
				}
			} catch (\Throwable $e) {
				$pagou->debug('status pedido ' . $orderId . ': ' . $e->getMessage()); // a página segue consultando
			}
			$t = $pagou->repository()->find($t['pagou_transaction_id']);
		}
		$this->respond(200, $pagou->publicView($t));
	}

	public function simulate() {
		$this->load->model('extension/pagou/payment/pagou');
		$pagou = $this->model_extension_pagou_payment_pagou;
		$orderId = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		if ($this->request->server['REQUEST_METHOD'] !== 'POST' || !$pagou->validToken($orderId, isset($this->request->get['token']) ? $this->request->get['token'] : '')) {
			$this->respond(403, array('error' => 'forbidden'));
			return;
		}
		$t = $pagou->repository()->latestForOrder($orderId);
		if (!$t || $t['environment'] !== 'sandbox' || $t['status'] !== Status::PENDING || $t['method'] === 'card') {
			$this->respond(409, array('error' => 'not eligible'));
			return;
		}
		try {
			$client = $pagou->engine()->client('sandbox');
			$key = 'oc-sim-' . $t['pagou_transaction_id'];
			if ($t['method'] === 'pix') {
				$client->simulatePixPayment($t['pagou_id'], $key);
			} else {
				$client->simulateBoletoPayment($t['pagou_id'], $key);
			}
			$this->respond(200, array('result' => 'processing'));
		} catch (\Exception $e) {
			$this->respond(502, array('error' => $e->getMessage()));
		}
	}

	public function pay() {
		$this->load->language('extension/pagou/payment/pagou');
		$this->load->model('extension/pagou/payment/pagou');
		$pagou = $this->model_extension_pagou_payment_pagou;

		$orderId = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
		$token = isset($this->request->get['token']) ? (string)$this->request->get['token'] : '';
		$t = $pagou->validToken($orderId, $token) ? $pagou->repository()->latestForOrder($orderId) : null;
		if (!$t) {
			$this->response->redirect($this->url->link('error/not_found', 'language=' . $this->config->get('config_language')));
			return;
		}

		// A partir daqui o pedido está registrado: esvazia o carrinho como no checkout/success.
		$pagou->clearCheckoutSession($orderId);

		$this->document->setTitle(sprintf($this->language->get('text_pay_title'), $orderId));
		// Página com dados de pagamento: fora dos buscadores (Document não tem setRobots no OC 3/4)
		$this->response->addHeader('X-Robots-Tag: noindex, nofollow');

		$data = array();
		foreach (array('text_sandbox', 'text_pix_instructions', 'text_pix_copy', 'text_copied', 'text_pix_expires', 'text_pix_expired',
			'text_boleto_generating', 'text_boleto_line', 'text_boleto_copy', 'text_boleto_open', 'text_boleto_pdf', 'text_boleto_pix',
			'text_card_review', 'text_waiting', 'text_paid', 'text_canceled', 'text_refunded', 'button_continue_shopping', 'button_my_orders') as $k) {
			$data[$k] = $this->language->get($k);
		}
		$data['heading_title'] = sprintf($this->language->get('text_pay_title'), $orderId);
		$data['view'] = $pagou->publicView($t);
		$data['amount'] = sprintf($this->language->get('text_amount'), $this->currency->format((float)$t['amount'], 'BRL', 1.0));
		$data['boleto_due'] = $data['view']['boleto_due'] ? sprintf($this->language->get('text_boleto_due'), date('d/m/Y', strtotime($data['view']['boleto_due']))) : '';
		$data['status_url'] = $pagou->statusUrl($orderId);
		$data['simulate_url'] = $data['view']['sandbox'] && $t['method'] !== 'card' ? str_replace('pagou.status&', 'pagou.simulate&', $data['status_url']) : '';
		$data['continue'] = $this->url->link('common/home', 'language=' . $this->config->get('config_language'));
		$data['orders'] = $this->customer->isLogged() ? $this->url->link('account/order.info', 'language=' . $this->config->get('config_language') . '&order_id=' . $orderId . (isset($this->session->data['customer_token']) ? '&customer_token=' . $this->session->data['customer_token'] : '')) : '';
		$data['view_json'] = json_encode($data['view']);

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home') ?: 'Home', 'href' => $this->url->link('common/home', 'language=' . $this->config->get('config_language'))),
			array('text' => $data['heading_title'], 'href' => $pagou->payUrl($orderId)),
		);
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('extension/pagou/payment/pagou_pay', $data));
	}

	private function respond($code, array $body) {
		http_response_code($code);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($body));
	}
}
