<?php
require_once DIR_SYSTEM . 'library/pagou/oc3_admin.php';

class ControllerExtensionPaymentPagouBoleto extends PagouOc3AdminController {
	protected $code = 'pagou_boleto';
	protected $method = 'boleto';

	protected function methodFields() {
		return array(
			'due_days' => array('number', 3),
			'grace_period' => array('number', 1),
			'fine' => array('number', 0),
			'interest' => array('number', 0),
		);
	}
}
