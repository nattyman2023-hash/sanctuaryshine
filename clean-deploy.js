import * as ftp from "basic-ftp";
import { readFileSync, existsSync, readdirSync, statSync, writeFileSync } from "fs";
import { join, resolve, relative, dirname } from "path";
import { execSync } from "child_process";

function loadEnv() {
  const envPath = join(process.cwd(), ".env");
  const content = readFileSync(envPath, "utf-8");
  const env = {};
  content.split("\n").forEach(line => {
    const i = line.indexOf("=");
    if (i > 0) env[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  });
  return env;
}

function walkFiles(dir, base = dir, files = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) walkFiles(full, base, files);
    else files.push(relative(base, full).replace(/\\/g, "/"));
  }
  return files;
}

const env = loadEnv();
const config = {
  host: env.FTP_HOST,
  user: env.FTP_USER,
  password: env.FTP_PASS,
  port: parseInt(env.FTP_PORT || "21"),
};

// Stale items that should NOT be in the web root
const STALE = new Set([
  ".git", ".vscode", "src", "public", "node_modules", "dist",
  "package.json", "package-lock.json", "tsconfig.json", "astro.config.mjs",
  "deploy.js", "ftp-check.js", "generate-logo.js", "clean-deploy.js",
  "README.md", "AGENTS.md", "CLAUDE.md", ".gitignore", ".env"
]);

async function main() {
  // Build first
  console.log("📦 Building...");
  execSync("npm run build", { stdio: "inherit" });

  // Write a safe .htaccess into dist
  const htaccess = `DirectoryIndex index.html index.php
Options -Indexes
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
</IfModule>
`;
  writeFileSync(join(process.cwd(), "dist", ".htaccess"), htaccess);
  console.log("✅ Wrote dist/.htaccess");

  const client = new ftp.Client(90000);
  client.ftp.verbose = false;

  console.log("🔌 Connecting...");
  await client.access({
    host: config.host,
    user: config.user,
    password: config.password,
    port: config.port,
    secure: false
  });

  const pwd = await client.pwd();
  console.log("PWD:", pwd);

  // Discover actual web root
  // Hostinger domain FTP users often land already inside public_html
  let webRoot = ".";
  const rootList = await client.list(".");
  console.log("\n=== Current remote root contents ===");
  for (const f of rootList) {
    console.log(`  ${f.isDirectory ? "[D]" : "[F]"} ${f.name}`);
  }

  // If we're at account root and public_html exists, use it
  if (rootList.some(f => f.name === "public_html" && f.isDirectory)) {
    webRoot = "public_html";
    console.log("\nUsing web root: public_html/");
  } else {
    console.log("\nUsing web root: current directory (already in public_html)");
  }

  await client.cd(webRoot === "." ? pwd : `/${webRoot}`.replace("//", "/"));
  // Prefer absolute path if public_html
  try {
    if (webRoot === "public_html") await client.cd("/public_html");
  } catch {}

  const current = await client.pwd();
  console.log("Working in:", current);

  // Remove stale junk
  console.log("\n🧹 Cleaning stale files...");
  const listing = await client.list();
  for (const f of listing) {
    if (f.name === "." || f.name === "..") continue;
    if (STALE.has(f.name)) {
      try {
        if (f.isDirectory) {
          await client.removeDir(f.name);
          console.log(`  🗑️  removed dir ${f.name}`);
        } else {
          await client.remove(f.name);
          console.log(`  🗑️  removed file ${f.name}`);
        }
      } catch (e) {
        console.log(`  ⚠️  could not remove ${f.name}: ${e.message}`);
      }
    }
  }

  // Upload dist files
  const localDist = resolve(process.cwd(), "dist");
  const files = walkFiles(localDist);
  // Critical first
  files.sort((a, b) => {
    if (a === "index.html") return -1;
    if (b === "index.html") return 1;
    if (a === ".htaccess") return -1;
    if (b === ".htaccess") return 1;
    if (a.endsWith(".html") && !b.endsWith(".html")) return -1;
    if (!a.endsWith(".html") && b.endsWith(".html")) return 1;
    return a.localeCompare(b);
  });

  console.log(`\n📤 Uploading ${files.length} files...`);
  let ok = 0, fail = [];
  for (const rel of files) {
    const localPath = join(localDist, rel);
    const remoteDir = dirname(rel).replace(/\\/g, "/");
    try {
      if (remoteDir && remoteDir !== ".") {
        await client.ensureDir(remoteDir);
        // ensureDir cds into it — go back
        await client.cd(current);
      }
      await client.uploadFrom(localPath, rel);
      ok++;
      if (ok % 10 === 0 || rel === "index.html" || rel === ".htaccess") {
        console.log(`  ✅ ${rel} (${ok}/${files.length})`);
      }
    } catch (e) {
      console.log(`  ❌ ${rel}: ${e.message}`);
      // retry once after reconnect
      try {
        await client.access({
          host: config.host, user: config.user, password: config.password,
          port: config.port, secure: false
        });
        await client.cd(current);
        if (remoteDir && remoteDir !== ".") {
          await client.ensureDir(remoteDir);
          await client.cd(current);
        }
        await client.uploadFrom(localPath, rel);
        ok++;
        console.log(`  ✅ ${rel} (retry ok)`);
      } catch (e2) {
        fail.push(rel);
        console.log(`  ❌ ${rel} still failed: ${e2.message}`);
      }
    }
  }

  // Verify
  console.log("\n🔍 Remote listing after upload:");
  const after = await client.list();
  for (const f of after) {
    if (f.name !== "." && f.name !== "..") {
      console.log(`  ${f.isDirectory ? "[D]" : "[F]"} ${f.name}`);
    }
  }
  const hasIndex = after.some(f => f.name === "index.html");
  console.log(hasIndex ? "\n✅ index.html present" : "\n❌ index.html MISSING");
  console.log(`Uploaded ${ok}/${files.length}, failed: ${fail.length}`);
  if (fail.length) console.log("Failed:", fail.join(", "));

  client.close();
  console.log("\nDone. Check https://sanctuaryshine.co.uk");
}

main().catch(err => {
  console.error("FAILED:", err);
  process.exit(1);
});
