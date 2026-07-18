import * as ftp from "basic-ftp";
import { readFileSync, existsSync } from "fs";
import { join, resolve } from "path";

function loadEnv() {
  const envPath = join(process.cwd(), ".env");
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

const env = loadEnv();
const client = new ftp.Client(60000);
client.ftp.verbose = true;

async function main() {
  console.log("Connecting to", env.FTP_HOST, "as", env.FTP_USER);
  await client.access({
    host: env.FTP_HOST,
    user: env.FTP_USER,
    password: env.FTP_PASS,
    port: parseInt(env.FTP_PORT || "21"),
    secure: false
  });

  console.log("\n=== PWD ===");
  console.log(await client.pwd());

  console.log("\n=== LIST / ===");
  console.log((await client.list("/")).map(f => `${f.isDirectory ? "[D]" : "[F]"} ${f.name}`).join("\n"));

  for (const path of ["/public_html", "/domains", "/domains/sanctuaryshine.co.uk", "/domains/sanctuaryshine.co.uk/public_html"]) {
    try {
      console.log(`\n=== LIST ${path} ===`);
      const list = await client.list(path);
      console.log(list.map(f => `${f.isDirectory ? "[D]" : "[F]"} ${f.name}`).join("\n") || "(empty)");
    } catch (e) {
      console.log(`Cannot list ${path}: ${e.message}`);
    }
  }

  // Upload dist to configured path
  const remotePath = env.FTP_PATH || "/public_html";
  const localDist = resolve(process.cwd(), "dist");
  console.log(`\n=== UPLOADING dist -> ${remotePath} ===`);
  console.log("Local dist exists:", existsSync(localDist));
  await client.ensureDir(remotePath);
  await client.uploadFromDir(localDist, remotePath);


  // Extra files
  for (const file of [
    { local: "public/send.php", remote: "send.php" },
    { local: "public/chatbot-config.js", remote: "chatbot-config.js" },
    { local: ".env", remote: ".env" }
  ]) {
    const localPath = resolve(process.cwd(), file.local);
    if (existsSync(localPath)) {
      await client.uploadFrom(localPath, `${remotePath}/${file.remote}`);
      console.log("Uploaded", file.remote);
    }
  }

  console.log(`\n=== LIST ${remotePath} AFTER UPLOAD ===`);
  console.log((await client.list(remotePath)).map(f => `${f.isDirectory ? "[D]" : "[F]"} ${f.name}`).join("\n"));

  console.log("\nDone.");
  client.close();
}

main().catch(err => {
  console.error("FAILED:", err);
  client.close();
  process.exit(1);
});
