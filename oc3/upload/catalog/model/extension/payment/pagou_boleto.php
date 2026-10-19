<?php
class ModelExtensionPaymentPagouBoleto extends Model {
	public function getMethod($address, $total) {
		$this->load->model('extension/payment/pagou');
		if (!$this->model_extension_payment_pagou->available('pagou_boleto', $address, $total)) {
			return array();
		}
		$this->load->language('extension/payment/pagou_boleto');
		$title = trim((string)$this->config->get('payment_pagou_boleto_title'));
		return array(
			'code'       => 'pagou_boleto',
			'title'      => $title !== '' ? $title : $this->language->get('text_title'),
			'terms'      => '',
			'sort_order' => $this->config->get('payment_pagou_boleto_sort_order'),
		);
	}
}
