// Comprueba la validación del formulario (JavaScript tal como lo sirve GLPI) contra los vectores compartidos.
// Uso: node js_vectores.js <formulario.html> <g1-hmac-vectors.json>
// El formulario es la página de préstamo nuevo tal como la sirve GLPI (g1.sh vectores la descarga del entorno).
const fs = require('fs');
const html = fs.readFileSync(process.argv[2], 'utf8');
const vec = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));

// taxStatus(): cuerpo completo, contando llaves
const start = html.indexOf('function taxStatus(v)');
let i = html.indexOf('{', start), depth = 0, end = -1;
for (; i < html.length; i++) {
  if (html[i] === '{') depth++;
  if (html[i] === '}' && --depth === 0) { end = i + 1; break; }
}
const taxStatus = new Function(html.slice(start, end) + '; return taxStatus;')();
// Expresión del email, tal como está en la página
const m = html.match(/ev\.length <= 254 && (\/\^.*?\$\/)\.test\(ev\)/);
const emailRe = eval(m[1]);
const emailStatus = (v) => { const ev = v.trim().toLowerCase(); return ev === '' ? 'missing' : (ev.length <= 254 && emailRe.test(ev) ? 'valid' : 'invalid'); };

let ok = 0, ko = 0;
for (const c of vec.email) {
  const got = emailStatus(c.input);
  if (got === c.status) ok++; else { ko++; console.log('email', JSON.stringify(c.input), 'esperado', c.status, 'obtenido', got); }
}
for (const c of vec.tax_id) {
  const got = taxStatus(c.input);
  if (got === c.status) ok++; else { ko++; console.log('tax_id', JSON.stringify(c.input), 'esperado', c.status, 'obtenido', got); }
}
console.log(`JS del formulario contra los vectores: ${ok} OK · ${ko} FALLO`);
process.exit(ko ? 1 : 0);
