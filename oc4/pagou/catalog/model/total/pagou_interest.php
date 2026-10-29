<?php
namespace Opencart\Catalog\Model\Extension\Pagou\Total;

/**
 * Linha "Juros do parcelamento" que a Pagou grava no pedido (cartão com juros repassados ao comprador).
 * Não é um total de carrinho e não aparece em Extensões > Totais: o OpenCart só exige que o model
 * exista, porque ao confirmar ou cancelar um pedido ele carrega o model de cada linha de total
 * (laço de confirm de cupom e pontos).
 */
class PagouInterest extends \Opencart\System\Engine\Model {
}
