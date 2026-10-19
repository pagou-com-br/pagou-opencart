<?php
namespace Opencart\Catalog\Controller\Extension\Pagou\Payment;

class PagouBoleto extends \Opencart\System\Engine\Controller {
	public function index(): string {
		$this->load->language('extension/pagou/payment/pagou');
		$this->load->language('extension/pagou/payment/pagou_boleto');
		$this->load->model('extension/pagou/payment/pagou');
		$this->load->model('checkout/order');

		$order = isset($this->session->data['order_id']) ? $this->model_checkout_order->getOrder((int)$this->session->data['order_id']) : [];
		$data['need_document'] = $order ? $this->model_extension_pagou_payment_pagou->needsDocument($order) : true;
		$data['sandbox'] = $this->model_extension_pagou_payment_pagou->environment() === 'sandbox';
		$data['instructions'] = nl2br(htmlspecialchars((string)$this->config->get('payment_pagou_boleto_instructions'), ENT_QUOTES, 'UTF-8'));
		$data['code'] = 'pagou_boleto';
		$data['confirm_url'] = 'index.php?route=extension/pagou/payment/pagou_boleto.confirm&language=' . $this->config->get('config_language');

		foreach (['button_confirm', 'text_loading', 'text_sandbox', 'text_document', 'text_document_help'] as $k) {
			$data[$k] = $this->language->get($k);
		}
		return $this->load->view('extension/pagou/payment/pagou_offline', $data);
	}

	public function confirm(): void {
		$this->load->model('extension/pagou/payment/pagou');
		$document = isset($this->request->post['document']) ? (string)$this->request->post['document'] : '';
		$json = $this->model_extension_pagou_payment_pagou->confirmOffline('pagou_boleto', $document);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
