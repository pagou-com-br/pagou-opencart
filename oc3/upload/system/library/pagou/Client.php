<?php
namespace Pagou\OpenCart;

/**
 * Cliente HTTP da API pública da Pagou (v1).
 *
 * Não depende do OpenCart: é compartilhado pelos pacotes OC 3 e OC 4.
 * Contrato: https://docs.pagou.com.br
 */
class Client {
	const SANDBOX = 'https://api.pagou.cloud';
	const PRODUCTION = 'https://api.pagou.com.br';
	const VERSION = '1.1.0';

	private $apiKey;
	private $baseUrl;
	private $userAgent;
	private $timeout;
	private $logger;

	/**
	 * @param string        $apiKey     chave X-API-KEY do ambiente
	 * @param bool          $production true = api.pagou.com.br, false = sandbox api.pagou.cloud
	 * @param string        $userAgent  identificação da integração
	 * @param callable|null $logger     function(string $message): void (nunca recebe a chave)
	 */
	public function __construct($apiKey, $production, $userAgent = '', $logger = null, $timeout = 30) {
		$this->apiKey = trim((string)$apiKey);
		$this->baseUrl = $production ? self::PRODUCTION : self::SANDBOX;
		$this->userAgent = $userAgent !== '' ? $userAgent : 'PagouOpenCart/' . self::VERSION;
		$this->logger = $logger;
		$this->timeout = (int)$timeout;
	}

	public function isProduction() {
		return $this->baseUrl === self::PRODUCTION;
	}

	// ---- Pix -------------------------------------------------------------

	public function createPix(array $body) {
		return $this->request('POST', '/v1/pix', $body);
	}

	public function getPix($id) {
		return $this->request('GET', '/v1/pix/' . rawurlencode($id));
	}

	public function cancelPix($id) {
		return $this->request('DELETE', '/v1/pix/' . rawurlencode($id));
	}

	/**
	 * GET /v1/pix: array de recursos (pode vir null). offset = número da página.
	 * Datas em UTC no formato YYYY-MM-DDTHH:mm:ssZ.
	 */
	public function listPix(array $query) {
		return self::listBody($this->request('GET', '/v1/pix?' . http_build_query($query)));
	}

	/** GET /v1/charges: mesmo formato da listagem de Pix. */
	public function listBoletos(array $query) {
		return self::listBody($this->request('GET', '/v1/charges?' . http_build_query($query)));
	}

	/** GET /v1/creditcard/charges: envelope { items, total }, offset = registros a pular. */
	public function listCardCharges(array $query) {
		$res = $this->request('GET', '/v1/creditcard/charges?' . http_build_query($query));
		return isset($res['items']) && is_array($res['items']) ? $res['items'] : array();
	}

	private static function listBody($res) {
		if (!is_array($res)) {
			return array();
		}
		// Array JSON na raiz (lista) ou, por tolerância, um envelope.
		if (isset($res['items']) && is_array($res['items'])) {
			return $res['items'];
		}
		if (isset($res['data']) && is_array($res['data']) && array_keys($res['data']) === range(0, count($res['data']) - 1)) {
			return $res['data'];
		}
		return array_keys($res) === range(0, count($res) - 1) ? $res : array();
	}

	// ---- Boleto ----------------------------------------------------------

	public function createBoleto(array $body) {
		return $this->request('POST', '/v1/charges', $body);
	}

	public function getBoleto($id) {
		return $this->request('GET', '/v1/charges/' . rawurlencode($id));
	}

	public function cancelBoleto($id) {
		return $this->request('DELETE', '/v1/charges/' . rawurlencode($id));
	}

	// ---- Cartão ----------------------------------------------------------

	/** Autorizações de cartão podem demorar: timeout próprio, maior que o das consultas. */
	public function createCardCharge(array $body, $idempotencyKey) {
		return $this->request('POST', '/v1/creditcard/charges', $body, array('Idempotency-Key: ' . $idempotencyKey), 60);
	}

	public function getCardCharge($id) {
		return $this->request('GET', '/v1/creditcard/charges/' . rawurlencode($id));
	}

	// ---- Conta / Sandbox -------------------------------------------------

	public function balance() {
		return $this->request('GET', '/v1/customers/balance');
	}

	/** Só existe no Sandbox. */
	public function simulatePixPayment($qrcodeId, $idempotencyKey) {
		if ($this->isProduction()) {
			throw new ApiException('Simulação disponível apenas no Sandbox.', 0);
		}
		return $this->request('POST', '/v1/payment-simulations/pix/' . rawurlencode($qrcodeId), null, array('Idempotency-Key: ' . $idempotencyKey));
	}

	/** Só existe no Sandbox: paga a cobrança pelo Pix vinculado. */
	public function simulateBoletoPayment($chargeId, $idempotencyKey) {
		if ($this->isProduction()) {
			throw new ApiException('Simulação disponível apenas no Sandbox.', 0);
		}
		return $this->request('POST', '/v1/payment-simulations/charges/' . rawurlencode($chargeId), array('payment_method' => 'pix'), array('Idempotency-Key: ' . $idempotencyKey));
	}

	// ---- Transporte ------------------------------------------------------

	/**
	 * @return array corpo JSON decodificado (já sem a API key, que a Pagou ecoa em metadata)
	 * @throws ApiException       resposta HTTP de erro (4xx/5xx)
	 * @throws TransportException timeout ou falha de rede: resultado INDETERMINADO
	 */
	public function request($method, $path, $body = null, array $extraHeaders = array(), $timeout = null) {
		if ($this->apiKey === '') {
			throw new ApiException('Chave de API da Pagou não configurada.', 401);
		}

		$headers = array_merge(array(
			'X-API-KEY: ' . $this->apiKey,
			'Accept: application/json',
			'User-Agent: ' . $this->userAgent,
		), $extraHeaders);

		$payload = null;
		if ($body !== null) {
			$payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
			$headers[] = 'Content-Type: application/json';
		}

		$ch = curl_init($this->baseUrl . $path);
		curl_setopt_array($ch, array(
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => $timeout ? (int)$timeout : $this->timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		));
		if ($payload !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		} elseif ($method !== 'GET') {
			// POST/PUT/DELETE sem corpo: envia Content-Length: 0 (alguns proxies respondem 411 sem ele)
			curl_setopt($ch, CURLOPT_POSTFIELDS, '');
		}

		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false || $errno) {
			$this->log(sprintf('%s %s -> falha de transporte (%d) %s', $method, $path, $errno, $error));
			throw new TransportException('Falha de comunicação com a Pagou: ' . $error, $errno);
		}

		$decoded = $raw === '' ? array() : json_decode($raw, true);
		if (!is_array($decoded)) {
			$decoded = array();
		}
		$decoded = $this->scrub($decoded);

		$this->log(sprintf('%s %s -> HTTP %d', $method, $path, $status));

		if ($status >= 200 && $status < 300) {
			return $decoded;
		}

		$message = self::errorMessage($decoded);
		throw new ApiException($message !== '' ? $message : 'HTTP ' . $status, $status, $decoded);
	}

	/** A API não tem um formato único de erro: error | message | errors[]. */
	public static function errorMessage(array $body) {
		$parts = array();
		foreach (array('error', 'message') as $k) {
			if (isset($body[$k]) && is_string($body[$k]) && $body[$k] !== '') {
				$parts[] = $body[$k];
			}
		}
		if (isset($body['errors'])) {
			foreach ((array)$body['errors'] as $k => $e) {
				if (is_string($e)) {
					$parts[] = is_string($k) ? $k . ': ' . $e : $e;
				} elseif (is_array($e)) {
					$parts[] = implode(', ', array_filter(array_map(function ($v) { return is_scalar($v) ? (string)$v : ''; }, $e)));
				}
			}
		}
		return implode(' | ', array_unique(array_filter($parts)));
	}

	/** Remove a API key de qualquer lugar da resposta (a Pagou devolve a chave em metadata). */
	private function scrub($data) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = $this->scrub($v);
			}
			return $data;
		}
		if (is_string($data) && $this->apiKey !== '' && strpos($data, $this->apiKey) !== false) {
			return str_replace($this->apiKey, '***', $data);
		}
		return $data;
	}

	private function log($message) {
		if ($this->logger) {
			call_user_func($this->logger, '[pagou] ' . $message);
		}
	}

	public static function uuid4() {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}
}

class ApiException extends \Exception {
	private $body;

	public function __construct($message, $code = 0, array $body = array()) {
		parent::__construct($message, (int)$code);
		$this->body = $body;
	}

	public function getBody() {
		return $this->body;
	}

	public function getErrorCode() {
		return isset($this->body['code']) && is_string($this->body['code']) ? $this->body['code'] : '';
	}

	/** 5xx / 502 podem significar que a operação aconteceu: tratar como indeterminado. */
	public function isIndeterminate() {
		return $this->getCode() >= 500 || in_array($this->getErrorCode(), array('idempotency_in_progress', 'idempotency_outcome_unknown'), true);
	}
}

class TransportException extends \Exception {
}
