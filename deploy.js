import * as ftp from "basic-ftp";
import { readFileSync, existsSync } from "fs";
import { join, resolve } from "path";
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

const env = loadEnv();

const config = {
  host: env.FTP_HOST,
  user: env.FTP_USER,
  password: env.FTP_PASS,
  port: parseInt(env.FTP_PORT || "21"),
  remotePath: env.FTP_PATH || "/public_html"
};

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
  const client = new ftp.Client(30000); // 30 second timeout
  
  client.ftp.verbose = true;

  try {
    await client.access({
      host: config.host,
      user: config.user,
      password: config.password,
      port: config.port,
      secure: false
    });
    console.log("✅ Connected to FTP server!\n");

    // Step 3: Upload dist/ folder
    console.log("📤 Step 3: Uploading files to public_html...");
    const localDistPath = resolve(process.cwd(), "dist");
    
    if (!existsSync(localDistPath)) {
      console.error("❌ dist/ folder not found. Build may have failed.");
      process.exit(1);
    }

    // Ensure we're in the right directory
    await client.ensureDir(config.remotePath);
    
    // Upload all files from dist/
    console.log(`Uploading from: ${localDistPath}`);
    console.log(`Uploading to: ${config.remotePath}`);
    
    await client.uploadFromDir(localDistPath, config.remotePath);
    console.log("✅ All files uploaded!\n");

    // Step 4: Upload additional files (send.php, chatbot-config.js, .env for chatbot proxy)
    console.log("📋 Step 4: Uploading additional files...");
    
    const additionalFiles = [
      { local: "public/send.php", remote: "send.php" },
      { local: "public/chatbot-config.js", remote: "chatbot-config.js" },
      { local: ".env", remote: ".env" }
    ];

    for (const file of additionalFiles) {
      const localPath = resolve(process.cwd(), file.local);
      if (existsSync(localPath)) {
        await client.uploadFrom(localPath, `${config.remotePath}/${file.remote}`);
        console.log(`  ✅ ${file.remote}`);
      }
    }


    console.log("\n🎉 Deployment complete!");
    console.log(`🌐 Your site should now be live at your domain.`);
    
  } catch (error) {
    console.error("❌ Deployment failed:", error.message);
    process.exit(1);
  } finally {
    client.close();
  }
}

deploy();