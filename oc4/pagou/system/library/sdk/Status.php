<?php
namespace Pagou\OpenCart;

/**
 * Normaliza os estados da Pagou num vocabulário único da integração:
 * pending | authorized | paid | canceled | expired | refunded | failed | chargeback | rejected | unknown
 */
class Status {
	const PENDING = 'pending';
	const AUTHORIZED = 'authorized';
	const PAID = 'paid';
	const CANCELED = 'canceled';
	const EXPIRED = 'expired';
	const REFUNDED = 'refunded';
	const FAILED = 'failed';
	const CHARGEBACK = 'chargeback';
	const REJECTED = 'rejected';
	const UNKNOWN = 'unknown';

	/** Pix: 1 inicial, 2 ativo, 3 cancelado, 4 pago, 5 estornado. */
	public static function fromPix($code) {
		switch ((int)$code) {
			case 1:
			case 2:
				return self::PENDING;
			case 3:
				return self::CANCELED;
			case 4:
				return self::PAID;
			case 5:
				return self::REFUNDED;
		}
		return self::UNKNOWN;
	}

	/** Boleto: 1/2 pendente, 3 cancelado, 4/8/9 pago, 5 estornado, 6 rejeitado, 7 removido (8 vai para conferência no Engine). */
	public static function fromBoleto($code) {
		switch ((int)$code) {
			case 1:
			case 2:
				return self::PENDING;
			case 3:
				return self::CANCELED;
			case 4:
			case 8:
			case 9:
				return self::PAID;
			case 5:
				return self::REFUNDED;
			case 6:
			case 7: // removido/outro: não é pagamento; encerra e a loja confere
				return self::REJECTED;
		}
		return self::UNKNOWN;
	}

	/** Cartão: strings da projeção pública. */
	public static function fromCard($status) {
		switch ((string)$status) {
			case 'pending':
				return self::PENDING;
			case 'authorized':
				return self::AUTHORIZED;
			case 'captured':
			case 'settled':
				return self::PAID;
			case 'canceled':
				return self::CANCELED;
			case 'failed':
				return self::FAILED;
			case 'reversed':
				return self::REFUNDED;
			case 'charged_back':
				return self::CHARGEBACK;
		}
		return self::UNKNOWN;
	}

	public static function fromApi($method, array $body) {
		$raw = isset($body['status']) ? $body['status'] : null;
		if ($method === 'card') {
			return self::fromCard($raw);
		}
		return $method === 'boleto' ? self::fromBoleto($raw) : self::fromPix($raw);
	}

	/** Estados finais: não voltam a ser consultados pelo cron. */
	public static function isFinal($status) {
		return in_array($status, array(self::PAID, self::CANCELED, self::EXPIRED, self::REFUNDED, self::FAILED, self::CHARGEBACK, self::REJECTED), true);
	}

	/**
	 * Um evento atrasado não pode fazer o pedido regredir.
	 * Ex.: pago -> pendente é ignorado; pago -> estornado é aceito.
	 */
	public static function canTransition($from, $to) {
		if ($from === $to) {
			return false;
		}
		$rank = array(
			self::UNKNOWN => 0, self::PENDING => 1, self::AUTHORIZED => 2,
			self::FAILED => 3, self::REJECTED => 3, self::EXPIRED => 3, self::CANCELED => 3,
			self::PAID => 4, self::REFUNDED => 5, self::CHARGEBACK => 5,
		);
		$a = isset($rank[$from]) ? $rank[$from] : 0;
		$b = isset($rank[$to]) ? $rank[$to] : 0;
		// Pagamento confirmado depois de expirar/cancelar localmente é real e precisa entrar.
		if ($to === self::PAID && in_array($from, array(self::EXPIRED, self::CANCELED, self::FAILED, self::UNKNOWN, self::PENDING, self::AUTHORIZED), true)) {
			return true;
		}
		return $b > $a;
	}
}
