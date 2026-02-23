const fs = require('fs');
const path = require('path');

function pickExecutablePath() {
  const envPath =
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ||
    process.env.PUPPETEER_EXECUTABLE_PATH ||
    process.env.CHROME_PATH ||
    process.env.CHROME_BIN;

  const candidates = [
    envPath,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ];

  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate)) {
      return candidate;
    }
  }

  return null;
}

async function main() {
  const [, , url, outputPathRaw] = process.argv;

  if (!url || !outputPathRaw) {
    console.log('Usage: node savepdf.js <url> <outputPath>');
    process.exit(2);
  }

  const outputPath = path.resolve(outputPathRaw);
  const outputDir = path.dirname(outputPath);
  fs.mkdirSync(outputDir, { recursive: true });

  let playwright;
  let puppeteer;
  let engine = 'playwright';
  try {
    playwright = require('playwright');
  } catch (error) {
    try {
      playwright = require('playwright-core');
    } catch (innerError) {
      try {
        puppeteer = require('puppeteer');
        engine = 'puppeteer';
      } catch (puppeteerError) {
        throw new Error(
          'Neither Playwright nor Puppeteer is installed. Run "npm i playwright" (or "npm i puppeteer") in C:\\xampp\\htdocs\\DHSUD.'
        );
      }
    }
  }

  const launchOptions = {
    headless: true,
    args: [
      '--disable-gpu',
      '--disable-dev-shm-usage',
      '--no-sandbox',
      '--disable-setuid-sandbox',
    ],
  };

  const executablePath = pickExecutablePath();
  if (executablePath) {
    launchOptions.executablePath = executablePath;
  }

  const browser = engine === 'playwright'
    ? await playwright.chromium.launch(launchOptions)
    : await puppeteer.launch(launchOptions);
  try {
    let context = null;
    let page;
    if (engine === 'playwright') {
      context = await browser.newContext({
        ignoreHTTPSErrors: true,
        userAgent:
          'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        viewport: { width: 1280, height: 720 },
        extraHTTPHeaders: { 'Accept-Language': 'en-US,en;q=0.9' },
      });
      page = await context.newPage();
      await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
      await page.waitForTimeout(2000);
    } else {
      page = await browser.newPage();
      await page.setUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
      );
      await page.setViewport({ width: 1280, height: 720 });
      await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
      await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
      await new Promise((resolve) => setTimeout(resolve, 2000));
    }

    await page.pdf({
      path: outputPath,
      printBackground: true,
      format: 'A4',
    });

    if (context) {
      await context.close();
    }
    console.log(`Saved PDF: ${outputPath}`);
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.log(`Error saving PDF: ${error && error.stack ? error.stack : error}`);
  process.exit(1);
});
