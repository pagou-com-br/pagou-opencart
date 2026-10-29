<?php
require_once DIR_SYSTEM . 'library/pagou/oc3_admin.php';

class ControllerExtensionPaymentPagouCard extends PagouOc3AdminController {
	protected $code = 'pagou_card';
	protected $method = 'card';

	protected function methodFields() {
		return array(
			'max_installments' => array('number', 6),
			'free_installments' => array('number', ''),
			'interest_mode' => array('select', 'price', array('price' => 'text_mode_price', 'table' => 'text_mode_table')),
			'interest_rate' => array('number', 3.65),
			'interest_table' => array('text', ''),
			'min_installment' => array('number', 50),
		);
	}

	/**
	 * Tabela de acréscimo gravada já normalizada ("4=6.5; 5=7.9") e taxa com ponto decimal: o campo
	 * numérico do navegador não exibe "2,99" e, ao salvar de novo, a taxa se perderia.
	 */
	protected function normalizeField($key, $value) {
		if ($key === 'interest_table') {
			return \Pagou\OpenCart\Order::formatRateTable(\Pagou\OpenCart\Order::parseRateTable($value));
		}
		if ($key === 'interest_rate' && $value !== '') {
			return rtrim(rtrim(number_format(max(0, min(100, (float)str_replace(',', '.', $value))), 4, '.', ''), '0'), '.');
		}
		return $value;
	}
}
