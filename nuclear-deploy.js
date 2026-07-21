import * as ftp from "basic-ftp";
import { readFileSync, existsSync, readdirSync, statSync, writeFileSync } from "fs";
import { join, resolve, relative, dirname } from "path";
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

function walkFiles(dir, base = dir, files = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) walkFiles(full, base, files);
    else files.push(relative(base, full).replace(/\\/g, "/"));
  }
  return files;
}

async function clearDir(client, path = ".") {
  const list = await client.list(path);
  for (const f of list) {
    if (f.name === "." || f.name === "..") continue;
    const full = path === "." ? f.name : `${path}/${f.name}`;
    try {
      if (f.isDirectory) {
        await client.removeDir(full);
        console.log(`  🗑️  DIR  ${full}`);
      } else {
        await client.remove(full);
        console.log(`  🗑️  FILE ${full}`);
      }
    } catch (e) {
      console.log(`  ⚠️  skip ${full}: ${e.message}`);
      // try recursive clear then remove
      if (f.isDirectory) {
        try {
          await clearDir(client, full);
          await client.removeDir(full);
          console.log(`  🗑️  DIR  ${full} (retry)`);
        } catch (e2) {
          console.log(`  ❌ still can't remove ${full}: ${e2.message}`);
        }
      }
    }
  }
}

async function uploadFile(client, localPath, remotePath, rootPwd, retries = 4) {
  let lastErr;
  for (let i = 1; i <= retries; i++) {
    try {
      const remoteDir = dirname(remotePath).replace(/\\/g, "/");
      if (remoteDir && remoteDir !== ".") {
        await client.ensureDir(remoteDir);
        await client.cd(rootPwd);
      }
      await client.uploadFrom(localPath, remotePath);
      return;
    } catch (e) {
      lastErr = e;
      console.log(`    retry ${i}/${retries} ${remotePath}: ${e.message}`);
      try {
        await client.cd(rootPwd);
      } catch {
        // reconnect handled by caller if needed
      }
      await new Promise(r => setTimeout(r, 300 * i));
    }
  }
  throw lastErr;
}

async function main() {
  console.log("📦 Building site...");
  execSync("npm run build", { stdio: "inherit" });

  // Ensure .htaccess exists in dist
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

  const env = loadEnv();
  const client = new ftp.Client(120000);
  client.ftp.verbose = false;

  console.log("\n🔌 Connecting FTP...");
  await client.access({
    host: env.FTP_HOST,
    user: env.FTP_USER,
    password: env.FTP_PASS,
    port: parseInt(env.FTP_PORT || "21"),
    secure: false
  });

  console.log("PWD:", await client.pwd());
  await client.cd("/public_html");
  const rootPwd = await client.pwd();
  console.log("Web root:", rootPwd);

  console.log("\n☢️  NUCLEAR CLEAR of /public_html ...");
  await clearDir(client, ".");

  // Verify empty
  let after = await client.list();
  console.log("\nAfter clear:");
  for (const f of after) {
    if (f.name !== "." && f.name !== "..") console.log(`  leftover: ${f.name}`);
  }

  const localDist = resolve(process.cwd(), "dist");
  if (!existsSync(localDist)) {
    console.error("dist/ missing");
    process.exit(1);
  }

  let files = walkFiles(localDist);
  files.sort((a, b) => {
    if (a === "index.html") return -1;
    if (b === "index.html") return 1;
    if (a === ".htaccess") return -1;
    if (b === ".htaccess") return 1;
    return a.localeCompare(b);
  });

  console.log(`\n📤 Uploading ${files.length} dist files...`);
  let ok = 0, fail = [];
  for (const rel of files) {
    try {
      await uploadFile(client, join(localDist, rel), rel, rootPwd);
      ok++;
      if (ok <= 5 || ok % 10 === 0 || rel === "index.html") {
        console.log(`  ✅ ${rel} (${ok}/${files.length})`);
      }
    } catch (e) {
      // reconnect and retry once more
      try {
        await client.access({
          host: env.FTP_HOST,
          user: env.FTP_USER,
          password: env.FTP_PASS,
          port: parseInt(env.FTP_PORT || "21"),
          secure: false
        });
        await client.cd(rootPwd);
        await uploadFile(client, join(localDist, rel), rel, rootPwd, 5);
        ok++;
        console.log(`  ✅ ${rel} (after reconnect)`);
      } catch (e2) {
        fail.push(rel);
        console.log(`  ❌ ${rel}: ${e2.message}`);
      }
    }
  }

  console.log("\n🔍 Final remote listing:");
  after = await client.list();
  for (const f of after) {
    if (f.name !== "." && f.name !== "..") {
      console.log(`  ${f.isDirectory ? "[D]" : "[F]"} ${f.name}`);
    }
  }

  const hasIndex = after.some(f => f.name === "index.html");
  console.log(hasIndex ? "\n✅ index.html IS on server" : "\n❌ index.html STILL MISSING");
  console.log(`Uploaded ${ok}/${files.length}, failed ${fail.length}`);
  if (fail.length) console.log("Failed:", fail.join(", "));

  // Download index.html to prove it's there
  if (hasIndex) {
    const { Writable } = await import("stream");
    let data = "";
    const w = new Writable({
      write(c, e, cb) { data += c.toString(); cb(); }
    });
    await client.downloadTo(w, "index.html");
    console.log(`Downloaded index.html: ${data.length} bytes`);
    console.log("Title snippet:", (data.match(/<title>[^<]+<\/title>/i) || ["?"])[0]);
  }

  client.close();
  console.log("\n🎉 Nuclear deploy complete.");
  console.log("⚠️  DO NOT push to GitHub until Hostinger Git Deploy is disabled,");
  console.log("   or it will overwrite public_html with source code again.");
}

main().catch(e => {
  console.error("FAILED:", e);
  process.exit(1);
});
