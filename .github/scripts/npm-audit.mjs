#!/usr/bin/env node
// Fails when `npm audit` reports an advisory at or above a severity level that
// the package has not accepted. Run it from the package directory:
//
//   node ../.github/scripts/npm-audit.mjs [--level=high] [--omit=dev]
//
// npm has no ignore list of its own, so an advisory is accepted the way
// Composer's `config.audit.ignore` does it: an `auditIgnore` map in
// package.json, advisory ID (GHSA-…) to the reason it is accepted.
//
//   "auditIgnore": { "GHSA-xxxx-xxxx-xxxx": "dev-only: … no fixed release yet" }
//
// An accepted ID that npm no longer reports is flagged, so the entry is removed
// once the fix lands.

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const LEVELS = ['info', 'low', 'moderate', 'high', 'critical'];

const args = process.argv.slice(2);
const level = (args.find((a) => a.startsWith('--level=')) ?? '--level=high').slice('--level='.length);
if (!LEVELS.includes(level)) {
  console.error(`Unknown --level=${level}; use one of ${LEVELS.join(', ')}.`);
  process.exit(2);
}
const npmArgs = ['audit', '--json', ...args.filter((a) => a.startsWith('--omit='))];

let raw;
try {
  raw = execFileSync('npm', npmArgs, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
} catch (error) {
  // npm audit exits 1 when it finds anything; the report is still on stdout.
  raw = error.stdout;
  if (!raw) throw error;
}

const report = JSON.parse(raw);
if (report.error) {
  console.error(`npm audit failed: ${report.error.summary ?? JSON.stringify(report.error)}`);
  process.exit(2);
}

const ignore = JSON.parse(readFileSync('package.json', 'utf8')).auditIgnore ?? {};

// One entry per advisory; `via` objects are advisories, `via` strings point at
// another vulnerable package and carry nothing new.
const advisories = new Map();
for (const vuln of Object.values(report.vulnerabilities ?? {})) {
  for (const via of vuln.via) {
    if (typeof via !== 'object') continue;
    const id = via.url?.match(/GHSA-[\w-]+/)?.[0] ?? String(via.source);
    const entry = advisories.get(id) ?? { ...via, id, paths: new Set() };
    entry.paths.add(vuln.name);
    advisories.set(id, entry);
  }
}

const threshold = LEVELS.indexOf(level);
const failing = [];
for (const adv of advisories.values()) {
  if (LEVELS.indexOf(adv.severity) < threshold) continue;
  const line = `${adv.severity.padEnd(8)} ${adv.id}  ${adv.name} ${adv.range}  ${adv.title}`;
  if (ignore[adv.id]) {
    console.log(`accepted ${line}\n         reason: ${ignore[adv.id]}`);
  } else {
    failing.push(adv);
    console.log(`::error::${line} (${adv.url})`);
  }
}

for (const id of Object.keys(ignore)) {
  if (!advisories.has(id)) {
    console.log(`::warning::auditIgnore lists ${id}, which npm audit no longer reports. Remove the entry.`);
  }
}

if (failing.length > 0) {
  console.log(
    `\n${failing.length} ${failing.length === 1 ? 'advisory' : 'advisories'} at ${level} or above. ` +
      'Update the dependency (an ' +
      '`overrides` entry pins a fixed transitive version), or accept it with a reason in ' +
      '`auditIgnore` in package.json.'
  );
  process.exit(1);
}
console.log(`No unaccepted advisories at ${level} or above (${advisories.size} reported in total).`);
