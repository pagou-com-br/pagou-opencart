# Converte as telas de admin do OC3 (Bootstrap 3) para o OC4 (Bootstrap 5, salvamento via AJAX).
# Uso único durante o desenvolvimento: python build/port_oc4_admin_views.py
from pathlib import Path

root = Path(__file__).resolve().parent.parent
src = root / 'oc3/upload/admin/view/template/extension/payment'
dst = root / 'oc4/pagou/admin/view/template/payment'
dst.mkdir(parents=True, exist_ok=True)

form = (src / 'pagou_form.twig').read_text(encoding='utf-8')

head_old = form[form.index('<div class="page-header">'):form.index('{% set p = prefix %}')]
head_new = '''<div class="page-header">
    <div class="container-fluid">
      <div class="float-end">
        <button type="submit" form="form-payment" data-bs-toggle="tooltip" title="{{ lang.button_save }}" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i></button>
        <a href="{{ back }}" data-bs-toggle="tooltip" title="{{ lang.button_back }}" class="btn btn-light"><i class="fa-solid fa-reply"></i></a>
      </div>
      <h1>{{ heading_title }}</h1>
      <ol class="breadcrumb">
        {% for breadcrumb in breadcrumbs %}<li class="breadcrumb-item"><a href="{{ breadcrumb.href }}">{{ breadcrumb.text }}</a></li>{% endfor %}
      </ol>
    </div>
  </div>
  <div class="container-fluid">
    '''
form = form.replace(head_old, head_new)

pairs = [
    ('<form action="{{ action }}" method="post" enctype="multipart/form-data" id="form-pagou" class="form-horizontal">',
     '<form id="form-payment" action="{{ save }}" method="post" data-oc-toggle="ajax">'),
    ('<div class="panel panel-default">', '<div class="card mb-3">'),
    ('<div class="panel-heading"><h3 class="panel-title">', '<div class="card-header">'),
    ('</h3></div>', '</div>'),
    ('<div class="panel-body">', '<div class="card-body">'),
    ('<div class="form-group">', '<div class="row mb-3">'),
    ('class="col-sm-2 control-label"', 'class="col-sm-2 col-form-label"'),
    ('data-toggle="tooltip"', 'data-bs-toggle="tooltip"'),
    ('<span class="input-group-btn"><button type="button" class="btn btn-default pagou-test"', '<button type="button" class="btn btn-outline-secondary pagou-test"'),
    ('{{ lang.button_test }}</button></span>', '{{ lang.button_test }}</button>'),
    ('<i class="fa fa-plug"></i>', '<i class="fa-solid fa-plug"></i>'),
    ('<i class="fa fa-pencil"></i>', '<i class="fa-solid fa-pencil"></i>'),
    ('<i class="fa fa-key"></i>', '<i class="fa-solid fa-key"></i>'),
    ('class="help-block"', 'class="form-text"'),
    ('{{ text_enabled }}', '{{ lang.text_enabled }}'),
    ('{{ text_disabled }}', '{{ lang.text_disabled }}'),
    ('{{ text_all_zones }}', '{{ lang.text_all_zones }}'),
]
for a, b in pairs:
    form = form.replace(a, b)
# selects do Bootstrap 5
import re
form = re.sub(r'(<select [^>]*?)class="form-control"', r'\1class="form-select"', form)
form = form.replace('<legend>', '<legend class="fs-6 fw-bold mt-3">')
(dst / 'pagou_form.twig').write_text(form, encoding='utf-8', newline='\n')

order = (src / 'pagou_order.twig').read_text(encoding='utf-8')
order = order.replace('table-condensed', 'table-sm').replace('btn btn-default btn-sm', 'btn btn-light btn-sm').replace('fa fa-refresh', 'fa-solid fa-rotate')
(dst / 'pagou_order.twig').write_text(order, encoding='utf-8', newline='\n')
print('ok')
