import sharp from "sharp";
import { readFileSync } from "fs";
import { resolve } from "path";

async function generateLogos() {
  const svgPath = resolve(process.cwd(), "public/images/logo/logo.svg");
  const svgBuffer = readFileSync(svgPath);

  // Generate transparent PNG at 512x512
  await sharp(svgBuffer)
    .resize(512, 512)
    .png({ transparency: true })
    .toFile(resolve(process.cwd(), "public/images/logo/logo-transparent.png"));
  
  console.log("✅ Generated: logo-transparent.png (512x512, transparent background)");

  // Generate transparent PNG at 256x256
  await sharp(svgBuffer)
    .resize(256, 256)
    .png({ transparency: true })
    .toFile(resolve(process.cwd(), "public/images/logo/logo-256.png"));
  
  console.log("✅ Generated: logo-256.png (256x256, transparent background)");

  // Generate transparent PNG at 128x128
  await sharp(svgBuffer)
    .resize(128, 128)
    .png({ transparency: true })
    .toFile(resolve(process.cwd(), "public/images/logo/logo-128.png"));
  
  console.log("✅ Generated: logo-128.png (128x128, transparent background)");

  // Generate favicon PNG
  await sharp(svgBuffer)
    .resize(64, 64)
    .png({ transparency: true })
    .toFile(resolve(process.cwd(), "public/images/logo/favicon.png"));
  
  console.log("✅ Generated: favicon.png (64x64, transparent background)");

  console.log("\n🎉 All logo files generated successfully!");
}

generateLogos().catch(console.error);