import * as ftp from "basic-ftp";
import { readFileSync, writeFileSync } from "fs";
import { join } from "path";

function loadEnv() {
  const content = readFileSync(join(process.cwd(), ".env"), "utf-8");
  const env = {};
  content.split("\n").forEach(line => {
    const i = line.indexOf("=");
    if (i > 0) env[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  });
  return env;
}

async function main() {
  const env = loadEnv();
  const client = new ftp.Client(60000);
  await client.access({
    host: env.FTP_HOST,
    user: env.FTP_USER,
    password: env.FTP_PASS,
    port: parseInt(env.FTP_PORT || "21"),
    secure: false
  });
  await client.cd("/public_html");

  // Upload fixed chatbot config
  await client.uploadFrom(join(process.cwd(), "public", "chatbot-config.js"), "chatbot-config.js");
  console.log("Uploaded chatbot-config.js");

  // Ensure .env is present
  await client.uploadFrom(join(process.cwd(), ".env"), ".env");
  console.log("Uploaded .env");

  // Ensure proxy is present
  await client.uploadFrom(join(process.cwd(), "public", "api", "chatbot-proxy.php"), "api/chatbot-proxy.php");
  console.log("Uploaded chatbot-proxy.php");

  // Better .htaccess
  const htaccess = [
    "DirectoryIndex index.html index.php",
    "Options -Indexes",
    "<IfModule mod_rewrite.c>",
    "  RewriteEngine On",
    "  RewriteCond %{REQUEST_FILENAME} -f [OR]",
    "  RewriteCond %{REQUEST_FILENAME} -d",
    "  RewriteRule ^ - [L]",
    "  RewriteRule ^ index.html [L]",
    "</IfModule>",
    ""
  ].join("\n");
  writeFileSync(join(process.cwd(), "dist", ".htaccess"), htaccess);
  await client.uploadFrom(join(process.cwd(), "dist", ".htaccess"), ".htaccess");
  console.log("Uploaded .htaccess");

  client.close();

  // Test OpenRouter proxy
  const body = JSON.stringify({
    model: "meta-llama/llama-3.2-3b-instruct:free",
    messages: [
      { role: "system", content: "Reply in one short sentence." },
      { role: "user", content: "Say hello briefly" }
    ],
    max_tokens: 50,
    temperature: 0.7
  });

  console.log("\nTesting chatbot proxy...");
  const res = await fetch("https://sanctuaryshine.co.uk/api/chatbot-proxy.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body
  });
  console.log("Status:", res.status);
  const text = await res.text();
  console.log("Body:", text.slice(0, 800));

  // Also verify config is live
  const cfg = await fetch("https://sanctuaryshine.co.uk/chatbot-config.js");
  const cfgText = await cfg.text();
  console.log("\nConfig endpoint status:", cfg.status);
  console.log("Config has .php endpoint:", cfgText.includes("chatbot-proxy.php"));
}

main().catch(e => {
  console.error(e);
  process.exit(1);
});
