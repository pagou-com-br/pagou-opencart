<?php
namespace Pagou\OpenCart;

/**
 * Regras de pagador brasileiro: CPF/CNPJ, telefone e endereço.
 * Tudo puro (sem OpenCart), para ser testável e igual nos pacotes 3.x e 4.x.
 */
class Payer {
	public static function digits($value) {
		return preg_replace('/\D+/', '', (string)$value);
	}

	public static function isCpf($value) {
		$c = self::digits($value);
		if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
			return false;
		}
		for ($t = 9; $t < 11; $t++) {
			$d = 0;
			for ($i = 0; $i < $t; $i++) {
				$d += (int)$c[$i] * (($t + 1) - $i);
			}
			$d = ((10 * $d) % 11) % 10;
			if ((int)$c[$t] !== $d) {
				return false;
			}
		}
		return true;
	}

	public static function isCnpj($value) {
		$c = self::digits($value);
		if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) {
			return false;
		}
		$weights = array(array(5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2), array(6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2));
		foreach ($weights as $n => $w) {
			$sum = 0;
			foreach ($w as $i => $weight) {
				$sum += (int)$c[$i] * $weight;
			}
			$r = $sum % 11;
			$dv = $r < 2 ? 0 : 11 - $r;
			if ((int)$c[12 + $n] !== $dv) {
				return false;
			}
		}
		return true;
	}

	/** @return string 'cpf' | 'cnpj' | '' (inválido) */
	public static function documentType($value) {
		if (self::isCpf($value)) {
			return 'cpf';
		}
		if (self::isCnpj($value)) {
			return 'cnpj';
		}
		return '';
	}

	/** Telefone para o cartão: 10 a 14 dígitos. Remove o DDI 55 quando sobra um número nacional válido. */
	public static function phone($value) {
		$d = self::digits($value);
		if (strlen($d) > 11 && substr($d, 0, 2) === '55' && strlen($d) - 2 >= 10) {
			$d = substr($d, 2);
		}
		$d = ltrim($d, '0');
		return (strlen($d) >= 10 && strlen($d) <= 14) ? $d : '';
	}

	public static function zip($value) {
		$d = self::digits($value);
		return strlen($d) === 8 ? $d : '';
	}

	/**
	 * Separa "Rua das Flores, 123" em logradouro + número quando a loja não tem campo de número.
	 * @return array{0:string,1:string}
	 */
	public static function splitStreetNumber($street) {
		$street = trim((string)$street);
		if (preg_match('/^(.*?)[,\s]+(?:n[ºo°.]*\s*)?(\d+[A-Za-z]?|s\/?n)\s*$/iu', $street, $m) && trim($m[1]) !== '') {
			return array(trim($m[1], " ,"), strtoupper($m[2]) === 'SN' || strtoupper($m[2]) === 'S/N' ? 'S/N' : $m[2]);
		}
		return array($street, '');
	}

	/** UF a partir do código/nome da zona do OpenCart. */
	public static function state($zoneCode, $zoneName = '') {
		$code = strtoupper(trim((string)$zoneCode));
		if (preg_match('/^[A-Z]{2}$/', $code)) {
			return $code;
		}
		static $names = array(
			'ACRE' => 'AC', 'ALAGOAS' => 'AL', 'AMAPA' => 'AP', 'AMAZONAS' => 'AM', 'BAHIA' => 'BA', 'CEARA' => 'CE',
			'DISTRITO FEDERAL' => 'DF', 'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO', 'MARANHAO' => 'MA', 'MATO GROSSO' => 'MT',
			'MATO GROSSO DO SUL' => 'MS', 'MINAS GERAIS' => 'MG', 'PARA' => 'PA', 'PARAIBA' => 'PB', 'PARANA' => 'PR',
			'PERNAMBUCO' => 'PE', 'PIAUI' => 'PI', 'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN',
			'RIO GRANDE DO SUL' => 'RS', 'RONDONIA' => 'RO', 'RORAIMA' => 'RR', 'SANTA CATARINA' => 'SC',
			'SAO PAULO' => 'SP', 'SERGIPE' => 'SE', 'TOCANTINS' => 'TO',
		);
		$name = strtoupper(self::ascii($zoneName));
		return isset($names[$name]) ? $names[$name] : '';
	}

	public static function ascii($s) {
		// Mapa fixo: iconv//TRANSLIT varia entre glibc, musl e Windows.
		$s = strtr((string)$s, array(
			'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
			'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
			'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
			'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
			'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
		));
		return preg_replace('/[^\x20-\x7E]/', '', $s);
	}

	/** Valor em reais com no máximo 2 casas (Pix/boleto). */
	public static function reais($amount) {
		return round((float)$amount, 2);
	}

	/** Valor em centavos inteiros (cartão). */
	public static function cents($amount) {
		return (int)round(((float)$amount) * 100);
	}
}
