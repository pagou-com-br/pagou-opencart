# Porta os controllers de catálogo do OC3 (cartão e compartilhado) para o OC4.
# Uso único durante o desenvolvimento: python build/port_oc4_catalog.py
import re
import sys
from pathlib import Path

root = Path(__file__).resolve().parent.parent
src = root / 'oc3/upload/catalog/controller/extension/payment'
dst = root / 'oc4/pagou/catalog/controller/payment'

LANG = "$this->config->get('config_language')"

REPLACEMENTS = [
    ("<?php\nrequire_once DIR_SYSTEM . 'library/pagou/autoload.php';\n",
     "<?php\nnamespace Opencart\\Catalog\\Controller\\Extension\\Pagou\\Payment;\n\nrequire_once DIR_EXTENSION . 'pagou/system/library/sdk/autoload.php';\n"),
    ("'extension/payment/pagou_card'", "'extension/pagou/payment/pagou_card'"),
    ("'extension/payment/pagou'", "'extension/pagou/payment/pagou'"),
    ("model_extension_payment_pagou", "model_extension_pagou_payment_pagou"),
    ("'index.php?route=extension/payment/pagou_card/confirm'",
     "'index.php?route=extension/pagou/payment/pagou_card.confirm&language=' . " + LANG),
    ("$this->load->view('extension/payment/", "$this->load->view('extension/pagou/payment/"),
    ("$this->currency->format($total / $n, 'BRL', 1)", "$this->currency->format($total / $n, 'BRL', 1.0)"),
    ("$this->currency->format($t['amount'], 'BRL', 1)", "$this->currency->format((float)$t['amount'], 'BRL', 1.0)"),
    ("route=extension/payment/pagou/", "route=extension/pagou/payment/pagou."),
    ("str_replace('/status&', '/simulate&', $data['status_url'])",
     "str_replace('pagou.status&', 'pagou.simulate&', $data['status_url'])"),
    ("$this->url->link('error/not_found')", "$this->url->link('error/not_found', 'language=' . " + LANG + ")"),
    ("$this->url->link('common/home')", "$this->url->link('common/home', 'language=' . " + LANG + ")"),
    ("$this->url->link('account/order/info', 'order_id=' . $orderId, true)",
     "$this->url->link('account/order.info', 'language=' . " + LANG + " . '&order_id=' . $orderId . (isset($this->session->data['customer_token']) ? '&customer_token=' . $this->session->data['customer_token'] : ''))"),
    ("$order['order_id'],", "(int)$order['order_id'],"),
    ("$pagou->payUrl($order['order_id'])", "$pagou->payUrl((int)$order['order_id'])"),
    # Currency::format do OC 4 tem $value float
    ("'BRL', 1)", "'BRL', 1.0)"),
]


def port(name, cls):
    s = (src / f'{name}.php').read_text(encoding='utf-8')
    for a, b in REPLACEMENTS:
        s = s.replace(a, b)
    s = re.sub(r'class ControllerExtensionPayment\w+ extends Controller',
               lambda m: f'class {cls} extends \\Opencart\\System\\Engine\\Controller', s)
    (dst / f'{name}.php').write_text(s, encoding='utf-8', newline='\n')


port('pagou_card', 'PagouCard')
port('pagou', 'Pagou')

# Telas do catálogo: Bootstrap 3 -> Bootstrap 5, FontAwesome 4 -> 6
vsrc = root / 'oc3/upload/catalog/view/theme/default/template/extension/payment'
vdst = root / 'oc4/pagou/catalog/view/template/payment'
VIEW = [
    ('class="form-group required"', 'class="mb-3 required"'),
    ('form-group required"', 'mb-3 required"'),
    ('class="control-label"', 'class="form-label"'),
    ('class="help-block"', 'class="form-text"'),
    ('<div class="pull-right">', '<div class="text-end">'),
    ('btn-default', 'btn-light'),
    ('<select id="pagou-card-installments" class="form-control">', '<select id="pagou-card-installments" class="form-select">'),
    ('fa fa-spinner fa-spin', 'fa-solid fa-spinner fa-spin'),
    ('fa fa-circle-o-notch fa-spin', 'fa-solid fa-circle-notch fa-spin'),
    ('<li><a href="{{ breadcrumb.href }}">', '<li class="breadcrumb-item"><a href="{{ breadcrumb.href }}">'),
    ('class="btn btn-primary pull-right"', 'class="btn btn-primary float-end"'),
]
for name in ('pagou_offline', 'pagou_card', 'pagou_pay'):
    s = (vsrc / f'{name}.twig').read_text(encoding='utf-8')
    for a, b in VIEW:
        s = s.replace(a, b)
    (vdst / f'{name}.twig').write_text(s, encoding='utf-8', newline='\n')
print('ok')
