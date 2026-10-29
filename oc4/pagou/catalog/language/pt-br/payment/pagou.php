<?php
// Textos comuns dos meios Pagou (checkout e página de pagamento)
$_['text_loading']            = 'Processando...';
$_['text_sandbox']            = 'Ambiente de testes (Sandbox): nenhum valor real será cobrado.';
$_['text_document']           = 'CPF ou CNPJ do pagador';
$_['text_document_help']      = 'Necessário para emitir a cobrança.';
$_['text_pay_title']          = 'Pagamento do pedido #%s';
$_['text_pix_instructions']   = 'Abra o app do seu banco, escolha Pix e escaneie o QR Code ou use o Pix Copia e Cola.';
$_['text_pix_copy']           = 'Copiar código Pix';
$_['text_copied']             = 'Copiado!';
$_['text_pix_expires']        = 'Este Pix expira em';
$_['text_pix_expired']        = 'Este Pix expirou. Entre em contato com a loja para gerar um novo.';
$_['text_boleto_generating']  = 'Estamos registrando seu boleto no banco. Isso leva alguns segundos...';
$_['text_boleto_line']        = 'Linha digitável';
$_['text_boleto_copy']        = 'Copiar linha digitável';
$_['text_boleto_open']        = 'Abrir boleto';
$_['text_boleto_pdf']         = 'Baixar PDF';
$_['text_boleto_due']         = 'Vencimento: %s';
$_['text_boleto_pix']         = 'Prefere pagar agora? Este boleto também aceita Pix:';
$_['text_card_review']        = 'Seu pagamento está em análise. Esta página atualiza sozinha.';
$_['text_waiting']            = 'Aguardando a confirmação do pagamento...';
$_['text_paid']               = 'Pagamento confirmado! Obrigado pela compra.';
$_['text_canceled']           = 'Esta cobrança não está mais ativa. Entre em contato com a loja.';
$_['text_refunded']           = 'Este pagamento foi estornado.';
$_['text_amount']             = 'Valor: %s';
$_['text_installments']       = '%dx de %s sem juros';
$_['text_installments_interest'] = '%dx de %s com juros (total %s)';
$_['text_interest_line']      = 'Juros do parcelamento (%dx)';
$_['text_history_link']       = 'Para pagar ou rever as instruções de pagamento, acesse: %s';
$_['text_history_card']       = 'Pagamento com cartão enviado para a Pagou (%dx).';
$_['button_continue_shopping']= 'Continuar comprando';
$_['button_my_orders']        = 'Meus pedidos';

$_['error_method']            = 'Forma de pagamento inválida. Recarregue a página.';
$_['error_order']             = 'Pedido não encontrado. Recarregue a página e tente novamente.';
$_['error_document']          = 'Informe um CPF ou CNPJ válido.';
$_['error_missing']           = 'Complete seu cadastro para pagar com este meio. Falta: %s.';
$_['error_api']               = 'Não foi possível gerar o pagamento agora: %s';
$_['error_indeterminate']     = 'Não recebemos a resposta da Pagou a tempo. Seu pedido foi registrado e o pagamento está sendo verificado: não pague de novo antes de conferir.';
$_['error_card_token']        = 'Confira os dados do cartão e tente novamente.';
$_['error_card_declined']     = 'Pagamento não aprovado%s. Confira os dados ou use outro cartão.';
$_['error_installments']      = 'Número de parcelas inválido.';
$_['error_not_found']         = 'Pagamento não encontrado.';

$_['field_document']          = 'CPF/CNPJ';
$_['field_name']              = 'nome';
$_['field_zip']               = 'CEP';
$_['field_street']            = 'endereço';
$_['field_city']              = 'cidade';
$_['field_state']             = 'estado (UF)';
$_['field_phone']             = 'telefone com DDD';
$_['field_email']             = 'e-mail';

$_['entry_card_number']       = 'Número do cartão';
$_['entry_card_holder']       = 'Nome impresso no cartão';
$_['entry_card_expiry']       = 'Validade (MM/AA)';
$_['entry_card_cvv']          = 'CVV';
$_['entry_installments']      = 'Parcelas';
