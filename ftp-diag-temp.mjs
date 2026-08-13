import * as ftp from "basic-ftp";
import { readFileSync } from "fs";
import { join } from "path";

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

const env = loadEnv();
const client = new ftp.Client(60000);
client.ftp.verbose = false;

async function main() {
  await client.access({
    host: env.FTP_HOST,
    user: env.FTP_USER,
    password: env.FTP_PASS,
    port: parseInt(env.FTP_PORT || "21"),
    secure: false
  });
  console.log("PWD:", await client.pwd());
  console.log("\n=== Raw LIST (root) ===");
  const list = await client.list(".");
  for (const f of list) {
    console.log(JSON.stringify({ name: f.name, type: f.type, size: f.size, permissions: f.permissions, group: f.group, user: f.user }));
  }
  console.log("\n=== Download .htaccess ===");
  try {
    const dest = "C:/Users/Natty/AppData/Local/Temp/claude/c--Users-Natty-Desktop-Anti-Grav-Sanctuary-Shine/9118bc03-c41f-4dcf-b80b-1a7dea8f8c7d/scratchpad/remote-htaccess.txt";
    await client.downloadTo(dest, ".htaccess");
    console.log(readFileSync(dest, "utf-8"));
  } catch (e) {
    console.log("Could not download .htaccess:", e.message);
  }
  console.log("\n=== Download index.html (first 500 chars) ===");
  try {
    const dest2 = "C:/Users/Natty/AppData/Local/Temp/claude/c--Users-Natty-Desktop-Anti-Grav-Sanctuary-Shine/9118bc03-c41f-4dcf-b80b-1a7dea8f8c7d/scratchpad/remote-index.html";
    await client.downloadTo(dest2, "index.html");
    console.log(readFileSync(dest2, "utf-8").slice(0, 500));
  } catch (e) {
    console.log("Could not download index.html:", e.message);
  }
  client.close();
}

main().catch(err => {
  console.error("FAILED:", err);
  process.exit(1);
});
