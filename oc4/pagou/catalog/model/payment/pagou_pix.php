<?php
namespace Opencart\Catalog\Model\Extension\Pagou\Payment;

class PagouPix extends \Opencart\System\Engine\Model {
	public function getMethods(array $address = []): array {
		$this->load->model('extension/pagou/payment/pagou');
		$total = $this->cart->getTotal();
		if (!$this->model_extension_pagou_payment_pagou->available('pagou_pix', $address, (float)$total)) {
			return [];
		}
		$this->load->language('extension/pagou/payment/pagou_pix');
		$title = trim((string)$this->config->get('payment_pagou_pix_title'));
		$title = $title !== '' ? $title : $this->language->get('heading_title');

		return [
			'code'       => 'pagou_pix',
			'name'       => $title,
			'option'     => ['pagou_pix' => ['code' => 'pagou_pix.pagou_pix', 'name' => $title]],
			'sort_order' => $this->config->get('payment_pagou_pix_sort_order'),
		];
	}
}
