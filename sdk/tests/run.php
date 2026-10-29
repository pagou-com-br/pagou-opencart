<?php
// Testes do SDK sem dependências: php sdk/tests/run.php
require __DIR__ . '/../src/autoload.php';

use Pagou\OpenCart\Engine;
use Pagou\OpenCart\Order;
use Pagou\OpenCart\Payer;
use Pagou\OpenCart\Repository;
use Pagou\OpenCart\Status;
use Pagou\OpenCart\Webhook;

$fails = 0;
$count = 0;
function ok($cond, $name) {
	global $fails, $count;
	$count++;
	if (!$cond) {
		$fails++;
		echo "FALHOU: $name\n";
	}
}

// ---- Payer ----------------------------------------------------------------
ok(Payer::isCpf('529.982.247-25'), 'CPF válido com máscara');
ok(!Payer::isCpf('111.111.111-11'), 'CPF repetido inválido');
ok(!Payer::isCpf('52998224724'), 'CPF DV errado');
ok(Payer::isCnpj('11.222.333/0001-81'), 'CNPJ válido');
ok(!Payer::isCnpj('11.222.333/0001-80'), 'CNPJ DV errado');
ok(Payer::documentType('11222333000181') === 'cnpj', 'tipo cnpj');
ok(Payer::documentType('123') === '', 'tipo inválido');
ok(Payer::phone('+55 (46) 99999-8888') === '46999998888', 'telefone com DDI');
ok(Payer::phone('(46) 3222-1111') === '4632221111', 'telefone fixo');
ok(Payer::phone('9999') === '', 'telefone curto');
ok(Payer::zip('85.501-000') === '85501000', 'CEP');
ok(Payer::splitStreetNumber('Rua das Flores, 123') === array('Rua das Flores', '123'), 'rua, número');
ok(Payer::splitStreetNumber('Av. Brasil nº 45A') === array('Av. Brasil', '45A'), 'rua nº');
ok(Payer::splitStreetNumber('Rua Sem Numero') === array('Rua Sem Numero', ''), 'rua sem número');
ok(Payer::state('PR') === 'PR', 'UF por código');
ok(Payer::state('', 'São Paulo') === 'SP', 'UF por nome acentuado');
ok(Payer::cents(50.005) === 5001 || Payer::cents(50.005) === 5000, 'centavos arredondados');
ok(Payer::cents(19.9) === 1990, 'centavos 19,90');

// ---- Webhook --------------------------------------------------------------
$key = 'chave-teste';
$body = '{"name":"qrcode.completed","data":{"id":"abc","transaction_id":"t1","amount":10.5}}';
$ts = (string)time();
$sig = Webhook::sign($body, $ts, $key);
ok(Webhook::verify($body, $ts, $sig, $key), 'assinatura válida');
ok(!Webhook::verify($body . ' ', $ts, $sig, $key), 'corpo alterado');
ok(!Webhook::verify($body, $ts, $sig, 'outra'), 'chave errada');
ok(!Webhook::verify($body, (string)(time() - 301), Webhook::sign($body, (string)(time() - 301), $key), $key), 'timestamp vencido');
ok(Webhook::verify($body, $ts, strtoupper($sig), $key), 'assinatura maiúscula');
ok(Webhook::parse('{"event_name":"x","data":{}}') === null, 'envelope sem name');
$ev = Webhook::parse($body);
ok($ev['name'] === 'qrcode.completed' && $ev['id'] === 'abc', 'parse');
ok(Webhook::dedupKey($ev) !== Webhook::dedupKey(array('name' => 'qrcode.refunded') + $ev), 'dedup separa eventos');

// ---- Status ---------------------------------------------------------------
ok(Status::fromPix(4) === 'paid' && Status::fromPix(2) === 'pending', 'pix status');
ok(Status::fromBoleto(9) === 'paid' && Status::fromBoleto(6) === 'rejected', 'boleto status');
ok(Status::fromCard('captured') === 'paid' && Status::fromCard('reversed') === 'refunded', 'cartão status');
ok(!Status::canTransition('paid', 'pending'), 'não regride pago');
ok(Status::canTransition('paid', 'refunded'), 'pago -> estornado');
ok(Status::canTransition('expired', 'paid'), 'expirado -> pago');
ok(!Status::canTransition('refunded', 'paid'), 'estornado não volta a pago');

// ---- Order ----------------------------------------------------------------
$orderInfo = array(
	'order_id' => 77, 'store_id' => 0, 'store_name' => 'Loja Teste', 'total' => '150.4000',
	'firstname' => 'Ana', 'lastname' => 'Silva', 'email' => 'ana@example.com', 'telephone' => '(46) 99999-0000',
	'custom_field' => array('2' => '529.982.247-25'),
	'payment_firstname' => 'Ana', 'payment_lastname' => 'Silva', 'payment_company' => '',
	'payment_address_1' => 'Rua A', 'payment_address_2' => 'Centro', 'payment_city' => 'Pato Branco',
	'payment_postcode' => '85501-000', 'payment_zone' => 'Paraná', 'payment_zone_code' => 'PR',
	'payment_custom_field' => array('1' => '100', '3' => 'Sala 2'),
);
$map = array('document_field' => '2', 'number_field' => '1', 'complement_field' => '3', 'neighborhood_field' => 'address_2');
$payer = Order::payer($orderInfo, $map);
ok($payer['document'] === '52998224725' && $payer['document_type'] === 'cpf', 'documento do custom field');
ok($payer['number'] === '100' && $payer['complement'] === 'Sala 2' && $payer['neighborhood'] === 'Centro', 'endereço');
ok($payer['state'] === 'PR' && $payer['zip'] === '85501000', 'UF/CEP');
ok(Order::missing($payer, 'card') === array(), 'nada faltando p/ cartão');
$semDoc = Order::payer(array('custom_field' => '') + $orderInfo, array('document_field' => '') + $map);
ok(in_array('document', Order::missing($semDoc, 'pix'), true), 'sem CPF');
$override = Order::payer($orderInfo, array('document_field' => '') + $map, '11.222.333/0001-81');
ok($override['document_type'] === 'cnpj', 'CPF/CNPJ digitado no checkout');
$pix = Order::pixBody($payer, 150.4, 77, 'Desc', 3600, 'https://x/wh');
ok($pix['amount'] === 150.4 && $pix['payer']['document'] === '52998224725' && $pix['notification_url'] === 'https://x/wh', 'corpo pix');
ok(!array_key_exists('notification_url', Order::pixBody($payer, 1, 1, 'd', 10, '')) && Order::pixBody($payer, 1, 1, 'd', 10, '')['expiration'] === 60, 'pix mínimo 60s, sem url');
$bol = Order::boletoBody($payer, 150.4, 77, 'Desc', '2026-10-20', 0, 2, 0, '');
ok($bol['grace_period'] === 1 && $bol['fine'] === 2.0 && !isset($bol['interest']) && $bol['payer']['zip'] === '85501000', 'corpo boleto');
$card = array('card_token' => str_repeat('A', 80), 'holder' => 'ANA SILVA', 'expires_at' => '2030-12');
ok(Order::validCard($card), 'card válido');
ok(!Order::validCard(array('expires_at' => '2030-13') + $card), 'mês inválido');
$cb = Order::cardBody($payer, $card, 150.4, 3, 'Desc', '203.0.113.10', '2026-10-14');
ok($cb['value'] === 15040 && $cb['installments'] === 3 && $cb['antifraud']['ip'] === '203.0.113.10' && $cb['customer']['address']['complement'] === 'Sala 2', 'corpo cartão');
ok(Order::installmentOptions(100, 12, 30) === array(1, 2, 3), 'parcelas com mínimo');
ok(Order::installmentOptions(100, 0, 0) === array(1), 'máximo inválido vira 1x');
ok(Order::installmentOptions(60, 12, 0) === range(1, 12), 'sem mínimo oferece até o máximo');

// ---- Juros do parcelamento ------------------------------------------------
$pl = Order::installmentPlan(1000, array('max' => 12));
ok(count($pl) === 12 && $pl[12]['total'] === 1000.0 && $pl[12]['interest'] === 0.0, 'sem configuração de juros: tudo sem juros (compatível com 1.0)');
$price = array('max' => 12, 'free' => 3, 'mode' => 'price', 'rate' => 2.99);
$pl = Order::installmentPlan(1000, $price);
ok($pl[1]['total'] === 1000.0 && $pl[3]['total'] === 1000.0 && $pl[3]['interest'] === 0.0, 'Price: até 3x sem juros');
ok($pl[12]['installment'] === 100.4 && $pl[12]['total'] === 1204.8, 'Price 2,99% 12x: parcela 100,40, total 1.204,80');
ok($pl[6]['installment'] === 184.54 && abs($pl[6]['total'] - 1107.24) < 0.001 && abs($pl[6]['interest'] - 107.24) < 0.001, 'Price 2,99% 6x');
ok(abs($pl[4]['total'] - round($pl[4]['installment'] * 4, 2)) < 0.001, 'Price: total = parcela x quantidade');
$pl = Order::installmentPlan(651, array('max' => 3, 'free' => 1, 'mode' => 'price', 'rate' => '1.99'));
ok($pl[3]['installment'] === 225.69 && abs($pl[3]['total'] - 677.07) < 0.001, 'Price 1,99% 3x');
$pl = Order::installmentPlan(1000, array('max' => 12, 'free' => 1, 'mode' => 'price', 'rate' => '3.49'));
ok($pl[1]['total'] === 1000.0 && $pl[2]['installment'] === 526.32 && $pl[6]['installment'] === 187.61 && abs($pl[12]['total'] - 1241.04) < 0.001, 'Price 3,49% (sugestão Pagou): 2x 526,32, 6x 187,61, 12x total 1.241,04');
$pl = Order::installmentPlan(1000, array('max' => 12, 'free' => 2, 'mode' => 'price', 'rate' => 0));
ok($pl[12]['total'] === 1000.0, 'Price com taxa zero: sem juros');
$pl = Order::installmentPlan(1000, array('max' => 12, 'free' => 1, 'mode' => 'price', 'rate' => 500));
ok(isset($pl[2]) && $pl[2]['total'] > 1000 && $pl[2]['total'] < 4000, 'taxa absurda limitada a 100% a.m.');
$table = Order::parseRateTable("2=3,5; 3 = 4.9%\n4:6\n13=9; 1=2; x=1; 5=150; 6=0");
ok($table === array(2 => 3.5, 3 => 4.9, 4 => 6.0, 6 => 0.0), 'tabela: vírgula, %, ":" e linhas; descarta 1x, 13x, >100% e lixo');
ok(Order::formatRateTable($table) === '2=3.5; 3=4.9; 4=6; 6=0', 'tabela normalizada');
ok(Order::parseRateTable(Order::formatRateTable($table)) === $table, 'tabela ida e volta');
$pl = Order::installmentPlan(1000, array('max' => 6, 'free' => 1, 'mode' => 'table', 'table' => $table));
ok(array_keys($pl) === array(1, 2, 3, 4, 6), 'tabela: quantidade sem taxa não é oferecida');
ok($pl[2]['total'] === 1035.0 && $pl[3]['total'] === 1049.0 && $pl[6]['total'] === 1000.0, 'tabela: acréscimo sobre o total');
ok($pl[3]['installment'] === 349.67, 'tabela: parcela arredondada ao centavo');
$pl = Order::installmentPlan(333.33, array('max' => 2, 'free' => 1, 'mode' => 'table', 'table' => array(2 => 4.75)));
ok($pl[2]['total'] === 349.16 && abs($pl[2]['interest'] - 15.83) < 0.001, 'tabela: arredondamento do total');
$pl = Order::installmentPlan(100, array('max' => 12, 'free' => 1, 'mode' => 'price', 'rate' => 5, 'min_installment' => 50));
ok(array_keys($pl) === array(1, 2) && $pl[2]['installment'] === 53.78, 'mínimo da parcela considera a parcela com juros');
$pl = Order::installmentPlan(30, array('max' => 12, 'free' => 12, 'min_installment' => 50));
ok(array_keys($pl) === array(1), '1x sempre oferecida, mesmo abaixo do mínimo');
$pl = Order::installmentPlan(1000, array('max' => 6, 'free' => '', 'mode' => 'price', 'rate' => 3));
ok($pl[6]['interest'] === 0.0, 'sem-juros vazio = todas sem juros');
$pl = Order::installmentPlan(1000, array('max' => 6, 'free' => 20, 'mode' => 'price', 'rate' => 3));
ok($pl[6]['interest'] === 0.0, 'sem-juros acima do máximo = todas sem juros');
$pl = Order::installmentPlan(0.1, array('max' => 3, 'free' => 1, 'mode' => 'price', 'rate' => 2));
ok(isset($pl[1]) && $pl[1]['total'] === 0.1, 'valor pequeno não quebra');
$cb = Order::cardBody($payer, $card, Order::installmentPlan(1000, $price)[12]['total'], 12, 'Desc', '203.0.113.10', '2026-10-14');
ok($cb['value'] === 120480 && $cb['installments'] === 12, 'cobrança vai com o total com juros, em centavos');
ok(Order::clientIp(array('REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'), 'CF-Connecting-IP') === '198.51.100.7', 'IP via Cloudflare');
ok(Order::clientIp(array('REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'), '') === '10.0.0.1', 'IP sem confiar no proxy');

// ---- Engine: webhook ponta a ponta com repositório em memória ------------
class MemRepo extends Repository {
	public $rows = array();
	public $events = array();
	public function __construct() {}
	public function create(array $row) { $id = count($this->rows) + 1; $this->rows[$id] = $row + array('pagou_transaction_id' => $id, 'data' => array(), 'status' => 'pending', 'installments' => 1, 'expires_at' => null, 'idempotency_key' => null, 'pagou_id' => null, 'checked_at' => null, 'date_added' => gmdate('Y-m-d H:i:s')); return $this->rows[$id]; }
	public function findByQrcodeId($q) { foreach (array_reverse($this->rows) as $r) if ($r['method'] === 'boleto' && isset($r['data']['qrcode_id']) && $r['data']['qrcode_id'] === $q) return $r; return null; }
	public function openForReconcile($limit, $days) { $out = array(); foreach ($this->rows as $r) if (in_array($r['status'], array('pending', 'authorized', 'unknown'), true)) $out[] = $r; return array_slice($out, 0, $limit); }
	public function allForOrder($o) { return array_values(array_filter($this->rows, function ($r) use ($o) { return $r['order_id'] == $o; })); }
	public function update($id, array $f) { foreach ($f as $k => $v) $this->rows[$id][$k] = $v; }
	public function claimStatus($id, $from, $to) { if ($this->rows[$id]['status'] !== $from) return false; $this->rows[$id]['status'] = $to; return true; }
	public function touchChecked($id) {}
	public function find($id) { return isset($this->rows[$id]) ? $this->rows[$id] : null; }
	public function findByPagouId($p) { foreach (array_reverse($this->rows) as $r) if ($r['pagou_id'] === $p) return $r; return null; }
	public function latestForOrder($o, $m = '') { foreach (array_reverse($this->rows) as $r) if ($r['order_id'] == $o && ($m === '' || $r['method'] === $m)) return $r; return null; }
	public function registerEvent($k, $n, $p) { if (isset($this->events[$k])) return false; $this->events[$k] = 1; return true; }
	public function forgetEvent($k) { unset($this->events[$k]); }
}
$repo = new MemRepo();
$history = array();
$engine = new Engine($repo, array('environment' => 'sandbox', 'api_key_sandbox' => $key), function ($o, $s, $c, $n) use (&$history) { $history[] = array($o, $s); });
$repo->create(array('order_id' => 77, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'abc', 'amount' => 10.5));

$server = array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => $sig);
ok($engine->handleWebhook($body, $server) === array(200, 'ok'), 'webhook pago');
ok($history === array(array(77, 'paid')), 'pedido marcado pago');
ok($engine->handleWebhook($body, $server) === array(200, 'duplicate'), 'reenvio não reaplica');
ok(count($history) === 1, 'sem histórico duplicado');
ok($engine->handleWebhook($body, array('HTTP_X_PAGOU_SIGNATURE' => str_repeat('0', 64)) + $server)[0] === 401, 'assinatura inválida 401');

// valor pago menor que a cobrança não libera
$repo->create(array('order_id' => 78, 'method' => 'boleto', 'environment' => 'sandbox', 'pagou_id' => 'bol1', 'amount' => 100));
$b2 = '{"name":"charge.paid","data":{"id":"bol1","transaction_id":"p1","amount":{"paid":50,"original":100},"paid_in":"boleto"}}';
$engine->handleWebhook($b2, array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => Webhook::sign($b2, $ts, $key)));
ok(end($history) === array(78, 'comment') && $repo->findByPagouId('bol1')['status'] === 'pending', 'pagamento a menor não libera');

// charge.created grava linha digitável
$b3 = '{"name":"charge.created","data":{"id":"bol1","transaction_id":"c1","payload":{"line":"34191.79001","bar_code":"3419"}}}';
$engine->handleWebhook($b3, array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => Webhook::sign($b3, $ts, $key)));
ok($repo->findByPagouId('bol1')['data']['boleto_line'] === '34191.79001', 'linha digitável salva');

// estorno parcial vira comentário; integral vira estornado
$b4 = '{"name":"qrcode.refunded","data":{"id":"abc","transaction_id":"r1","amount":5}}';
$engine->handleWebhook($b4, array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => Webhook::sign($b4, $ts, $key)));
ok($repo->findByPagouId('abc')['status'] === 'paid', 'estorno parcial mantém pago');
$b5 = '{"name":"qrcode.refunded","data":{"id":"abc","transaction_id":"r2","amount":10.5}}';
$engine->handleWebhook($b5, array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => Webhook::sign($b5, $ts, $key)));
ok($repo->findByPagouId('abc')['status'] === 'refunded' && end($history) === array(77, 'refunded'), 'estorno integral');

// cobrança desconhecida com assinatura válida: 200 ignorado (não gera reenvio infinito)
$b6 = '{"name":"qrcode.completed","data":{"id":"zzz","transaction_id":"t9","amount":1}}';
ok($engine->handleWebhook($b6, array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => Webhook::sign($b6, $ts, $key))) === array(200, 'ignored'), 'desconhecida ignorada');

// ---- Engine: criação, recuperação e conciliação com cliente falso ---------
class FakeClient extends \Pagou\OpenCart\Client {
	public $calls = array();
	public $handler;
	public function __construct($handler) { parent::__construct('k', false); $this->handler = $handler; }
	public function request($method, $path, $body = null, array $extraHeaders = array(), $timeout = null) {
		$this->calls[] = $method . ' ' . strtok($path, '?');
		return call_user_func($this->handler, $method, $path, $body);
	}
}
$newEngine = function (&$history) {
	$repo = new MemRepo();
	$e = new Engine($repo, array('environment' => 'sandbox', 'api_key_sandbox' => 'k', 'pix_expiration' => 30), function ($o, $s, $c, $n) use (&$history) { $history[] = array($o, $s); });
	return array($e, $repo);
};
$order = array('order_id' => 90, 'store_id' => 0, 'store_name' => 'Loja', 'total' => 25.5);
$pixOk = array('id' => 'pix-1', 'payload' => array('payload_id' => 1, 'data' => 'COPIA', 'image' => 'IMG'));

// Pix criado normalmente
$h = array();
list($e, $repo) = $newEngine($h);
$fake = new FakeClient(function ($m, $p) use ($pixOk) { return $pixOk; });
$e->setClient('sandbox', $fake);
$t = $e->createPix($order, $payer, 'https://x/wh');
ok($t['pagou_id'] === 'pix-1' && $t['status'] === 'pending' && $t['data']['pix_code'] === 'COPIA' && $t['expires_at'], 'pix criado');
ok($e->createPix($order, $payer, 'https://x/wh')['pagou_transaction_id'] === $t['pagou_transaction_id'] && count($fake->calls) === 1, 'segundo clique reaproveita o Pix');

// Timeout na criação: fica unknown; nova tentativa dentro da janela procura e pede para aguardar
$h = array();
list($e, $repo) = $newEngine($h);
$mode = 'timeout';
$fake = new FakeClient(function ($m, $p) use (&$mode, $pixOk) {
	if ($m === 'POST' && $mode === 'timeout') throw new \Pagou\OpenCart\TransportException('timeout');
	if ($m === 'GET' && strpos($p, '/v1/pix?') === 0) return $mode === 'found' ? array(array('id' => 'pix-9', 'customer_code' => 'oc-90', 'amount' => 25.5, 'expiration' => 1800, 'status' => 2)) : array();
	if ($m === 'GET' && $p === '/v1/pix/pix-9') return array('id' => 'pix-9', 'status' => 2, 'amount' => 25.5, 'payload' => array('id' => 1, 'data' => 'COPIA9', 'image' => 'IMG9'));
	return $pixOk;
});
$e->setClient('sandbox', $fake);
$threw = false;
try { $e->createPix($order, $payer, ''); } catch (\Pagou\OpenCart\TransportException $x) { $threw = true; }
ok($threw && $repo->rows[1]['status'] === 'unknown' && $repo->rows[1]['pagou_id'] === null, 'timeout deixa tentativa unknown');
$mode = 'missing';
$threw = false;
try { $e->createPix($order, $payer, ''); } catch (\Pagou\OpenCart\TransportException $x) { $threw = true; }
ok($threw && count($repo->rows) === 1, 'dentro da janela: não cria outro Pix');
$mode = 'found';
$t = $e->createPix($order, $payer, '');
ok($t['pagou_id'] === 'pix-9' && $t['data']['pix_code'] === 'COPIA9' && count($repo->rows) === 1, 'recupera o Pix criado no timeout');
ok(!in_array('POST /v1/pix', array_slice($fake->calls, 1), true), 'recuperação não cria cobrança nova');

// Tentativa antiga sem cobrança na Pagou: encerra e cria uma nova
$h = array();
list($e, $repo) = $newEngine($h);
$fake = new FakeClient(function ($m, $p) use ($pixOk) { return $m === 'GET' ? array() : $pixOk; });
$e->setClient('sandbox', $fake);
$repo->create(array('order_id' => 90, 'method' => 'pix', 'environment' => 'sandbox', 'status' => 'unknown', 'amount' => 25.5, 'date_added' => gmdate('Y-m-d H:i:s', time() - 600)));
$t = $e->createPix($order, $payer, '');
ok($repo->rows[1]['status'] === 'failed' && $t['pagou_id'] === 'pix-1', 'tentativa antiga encerrada, Pix novo criado');

// Erro de validação (400) não fica pendurado
$h = array();
list($e, $repo) = $newEngine($h);
$e->setClient('sandbox', new FakeClient(function () { throw new \Pagou\OpenCart\ApiException('validation error', 400); }));
try { $e->createPix($order, $payer, ''); } catch (\Pagou\OpenCart\ApiException $x) {}
ok($repo->rows[1]['status'] === 'failed', '400 marca failed');

// Cartão recusado (422) e aprovado
$h = array();
list($e, $repo) = $newEngine($h);
$e->setClient('sandbox', new FakeClient(function () { throw new \Pagou\OpenCart\ApiException('lojista não possui credenciamento', 422, array('code' => 'merchant_not_accredited')); }));
try { $e->createCard($order, $payer, $card, 1, '1.2.3.4'); } catch (\Pagou\OpenCart\ApiException $x) {}
ok($repo->rows[1]['status'] === 'failed' && $repo->rows[1]['data']['code'] === 'merchant_not_accredited', 'cartão 422 = failed');
$cardFake = new FakeClient(function ($m, $p, $b) { return array('id' => 'cc-1', 'status' => 'captured', 'value' => $b['value']); });
$e->setClient('sandbox', $cardFake);
$t = $e->createCard($order, $payer, $card, 2, '1.2.3.4');
ok($t['status'] === 'paid' && $t['pagou_id'] === 'cc-1' && $t['installments'] == 2, 'cartão capturado');

// Conciliação: 429 interrompe a rodada
$h = array();
list($e, $repo) = $newEngine($h);
foreach (array('a', 'b', 'c') as $id) $repo->create(array('order_id' => 1, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => $id, 'amount' => 1));
$f429 = new FakeClient(function () { throw new \Pagou\OpenCart\ApiException('too many requests', 429); });
$e->setClient('sandbox', $f429);
$e->reconcile(10, 0);
ok(count($f429->calls) === 1, '429 para a conciliação');

// Conciliação: Pix pago confirmado pela consulta
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 5, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'p5', 'amount' => 10));
$e->setClient('sandbox', new FakeClient(function () { return array('id' => 'p5', 'status' => 4, 'amount' => 10); }));
$e->reconcile(10, 0);
ok($repo->rows[1]['status'] === 'paid' && $h === array(array(5, 'paid')), 'cron confirma pagamento');

// Webhook do Pix vinculado ao boleto
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 6, 'method' => 'boleto', 'environment' => 'sandbox', 'pagou_id' => 'bol6', 'amount' => 30, 'data' => array('qrcode_id' => 'qr6')));
$b7 = '{"name":"qrcode.completed","data":{"id":"qr6","transaction_id":"t6","amount":30}}';
$r = $e->handleWebhook($b7, array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => Webhook::sign($b7, $ts, 'k')));
ok($r === array(200, 'ok') && $repo->rows[1]['status'] === 'paid', 'Pix do boleto confirma o boleto');

// ---- Correções da revisão independente -----------------------------------

// Corrida: dois processos com a mesma leitura "pending" -> só um grava histórico
$h = array();
list($e, $repo) = $newEngine($h);
$t = $repo->create(array('order_id' => 7, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'p7', 'amount' => 10));
$e->transition($t, 'paid', 'webhook');
$e->transition($t, 'paid', 'polling'); // $t antigo, ainda "pending"
ok($h === array(array(7, 'paid')), 'corrida: histórico aplicado uma vez');

// Falha ao atualizar o pedido: transação volta ao estado anterior e o reenvio funciona
$h = array();
$repo = new MemRepo();
$fail = true;
$e = new Engine($repo, array('environment' => 'sandbox', 'api_key_sandbox' => 'k'), function ($o, $s) use (&$fail, &$h) { if ($fail) throw new \RuntimeException('smtp'); $h[] = array($o, $s); });
$repo->create(array('order_id' => 8, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'p8', 'amount' => 10));
$b8 = '{"name":"qrcode.completed","data":{"id":"p8","transaction_id":"t8","amount":10}}';
$hd8 = array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => Webhook::sign($b8, $ts, 'k'));
ok($e->handleWebhook($b8, $hd8)[0] === 500 && $repo->rows[1]['status'] === 'pending', 'falha no pedido: 500 e transação revertida');
$fail = false;
ok($e->handleWebhook($b8, $hd8) === array(200, 'ok') && $h === array(array(8, 'paid')), 'reenvio aplica o pagamento');

// Cobrança antiga expirando não mexe em pedido pago por outra cobrança
$h = array();
list($e, $repo) = $newEngine($h);
$old = $repo->create(array('order_id' => 9, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'old', 'amount' => 10));
$repo->create(array('order_id' => 9, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'new', 'amount' => 10, 'status' => 'paid'));
$e->transition($old, 'expired', 'cron');
ok($h === array(array(9, 'comment')), 'expiração de Pix antigo vira só comentário');

// Estorno parcial vindo da consulta não marca o pedido como estornado
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 10, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'p10', 'amount' => 100));
$e->setClient('sandbox', new FakeClient(function () { return array('id' => 'p10', 'status' => 5, 'amount' => 100, 'refund' => array('amount' => 30, 'status' => 3)); }));
$e->refresh($repo->rows[1], true);
ok($repo->rows[1]['status'] === 'pending' && $h === array(), 'estorno parcial pela consulta não estorna o pedido');

// Boleto cancelado mas pago pelo Pix vinculado
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 11, 'method' => 'boleto', 'environment' => 'sandbox', 'pagou_id' => 'b11', 'amount' => 50, 'data' => array('qrcode_id' => 'q11')));
$e->setClient('sandbox', new FakeClient(function ($m, $p) { return $p === '/v1/pix/q11' ? array('id' => 'q11', 'status' => 4, 'amount' => 50) : array('id' => 'b11', 'status' => 3, 'amount' => 50, 'payload' => array('qrcode_id' => 'q11')); }));
$e->refresh($repo->rows[1], true);
ok($repo->rows[1]['status'] === 'paid' && $h === array(array(11, 'paid')), 'boleto cancelado pago pelo Pix vira pago');

// Pix sem payload na criação: fica pendente e a consulta traz o QR Code
$h = array();
list($e, $repo) = $newEngine($h);
$e->setClient('sandbox', new FakeClient(function ($m) { return $m === 'POST' ? array('id' => 'p12', 'payload' => null) : array('id' => 'p12', 'status' => 2, 'amount' => 25.5, 'payload' => array('id' => 1, 'data' => 'COPIA12', 'image' => 'I')); }));
$t = $e->createPix($order, $payer, '');
ok($t['status'] === 'pending' && $t['data']['pix_code'] === 'COPIA12', 'Pix sem payload: consulta preenche');

// Cartão sem resposta: recupera repetindo a MESMA chave e o MESMO corpo; nunca cria cobrança nova
$h = array();
list($e, $repo) = $newEngine($h);
$step = 'timeout';
$seen = array();
$cf = new FakeClient(function ($m, $p, $b) use (&$step, &$seen) {
	$seen[] = $b;
	if ($step === 'timeout') throw new \Pagou\OpenCart\TransportException('timeout');
	if ($step === 'busy') throw new \Pagou\OpenCart\ApiException('in progress', 409, array('code' => 'idempotency_in_progress'));
	return array('id' => 'cc-9', 'status' => 'captured', 'value' => $b['value']);
});
$e->setClient('sandbox', $cf);
try { $e->createCard($order, $payer, $card, 1, '1.2.3.4'); } catch (\Pagou\OpenCart\TransportException $x) {}
$key1 = $repo->rows[1]['idempotency_key'];
ok($repo->rows[1]['status'] === 'unknown' && !empty($repo->rows[1]['data']['request']), 'cartão timeout guarda a requisição');
$step = 'busy';
$threw = false;
try { $e->createCard($order, $payer, $card, 1, '1.2.3.4'); } catch (\Pagou\OpenCart\TransportException $x) { $threw = true; }
ok($threw && count($repo->rows) === 1, '409 em processamento: não cria outra cobrança');
$step = 'ok';
$t = $e->createCard($order, $payer, $card, 1, '1.2.3.4');
ok($t['status'] === 'paid' && $t['pagou_id'] === 'cc-9' && count($repo->rows) === 1 && $seen[0] === $seen[2], 'replay recupera com o mesmo corpo');
ok(!isset($t['data']['request']), 'token descartado depois de resolver');
ok(count($cf->calls) === 3 && $repo->rows[1]['idempotency_key'] === $key1, 'mesma Idempotency-Key em todas as tentativas');

// Boleto vencido não é reaproveitado
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 90, 'method' => 'boleto', 'environment' => 'sandbox', 'pagou_id' => 'bv', 'amount' => 25.5, 'data' => array('due_date' => gmdate('Y-m-d', time() - 5 * 86400))));
ok($e->reusable(90, 'boleto', 25.5) === null, 'boleto vencido não é reaproveitado');

// ---- Correções da segunda auditoria ---------------------------------------

// Cartão recuperado pelo cron: o pedido É atualizado (antes ficava "aguardando" com a Pagou paga)
$h = array();
list($e, $repo) = $newEngine($h);
$step = 'timeout';
$e->setClient('sandbox', new FakeClient(function ($m, $p, $b) use (&$step) {
	if ($step === 'timeout') throw new \Pagou\OpenCart\TransportException('timeout');
	return array('id' => 'cc-r', 'status' => 'captured', 'value' => $b['value']);
}));
try { $e->createCard($order, $payer, $card, 1, '1.2.3.4'); } catch (\Pagou\OpenCart\TransportException $x) {}
$step = 'ok';
$e->reconcile(10, 0);
ok($repo->rows[1]['status'] === 'paid' && $h === array(array(90, 'paid')), 'replay pelo cron atualiza o pedido');

// Cartão aprovado no checkout: um único histórico "pago", aplicado pelo Engine
$h = array();
list($e, $repo) = $newEngine($h);
$e->setClient('sandbox', new FakeClient(function ($m, $p, $b) { return array('id' => 'cc-a', 'status' => 'captured', 'value' => $b['value']); }));
$e->createCard($order, $payer, $card, 3, '1.2.3.4');
ok($h === array(array(90, 'paid')), 'cartão aprovado: um histórico');

// Cartão recusado de forma assíncrona: pedido não muda (só comentário)
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 20, 'method' => 'card', 'environment' => 'sandbox', 'pagou_id' => 'c20', 'amount' => 10));
$e->setClient('sandbox', new FakeClient(function () { return array('id' => 'c20', 'status' => 'failed', 'value' => 1000, 'failure_reason' => 'saldo'); }));
$e->refresh($repo->rows[1], true);
ok($repo->rows[1]['status'] === 'failed' && $h === array(array(20, 'comment')), 'recusa assíncrona só comenta');

// Cartão incerto além da janela: encerra, apaga o token e libera nova tentativa
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 90, 'method' => 'card', 'environment' => 'sandbox', 'status' => 'unknown', 'amount' => 25.5, 'idempotency_key' => 'k1', 'date_added' => gmdate('Y-m-d H:i:s', time() - 3600), 'data' => array('holder' => 'X', 'request' => '{"value":2550}')));
$e->setClient('sandbox', new FakeClient(function ($m, $p, $b) { return array('id' => 'cc-new', 'status' => 'captured', 'value' => $b['value']); }));
$t = $e->createCard($order, $payer, $card, 1, '1.2.3.4');
ok($repo->rows[1]['status'] === 'failed' && !isset($repo->rows[1]['data']['request']) && $t['pagou_id'] === 'cc-new', 'cartão incerto antigo: encerrado e nova tentativa liberada');
ok($h[0] === array(90, 'comment'), 'encerramento comenta no pedido');

// Replay com 401 (chave trocada) não é tratado como recusa
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 90, 'method' => 'card', 'environment' => 'sandbox', 'status' => 'unknown', 'amount' => 25.5, 'idempotency_key' => 'k2', 'data' => array('holder' => 'X', 'request' => '{"value":2550}')));
$e->setClient('sandbox', new FakeClient(function () { throw new \Pagou\OpenCart\ApiException('invalid api key', 401); }));
$e->reconcile(10, 0);
ok($repo->rows[1]['status'] === 'unknown' && isset($repo->rows[1]['data']['request']), '401 no replay mantém incerto');

// Pagamento a menor: a consulta seguinte não libera, e o alerta sai uma vez
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 21, 'method' => 'boleto', 'environment' => 'sandbox', 'pagou_id' => 'b21', 'amount' => 100));
$b21 = '{"name":"charge.paid","data":{"id":"b21","transaction_id":"p21","amount":{"paid":60,"original":100}}}';
$e->handleWebhook($b21, array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => Webhook::sign($b21, $ts, 'k')));
$e->setClient('sandbox', new FakeClient(function () { return array('id' => 'b21', 'status' => 4, 'amount' => 100); }));
$e->reconcile(10, 0);
$e->reconcile(10, 0);
ok($repo->rows[1]['status'] === 'pending' && $h === array(array(21, 'comment')), 'pago a menor: consulta não libera, alerta único');

// Boleto 8 (pago com desconto) vai para conferência
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 22, 'method' => 'boleto', 'environment' => 'sandbox', 'pagou_id' => 'b22', 'amount' => 100));
$e->setClient('sandbox', new FakeClient(function () { return array('id' => 'b22', 'status' => 8, 'amount' => 100); }));
$e->reconcile(10, 0);
ok($repo->rows[1]['status'] === 'pending' && $h === array(array(22, 'comment')), 'boleto 8 não libera sozinho');

// Estado 5 sem detalhe de refund não estorna o pedido
$h = array();
list($e, $repo) = $newEngine($h);
$repo->create(array('order_id' => 23, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'p23', 'amount' => 10));
$e->setClient('sandbox', new FakeClient(function () { return array('id' => 'p23', 'status' => 5, 'amount' => 10, 'refund' => null); }));
$e->refresh($repo->rows[1], true);
ok($repo->rows[1]['status'] === 'pending' && $h === array(), 'estado 5 sem refund não estorna');

// Um item com erro não derruba a rodada do cron
$h = array();
$repo = new MemRepo();
$e = new Engine($repo, array('environment' => 'sandbox', 'api_key_sandbox' => 'k'), function ($o, $s) use (&$h) { if ($o === 30) throw new \RuntimeException('falha'); $h[] = array($o, $s); });
$repo->create(array('order_id' => 30, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'p30', 'amount' => 10));
$repo->create(array('order_id' => 31, 'method' => 'pix', 'environment' => 'sandbox', 'pagou_id' => 'p31', 'amount' => 10));
$e->setClient('sandbox', new FakeClient(function () { return array('status' => 4, 'amount' => 10); }));
$done = $e->reconcile(10, 0);
ok(count($done) === 2 && $h === array(array(31, 'paid')) && $repo->rows[1]['status'] === 'pending', 'erro num item não para o cron');

// Webhook sem assinatura válida não consulta o banco
class SpyRepo extends MemRepo { public $lookups = 0; public function findByPagouId($p) { $this->lookups++; return parent::findByPagouId($p); } public function findByQrcodeId($q) { $this->lookups++; return parent::findByQrcodeId($q); } }
$spy = new SpyRepo();
$e = new Engine($spy, array('environment' => 'sandbox', 'api_key_sandbox' => 'k'), function () {});
$r = $e->handleWebhook('{"name":"qrcode.completed","data":{"id":"x","transaction_id":"t"}}', array('HTTP_X_PAGOU_TIMESTAMP' => $ts, 'HTTP_X_PAGOU_SIGNATURE' => str_repeat('a', 64)));
ok($r[0] === 401 && $spy->lookups === 0, 'assinatura inválida não toca no banco');

// Boleto 7 encerra
ok(Status::fromBoleto(7) === 'rejected', 'boleto 7 encerrado');

// Entidades HTML do OpenCart não vão para a API
$esc = Order::payer(array('payment_firstname' => 'Jo&#039;ana', 'payment_lastname' => 'D&amp;Ávila', 'payment_address_1' => 'Rua A &amp; B, 10') + $orderInfo, $map);
ok($esc['name'] === "Jo'ana D&Ávila" && $esc['street'] === 'Rua A & B, 10', 'entidades HTML decodificadas');
ok(Order::cardBody($payer, array('holder' => 'D&#039;AVILA') + $card, 1, 1, 'd', '1.1.1.1', '2026-10-14')['card']['holder'] === "D'AVILA", 'portador decodificado');

// Nome curto no cartão
ok(in_array('name', Order::missing(array('name' => 'Al') + $payer, 'card'), true), 'nome com menos de 3 letras');

echo $fails ? "\n$fails de $count testes falharam\n" : "OK: $count testes\n";
exit($fails ? 1 : 0);
