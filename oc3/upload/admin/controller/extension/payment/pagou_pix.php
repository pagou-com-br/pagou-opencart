<?php
require_once DIR_SYSTEM . 'library/pagou/oc3_admin.php';

class ControllerExtensionPaymentPagouPix extends PagouOc3AdminController {
	protected $code = 'pagou_pix';
	protected $method = 'pix';

	protected function methodFields() {
		return array(
			'expiration' => array('number', 60),
		);
	}
}
