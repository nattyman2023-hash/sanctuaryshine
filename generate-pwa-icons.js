import sharp from "sharp";
import { readFileSync, writeFileSync } from "fs";
import { resolve } from "path";

const BRAND_TEAL = "#00C2D1";

function createIco(images) {
  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0);
  header.writeUInt16LE(1, 2);
  header.writeUInt16LE(images.length, 4);
  const entries = Buffer.alloc(images.length * 16);
  let offset = header.length + entries.length;
  images.forEach(({ size, data }, index) => {
    const entry = index * 16;
    entries.writeUInt8(size >= 256 ? 0 : size, entry);
    entries.writeUInt8(size >= 256 ? 0 : size, entry + 1);
    entries.writeUInt8(0, entry + 2);
    entries.writeUInt8(0, entry + 3);
    entries.writeUInt16LE(1, entry + 4);
    entries.writeUInt16LE(32, entry + 6);
    entries.writeUInt32LE(data.length, entry + 8);
    entries.writeUInt32LE(offset, entry + 12);
    offset += data.length;
  });
  return Buffer.concat([header, entries, ...images.map(({ data }) => data)]);
}

async function generatePwaIcons() {
  const logoPath = resolve(process.cwd(), "public/images/logo/logo.png");
  const outDir = resolve(process.cwd(), "public/icons");
  const logoMetadata = await sharp(logoPath).metadata();
  // Use the square house-and-sparkle mark; the full wordmark is too small to
  // remain legible in a browser tab or a phone app icon.
  const markSize = 560;
  const mark = await sharp(logoPath)
    .extract({
      left: Math.round(((logoMetadata.width || 1254) - markSize) / 2),
      top: 195,
      width: markSize,
      height: markSize,
    })
    .flatten({ background: "#FFFFFF" })
    .png()
    .toBuffer();

  const renderMark = (size) => sharp(mark).resize(size, size).png().toBuffer();
  const faviconImages = [];
  for (const size of [16, 32, 48]) {
    const data = await renderMark(size);
    faviconImages.push({ size, data });
  }
  writeFileSync(resolve(process.cwd(), "public/favicon.ico"), createIco(faviconImages));
  writeFileSync(resolve(process.cwd(), "public/favicon.png"), await renderMark(48));
  writeFileSync(resolve(process.cwd(), "public/apple-touch-icon.png"), await renderMark(180));

  for (const size of [192, 512]) {
    writeFileSync(resolve(outDir, `icon-${size}.png`), await renderMark(size));
    console.log(`Generated icon-${size}.png`);
  }

  // Maskable icons: shrink the mark onto a full-bleed teal canvas (matching the
  // logo's own circle colour) so any OS mask shape crops into flat colour, not artwork.
  for (const size of [192, 512]) {
    const logoSize = Math.round(size * 0.7);
    const logoPng = await sharp(mark).resize(logoSize, logoSize).png().toBuffer();
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

  console.log("\nFavicon, Apple touch icon and PWA icons generated from the supplied Sanctuary Shine logo.");
}

generatePwaIcons().catch(console.error);
