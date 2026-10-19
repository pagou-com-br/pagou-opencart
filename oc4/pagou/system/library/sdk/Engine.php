<?php
namespace Pagou\OpenCart;

/**
 * Regras de negócio da integração, iguais no OpenCart 3 e 4.
 *
 * O pacote de cada versão injeta:
 *  - $settings: configuração já sem prefixo (environment, api_key_sandbox, api_key_production, ...)
 *  - $onStatus: function(int $orderId, string $status, string $comment, bool $notify): void
 *               aplica o status normalizado no pedido (addHistory / addOrderHistory)
 *  - $logger:   function(string $message): void
 */
class Engine {
	private $repo;
	private $settings;
	private $onStatus;
	private $logger;
	private $clients = array();
	/** A API respondeu 429 nesta execução: a conciliação para de consultar. */
	private $rateLimited = false;
	/** A última chamada de refresh() obteve resposta da Pagou? */
	private $lastRefreshOk = false;

	public function __construct(Repository $repo, array $settings, callable $onStatus, $logger = null) {
		$this->repo = $repo;
		$this->settings = $settings;
		$this->onStatus = $onStatus;
		$this->logger = $logger;
	}

	public function repository() {
		return $this->repo;
	}

	public function environment() {
		return (isset($this->settings['environment']) && $this->settings['environment'] === 'production') ? 'production' : 'sandbox';
	}

	/** Substitui o cliente HTTP de um ambiente (testes). */
	public function setClient($environment, Client $client) {
		$this->clients[$environment] = $client;
	}

	public function client($environment = null) {
		$environment = $environment ?: $this->environment();
		if (!isset($this->clients[$environment])) {
			$key = isset($this->settings['api_key_' . $environment]) ? $this->settings['api_key_' . $environment] : '';
			$ua = 'PagouOpenCart/' . Client::VERSION . (isset($this->settings['platform']) ? ' (' . $this->settings['platform'] . ')' : '');
			$this->clients[$environment] = new Client($key, $environment === 'production', $ua, $this->logger);
		}
		return $this->clients[$environment];
	}

	private function setting($key, $default = '') {
		return isset($this->settings[$key]) && $this->settings[$key] !== '' && $this->settings[$key] !== null ? $this->settings[$key] : $default;
	}

	// ---------------------------------------------------------------------
	// Criação
	// ---------------------------------------------------------------------

	/**
	 * Reaproveita a cobrança Pix/boleto em aberto do mesmo pedido e valor (cliente que clicou duas vezes,
	 * voltou do e-mail etc.): a API não tem idempotência para Pix/boleto.
	 */
	public function reusable($orderId, $method, $amount) {
		$t = $this->repo->latestForOrder($orderId, $method);
		if (!$t || $t['environment'] !== $this->environment() || abs((float)$t['amount'] - Payer::reais($amount)) > 0.005) {
			return null;
		}
		if ($t['status'] !== Status::PENDING || empty($t['pagou_id'])) {
			return null;
		}
		if ($method === 'pix' && !empty($t['expires_at']) && strtotime($t['expires_at'] . ' UTC') <= time() + 120) {
			return null;
		}
		if ($method === 'boleto' && !empty($t['data']['due_date'])) {
			// Não reapresenta boleto vencido (vencimento + tolerância passou ou passa amanhã)
			$grace = max(1, (int)$this->setting('boleto_grace_period', 1));
			$limit = strtotime($t['data']['due_date'] . ' 23:59:59 America/Sao_Paulo') + $grace * 86400;
			if ($limit < time() + 86400) {
				return null;
			}
		}
		return $t;
	}

	/** Janela em que uma criação sem resposta ainda pode aparecer na listagem da Pagou. */
	const RECOVERY_GRACE = 180;
	/**
	 * Por quanto tempo um cartão sem resposta é recuperado repetindo a requisição original.
	 * Curto de propósito: o replay pode efetivar a cobrança, e isso não deve acontecer horas depois
	 * da compra, sem o cliente presente. Depois disso a tentativa vai para conferência manual.
	 */
	const CARD_REPLAY_WINDOW = 1800;

	public function createPix(array $orderInfo, array $payer, $notificationUrl) {
		$amount = Payer::reais($orderInfo['total']);
		if ($existing = $this->reusable($orderInfo['order_id'], 'pix', $amount)) {
			return $existing;
		}
		if ($recovered = $this->settlePendingAttempt($orderInfo['order_id'], 'pix')) {
			return $recovered;
		}
		$minutes = max(1, (int)$this->setting('pix_expiration', 60));
		$body = Order::pixBody($payer, $amount, $orderInfo['order_id'], Order::description($orderInfo['store_name'], $orderInfo['order_id']), $minutes * 60, $notificationUrl);
		$t = $this->startAttempt($orderInfo, 'pix', $amount, 1, array());

		$client = $this->client();
		$res = $this->send($t, function () use ($client, $body) {
			return $client->createPix($body);
		});
		if (empty($res['id'])) {
			$this->repo->update($t['pagou_transaction_id'], array('status' => Status::FAILED, 'data' => array('error' => 'resposta sem id')));
			throw new ApiException('A Pagou não devolveu o identificador do Pix.', 0, $res);
		}
		$this->repo->update($t['pagou_transaction_id'], array(
			'pagou_id' => (string)$res['id'],
			'status' => Status::PENDING,
			'expires_at' => gmdate('Y-m-d H:i:s', time() + $minutes * 60),
			'data' => array(
				'pix_code' => isset($res['payload']['data']) ? (string)$res['payload']['data'] : '',
				'pix_image' => isset($res['payload']['image']) ? (string)$res['payload']['image'] : '',
			),
		));
		$t = $this->repo->find($t['pagou_transaction_id']);
		// Status 1 = dados ainda não disponíveis: a consulta preenche o QR Code.
		return $t['data']['pix_code'] === '' ? $this->refresh($t, true) : $t;
	}

	public function createBoleto(array $orderInfo, array $payer, $notificationUrl) {
		$amount = Payer::reais($orderInfo['total']);
		if ($amount < 5) {
			throw new ApiException('O boleto exige valor mínimo de R$ 5,00.', 400);
		}
		if ($existing = $this->reusable($orderInfo['order_id'], 'boleto', $amount)) {
			return $existing;
		}
		if ($recovered = $this->settlePendingAttempt($orderInfo['order_id'], 'boleto')) {
			return $recovered;
		}
		$tz = new \DateTimeZone('America/Sao_Paulo');
		$due = new \DateTime('now', $tz);
		$due->modify('+' . max(1, (int)$this->setting('boleto_due_days', 3)) . ' day');
		$body = Order::boletoBody(
			$payer, $amount, $orderInfo['order_id'],
			Order::description($orderInfo['store_name'], $orderInfo['order_id']),
			$due->format('Y-m-d'),
			(int)$this->setting('boleto_grace_period', 1),
			(float)$this->setting('boleto_fine', 0),
			(float)$this->setting('boleto_interest', 0),
			$notificationUrl
		);
		$t = $this->startAttempt($orderInfo, 'boleto', $amount, 1, array('due_date' => $body['due_date']));

		$client = $this->client();
		$res = $this->send($t, function () use ($client, $body) {
			return $client->createBoleto($body);
		});
		if (empty($res['id'])) {
			$this->repo->update($t['pagou_transaction_id'], array('status' => Status::FAILED, 'data' => array('due_date' => $body['due_date'], 'error' => 'resposta sem id')));
			throw new ApiException('A Pagou não devolveu o identificador do boleto.', 0, $res);
		}
		$this->repo->update($t['pagou_transaction_id'], array('pagou_id' => (string)$res['id'], 'status' => Status::PENDING));
		// O registro é assíncrono: tenta uma vez já trazer a linha digitável.
		return $this->refresh($this->repo->find($t['pagou_transaction_id']), true);
	}

	/**
	 * Cartão. Retorna a transação; status pode ser paid, authorized, pending, failed ou unknown.
	 * Falha financeira confirmada (failed) não vira status de pedido: o comprador pode tentar outro cartão.
	 */
	public function createCard(array $orderInfo, array $payer, array $card, $installments, $ip) {
		$amount = Payer::reais($orderInfo['total']);
		if ($recovered = $this->settlePendingAttempt($orderInfo['order_id'], 'card')) {
			return $recovered;
		}
		$key = Client::uuid4();
		$body = Order::cardBody($payer, $card, $amount, $installments, Order::description($orderInfo['store_name'], $orderInfo['order_id']), $ip, gmdate('Y-m-d'));
		// A requisição original (com o token, que só a Pagou decifra) fica guardada enquanto o resultado
		// for incerto: a doc manda repetir com a MESMA chave e o MESMO corpo, nunca com chave nova.
		$t = $this->startAttempt($orderInfo, 'card', $amount, $installments, array('holder' => $card['holder'], 'request' => json_encode($body)), $key);

		$client = $this->client();
		$res = $this->send($t, function () use ($client, $body, $key) {
			return $client->createCardCharge($body, $key);
		});

		return $this->storeCardResult($t, $res, sprintf('Pagamento com cartão em %dx', (int)$installments));
	}

	/**
	 * Grava o resultado da cobrança de cartão, descarta a requisição guardada (token) e aplica o
	 * status pelo caminho normal (transition): pedido atualizado uma vez, com histórico e e-mail.
	 */
	private function storeCardResult(array $t, array $res, $reason) {
		$status = Status::fromCard(isset($res['status']) ? $res['status'] : '');
		$data = array('holder' => isset($t['data']['holder']) ? $t['data']['holder'] : '', 'card_status' => isset($res['status']) ? (string)$res['status'] : '');
		if (!empty($res['failure_reason'])) {
			$data['failure_reason'] = (string)$res['failure_reason'];
		}
		$this->repo->update($t['pagou_transaction_id'], array(
			'pagou_id' => isset($res['id']) ? (string)$res['id'] : null,
			'data' => $data,
		));
		$t = $this->repo->find($t['pagou_transaction_id']);
		if ($status !== Status::UNKNOWN) {
			$label = array(Status::PAID => 'aprovado', Status::AUTHORIZED => 'autorizado, aguardando captura', Status::PENDING => 'em processamento', Status::FAILED => 'recusado', Status::CANCELED => 'cancelado');
			$why = $reason . ': ' . (isset($label[$status]) ? $label[$status] : $status) . (!empty($data['failure_reason']) ? ' (' . $data['failure_reason'] . ')' : '');
			$this->transition($t, $status, $why, $this->paidAmount('card', $res));
		}
		return $this->repo->find($t['pagou_transaction_id']);
	}

	/**
	 * Recupera um cartão sem resposta repetindo a requisição original com a mesma Idempotency-Key
	 * (válida por 24 h). Nunca cria uma cobrança nova para contornar a incerteza.
	 * @return array|null transação resolvida; null se ainda incerta
	 */
	private function replayCard(array $t) {
		if (empty($t['data']['request']) || empty($t['idempotency_key'])) {
			return null;
		}
		$body = json_decode($t['data']['request'], true);
		if (!is_array($body)) {
			return null;
		}
		try {
			$res = $this->client($t['environment'])->createCardCharge($body, $t['idempotency_key']);
		} catch (ApiException $e) {
			$this->noteRateLimit($e);
			if (self::isExplicitRefusal($e)) {
				// Recusa explícita (400/422 de dados ou regra): a cobrança não foi feita.
				$this->repo->update($t['pagou_transaction_id'], array('data' => array('holder' => isset($t['data']['holder']) ? $t['data']['holder'] : '', 'error' => $e->getMessage(), 'code' => $e->getErrorCode())));
				$t = $this->repo->find($t['pagou_transaction_id']);
				$this->transition($t, Status::FAILED, 'Pagamento com cartão recusado: ' . $e->getMessage());
				return $this->repo->find($t['pagou_transaction_id']);
			}
			return null; // 401/403/404/409/429/5xx/conflito de chave: segue incerto
		} catch (\Exception $e) {
			return null;
		}
		return $this->storeCardResult($t, $res, 'Pagamento com cartão confirmado na recuperação');
	}

	/** 400/422 recusam a operação; idempotency_key_conflict e o resto não provam que a cobrança não existe. */
	private static function isExplicitRefusal(ApiException $e) {
		return in_array($e->getCode(), array(400, 422), true) && $e->getErrorCode() !== 'idempotency_key_conflict';
	}

	/**
	 * Cartão sem confirmação depois da janela: apaga o token, comenta no pedido para a loja conferir
	 * no painel da Pagou e libera uma nova tentativa do cliente.
	 */
	private function closeUncertainCard(array $t) {
		$data = $t['data'];
		unset($data['request']);
		$data['recovery'] = 'sem confirmação da Pagou em ' . (self::CARD_REPLAY_WINDOW / 60) . ' min';
		$this->repo->update($t['pagou_transaction_id'], array('data' => $data));
		$t = $this->repo->find($t['pagou_transaction_id']);
		$this->transition($t, Status::FAILED, 'ATENÇÃO: pagamento com cartão sem confirmação da Pagou. Confira no painel da Pagou se houve cobrança antes de cobrar o cliente de novo');
	}

	/** Registra a tentativa ANTES de chamar a API: um timeout deixa rastro para a recuperação. */
	private function startAttempt(array $orderInfo, $method, $amount, $installments, array $data, $key = null) {
		return $this->repo->create(array(
			'order_id' => $orderInfo['order_id'],
			'store_id' => isset($orderInfo['store_id']) ? $orderInfo['store_id'] : 0,
			'method' => $method,
			'environment' => $this->environment(),
			'idempotency_key' => $key,
			'status' => Status::UNKNOWN,
			'amount' => $amount,
			'installments' => $installments,
			'data' => $data,
		));
	}

	/**
	 * Executa a criação. Falha confirmada => failed. Timeout/5xx => fica unknown (a cobrança pode
	 * existir na Pagou) e a próxima tentativa ou o cron procuram por ela antes de criar outra.
	 */
	private function send(array $t, $call) {
		try {
			return call_user_func($call);
		} catch (TransportException $e) {
			$this->repo->update($t['pagou_transaction_id'], array('data' => $t['data'] + array('error' => 'sem resposta: ' . $e->getMessage())));
			throw $e;
		} catch (ApiException $e) {
			$status = $e->isIndeterminate() ? Status::UNKNOWN : Status::FAILED;
			$data = $t['data'];
			if ($status === Status::FAILED) {
				unset($data['request']); // recusa definitiva: não há o que recuperar, descarta o token
			}
			$this->repo->update($t['pagou_transaction_id'], array('status' => $status, 'data' => $data + array('error' => $e->getMessage(), 'code' => $e->getErrorCode())));
			throw $e;
		}
	}

	/**
	 * Há uma tentativa anterior sem resposta (unknown, sem ID)? Procura na Pagou antes de criar outra.
	 * @return array|null a cobrança recuperada (para reaproveitar)
	 * @throws TransportException ainda dentro da janela e sem achar: pede para o cliente aguardar
	 */
	private function settlePendingAttempt($orderId, $method) {
		$last = $this->repo->latestForOrder($orderId, $method);
		if (!$last || $last['status'] !== Status::UNKNOWN || !empty($last['pagou_id']) || $last['environment'] !== $this->environment()) {
			return null;
		}
		$found = $this->recover($last);
		if ($found) {
			// Recusada/encerrada: o cliente pode tentar de novo. Caso contrário, reaproveita.
			return in_array($found['status'], array(Status::FAILED, Status::CANCELED, Status::REJECTED), true) ? null : $found;
		}
		if ($method === 'card') {
			$now = $this->repo->find($last['pagou_transaction_id']);
			if ($now && $now['status'] === Status::FAILED) {
				return null; // encerrada para conferência manual: libera nova tentativa
			}
			// Dentro da janela, sem confirmação, não há segunda cobrança.
			throw new TransportException('O pagamento anterior com cartão ainda está em verificação.');
		}
		if (time() - strtotime($last['date_added'] . ' UTC') < self::RECOVERY_GRACE) {
			throw new TransportException('A tentativa anterior ainda está sendo confirmada pela Pagou.');
		}
		$this->repo->update($last['pagou_transaction_id'], array('status' => Status::FAILED, 'data' => $last['data'] + array('recovery' => 'não encontrada na Pagou')));
		return null;
	}

	/**
	 * Procura na listagem da Pagou a cobrança criada por uma tentativa sem resposta.
	 * Pix/boleto: customer_code "oc-<pedido>" + valor. Cartão: descrição "Pedido #<pedido>" + valor.
	 */
	public function recover(array $t) {
		if ($t['method'] === 'card') {
			if (strtotime($t['date_added'] . ' UTC') > time() - self::CARD_REPLAY_WINDOW) {
				return $this->replayCard($t);
			}
			$this->closeUncertainCard($t);
			return null;
		}
		$from = strtotime($t['date_added'] . ' UTC') - 120;
		$to = strtotime($t['date_added'] . ' UTC') + 900;
		$query = array(
			'created_at_from' => gmdate('Y-m-d\TH:i:s\Z', $from),
			'created_at_to' => gmdate('Y-m-d\TH:i:s\Z', $to),
			'limit' => 100,
			'offset' => 0,
		);
		$client = $this->client($t['environment']);
		try {
			if ($t['method'] === 'pix') {
				$items = $client->listPix($query);
			} else {
				$items = $client->listBoletos($query);
			}
		} catch (\Exception $e) {
			$this->noteRateLimit($e);
			$this->log('recuperação ' . $t['method'] . ' pedido ' . $t['order_id'] . ': ' . $e->getMessage());
			return null;
		}

		foreach ($items as $item) {
			if (!is_array($item) || empty($item['id'])) {
				continue;
			}
			$match = isset($item['customer_code']) && (string)$item['customer_code'] === 'oc-' . (int)$t['order_id']
				&& isset($item['amount']) && abs((float)$item['amount'] - (float)$t['amount']) < 0.005;
			if (!$match || $this->repo->findByPagouId((string)$item['id'])) {
				continue;
			}
			$fields = array('pagou_id' => (string)$item['id'], 'status' => Status::PENDING);
			if ($t['method'] === 'pix') {
				$fields['expires_at'] = gmdate('Y-m-d H:i:s', strtotime($t['date_added'] . ' UTC') + max(60, (int)(isset($item['expiration']) ? $item['expiration'] : 3600)));
			}
			$this->repo->update($t['pagou_transaction_id'], $fields);
			$this->log('recuperada ' . $t['method'] . ' ' . $item['id'] . ' do pedido ' . $t['order_id']);
			return $this->refresh($this->repo->find($t['pagou_transaction_id']), true);
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Consulta / conciliação
	// ---------------------------------------------------------------------

	/**
	 * Consulta a Pagou e aplica o resultado. $quiet = não lança exceção de rede.
	 */
	public function refresh(array $t, $quiet = false) {
		$this->lastRefreshOk = false;
		if (empty($t['pagou_id'])) {
			return $t;
		}
		try {
			$client = $this->client($t['environment']);
			if ($t['method'] === 'pix') {
				$res = $client->getPix($t['pagou_id']);
			} elseif ($t['method'] === 'boleto') {
				$res = $client->getBoleto($t['pagou_id']);
			} else {
				$res = $client->getCardCharge($t['pagou_id']);
			}
		} catch (\Exception $e) {
			$this->noteRateLimit($e);
			$this->repo->touchChecked($t['pagou_transaction_id']);
			if ($quiet) {
				$this->log('refresh ' . $t['pagou_id'] . ': ' . $e->getMessage());
				return $this->repo->find($t['pagou_transaction_id']);
			}
			throw $e;
		}
		$this->repo->touchChecked($t['pagou_transaction_id']);
		$this->lastRefreshOk = true;

		$data = $t['data'];
		if ($t['method'] === 'boleto' && !empty($res['payload']) && is_array($res['payload'])) {
			$data = $this->mergeBoletoPayload($data, $res['payload']);
		}
		if ($t['method'] === 'pix' && empty($data['pix_code']) && !empty($res['payload']['data']) && is_string($res['payload']['data'])) {
			// Cobrança recuperada pela listagem: a consulta traz o Copia e Cola.
			$data['pix_code'] = $res['payload']['data'];
			$data['pix_image'] = isset($res['payload']['image']) && is_string($res['payload']['image']) ? $res['payload']['image'] : '';
		}
		if ($t['method'] === 'card' && isset($res['status'])) {
			$data['card_status'] = (string)$res['status'];
			if (!empty($res['failure_reason'])) {
				$data['failure_reason'] = (string)$res['failure_reason'];
			}
		}
		if ($data !== $t['data']) {
			$this->repo->update($t['pagou_transaction_id'], array('data' => $data));
			$t['data'] = $data;
		}

		$status = Status::fromApi($t['method'], $res);
		$reason = 'Consulta à Pagou';

		// Estado 5 não garante devolução integral: só estorna o pedido com refund.amount integral.
		if ($status === Status::REFUNDED && $t['method'] !== 'card') {
			$refunded = isset($res['refund']['amount']) && is_numeric($res['refund']['amount']) ? (float)$res['refund']['amount'] : null;
			if ($refunded === null || $refunded + 0.005 < (float)$t['amount']) {
				$status = Status::UNKNOWN; // parcial ou sem detalhe: o webhook qrcode.refunded registra
			}
		}
		// Boleto 8 = pago com desconto: a integração não oferece desconto, então vai para conferência.
		if ($t['method'] === 'boleto' && isset($res['status']) && (int)$res['status'] === 8) {
			$this->flagUnderpaid($t, isset($res['amount_paid']) && is_numeric($res['amount_paid']) ? (float)$res['amount_paid'] : null, 'a Pagou informou o boleto como pago com desconto');
			$status = Status::UNKNOWN;
		}
		// Boleto cancelado pode ter sido pago pelo Pix vinculado: confere o QR Code antes de cancelar.
		if ($status === Status::CANCELED && $t['method'] === 'boleto' && !empty($data['qrcode_id'])) {
			try {
				$pix = $this->client($t['environment'])->getPix($data['qrcode_id']);
				if (Status::fromPix(isset($pix['status']) ? $pix['status'] : 0) === Status::PAID) {
					$status = Status::PAID;
					$reason = 'Boleto pago pelo Pix vinculado (consulta à Pagou)';
					$res = $pix;
				}
			} catch (\Exception $e) {
				$this->noteRateLimit($e);
				$status = Status::UNKNOWN; // na dúvida, não cancela
			}
		}

		if ($status !== Status::UNKNOWN) {
			$this->transition($t, $status, $reason, $this->paidAmount($t['method'] === 'boleto' && $reason !== 'Consulta à Pagou' ? 'pix' : $t['method'], $res));
		}
		return $this->repo->find($t['pagou_transaction_id']);
	}

	/** Conciliação periódica (cron): não depende do webhook ter chegado. */
	public function reconcile($limit = 25, $sleepMs = 1500) {
		$done = array();
		foreach ($this->repo->openForReconcile($limit, 35) as $t) {
			if ($this->rateLimited) {
				break; // 429: retoma na próxima execução do cron
			}
			$before = $t['status'];
			try {
				$t = $this->reconcileOne($t);
			} catch (\Throwable $e) {
				$this->log('conciliação pedido ' . $t['order_id'] . ': ' . $e->getMessage());
			}
			$done[] = array('order_id' => $t['order_id'], 'method' => $t['method'], 'from' => $before, 'to' => $t['status']);
			if ($sleepMs > 0) {
				usleep($sleepMs * 1000); // a API limita consultas seguidas (HTTP 429)
			}
		}
		return $done;
	}

	private function reconcileOne(array $t) {
			$refreshed = false;
			if (empty($t['pagou_id'])) {
				// Criação sem resposta: procura a cobrança; Pix/boleto sem rastro após 30 min são encerrados.
				// Cartão nunca é encerrado sozinho (pode ter sido cobrado): fica para a loja verificar.
				$found = $this->recover($t);
				if ($found) {
					$t = $found;
				} elseif ($t['method'] !== 'card' && !$this->rateLimited && strtotime($t['date_added'] . ' UTC') < time() - 1800) {
					$this->repo->update($t['pagou_transaction_id'], array('status' => Status::FAILED, 'data' => $t['data'] + array('recovery' => 'não encontrada na Pagou')));
					$t = $this->repo->find($t['pagou_transaction_id']);
				} else {
					$this->repo->touchChecked($t['pagou_transaction_id']);
				}
			} else {
				$t = $this->refresh($t, true);
				$refreshed = $this->lastRefreshOk;
			}
			// Pix vencido localmente: só marca expirado depois de a consulta confirmar que segue em aberto.
			if ($refreshed && $t['method'] === 'pix' && $t['status'] === Status::PENDING && !empty($t['expires_at']) && strtotime($t['expires_at'] . ' UTC') < time() - 600) {
				$this->transition($t, Status::EXPIRED, 'Pix expirado sem pagamento');
				$t = $this->repo->find($t['pagou_transaction_id']);
			}
			return $t;
	}

	// ---------------------------------------------------------------------
	// Webhook
	// ---------------------------------------------------------------------

	/**
	 * @return array{0:int,1:string} [código HTTP, mensagem]
	 */
	public function handleWebhook($rawBody, array $server) {
		$h = Webhook::headersFromServer($server);
		$event = Webhook::parse($rawBody);
		if (!$event) {
			return array(400, 'invalid payload');
		}

		// Assinatura primeiro (barato, sem banco): POST anônimo não dispara consulta nenhuma.
		$env = '';
		foreach (array('production', 'sandbox') as $candidate) {
			$key = isset($this->settings['api_key_' . $candidate]) ? $this->settings['api_key_' . $candidate] : '';
			if ($key !== '' && Webhook::verify($rawBody, $h['timestamp'], $h['signature'], $key)) {
				$env = $candidate;
				break;
			}
		}
		if ($env === '') {
			$this->log('webhook rejeitado (assinatura) ' . $event['name'] . ' ' . $event['id']);
			return array(401, 'invalid signature');
		}

		$t = $this->repo->findByPagouId($event['id']);
		if (!$t && strpos($event['name'], 'qrcode.') === 0) {
			// Pix vinculado a um boleto: o evento traz o ID do QR Code.
			$t = $this->repo->findByQrcodeId($event['id']);
		}
		if ($t && $t['environment'] !== $env) {
			// A assinatura tem de ser da chave do ambiente da cobrança.
			$this->log('webhook com chave de outro ambiente ' . $event['id']);
			return array(401, 'invalid signature');
		}
		if (!$t) {
			$this->log('webhook de cobrança desconhecida ' . $event['id'] . ' (' . $event['name'] . ')');
			return array(200, 'ignored');
		}

		$key = Webhook::dedupKey($event);
		if (!$this->repo->registerEvent($key, $event['name'], $event['id'])) {
			return array(200, 'duplicate');
		}

		try {
			$this->applyEvent($t, $event);
		} catch (\Throwable $e) {
			// Não confirma: a Pagou reenvia e o evento volta a ser processado.
			$this->repo->forgetEvent($key);
			$this->log('webhook falhou ' . $event['id'] . ': ' . $e->getMessage());
			return array(500, 'error');
		}
		return array(200, 'ok');
	}

	private function applyEvent(array $t, array $event) {
		$d = $event['data'];
		switch ($event['name']) {
			case 'qrcode.completed':
				$paid = isset($d['amount']) ? (float)$d['amount'] : null;
				$this->transition($t, Status::PAID, 'Pix confirmado pela Pagou' . (!empty($d['e2e_id']) ? ' (E2E ' . $d['e2e_id'] . ')' : ''), $paid);
				break;
			case 'qrcode.refunded':
				$amount = isset($d['amount']) ? (float)$d['amount'] : 0;
				if ($amount + 0.005 >= (float)$t['amount']) {
					$this->transition($t, Status::REFUNDED, 'Pix devolvido integralmente pela Pagou');
				} else {
					$this->comment($t, sprintf('Devolução parcial do Pix confirmada pela Pagou: R$ %s', number_format($amount, 2, ',', '.')));
				}
				break;
			case 'charge.created':
				if (!empty($d['payload']) && is_array($d['payload'])) {
					$data = $this->mergeBoletoPayload($t['data'], $d['payload']);
					$this->repo->update($t['pagou_transaction_id'], array('data' => $data));
				}
				break;
			case 'charge.paid':
				$paid = isset($d['amount']['paid']) ? (float)$d['amount']['paid'] : null;
				$how = !empty($d['paid_in']) ? ' via ' . $d['paid_in'] : '';
				$this->transition($t, Status::PAID, 'Boleto pago' . $how . ' (confirmado pela Pagou)', $paid);
				break;
			default:
				$this->log('evento ignorado ' . $event['name']);
		}
	}

	// ---------------------------------------------------------------------
	// Transição de status
	// ---------------------------------------------------------------------

	/**
	 * Aplica o novo status na transação e no pedido, sem regredir e conferindo o valor pago.
	 */
	public function transition(array $t, $status, $reason, $paidAmount = null) {
		if (!Status::canTransition($t['status'], $status)) {
			return false;
		}
		if ($status === Status::PAID && $paidAmount !== null && $paidAmount + 0.005 < (float)$t['amount']) {
			$this->flagUnderpaid($t, $paidAmount, 'a Pagou informou pagamento menor que o valor da cobrança');
			return false;
		}
		if ($status === Status::PAID && !empty($t['data']['underpaid']) && ($paidAmount === null || $paidAmount + 0.005 < (float)$t['amount'])) {
			return false; // marcada para conferência: só um valor integral comprovado libera o pedido
		}
		// Só um processo (webhook, página ou cron) aplica cada mudança.
		if (!$this->repo->claimStatus($t['pagou_transaction_id'], $t['status'], $status)) {
			return false;
		}
		$label = array('pix' => 'Pix', 'boleto' => 'Boleto', 'card' => 'Cartão');
		$comment = '[Pagou ' . (isset($label[$t['method']]) ? $label[$t['method']] : $t['method']) . '] ' . $reason . '. ID ' . ($t['pagou_id'] ?: '-') . ($t['environment'] === 'sandbox' ? ' (SANDBOX)' : '');

		// Cartão recusado não muda o pedido (o cliente pode tentar outro cartão): só comentário.
		if ($status === Status::FAILED) {
			$status = 'comment';
		}

		// Uma cobrança antiga que expira/cancela não mexe num pedido já pago por outra cobrança.
		if (!in_array($status, array(Status::PAID, Status::REFUNDED, Status::CHARGEBACK), true) && $this->orderHasOtherPaid($t)) {
			$status = 'comment';
		}
		try {
			call_user_func($this->onStatus, (int)$t['order_id'], $status, $comment, in_array($status, array(Status::PAID, Status::REFUNDED, Status::CANCELED, Status::EXPIRED), true));
		} catch (\Throwable $e) {
			// O pedido não foi atualizado: devolve a transação ao estado anterior para o reenvio/cron tentar de novo.
			$this->repo->update($t['pagou_transaction_id'], array('status' => $t['status']));
			throw $e;
		}
		return true;
	}

	/** Marca a cobrança para conferência e comenta no pedido uma única vez. */
	private function flagUnderpaid(array $t, $paidAmount, $why) {
		if (!empty($t['data']['underpaid'])) {
			return;
		}
		$data = $t['data'];
		$data['underpaid'] = $paidAmount !== null ? round((float)$paidAmount, 2) : 'sem valor';
		$this->repo->update($t['pagou_transaction_id'], array('data' => $data));
		$this->comment($t, sprintf('ATENÇÃO: %s%s (cobrança de R$ %s). Pedido não foi liberado automaticamente: confira no painel da Pagou.',
			$why, $paidAmount !== null ? ': R$ ' . number_format((float)$paidAmount, 2, ',', '.') : '', number_format((float)$t['amount'], 2, ',', '.')));
	}

	private function orderHasOtherPaid(array $t) {
		foreach ($this->repo->allForOrder($t['order_id']) as $other) {
			if ($other['pagou_transaction_id'] != $t['pagou_transaction_id'] && $other['status'] === Status::PAID) {
				return true;
			}
		}
		return false;
	}

	private function comment(array $t, $text) {
		call_user_func($this->onStatus, (int)$t['order_id'], 'comment', '[Pagou] ' . $text, false);
	}

	private function paidAmount($method, array $res) {
		if ($method === 'card') {
			return isset($res['value']) ? ((int)$res['value']) / 100 : null;
		}
		if (isset($res['amount_paid']) && is_numeric($res['amount_paid'])) {
			return (float)$res['amount_paid'];
		}
		// No boleto, "amount" da consulta é o valor ORIGINAL (multa/desconto mudam o pago): não prova nada.
		if ($method === 'boleto') {
			return null;
		}
		return isset($res['amount']) && is_numeric($res['amount']) ? (float)$res['amount'] : null;
	}

	private function mergeBoletoPayload(array $data, array $payload) {
		foreach (array('line' => 'boleto_line', 'bar_code' => 'boleto_barcode') as $from => $to) {
			if (!empty($payload[$from]) && is_string($payload[$from])) {
				$data[$to] = $payload[$from];
			}
		}
		// Pix embutido no boleto (quando a conta oferece)
		if (!empty($payload['data']) && is_string($payload['data'])) {
			$data['pix_code'] = $payload['data'];
		}
		if (!empty($payload['image']) && is_string($payload['image'])) {
			$data['pix_image'] = $payload['image'];
		}
		if (!empty($payload['qrcode_id']) && is_scalar($payload['qrcode_id'])) {
			$data['qrcode_id'] = (string)$payload['qrcode_id'];
		}
		return $data;
	}

	/** Links públicos do boleto (só fazem sentido depois do registro). */
	public static function boletoLinks($pagouId) {
		return array(
			'html' => 'https://fatura.pagou.com.br/boleto/' . rawurlencode($pagouId),
			'pdf' => 'https://fatura.pagou.com.br/boleto/pdf/' . rawurlencode($pagouId),
		);
	}

	private function noteRateLimit(\Exception $e) {
		if ($e instanceof ApiException && $e->getCode() === 429) {
			$this->rateLimited = true;
		}
	}

	private function log($message) {
		if ($this->logger) {
			call_user_func($this->logger, '[pagou] ' . $message);
		}
	}
}
