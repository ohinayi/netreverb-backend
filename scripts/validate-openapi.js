import fs from 'node:fs';
import { parseDocument } from 'yaml';

const source = fs.readFileSync(new URL('../docs/openapi.yaml', import.meta.url), 'utf8');
const document = parseDocument(source, { uniqueKeys: true });

if (document.errors.length > 0) {
    for (const error of document.errors) {
        process.stderr.write(`${error.message}\n`);
    }

    process.exit(1);
}

const openapi = document.toJS();

if (openapi.openapi !== '3.1.0' || typeof openapi.paths !== 'object') {
    throw new Error('The document must be an OpenAPI 3.1 contract with paths.');
}

process.stdout.write(`Validated ${Object.keys(openapi.paths).length} OpenAPI paths.\n`);
