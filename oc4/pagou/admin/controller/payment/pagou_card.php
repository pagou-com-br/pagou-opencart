<?php
namespace Opencart\Admin\Controller\Extension\Pagou\Payment;

require_once DIR_EXTENSION . 'pagou/system/library/admin_base.php';

class PagouCard extends \Opencart\System\Library\Extension\Pagou\AdminBase {
	protected string $code = 'pagou_card';
	protected string $method = 'card';

	protected function methodFields(): array {
		return array(
			'max_installments' => array('number', 6),
			'min_installment' => array('number', 50),
		);
	}
}
