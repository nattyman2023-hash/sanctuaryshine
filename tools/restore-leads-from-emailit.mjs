import * as ftp from 'basic-ftp';
import { createHash } from 'crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'fs';
import { dirname, join, resolve } from 'path';

function loadEnv() {
  const values = {};
  for (const rawLine of readFileSync(join(process.cwd(), '.env'), 'utf8').split(/\r?\n/)) {
    const line = rawLine.trim();
    const separator = line.indexOf('=');
    if (separator > 0) values[line.slice(0, separator).trim()] = line.slice(separator + 1).trim();
  }
  return values;
}

function loadEmailitKey() {
  const config = readFileSync(join(process.cwd(), 'public', 'crm-config.php'), 'utf8');
  const match = config.match(/'emailit_api_key'\s*=>\s*'([^']+)'/);
  if (!match?.[1]) throw new Error('Emailit API key was not found in local CRM configuration.');
  return match[1];
}

function quotedPrintableDecode(value) {
  return String(value || '')
    .replace(/=\r?\n/g, '')
    .replace(/=([0-9a-f]{2})/gi, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
}

function htmlDecode(value) {
  return String(value || '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'");
}

function cleanText(value) {
  return htmlDecode(quotedPrintableDecode(value))
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function field(html, labels) {
  for (const label of labels) {
    const pattern = new RegExp(`<td\\b[^>]*>\\s*${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*</td>\\s*<td\\b[^>]*>([\\s\\S]*?)</td>`, 'i');
    const match = html.match(pattern);
    if (match?.[1]) return cleanText(match[1]);
  }
  return '';
}

function classify(subject) {
  if (/^New Quote Request from /i.test(subject)) return 'quote';
  if (/^New Booking(?: Request)? from /i.test(subject)) return 'booking';
  if (/^New (?:Website )?Enquiry from /i.test(subject)) return 'inquiry';
  return '';
}

async function getJson(url, headers) {
  if (url.startsWith('/')) url = `https://api.emailit.com${url}`;
  const response = await fetch(url, { headers });
  if (!response.ok) throw new Error(`Emailit request failed (${response.status}) for ${url}`);
  return response.json();
}

async function fetchAdminEmails(headers) {
  const summaries = [];
  let nextUrl = 'https://api.emailit.com/v2/emails?limit=100';
  for (let page = 0; nextUrl && page < 100; page += 1) {
    const result = await getJson(nextUrl, headers);
    summaries.push(...(Array.isArray(result.data) ? result.data : []));
    nextUrl = result.next_page_url || '';
  }

  const candidates = summaries.filter((email) => classify(String(email.subject || '')));
  const recovered = [];
  for (let index = 0; index < candidates.length; index += 5) {
    const batch = candidates.slice(index, index + 5);
    const details = await Promise.all(batch.map((email) => getJson(`https://api.emailit.com/v2/emails/${email.id}`, headers)));
    for (let item = 0; item < batch.length; item += 1) {
      const summary = batch[item];
      const detail = details[item];
      const subject = String(summary.subject || '');
      const type = classify(subject);
      const html = String(detail.body?.html || '');
      const name = field(html, ['Name']) || subject.replace(/^New (?:Quote Request|Booking(?: Request)?|Website Enquiry|Contact Enquiry) from /i, '').trim();
      const email = field(html, ['Email']).toLowerCase();
      if (!name || !email.includes('@') || /\btest\b/i.test(name) || /\btest\b/i.test(subject)) continue;
      const message = field(html, ['Message', 'Customer Message']);
      const preferredDate = field(html, ['Preferred Date', 'Service Date']);
      const cleaningType = field(html, ['Service', 'Cleaning Service']);
      const propertySize = field(html, ['Property Size', 'Size', 'Bedrooms / Offices']);
      const postcode = field(html, ['Postcode', 'Postal Code']);
      const createdAt = String(summary.created_at || new Date().toISOString());
      const dedupeKey = [type, email, preferredDate, message, createdAt.slice(0, 13)].join('|').toLowerCase();
      recovered.push({
        id: 'lead_emailit_' + createHash('sha256').update(dedupeKey).digest('hex').slice(0, 20),
        type,
        status: 'new',
        created_at: createdAt,
        updated_at: createdAt,
        name,
        email,
        phone: field(html, ['Phone', 'Telephone']),
        postcode,
        subject: type === 'booking' ? 'booking' : (type === 'quote' ? 'quote' : 'enquiry'),
        cleaning_type: cleaningType,
        property_type: '',
        size: propertySize,
        preferred_date: preferredDate,
        message,
        email_status: 'emailit-recovered',
        notes: 'Recovered from the Sanctuary Shine admin email archive.',
      });
    }
    console.log(`Processed ${Math.min(index + 5, candidates.length)}/${candidates.length} admin emails`);
  }

  const unique = new Map();
  for (const lead of recovered) unique.set(lead.id, lead);
  return [...unique.values()].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)));
}

async function uploadToCrmData(filePath, env) {
  const client = new ftp.Client(90000);
  client.ftp.verbose = false;
  await client.access({ host: env.FTP_HOST, user: env.FTP_USER, password: env.FTP_PASS, port: parseInt(env.FTP_PORT || '21', 10), secure: false });
  const root = await client.list('.');
  const webRoot = root.some((item) => item.name === 'public_html' && item.isDirectory) ? 'public_html' : '.';
  await client.cd(webRoot === '.' ? await client.pwd() : '/public_html');
  const current = await client.pwd();
  await client.ensureDir('crm-data');
  await client.cd(current);
  await client.uploadFrom(filePath, 'crm-data/leads.json');
  await client.uploadFrom(filePath, 'crm-data/leads.json.bak');
  client.close();
}

const env = loadEnv();
const headers = { Authorization: `Bearer ${loadEmailitKey()}` };
const leads = await fetchAdminEmails(headers);
const outputDirectory = resolve(process.cwd(), 'backups', 'recovered-crm-data');
mkdirSync(outputDirectory, { recursive: true });
const outputPath = join(outputDirectory, 'leads.json');
writeFileSync(outputPath, JSON.stringify(leads, null, 2));
console.log(`Recovered ${leads.length} unique lead(s) to ${outputPath}`);
if (process.argv.includes('--upload')) {
  await uploadToCrmData(outputPath, env);
  console.log('Uploaded leads.json and leads.json.bak to the live CRM data directory.');
}
