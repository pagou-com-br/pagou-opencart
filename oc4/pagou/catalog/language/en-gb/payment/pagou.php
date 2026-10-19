<?php
// Shared texts for the Pagou payment methods (checkout and payment page)
$_['text_loading']            = 'Processing...';
$_['text_sandbox']            = 'Test environment (Sandbox): no real money will be charged.';
$_['text_document']           = 'Payer CPF or CNPJ';
$_['text_document_help']      = 'Required to issue the charge.';
$_['text_pay_title']          = 'Payment for order #%s';
$_['text_pix_instructions']   = 'Open your banking app, choose Pix and scan the QR Code or use Pix Copy and Paste.';
$_['text_pix_copy']           = 'Copy Pix code';
$_['text_copied']             = 'Copied!';
$_['text_pix_expires']        = 'This Pix expires in';
$_['text_pix_expired']        = 'This Pix has expired. Please contact the store to get a new one.';
$_['text_boleto_generating']  = 'Your boleto is being registered with the bank. This takes a few seconds...';
$_['text_boleto_line']        = 'Payment line';
$_['text_boleto_copy']        = 'Copy payment line';
$_['text_boleto_open']        = 'Open boleto';
$_['text_boleto_pdf']         = 'Download PDF';
$_['text_boleto_due']         = 'Due date: %s';
$_['text_boleto_pix']         = 'Prefer to pay now? This boleto also accepts Pix:';
$_['text_card_review']        = 'Your payment is under review. This page refreshes automatically.';
$_['text_waiting']            = 'Waiting for payment confirmation...';
$_['text_paid']               = 'Payment confirmed! Thank you for your purchase.';
$_['text_canceled']           = 'This charge is no longer active. Please contact the store.';
$_['text_refunded']           = 'This payment was refunded.';
$_['text_amount']             = 'Amount: %s';
$_['text_installments']       = '%dx of %s interest-free';
$_['text_history_link']       = 'To pay or review the payment instructions, visit: %s';
$_['text_history_card']       = 'Card payment sent to Pagou (%dx).';
$_['button_continue_shopping']= 'Continue shopping';
$_['button_my_orders']        = 'My orders';

$_['error_method']            = 'Invalid payment method. Please reload the page.';
$_['error_order']             = 'Order not found. Please reload the page and try again.';
$_['error_document']          = 'Please enter a valid CPF or CNPJ.';
$_['error_missing']           = 'Please complete your details to use this payment method. Missing: %s.';
$_['error_api']               = 'We could not create the payment right now: %s';
$_['error_indeterminate']     = 'Pagou did not answer in time. Your order was saved and the payment is being checked: do not pay again before checking.';
$_['error_card_token']        = 'Please check your card details and try again.';
$_['error_card_declined']     = 'Payment not approved%s. Check the details or use another card.';
$_['error_installments']      = 'Invalid number of installments.';
$_['error_not_found']         = 'Payment not found.';

$_['field_document']          = 'CPF/CNPJ';
$_['field_name']              = 'name';
$_['field_zip']               = 'postcode';
$_['field_street']            = 'address';
$_['field_city']              = 'city';
$_['field_state']             = 'state';
$_['field_phone']             = 'phone with area code';
$_['field_email']             = 'e-mail';

$_['entry_card_number']       = 'Card number';
$_['entry_card_holder']       = 'Name on card';
$_['entry_card_expiry']       = 'Expiry (MM/YY)';
$_['entry_card_cvv']          = 'CVV';
$_['entry_installments']      = 'Installments';
