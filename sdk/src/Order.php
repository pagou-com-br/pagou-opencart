<?php
namespace Pagou\OpenCart;

/**
 * Converte o pedido do OpenCart ($order_info, igual no 3.x e no 4.x) num pagador normalizado
 * e monta os corpos das chamadas de Pix, boleto e cartão.
 */
class Order {
	/**
	 * @param array $orderInfo  retorno de model checkout/order getOrder()
	 * @param array $map        mapeamento de campos:
	 *                          document_field, number_field, complement_field, neighborhood_field
	 *                          (id de custom field, 'address_2', 'company' ou '')
	 * @param string $documentOverride CPF/CNPJ digitado no passo de pagamento (quando a loja não coleta)
	 */
	public static function payer(array $orderInfo, array $map, $documentOverride = '') {
		// O OpenCart guarda tudo com htmlspecialchars ("D'Ávila" vira "D&#039;Ávila"): a API recebe o texto puro.
		array_walk_recursive($orderInfo, function (&$v) {
			if (is_string($v)) {
				$v = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
			}
		});
		$documentOverride = html_entity_decode((string)$documentOverride, ENT_QUOTES, 'UTF-8');
		$field = function ($ref) use ($orderInfo) {
			$ref = (string)$ref;
			if ($ref === '') {
				return '';
			}
			if (in_array($ref, array('address_2', 'company', 'address_1'), true)) {
				return isset($orderInfo['payment_' . $ref]) ? trim((string)$orderInfo['payment_' . $ref]) : '';
			}
			// Custom field: pode estar na conta (custom_field) ou no endereço (payment_custom_field)
			foreach (array('payment_custom_field', 'custom_field', 'shipping_custom_field') as $bag) {
				if (!empty($orderInfo[$bag])) {
					$values = is_array($orderInfo[$bag]) ? $orderInfo[$bag] : json_decode((string)$orderInfo[$bag], true);
					if (is_array($values) && isset($values[$ref]) && is_scalar($values[$ref]) && trim((string)$values[$ref]) !== '') {
						return trim((string)$values[$ref]);
					}
				}
			}
			return '';
		};

		$document = Payer::digits($documentOverride !== '' ? $documentOverride : $field(isset($map['document_field']) ? $map['document_field'] : ''));

		// OC 4.x pode não ter endereço de pagamento (config_checkout_payment_address = 0): usa o de entrega.
		$prefix = (!empty($orderInfo['payment_address_1']) || empty($orderInfo['shipping_address_1'])) ? 'payment_' : 'shipping_';
		$get = function ($k) use ($orderInfo, $prefix) {
			return isset($orderInfo[$prefix . $k]) ? trim((string)$orderInfo[$prefix . $k]) : '';
		};

		$street = $get('address_1');
		$number = $field(isset($map['number_field']) ? $map['number_field'] : '');
		if ($number === '') {
			list($street, $number) = Payer::splitStreetNumber($street);
		}
		$neighborhood = $field(isset($map['neighborhood_field']) ? $map['neighborhood_field'] : 'address_2');
		$complement = $field(isset($map['complement_field']) ? $map['complement_field'] : '');

		$name = trim($get('firstname') . ' ' . $get('lastname'));
		if ($name === '') {
			$name = trim((isset($orderInfo['firstname']) ? $orderInfo['firstname'] : '') . ' ' . (isset($orderInfo['lastname']) ? $orderInfo['lastname'] : ''));
		}
		$type = Payer::documentType($document);
		if ($type === 'cnpj' && $get('company') !== '') {
			$name = $get('company');
		}

		return array(
			'name' => $name,
			'email' => isset($orderInfo['email']) ? trim((string)$orderInfo['email']) : '',
			'phone' => Payer::phone(isset($orderInfo['telephone']) ? $orderInfo['telephone'] : ''),
			'document' => $document,
			'document_type' => $type,
			'street' => $street,
			'number' => $number !== '' ? $number : 'S/N',
			'complement' => $complement,
			'neighborhood' => $neighborhood,
			'city' => $get('city'),
			'state' => Payer::state($get('zone_code'), $get('zone')),
			'zip' => Payer::zip($get('postcode')),
		);
	}

	/** Campos que faltam para cada meio. Vazio = pode cobrar. */
	public static function missing(array $payer, $method) {
		$missing = array();
		if ($payer['document_type'] === '') {
			$missing[] = 'document';
		}
		if (trim($payer['name']) === '') {
			$missing[] = 'name';
		}
		if ($method === 'boleto' || $method === 'card') {
			foreach (array('zip', 'street', 'city', 'state') as $k) {
				if ($payer[$k] === '') {
					$missing[] = $k;
				}
			}
		}
		if ($method === 'card') {
			// customer.name: "pelo menos 3 caracteres"
			$len = function_exists('mb_strlen') ? mb_strlen(trim($payer['name'])) : strlen(trim($payer['name']));
			if ($len < 3 && !in_array('name', $missing, true)) {
				$missing[] = 'name';
			}
			if ($payer['phone'] === '') {
				$missing[] = 'phone';
			}
			if (!filter_var($payer['email'], FILTER_VALIDATE_EMAIL)) {
				$missing[] = 'email';
			}
		}
		return $missing;
	}

	public static function description($storeName, $orderId) {
		$d = trim($storeName) . ' - Pedido #' . (int)$orderId;
		return function_exists('mb_substr') ? mb_substr($d, 0, 255) : substr($d, 0, 255);
	}

	/** POST /v1/pix */
	public static function pixBody(array $payer, $amount, $orderId, $description, $expirationSeconds, $notificationUrl) {
		$body = array(
			'amount' => Payer::reais($amount),
			'description' => $description,
			'expiration' => max(60, (int)$expirationSeconds),
			'payer' => array('name' => $payer['name'], 'document' => $payer['document']),
			'customer_code' => 'oc-' . (int)$orderId,
			'metadata' => array(array('key' => 'order_id', 'value' => (string)(int)$orderId), array('key' => 'platform', 'value' => 'opencart')),
		);
		if ($notificationUrl !== '') {
			$body['notification_url'] = $notificationUrl;
		}
		return $body;
	}

	/** POST /v1/charges */
	public static function boletoBody(array $payer, $amount, $orderId, $description, $dueDate, $gracePeriod, $fine, $interest, $notificationUrl) {
		$body = array(
			'amount' => Payer::reais($amount),
			'description' => $description,
			'due_date' => $dueDate,
			'grace_period' => min(30, max(1, (int)$gracePeriod)),
			'payer' => array(
				'name' => $payer['name'],
				'document' => $payer['document'],
				'zip' => $payer['zip'],
				'street' => $payer['street'],
				'number' => $payer['number'],
				'neighborhood' => $payer['neighborhood'] !== '' ? $payer['neighborhood'] : 'Centro',
				'city' => $payer['city'],
				'state' => $payer['state'],
			),
			'customer_code' => 'oc-' . (int)$orderId,
			'metadata' => array(array('key' => 'order_id', 'value' => (string)(int)$orderId), array('key' => 'platform', 'value' => 'opencart')),
		);
		$fine = (float)$fine;
		$interest = (float)$interest;
		if ($fine > 0) {
			$body['fine'] = round(min(100, max(0.1, $fine)), 2);
		}
		if ($interest > 0) {
			$body['interest'] = round(min(100, max(0.1, $interest)), 2);
		}
		if ($notificationUrl !== '') {
			$body['notification_url'] = $notificationUrl;
		}
		return $body;
	}

	/** POST /v1/creditcard/charges (modo com dados completos) */
	public static function cardBody(array $payer, array $card, $amount, $installments, $description, $ip, $payday) {
		$address = array(
			'zip_code' => $payer['zip'],
			'street' => $payer['street'],
			'number' => $payer['number'],
			'neighborhood' => $payer['neighborhood'] !== '' ? $payer['neighborhood'] : 'Centro',
			'city' => $payer['city'],
			'state' => $payer['state'],
		);
		if ($payer['complement'] !== '') {
			$address['complement'] = $payer['complement'];
		}
		return array(
			'customer' => array(
				'document' => $payer['document'],
				'document_type' => $payer['document_type'],
				'name' => $payer['name'],
				'email' => $payer['email'],
				'phone' => $payer['phone'],
				'address' => $address,
			),
			'card' => array(
				'card_token' => (string)$card['card_token'],
				'holder' => html_entity_decode((string)$card['holder'], ENT_QUOTES, 'UTF-8'),
				'expires_at' => (string)$card['expires_at'],
			),
			'value' => Payer::cents($amount),
			'installments' => max(1, min(12, (int)$installments)),
			'payday' => $payday,
			'description' => $description,
			'antifraud' => array('ip' => (string)$ip),
		);
	}

	/** Valida o objeto card que veio do navegador (tokenização). */
	public static function validCard(array $card) {
		return isset($card['card_token'], $card['holder'], $card['expires_at'])
			&& is_string($card['card_token']) && strlen($card['card_token']) >= 64 && strlen($card['card_token']) <= 16384
			&& preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $card['card_token'])
			&& is_string($card['holder']) && strlen(trim($card['holder'])) >= 2
			&& is_string($card['expires_at']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $card['expires_at']);
	}

	/**
	 * Parcelas sem juros permitidas: até $max, respeitando o valor mínimo da parcela.
	 * @return int[] lista de quantidades de parcelas
	 */
	public static function installmentOptions($total, $max, $minInstallment) {
		return array_keys(self::installmentPlan($total, array('max' => $max, 'min_installment' => $minInstallment)));
	}

	/**
	 * Plano de parcelamento do cartão: para cada quantidade oferecida, o total cobrado e a parcela.
	 *
	 * Até `free` parcelas (null = todas) não há juros. Acima disso o comprador paga juros:
	 *  - mode "price": juros compostos de `rate`% ao mês (tabela Price), parcela arredondada ao centavo
	 *    e total = parcela x quantidade;
	 *  - mode "table": acréscimo de `table[n]`% sobre o valor; quantidade sem taxa na tabela não é oferecida.
	 * 1x é sempre à vista, sem juros. Parcelas abaixo de `min_installment` (já com juros) não são oferecidas.
	 * A API da Pagou recebe só o total e as parcelas: os juros ao comprador são regra da loja.
	 *
	 * @param float $total valor em BRL, sem juros
	 * @param array $cfg max, min_installment, free, mode, rate, table
	 * @return array<int, array{installments:int, total:float, installment:float, interest:float}> por quantidade
	 */
	public static function installmentPlan($total, array $cfg) {
		$max = max(1, min(12, isset($cfg['max']) ? (int)$cfg['max'] : 1));
		$min = isset($cfg['min_installment']) ? max(0, (float)$cfg['min_installment']) : 0;
		$free = isset($cfg['free']) && $cfg['free'] !== '' ? max(1, (int)$cfg['free']) : $max;
		$mode = isset($cfg['mode']) && $cfg['mode'] === 'table' ? 'table' : 'price';
		$rate = isset($cfg['rate']) ? max(0, min(100, (float)$cfg['rate'])) : 0;
		$table = isset($cfg['table']) && is_array($cfg['table']) ? $cfg['table'] : array();

		$base = (int)round((float)$total * 100);
		$plan = array();
		for ($n = 1; $n <= $max; $n++) {
			if ($n === 1 || $n <= $free) {
				$cents = $base;
			} elseif ($mode === 'price') {
				if ($rate <= 0) {
					$cents = $base;
				} else {
					$i = $rate / 100;
					$cents = (int)round($base * $i / (1 - pow(1 + $i, -$n))) * $n;
				}
			} else {
				if (!isset($table[$n])) {
					continue;
				}
				$cents = (int)round($base * (1 + max(0, (float)$table[$n]) / 100));
			}
			if ($n > 1 && $min > 0 && $cents / $n + 0.001 < $min * 100) {
				continue;
			}
			$plan[$n] = array(
				'installments' => $n,
				'total' => (float)($cents / 100),
				'installment' => (float)(round($cents / $n) / 100),
				'interest' => (float)(max(0, $cents - $base) / 100),
			);
		}
		return $plan;
	}

	/**
	 * Lê a tabela de acréscimo por parcela digitada no admin: "2=3,5; 3=4.9" ou uma por linha,
	 * com "%" opcional. Ignora o que não for de 2 a 12 parcelas ou de 0 a 100%.
	 * @return array<int, float> quantidade => acréscimo em %
	 */
	public static function parseRateTable($text) {
		$out = array();
		foreach (preg_split('/[;\r\n]+/', (string)$text) as $part) {
			if (preg_match('/^\s*(\d{1,2})\s*[x:=]\s*(\d{1,3}(?:[.,]\d+)?)\s*%?\s*$/i', $part, $m)) {
				$n = (int)$m[1];
				$pct = (float)str_replace(',', '.', $m[2]);
				if ($n >= 2 && $n <= 12 && $pct >= 0 && $pct <= 100) {
					$out[$n] = $pct;
				}
			}
		}
		ksort($out);
		return $out;
	}

	/** Tabela de acréscimo normalizada para gravar e reexibir ("2=3.5; 3=4.9"). */
	public static function formatRateTable(array $table) {
		$parts = array();
		foreach ($table as $n => $pct) {
			$parts[] = (int)$n . '=' . rtrim(rtrim(number_format((float)$pct, 4, '.', ''), '0'), '.');
		}
		return implode('; ', $parts);
	}

	/** IP real do comprador para o antifraude (cabeçalho de proxy só se o lojista confiar nele). */
	public static function clientIp(array $server, $trustedHeader = '') {
		$candidates = array();
		if ($trustedHeader !== '') {
			$key = 'HTTP_' . strtoupper(str_replace('-', '_', preg_replace('/^HTTP_/i', '', $trustedHeader)));
			if (!empty($server[$key])) {
				$candidates[] = trim(explode(',', (string)$server[$key])[0]);
			}
		}
		if (!empty($server['REMOTE_ADDR'])) {
			$candidates[] = (string)$server['REMOTE_ADDR'];
		}
		foreach ($candidates as $ip) {
			if (filter_var($ip, FILTER_VALIDATE_IP)) {
				return $ip;
			}
		}
		return '';
	}
}
