# Changelog

## 1.1.1 - 2026-10-29

- Juros ao mês sugerido no admin passa a ser 3,49% (antes 3,65%).

## 1.1.0 - 2026-10-29

- Cartão: juros do parcelamento repassados ao comprador. Novos campos "Parcelas sem juros até", "Juros acima disso" (juros ao mês pela tabela Price ou acréscimo fixo por parcela), "Juros ao mês (%)" e "Acréscimo por parcela (%)".
- Checkout mostra parcela e total de cada opção com juros; o valor é recalculado no servidor.
- Juros gravados no pedido como a linha "Juros do parcelamento (Nx)" antes do Total: pedido, e-mails, fatura e conferência batem com o valor cobrado. A linha é removida em recusa, erro ou troca para Pix/boleto.
- Sem configuração nova, o comportamento é o da 1.0.0 (todas as parcelas sem juros).
- "Valor mínimo da parcela" passa a considerar a parcela com juros.
- Admin: campos de seleção e texto nos meios; taxa e tabela de acréscimo gravadas normalizadas.
- README: procedimento de atualização no OpenCart 4 detalhado.

## 1.0.0 - 2026-10-19

- Pix imediato com QR Code, Copia e Cola, contagem regressiva e confirmação automática.
- Boleto com vencimento, tolerância, multa e juros; linha digitável, links HTML/PDF e Pix embutido.
- Cartão de crédito com tokenização no navegador e parcelamento sem juros.
- Webhook com HMAC, deduplicação e proteção contra regressão de status.
- Cron de conciliação para cobranças em aberto.
- Modo de teste com visibilidade restrita por IP ou e-mail.
- Aba "Pagou" no pedido do admin.
- Pacotes para OpenCart 3.0.x e 4.x a partir de um SDK único.
