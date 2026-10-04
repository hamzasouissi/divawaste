// Every translation key written literally in the sources must exist in each translation file.
// Keys built at run time (t('status.lot.' + value)) are checked by their prefix: the prefix must lead to a group.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const files = (dir) => readdirSync(dir).flatMap((name) => {
  const path = join(dir, name);
  return statSync(path).isDirectory() ? files(path) : /\.ts$/.test(name) && !/\.spec\.ts$/.test(name) ? [path] : [];
});
const lookup = (tree, key) => key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), tree);
// Keys that contain dots themselves (notification types) are stored as one segment.
const resolve = (tree, key) => {
  const direct = lookup(tree, key);
  if (direct !== undefined) return direct;
  const match = /^(notifications\.(?:types|labels))\.([a-z_]+\.[a-z_]+)(?:\.(title|body))?$/.exec(key);
  if (!match) return undefined;
  const node = lookup(tree, match[1])?.[match[2]];
  return match[3] ? node?.[match[3]] : node;
};

const literal = /(?:\bt\(|toast\.(?:success|info|text)\(|problem\.set\(|header: |label: |emptyTitle="|\bdone = )'([a-zA-Z][\w]*(?:\.[\w]+)+\.?)'(\s*\+)?/g;
const sources = files(join(root, 'src', 'app'));
let failures = 0;
let checked = 0;

for (const language of readdirSync(join(root, 'public', 'i18n')).filter((name) => name.endsWith('.json'))) {
  const tree = JSON.parse(readFileSync(join(root, 'public', 'i18n', language), 'utf8'));
  for (const file of sources) {
    for (const [, key, dynamic] of readFileSync(file, 'utf8').matchAll(literal)) {
      checked++;
      const target = dynamic ? key.replace(/\.$/, '') : key;
      const value = resolve(tree, target.replace(/\.$/, ''));
      if (dynamic ? typeof value !== 'object' : typeof value !== 'string') {
        failures++;
        console.error(`${language}: missing ${dynamic ? 'group' : 'key'} "${target}"  (${file.replace(root, '')})`);
      }
    }
  }
}

if (failures > 0) {
  console.error(`\n${failures} translation key(s) missing.`);
  process.exit(1);
}
console.log(`All ${checked} translation key references in the sources exist.`);
