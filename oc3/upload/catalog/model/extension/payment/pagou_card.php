<?php
class ModelExtensionPaymentPagouCard extends Model {
	public function getMethod($address, $total) {
		$this->load->model('extension/payment/pagou');
		if (!$this->model_extension_payment_pagou->available('pagou_card', $address, $total)) {
			return array();
		}
		$this->load->language('extension/payment/pagou_card');
		$title = trim((string)$this->config->get('payment_pagou_card_title'));
		return array(
			'code'       => 'pagou_card',
			'title'      => $title !== '' ? $title : $this->language->get('text_title'),
			'terms'      => '',
			'sort_order' => $this->config->get('payment_pagou_card_sort_order'),
		);
	}
}
