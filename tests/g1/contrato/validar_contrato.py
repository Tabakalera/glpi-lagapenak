"""Valida respuestas reales de la API contra los esquemas del contrato (JSON Schema 2020-12).
Uso: python validar_contrato.py <openapi-g1.yaml> <carpeta con las respuestas capturadas>
Sale con código 1 si hay algún error."""
import json, sys, yaml
from jsonschema import Draft202012Validator
from referencing import Registry, Resource

spec = yaml.safe_load(open(sys.argv[1], encoding='utf-8'))
registry = Registry().with_resource('urn:g1', Resource.from_contents(spec, default_specification=__import__('referencing.jsonschema', fromlist=['DRAFT202012']).DRAFT202012))
cases = {'meta': 'Meta', 'meta_np': 'Meta', 'events': 'LoanEventPage', 'events_upsert': 'LoanEventPage', 'loan': 'Loan', 'loan_np': 'Loan', 'loan_signed': 'Loan', 'batch': 'LoanDetailPage', 'number': 'LoanDetailPage',
         'problem410': 'Problem', 'problem400': 'Problem'}
total_err = 0
for name, schema in cases.items():
    body = json.load(open(f'{sys.argv[2]}/{name}.json', encoding='utf-8'))
    v = Draft202012Validator({'$ref': f'urn:g1#/components/schemas/{schema}'}, registry=registry)
    errs = list(v.iter_errors(body))
    total_err += len(errs)
    extra = ''
    if name.startswith('events'):
        extra = ' · eventos: ' + ', '.join(sorted({e['event'] for e in body['data']}))
    if name in ('loan', 'loan_np', 'loan_signed'):
        extra = ' · contacto: ' + ('sí' if body['beneficiary']['contact'] else 'null') + ' · firmante: ' + ('sí' if body['delivery_note']['signer_name'] else 'null')
    if name == 'batch':
        extra = ' · not_found: ' + ', '.join(sorted({n['reason'] for n in body['not_found']}))
    print(f'{name:11} → {schema:15} {"OK" if not errs else "ERRORES: " + str(len(errs))}{extra}')
    for e in errs[:5]:
        print('     ', list(e.absolute_path), e.message[:160])
print('total errores:', total_err)
sys.exit(1 if total_err else 0)
