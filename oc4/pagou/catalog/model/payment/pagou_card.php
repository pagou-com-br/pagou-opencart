<?php
namespace Opencart\Catalog\Model\Extension\Pagou\Payment;

class PagouCard extends \Opencart\System\Engine\Model {
	public function getMethods(array $address = []): array {
		$this->load->model('extension/pagou/payment/pagou');
		$total = $this->cart->getTotal();
		if (!$this->model_extension_pagou_payment_pagou->available('pagou_card', $address, (float)$total)) {
			return [];
		}
		$this->load->language('extension/pagou/payment/pagou_card');
		$title = trim((string)$this->config->get('payment_pagou_card_title'));
		$title = $title !== '' ? $title : $this->language->get('heading_title');

		return [
			'code'       => 'pagou_card',
			'name'       => $title,
			'option'     => ['pagou_card' => ['code' => 'pagou_card.pagou_card', 'name' => $title]],
			'sort_order' => $this->config->get('payment_pagou_card_sort_order'),
		];
	}
}
