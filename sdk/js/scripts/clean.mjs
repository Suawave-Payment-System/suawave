import { rmSync } from 'node:fs';

const requested = process.argv.slice(2);
const directories = requested.length > 0 ? requested : ['dist', '.test-build'];

for (const directory of directories) {
  rmSync(directory, { recursive: true, force: true });
}
