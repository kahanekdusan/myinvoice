import fs from 'node:fs';
import { baseCompile } from '@intlify/message-compiler';

const files = ['src/i18n/cs.json', 'src/i18n/en.json'];

function walk(obj, path, out) {
  if (typeof obj === 'string') { out.push([path.join('.'), obj]); return; }
  if (Array.isArray(obj)) { obj.forEach((v, i) => walk(v, [...path, String(i)], out)); return; }
  if (obj && typeof obj === 'object') for (const [k, v] of Object.entries(obj)) walk(v, [...path, k], out);
}

let count = 0;
for (const file of files) {
  const data = JSON.parse(fs.readFileSync(file, 'utf8'));
  const msgs = []; walk(data, [], msgs);
  for (const [key, val] of msgs) {
    const errs = [];
    baseCompile(val, { onError: (e) => errs.push(e) });
    if (errs.length) {
      count++;
      console.log(`FILE=${file}`);
      console.log(`KEY=${key}`);
      console.log(`VALUE=${JSON.stringify(val)}`);
      for (const e of errs) console.log(`ERR code=${e.code} msg=${e.message}`);
      console.log('---');
    }
  }
}
if (!count) console.log('NO_ERRORS');
