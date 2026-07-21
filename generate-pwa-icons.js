import sharp from "sharp";
import { readFileSync } from "fs";
import { resolve } from "path";

const BRAND_TEAL = "#00C2D1";

async function generatePwaIcons() {
  const svgPath = resolve(process.cwd(), "public/images/logo/logo.svg");
  const svgBuffer = readFileSync(svgPath);
  const outDir = resolve(process.cwd(), "public/icons");

  for (const size of [192, 512]) {
    await sharp(svgBuffer)
      .resize(size, size)
      .png()
      .toFile(resolve(outDir, `icon-${size}.png`));
    console.log(`Generated icon-${size}.png`);
  }

  // Maskable icons: shrink the mark onto a full-bleed teal canvas (matching the
  // logo's own circle colour) so any OS mask shape crops into flat colour, not artwork.
  for (const size of [192, 512]) {
    const logoSize = Math.round(size * 0.7);
    const logoPng = await sharp(svgBuffer).resize(logoSize, logoSize).png().toBuffer();
    await sharp({
      create: {
        width: size,
        height: size,
        channels: 4,
        background: BRAND_TEAL,
      },
    })
      .composite([{ input: logoPng, gravity: "center" }])
      .png()
      .toFile(resolve(outDir, `icon-maskable-${size}.png`));
    console.log(`Generated icon-maskable-${size}.png`);
  }

  console.log("\nAll PWA icons generated in public/icons/.");
}

generatePwaIcons().catch(console.error);
