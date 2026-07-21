import * as ftp from "basic-ftp";
import { readFileSync, existsSync, readdirSync, statSync, mkdirSync } from "fs";
import { join, resolve, relative, dirname } from "path";
import { execSync } from "child_process";

// Load .env file manually (no dotenv dependency)
function loadEnv() {
  const envPath = join(process.cwd(), ".env");
  if (!existsSync(envPath)) {
    console.error("❌ No .env file found. Please create one with FTP credentials.");
    process.exit(1);
  }
  const content = readFileSync(envPath, "utf-8");
  const env = {};
  content.split("\n").forEach(line => {
    const [key, ...valueParts] = line.split("=");
    if (key && valueParts.length > 0) {
      env[key.trim()] = valueParts.join("=").trim();
    }
  });
  return env;
}

function walkFiles(dir, base = dir, files = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const st = statSync(full);
    if (st.isDirectory()) {
      walkFiles(full, base, files);
    } else {
      files.push(relative(base, full).replace(/\\/g, "/"));
    }
  }
  return files;
}

const env = loadEnv();

const config = {
  host: env.FTP_HOST,
  user: env.FTP_USER,
  password: env.FTP_PASS,
  port: parseInt(env.FTP_PORT || "21"),
  remotePath: env.FTP_PATH || "/public_html"
};

async function uploadWithRetry(client, localPath, remotePath, retries = 3) {
  let lastError;
  for (let attempt = 1; attempt <= retries; attempt++) {
    try {
      // Ensure remote directory exists
      const remoteDir = dirname(remotePath).replace(/\\/g, "/");
      if (remoteDir && remoteDir !== "." && remoteDir !== "/") {
        await client.ensureDir(remoteDir);
        // ensureDir changes cwd — go back to root of remote path
        await client.cd(config.remotePath);
      }
      await client.uploadFrom(localPath, remotePath);
      return;
    } catch (err) {
      lastError = err;
      console.log(`  ⚠️  Retry ${attempt}/${retries} for ${remotePath}: ${err.message}`);
      // Reconnect if connection dropped
      try {
        await client.ensureDir(config.remotePath);
      } catch {
        await client.access({
          host: config.host,
          user: config.user,
          password: config.password,
          port: config.port,
          secure: false
        });
        await client.ensureDir(config.remotePath);
      }
      await new Promise(r => setTimeout(r, 500 * attempt));
    }
  }
  throw lastError;
}

async function deploy() {
  console.log("🚀 Starting deployment to Hostinger...\n");

  // Step 1: Build the site
  console.log("📦 Step 1: Building the site...");
  try {
    execSync("npm run build", { stdio: "inherit", cwd: process.cwd() });
    console.log("✅ Build complete!\n");
  } catch (error) {
    console.error("❌ Build failed:", error.message);
    process.exit(1);
  }

  // Step 2: Connect to FTP
  console.log("🔌 Step 2: Connecting to FTP server...");
  const client = new ftp.Client(60000);
  client.ftp.verbose = false;

  try {
    await client.access({
      host: config.host,
      user: config.user,
      password: config.password,
      port: config.port,
      secure: false
    });
    console.log("✅ Connected to FTP server!\n");

    // Step 2b: Back up live CRM data (leads/users) before touching anything else.
    // This data only ever exists on the server — it is never in dist/ or git — so
    // it must be pulled down before any upload that might disturb the remote state.
    console.log("💾 Backing up live CRM data...");
    const backupDir = resolve(process.cwd(), "backups", `crm-data-${new Date().toISOString().replace(/[:.]/g, "-")}`);
    let backedUp = 0;
    for (const remoteFile of ["crm-data/leads.json", "crm-data/users.json"]) {
      try {
        mkdirSync(backupDir, { recursive: true });
        await client.downloadTo(join(backupDir, remoteFile.split("/")[1]), `${config.remotePath}/${remoteFile}`);
        backedUp++;
      } catch {
        // File may not exist yet (e.g. no leads recorded so far) — nothing to back up.
      }
    }
    console.log(backedUp > 0 ? `✅ Backed up ${backedUp} file(s) to ${relative(process.cwd(), backupDir)}\n` : "ℹ️  No existing CRM data found to back up.\n");

    const localDistPath = resolve(process.cwd(), "dist");
    if (!existsSync(localDistPath)) {
      console.error("❌ dist/ folder not found. Build may have failed.");
      process.exit(1);
    }

    await client.ensureDir(config.remotePath);

    // Step 3: Upload critical files first (index.html etc.) so site never stays 403
    console.log("📤 Step 3: Uploading critical files first...");
    const critical = ["index.html", "favicon.ico", "favicon.svg", "robots.txt", "chatbot-config.js", "send.php"];
    for (const file of critical) {
      const localPath = join(localDistPath, file);
      if (existsSync(localPath)) {
        await uploadWithRetry(client, localPath, `${config.remotePath}/${file}`);
        console.log(`  ✅ ${file}`);
      }
    }

    // Step 4: Upload remaining dist files one-by-one with retries
    console.log("\n📤 Step 4: Uploading remaining dist files...");
    const allFiles = walkFiles(localDistPath);
    // Put index.html first, then other html, then assets
    allFiles.sort((a, b) => {
      if (a === "index.html") return -1;
      if (b === "index.html") return 1;
      if (a.endsWith(".html") && !b.endsWith(".html")) return -1;
      if (!a.endsWith(".html") && b.endsWith(".html")) return 1;
      return a.localeCompare(b);
    });

    let uploaded = 0;
    let failed = [];
    for (const rel of allFiles) {
      if (critical.includes(rel)) {
        uploaded++;
        continue; // already uploaded
      }
      const localPath = join(localDistPath, rel);
      const remotePath = `${config.remotePath}/${rel}`;
      try {
        await uploadWithRetry(client, localPath, remotePath);
        uploaded++;
        if (uploaded % 10 === 0) console.log(`  ... ${uploaded}/${allFiles.length} files`);
      } catch (err) {
        console.log(`  ❌ Failed: ${rel} — ${err.message}`);
        failed.push(rel);
      }
    }
    console.log(`✅ Uploaded ${uploaded}/${allFiles.length} files`);

    // Retry any failures once more
    if (failed.length > 0) {
      console.log(`\n🔁 Retrying ${failed.length} failed files...`);
      const stillFailed = [];
      for (const rel of failed) {
        try {
          await uploadWithRetry(client, join(localDistPath, rel), `${config.remotePath}/${rel}`, 5);
          console.log(`  ✅ ${rel}`);
        } catch (err) {
          console.log(`  ❌ Still failed: ${rel}`);
          stillFailed.push(rel);
        }
      }
      failed = stillFailed;
    }

    // Step 5: Upload additional root files
    console.log("\n📋 Step 5: Uploading additional files...");
    const additionalFiles = [
      { local: "public/send.php", remote: "send.php" },
      { local: "public/chatbot-config.js", remote: "chatbot-config.js" }
    ];
    for (const file of additionalFiles) {
      const localPath = resolve(process.cwd(), file.local);
      if (existsSync(localPath)) {
        await uploadWithRetry(client, localPath, `${config.remotePath}/${file.remote}`);
        console.log(`  ✅ ${file.remote}`);
      }
    }

    // Verify index.html exists remotely
    console.log("\n🔍 Verifying remote index.html...");
    const list = await client.list(config.remotePath);
    const hasIndex = list.some(f => f.name === "index.html");
    if (!hasIndex) {
      console.error("❌ index.html missing on server after upload!");
      process.exit(1);
    }
    console.log("✅ index.html confirmed on server");

    if (failed.length > 0) {
      console.log(`\n⚠️  Deployment finished with ${failed.length} failed non-critical files:`);
      failed.forEach(f => console.log(`   - ${f}`));
    } else {
      console.log("\n🎉 Deployment complete!");
    }
    console.log("🌐 https://sanctuaryshine.co.uk");

  } catch (error) {
    console.error("❌ Deployment failed:", error.message);
    process.exit(1);
  } finally {
    client.close();
  }
}

deploy();
