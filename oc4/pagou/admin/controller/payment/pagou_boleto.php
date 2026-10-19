<?php
namespace Opencart\Admin\Controller\Extension\Pagou\Payment;

require_once DIR_EXTENSION . 'pagou/system/library/admin_base.php';

class PagouBoleto extends \Opencart\System\Library\Extension\Pagou\AdminBase {
	protected string $code = 'pagou_boleto';
	protected string $method = 'boleto';

	protected function methodFields(): array {
		return array(
			'due_days' => array('number', 3),
			'grace_period' => array('number', 1),
			'fine' => array('number', 0),
			'interest' => array('number', 0),
		);
	}
}
