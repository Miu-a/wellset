import { readFileSync } from 'node:fs';
import { apply } from '../js/dist/index.js';

const sample = readFileSync(new URL('./sample.cs.html', import.meta.url), 'utf8');
const sampleBytes = Buffer.byteLength(sample);
const text = sample.repeat(Math.floor((100 * 1024) / sampleBytes) + 1);
const bytes = Buffer.byteLength(text);
const runs = 200;

for (let i = 0; i < 20; i++) {
  apply(text, 'cs', { html: true });
}
const start = process.hrtime.bigint();
for (let i = 0; i < runs; i++) {
  apply(text, 'cs', { html: true });
}
const perRun = Number(process.hrtime.bigint() - start) / runs / 1e6;

console.log(
  `Node ${process.version}, ${Math.floor(bytes / 1024)} kB, ${runs} runs: ${perRun.toFixed(2)} ms per run, ${(bytes / 1e6 / (perRun / 1000)).toFixed(1)} MB/s`,
);
