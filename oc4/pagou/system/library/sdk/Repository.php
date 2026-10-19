<?php
namespace Pagou\OpenCart;

/**
 * Persistência das cobranças e dos eventos de webhook.
 * Usa o objeto $db do OpenCart (mesma interface no 3.x e no 4.x: query/escape/getLastId).
 */
class Repository {
	private $db;
	private $prefix;

	public function __construct($db, $prefix) {
		$this->db = $db;
		$this->prefix = $prefix;
	}

	private function t($name) {
		return '`' . $this->prefix . $name . '`';
	}

	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS " . $this->t('pagou_transaction') . " (
			`pagou_transaction_id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`store_id` INT(11) NOT NULL DEFAULT 0,
			`method` VARCHAR(10) NOT NULL,
			`environment` VARCHAR(10) NOT NULL,
			`pagou_id` VARCHAR(64) NULL DEFAULT NULL,
			`idempotency_key` VARCHAR(64) NULL DEFAULT NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'pending',
			`amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
			`installments` TINYINT(2) NOT NULL DEFAULT 1,
			`data` MEDIUMTEXT NULL,
			`expires_at` DATETIME NULL DEFAULT NULL,
			`checked_at` DATETIME NULL DEFAULT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`pagou_transaction_id`),
			KEY `order_id` (`order_id`),
			KEY `pagou_id` (`pagou_id`),
			KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$this->db->query("CREATE TABLE IF NOT EXISTS " . $this->t('pagou_event') . " (
			`event_key` CHAR(64) NOT NULL,
			`name` VARCHAR(40) NOT NULL,
			`pagou_id` VARCHAR(64) NOT NULL,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`event_key`),
			KEY `pagou_id` (`pagou_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	public function create(array $row) {
		$data = isset($row['data']) ? $row['data'] : array();
		$this->db->query("INSERT INTO " . $this->t('pagou_transaction') . " SET
			`order_id` = '" . (int)$row['order_id'] . "',
			`store_id` = '" . (int)(isset($row['store_id']) ? $row['store_id'] : 0) . "',
			`method` = '" . $this->db->escape($row['method']) . "',
			`environment` = '" . $this->db->escape($row['environment']) . "',
			`pagou_id` = " . (empty($row['pagou_id']) ? 'NULL' : "'" . $this->db->escape($row['pagou_id']) . "'") . ",
			`idempotency_key` = " . (empty($row['idempotency_key']) ? 'NULL' : "'" . $this->db->escape($row['idempotency_key']) . "'") . ",
			`status` = '" . $this->db->escape(isset($row['status']) ? $row['status'] : Status::PENDING) . "',
			`amount` = '" . (float)$row['amount'] . "',
			`installments` = '" . (int)(isset($row['installments']) ? $row['installments'] : 1) . "',
			`data` = '" . $this->db->escape(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "',
			`expires_at` = " . (empty($row['expires_at']) ? 'NULL' : "'" . $this->db->escape($row['expires_at']) . "'") . ",
			`date_added` = UTC_TIMESTAMP(), `date_modified` = UTC_TIMESTAMP()");
		return $this->find((int)$this->db->getLastId());
	}

	public function update($id, array $fields) {
		$sets = array();
		foreach ($fields as $k => $v) {
			if ($k === 'data') {
				$v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
			if (!in_array($k, array('pagou_id', 'status', 'data', 'expires_at', 'checked_at', 'idempotency_key'), true)) {
				continue;
			}
			$sets[] = "`" . $k . "` = " . ($v === null ? 'NULL' : "'" . $this->db->escape((string)$v) . "'");
		}
		if (!$sets) {
			return;
		}
		$sets[] = "`date_modified` = UTC_TIMESTAMP()";
		$this->db->query("UPDATE " . $this->t('pagou_transaction') . " SET " . implode(', ', $sets) . " WHERE `pagou_transaction_id` = '" . (int)$id . "'");
	}

	/**
	 * Troca de status atômica: só grava se o status ainda for $from.
	 * Webhook, polling da página e cron podem ver a mesma mudança ao mesmo tempo; só um "ganha".
	 * @return bool true se esta chamada fez a troca
	 */
	public function claimStatus($id, $from, $to) {
		$this->db->query("UPDATE " . $this->t('pagou_transaction') . " SET `status` = '" . $this->db->escape($to) . "', `date_modified` = UTC_TIMESTAMP() WHERE `pagou_transaction_id` = '" . (int)$id . "' AND `status` = '" . $this->db->escape($from) . "'");
		return $this->db->countAffected() > 0;
	}

	public function touchChecked($id) {
		$this->db->query("UPDATE " . $this->t('pagou_transaction') . " SET `checked_at` = UTC_TIMESTAMP() WHERE `pagou_transaction_id` = '" . (int)$id . "'");
	}

	public function find($id) {
		return $this->hydrate($this->db->query("SELECT * FROM " . $this->t('pagou_transaction') . " WHERE `pagou_transaction_id` = '" . (int)$id . "'")->row);
	}

	public function findByPagouId($pagouId) {
		return $this->hydrate($this->db->query("SELECT * FROM " . $this->t('pagou_transaction') . " WHERE `pagou_id` = '" . $this->db->escape($pagouId) . "' ORDER BY `pagou_transaction_id` DESC LIMIT 1")->row);
	}

	/** Boleto com Pix vinculado: o webhook do Pix chega com o ID do QR Code, não o do boleto. */
	public function findByQrcodeId($qrcodeId) {
		$needle = '"qrcode_id":' . json_encode((string)$qrcodeId);
		return $this->hydrate($this->db->query("SELECT * FROM " . $this->t('pagou_transaction') . " WHERE `method` = 'boleto' AND `data` LIKE '%" . $this->db->escape(addcslashes($needle, '%_\\')) . "%' ORDER BY `pagou_transaction_id` DESC LIMIT 1")->row);
	}

	/** Última cobrança do pedido (opcionalmente de um meio). */
	public function latestForOrder($orderId, $method = '') {
		$sql = "SELECT * FROM " . $this->t('pagou_transaction') . " WHERE `order_id` = '" . (int)$orderId . "'";
		if ($method !== '') {
			$sql .= " AND `method` = '" . $this->db->escape($method) . "'";
		}
		return $this->hydrate($this->db->query($sql . " ORDER BY `pagou_transaction_id` DESC LIMIT 1")->row);
	}

	public function allForOrder($orderId) {
		$rows = $this->db->query("SELECT * FROM " . $this->t('pagou_transaction') . " WHERE `order_id` = '" . (int)$orderId . "' ORDER BY `pagou_transaction_id` DESC")->rows;
		return array_map(array($this, 'hydrate'), $rows);
	}

	/** Cobranças em aberto para a conciliação, as menos consultadas primeiro. */
	public function openForReconcile($limit, $maxAgeDays) {
		$rows = $this->db->query("SELECT * FROM " . $this->t('pagou_transaction') . "
			WHERE (`status` IN ('pending','authorized','unknown') AND `pagou_id` IS NOT NULL OR `status` = 'unknown')
			AND `date_added` >= UTC_TIMESTAMP() - INTERVAL " . (int)$maxAgeDays . " DAY
			ORDER BY COALESCE(`checked_at`, '1970-01-01') ASC LIMIT " . (int)$limit)->rows;
		return array_map(array($this, 'hydrate'), $rows);
	}

	/** @return bool true se o evento é novo (gravado agora), false se já tinha sido processado */
	public function registerEvent($key, $name, $pagouId) {
		$this->db->query("INSERT IGNORE INTO " . $this->t('pagou_event') . " SET `event_key` = '" . $this->db->escape($key) . "', `name` = '" . $this->db->escape(substr($name, 0, 40)) . "', `pagou_id` = '" . $this->db->escape(substr($pagouId, 0, 64)) . "', `date_added` = UTC_TIMESTAMP()");
		return $this->db->countAffected() > 0;
	}

	public function forgetEvent($key) {
		$this->db->query("DELETE FROM " . $this->t('pagou_event') . " WHERE `event_key` = '" . $this->db->escape($key) . "'");
	}

	private function hydrate($row) {
		if (!$row) {
			return null;
		}
		$row['data'] = $row['data'] ? json_decode($row['data'], true) : array();
		if (!is_array($row['data'])) {
			$row['data'] = array();
		}
		return $row;
	}
}
