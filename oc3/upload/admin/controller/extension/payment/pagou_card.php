<?php
require_once DIR_SYSTEM . 'library/pagou/oc3_admin.php';

class ControllerExtensionPaymentPagouCard extends PagouOc3AdminController {
	protected $code = 'pagou_card';
	protected $method = 'card';

	protected function methodFields() {
		return array(
			'max_installments' => array('number', 6),
			'min_installment' => array('number', 50),
		);
	}
}
