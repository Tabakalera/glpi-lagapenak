"""Implementación de referencia independiente (Python) de la normalización g1-v1 y vectores de prueba.
Clave de PRUEBA, nunca de producción.
  python referencia.py --comprobar docs/api/g1-hmac-vectors.json   comprueba que el fichero coincide (sale con 1 si no)
  python referencia.py <salida.json>                                regenera el fichero (solo si cambia la norma)"""
import base64, hashlib, hmac, json, re, sys

KEY_B64 = base64.b64encode(bytes(range(32))).decode()
KEY = base64.b64decode(KEY_B64)
LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE'
EMAIL_RE = re.compile(r"^[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$")


def norm_email(v):
    e = v.strip(' \t\n\r\0\x0b')
    e = ''.join(chr(ord(c) + 32) if 'A' <= c <= 'Z' else c for c in e)
    if e == '':
        return 'missing', None
    if len(e.encode()) > 254 or not EMAIL_RE.match(e):
        return 'invalid', None
    return 'valid', e


def norm_tax(v):
    t = ''.join(chr(ord(c) - 32) if 'a' <= c <= 'z' else c for c in v)
    t = re.sub(r'[ \t\n\r\f\v._/-]+', '', t)
    if t == '':
        return 'missing', None
    m = re.match(r'^([0-9]{1,8})([A-Z]?)$', t)
    if m:
        num = m.group(1).zfill(8)
        canon, letter = num + LETTERS[int(num) % 23], m.group(2)
    else:
        m = re.match(r'^([XYZ])([0-9]{1,7})([A-Z]?)$', t)
        if not m:
            return 'other', None
        digits = m.group(2).zfill(7)
        canon = m.group(1) + digits + LETTERS[int(str('XYZ'.index(m.group(1))) + digits) % 23]
        letter = m.group(3)
    if letter and canon[-1] == letter:
        return 'valid', canon
    return 'invalid', None


def fp(kind, value):
    return hmac.new(KEY, f'lagapenak-g1|v1|{kind}|{value}'.encode(), hashlib.sha256).hexdigest()


emails = ['Artista.Ficticia@Example.TEST', '  artista.ficticia@example.test\t', 'ARTISTA+g1@sub.example.test',
          'o\'brien.ficticio@example.test', 'sin-arroba.example.test', 'dos@@example.test', 'acentuado@exámple.test',
          'punto@example', '', '   ']
tax = ['12345678Z', '12.345.678-z', ' 12345678 z ', '1234567L', '01234567L', '12345678A', '12345678',
       'X1234567L', 'x-1234567-l', 'Y0000001R', 'Y0000001S', 'X 1234567 L', '1234567８L', 'Z7654321H', 'X1234567A', 'AB1234567', 'P-ESP-998877', '']
out = {
    'normalization': 'g1-v1',
    'algorithm': 'HMAC-SHA256',
    'message_format': 'lagapenak-g1|v1|<email|tax_id>|<normalised value>',
    'output': 'lower-case hexadecimal',
    'test_key_base64': KEY_B64,
    'warning': 'Test key only. Never use it outside tests.',
    'email': [],
    'tax_id': [],
}
for e in emails:
    st, n = norm_email(e)
    out['email'].append({'input': e, 'status': st, 'normalized': n, 'fingerprint': fp('email', n) if n else None})
for t in tax:
    st, n = norm_tax(t)
    out['tax_id'].append({'input': t, 'status': st, 'normalized': n, 'fingerprint': fp('tax_id', n) if n else None})
if len(sys.argv) != 2 and sys.argv[1:2] != ['--comprobar']:
    sys.exit(__doc__)
if sys.argv[1] == '--comprobar':
    saved = json.load(open(sys.argv[2], encoding='utf-8'))
    bad = [k for k in out if saved.get(k) != out[k]] + [k for k in saved if k not in out]
    n = len(out['email']) + len(out['tax_id'])
    print(f'Referencia Python contra los vectores: {n} casos, ' + ('coinciden' if not bad else 'DIFERENCIAS en ' + ', '.join(bad)))
    sys.exit(1 if bad else 0)
json.dump(out, open(sys.argv[1], 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=2)
for k in ('email', 'tax_id'):
    for v in out[k]:
        print(k, ascii(v['input']), v['status'], v['normalized'])
