import * as ftp from "basic-ftp";
import { readFileSync } from "fs";
import { join } from "path";
import { execSync } from "child_process";

function loadEnv() {
  const content = readFileSync(join(process.cwd(), ".env"), "utf-8");
  const env = {};
  content.split("\n").forEach(line => {
    const i = line.indexOf("=");
    if (i > 0) env[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  });
  return env;
}

async function listDeep(client, path, depth = 0, maxDepth = 2) {
  const pad = "  ".repeat(depth);
  let items = [];
  try {
    items = await client.list(path);
  } catch (e) {
    console.log(`${pad}(cannot list ${path}: ${e.message})`);
    return;
  }
  for (const f of items) {
    if (f.name === "." || f.name === "..") continue;
    const full = path === "." ? f.name : `${path}/${f.name}`.replace(/\/+/g, "/");
    console.log(`${pad}${f.isDirectory ? "[D]" : "[F]"} ${full} ${f.isFile ? `(${f.size}b)` : ""}`);
    if (f.isDirectory && depth < maxDepth) {
      await listDeep(client, full, depth + 1, maxDepth);
    }
  }
}

async function main() {
  const env = loadEnv();
  console.log("=== DNS / IP checks ===");
  try {
    console.log(execSync("nslookup sanctuaryshine.co.uk", { encoding: "utf-8" }));
  } catch (e) {
    console.log(e.stdout || e.message);
  }
  try {
    console.log("curl apex:");
    console.log(execSync('curl.exe --ssl-no-revoke -sI "https://sanctuaryshine.co.uk/"', { encoding: "utf-8" }));
  } catch (e) {
    console.log(e.stdout || e.message);
  }
  try {
    console.log("curl by Host header to FTP IP:");
    console.log(execSync(`curl.exe --ssl-no-revoke -sI -H "Host: sanctuaryshine.co.uk" "http://${env.FTP_HOST}/"`, { encoding: "utf-8" }));
  } catch (e) {
    console.log(e.stdout || e.message);
  }

  const client = new ftp.Client(60000);
  client.ftp.verbose = false;
  await client.access({
    host: env.FTP_HOST,
    user: env.FTP_USER,
    password: env.FTP_PASS,
    port: parseInt(env.FTP_PORT || "21"),
    secure: false
  });

  console.log("\n=== FTP session ===");
  console.log("PWD:", await client.pwd());

  // Try going up
  const candidates = [
    ".",
    "/",
    "/public_html",
    "/domains",
    "/domains/sanctuaryshine.co.uk",
    "/domains/sanctuaryshine.co.uk/public_html",
    "/home",
    ".."
  ];

  for (const p of candidates) {
    console.log(`\n--- Trying path: ${p} ---`);
    try {
      await client.cd(p);
      const pwd = await client.pwd();
      console.log("PWD now:", pwd);
      const list = await client.list();
      for (const f of list) {
        if (f.name === "." || f.name === "..") continue;
        console.log(`  ${f.isDirectory ? "[D]" : "[F]"} ${f.name}`);
      }
      // Check for index.html
      const hasIndex = list.some(f => f.name === "index.html");
      console.log(hasIndex ? "  >>> HAS index.html" : "  >>> NO index.html");
    } catch (e) {
      console.log(`  FAIL: ${e.message}`);
    }
  }

  // Deep list from wherever we can get to root
  console.log("\n=== Deep tree from / ===");
  try {
    await client.cd("/");
    await listDeep(client, ".", 0, 3);
  } catch (e) {
    console.log("Cannot deep list /:", e.message);
    try {
      await client.cd("/public_html");
      console.log("Deep from /public_html:");
      await listDeep(client, ".", 0, 2);
    } catch (e2) {
      console.log(e2.message);
    }
  }

  // Also try to download index.html from public_html and show first 200 chars
  console.log("\n=== index.html content check ===");
  try {
    await client.cd("/public_html");
    const { Writable } = await import("stream");
    let data = "";
    const writable = new Writable({
      write(chunk, enc, cb) {
        data += chunk.toString();
        cb();
      }
    });
    await client.downloadTo(writable, "index.html");
    console.log("index.html size:", data.length);
    console.log("first 300 chars:", data.slice(0, 300));
  } catch (e) {
    console.log("Cannot download index.html:", e.message);
  }

  client.close();
}

main().catch(e => {
  console.error(e);
  process.exit(1);
});
