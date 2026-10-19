<?php
class ControllerExtensionPaymentPagouPix extends Controller {
	public function index() {
		$this->load->language('extension/payment/pagou');
		$this->load->language('extension/payment/pagou_pix');
		$this->load->model('extension/payment/pagou');
		$this->load->model('checkout/order');

		$order = isset($this->session->data['order_id']) ? $this->model_checkout_order->getOrder($this->session->data['order_id']) : array();
		$data['need_document'] = $order ? $this->model_extension_payment_pagou->needsDocument($order) : true;
		$data['sandbox'] = $this->model_extension_payment_pagou->environment() === 'sandbox';
		$data['instructions'] = nl2br(htmlspecialchars((string)$this->config->get('payment_pagou_pix_instructions'), ENT_QUOTES, 'UTF-8'));
		$data['code'] = 'pagou_pix';
		$data['confirm_url'] = 'index.php?route=extension/payment/pagou_pix/confirm';

		foreach (array('button_confirm', 'text_loading', 'text_sandbox', 'text_document', 'text_document_help') as $k) {
			$data[$k] = $this->language->get($k);
		}
		return $this->load->view('extension/payment/pagou_offline', $data);
	}

	public function confirm() {
		$this->load->model('extension/payment/pagou');
		$document = isset($this->request->post['document']) ? (string)$this->request->post['document'] : '';
		$json = $this->model_extension_payment_pagou->confirmOffline('pagou_pix', $document);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
