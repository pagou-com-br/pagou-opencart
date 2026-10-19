<?php
namespace Opencart\Catalog\Model\Extension\Pagou\Payment;

class PagouBoleto extends \Opencart\System\Engine\Model {
	public function getMethods(array $address = []): array {
		$this->load->model('extension/pagou/payment/pagou');
		$total = $this->cart->getTotal();
		if (!$this->model_extension_pagou_payment_pagou->available('pagou_boleto', $address, (float)$total)) {
			return [];
		}
		$this->load->language('extension/pagou/payment/pagou_boleto');
		$title = trim((string)$this->config->get('payment_pagou_boleto_title'));
		$title = $title !== '' ? $title : $this->language->get('heading_title');

		return [
			'code'       => 'pagou_boleto',
			'name'       => $title,
			'option'     => ['pagou_boleto' => ['code' => 'pagou_boleto.pagou_boleto', 'name' => $title]],
			'sort_order' => $this->config->get('payment_pagou_boleto_sort_order'),
		];
	}
}
