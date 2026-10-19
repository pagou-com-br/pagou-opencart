<?php
namespace Opencart\Catalog\Controller\Extension\Pagou\Payment;

require_once DIR_EXTENSION . 'pagou/system/library/sdk/autoload.php';

use Pagou\OpenCart\ApiException;
use Pagou\OpenCart\Order;
use Pagou\OpenCart\Status;
use Pagou\OpenCart\TransportException;

class PagouCard extends \Opencart\System\Engine\Controller {
	public function index() {
		$this->load->language('extension/pagou/payment/pagou');
		$this->load->language('extension/pagou/payment/pagou_card');
		$this->load->model('extension/pagou/payment/pagou');
		$this->load->model('checkout/order');

		$order = isset($this->session->data['order_id']) ? $this->model_checkout_order->getOrder($this->session->data['order_id']) : array();
		$total = $order ? $this->model_extension_pagou_payment_pagou->toBrl($order['total']) : 0;

		$data['need_document'] = $order ? $this->model_extension_pagou_payment_pagou->needsDocument($order) : true;
		$data['sandbox'] = $this->model_extension_pagou_payment_pagou->environment() === 'sandbox';
		$data['production'] = !$data['sandbox'];
		$data['instructions'] = nl2br(htmlspecialchars((string)$this->config->get('payment_pagou_card_instructions'), ENT_QUOTES, 'UTF-8'));
		$data['confirm_url'] = 'index.php?route=extension/pagou/payment/pagou_card.confirm&language=' . $this->config->get('config_language');

		$data['installments'] = array();
		foreach ($this->installmentOptions($total) as $n) {
			$data['installments'][] = array(
				'value' => $n,
				'text' => sprintf($this->language->get('text_installments'), $n, $this->currency->format($total / $n, 'BRL', 1.0)),
			);
		}

		foreach (array('button_confirm', 'text_loading', 'text_sandbox', 'text_document', 'text_document_help', 'entry_card_number',
			'entry_card_holder', 'entry_card_expiry', 'entry_card_cvv', 'entry_installments', 'error_card_token') as $k) {
			$data[$k] = $this->language->get($k);
		}
		return $this->load->view('extension/pagou/payment/pagou_card', $data);
	}

	private function installmentOptions($total) {
		return Order::installmentOptions($total, (int)$this->config->get('payment_pagou_card_max_installments') ?: 1, (float)$this->config->get('payment_pagou_card_min_installment'));
	}

	public function confirm() {
		$this->load->language('extension/pagou/payment/pagou');
		$this->load->model('extension/pagou/payment/pagou');
		$pagou = $this->model_extension_pagou_payment_pagou;
		$json = array();

		list($order, $error) = $pagou->sessionOrder('pagou_card');
		$post = $this->request->post;
		$card = array(
			'card_token' => isset($post['card_token']) ? (string)$post['card_token'] : '',
			'holder' => isset($post['holder']) ? trim((string)$post['holder']) : '',
			'expires_at' => isset($post['expires_at']) ? (string)$post['expires_at'] : '',
		);
		$installments = isset($post['installments']) ? (int)$post['installments'] : 1;

		if (!$error && !Order::validCard($card)) {
			$error = $this->language->get('error_card_token');
		}
		if (!$error && !in_array($installments, $this->installmentOptions($pagou->toBrl($order['total'])), true)) {
			$error = $this->language->get('error_installments');
		}
		$payer = null;
		if (!$error) {
			list($payer, $error) = $pagou->validPayer($order, 'card', isset($post['document']) ? (string)$post['document'] : '');
		}

		if (!$error) {
			// Mesmo pedido já pago (clique duplo / voltar do navegador): não cobra de novo.
			$last = $pagou->repository()->latestForOrder((int)$order['order_id'], 'card');
			// (tentativa "unknown" sem ID é resolvida pelo Engine: recupera ou libera depois da janela)
			if ($last && !empty($last['pagou_id']) && in_array($last['status'], array(Status::PAID, Status::AUTHORIZED, Status::PENDING, Status::UNKNOWN), true)) {
				$json['redirect'] = $pagou->payUrl((int)$order['order_id']);
			}
		}

		if (!$error && !$json) {
			try {
				$t = $pagou->engine()->createCard($pagou->orderForSdk($order), $payer, $card, $installments, $pagou->clientIp());
				if ($t['status'] === Status::FAILED || $t['status'] === Status::CANCELED) {
					$reason = !empty($t['data']['failure_reason']) ? ': ' . $t['data']['failure_reason'] : '';
					$error = sprintf($this->language->get('error_card_declined'), $reason);
				} else {
					// Pago/autorizado/em processamento: o Engine já aplicou o status no pedido (uma vez só).
					if ($t['status'] === Status::UNKNOWN) {
						$pagou->markPending((int)$order['order_id'], sprintf($this->language->get('text_history_card'), $installments));
					}
					$json['redirect'] = $pagou->payUrl((int)$order['order_id']);
				}
			} catch (TransportException $e) {
				$pagou->markPending((int)$order['order_id'], 'Cartão: sem resposta da Pagou, resultado a confirmar.');
				$json['redirect'] = $pagou->payUrl((int)$order['order_id']);
			} catch (ApiException $e) {
				$pagou->debug('cartão pedido ' . $order['order_id'] . ': HTTP ' . $e->getCode() . ' ' . $e->getMessage());
				if ($e->isIndeterminate()) {
					$pagou->markPending((int)$order['order_id'], 'Cartão: resultado incerto na Pagou (' . $e->getMessage() . '), a confirmar.');
					$json['redirect'] = $pagou->payUrl((int)$order['order_id']);
				} else {
					$error = sprintf($this->language->get('error_card_declined'), ': ' . $e->getMessage());
				}
			} catch (\Throwable $e) {
				// Erro depois da chamada (ex.: e-mail do pedido): se houve cobrança, o cliente vai para a
				// página de pagamento, que mostra o estado real; nunca um 500 depois de cobrar.
				$pagou->debug('cartão pedido ' . $order['order_id'] . ': ' . get_class($e) . ' ' . $e->getMessage());
				$last = $pagou->repository()->latestForOrder((int)$order['order_id'], 'card');
				if ($last && $last['status'] !== Status::FAILED) {
					$json['redirect'] = $pagou->payUrl((int)$order['order_id']);
				} else {
					$error = $this->language->get('error_card_token');
				}
			}
		}

		if ($error) {
			$json = array('error' => $error);
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
