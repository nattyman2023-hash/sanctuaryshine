import * as ftp from "basic-ftp";
import { readFileSync, readdirSync, statSync } from "fs";
import { join, relative, dirname } from "path";

function loadEnv() {
  const content = readFileSync(join(process.cwd(), ".env"), "utf-8");
  const env = {};
  content.split("\n").forEach((line) => {
    const i = line.indexOf("=");
    if (i > 0) env[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  });
  return env;
}

function walk(dir, base = dir, files = []) {
  for (const e of readdirSync(dir)) {
    const full = join(dir, e);
    if (statSync(full).isDirectory()) walk(full, base, files);
    else {
      // Always use forward slashes for FTP remote paths
      const rel = relative(base, full).replace(/\\/g, "/");
      files.push(rel);
    }
  }
  return files;
}

async function main() {
  // Verify local build first
  const samplePath = join(
    process.cwd(),
    "dist",
    "blog",
    "domestic-cleaners-salford-complete-guide",
    "index.html"
  );
  const sample = readFileSync(samplePath, "utf-8");
  console.log("LOCAL build check:");
  console.log("  has <h2>:", sample.includes("<h2"));
  console.log("  has <strong>:", sample.includes("<strong"));
  console.log("  has raw ## Finding:", sample.includes("## Finding"));

  if (sample.includes("## Finding") || !sample.includes("<h2")) {
    console.error("Local build still has raw markdown — aborting deploy");
    process.exit(1);
  }

  const env = loadEnv();
  const client = new ftp.Client(180000);
  client.ftp.verbose = false;

  await client.access({
    host: env.FTP_HOST,
    user: env.FTP_USER,
    password: env.FTP_PASS,
    port: parseInt(env.FTP_PORT || "21"),
    secure: false,
  });

  await client.cd("/public_html");
  const root = await client.pwd();
  console.log("PWD:", root);

  const dist = join(process.cwd(), "dist");
  const all = walk(dist);
  console.log("Total files:", all.length);

  const blogFiles = all.filter((f) => f.startsWith("blog/"));
  console.log("Blog files to upload:");
  blogFiles.forEach((f) => console.log(" -", f));

  // Upload blog pages first (critical)
  for (const rel of blogFiles) {
    const remoteDir = dirname(rel).replace(/\\/g, "/");
    if (remoteDir && remoteDir !== ".") {
      await client.ensureDir(remoteDir);
      await client.cd(root);
    }
    await client.uploadFrom(join(dist, rel), rel);
    console.log("OK", rel);
  }

  // Upload remaining dist files
  let ok = 0;
  for (const rel of all) {
    if (rel.startsWith("blog/")) continue;
    const remoteDir = dirname(rel).replace(/\\/g, "/");
    if (remoteDir && remoteDir !== ".") {
      await client.ensureDir(remoteDir);
      await client.cd(root);
    }
    await client.uploadFrom(join(dist, rel), rel);
    ok++;
  }
  console.log("Uploaded", ok, "non-blog files");

  // Keep .env
  await client.cd(root);
  await client.uploadFrom(join(process.cwd(), ".env"), ".env");
  client.close();

  // Live verify
  await new Promise((r) => setTimeout(r, 1500));
  const url =
    "https://sanctuaryshine.co.uk/blog/domestic-cleaners-salford-complete-guide/?nocache=" +
    Date.now();
  const live = await fetch(url, {
    headers: { "Cache-Control": "no-cache", Pragma: "no-cache" },
  });
  const html = await live.text();
  console.log("\nLIVE check:");
  console.log("  status:", live.status);
  console.log("  has <h2>:", html.includes("<h2"));
  console.log("  has <strong>:", html.includes("<strong"));
  console.log("  has raw ## Finding:", html.includes("## Finding"));
  const idx = html.indexOf("Finding Domestic");
  if (idx >= 0) {
    console.log(
      "  snippet:",
      html
        .slice(Math.max(0, idx - 60), idx + 100)
        .replace(/\s+/g, " ")
        .trim()
    );
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
