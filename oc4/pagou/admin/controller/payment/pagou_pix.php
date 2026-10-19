<?php
namespace Opencart\Admin\Controller\Extension\Pagou\Payment;

require_once DIR_EXTENSION . 'pagou/system/library/admin_base.php';

class PagouPix extends \Opencart\System\Library\Extension\Pagou\AdminBase {
	protected string $code = 'pagou_pix';
	protected string $method = 'pix';

	protected function methodFields(): array {
		return array(
			'expiration' => array('number', 60),
		);
	}
}
