<?php
class ModelExtensionPaymentPagouPix extends Model {
	public function getMethod($address, $total) {
		$this->load->model('extension/payment/pagou');
		if (!$this->model_extension_payment_pagou->available('pagou_pix', $address, $total)) {
			return array();
		}
		$this->load->language('extension/payment/pagou_pix');
		$title = trim((string)$this->config->get('payment_pagou_pix_title'));
		return array(
			'code'       => 'pagou_pix',
			'title'      => $title !== '' ? $title : $this->language->get('text_title'),
			'terms'      => '',
			'sort_order' => $this->config->get('payment_pagou_pix_sort_order'),
		);
	}
}
