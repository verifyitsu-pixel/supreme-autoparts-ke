const { chromium } = require("playwright"),
  fs = require("fs"),
  assert = require("assert/strict");
(async () => {
  const b = await chromium.launch({ headless: true, args: ["--no-sandbox"] });
  let assertions = 0;
  const check = (v, msg) => {
    assert(v, msg);
    assertions++;
    console.log("PASS", msg);
  };
  try {
    const seed = JSON.parse(
      fs.readFileSync(
        process.env.SA_STAGE_SEED ||
          "/downloads/supreme-paypal/local-runtime/seed.json",
        "utf8",
      ),
    );
    for (const width of [390, 1280]) {
      const page = await b.newPage({ viewport: { width, height: 900 } });
      let externalSucceeded = [];
      page.on("response", (r) => {
        if (!r.url().startsWith("http://127.0.0.1:8097") && r.ok())
          externalSucceeded.push(r.url());
      });
      const shop = await page.goto("http://127.0.0.1:8097/?post_type=product", {
        waitUntil: "networkidle",
      });
      check(shop.status() === 200, `${width} native shop200`);
      check(
        (shop.headers()["content-security-policy"] || "").includes(
          "frame-src 'none'",
        ),
        `${width} empty-key CSP active`,
      );
      const link = page
        .locator('a[href*="add-to-cart="]')
        .filter({ hasText: "Add to cart" })
        .first();
      check(
        (await link.count()) === 1,
        `${width} real Woo add-to-cart present`,
      );
      console.log("ADDHREF", await link.getAttribute("href"));
      await page.goto("http://127.0.0.1:8097/?add-to-cart=" + seed.product_id, {
        waitUntil: "networkidle",
      });
      console.log(
        "ADDBODY",
        (await page.locator("body").innerText()).slice(0, 700),
      );
      await page.goto(`http://127.0.0.1:8097/?page_id=${seed.cart_page}`, {
        waitUntil: "networkidle",
      });
      console.log(
        "CARTBODY",
        (await page.locator("body").innerText()).slice(0, 1400),
      );
      check(
        (await page.locator("body").innerText()).includes(
          "Fictional sandbox test part",
        ),
        `${width} product persists in Woo cart`,
      );
      await page.screenshot({
        path: `${process.env.SA_STAGE_EVIDENCE || "/downloads/supreme-paypal"}/cart-native-${width}.png`,
        fullPage: true,
      });
      await page.goto(`http://127.0.0.1:8097/?page_id=${seed.checkout_page}`, {
        waitUntil: "networkidle",
      });
      check(
        (
          (await page.request.get(page.url())).headers()[
            "content-security-policy"
          ] || ""
        ).includes("frame-src 'none'"),
        `${width} checkout retains strict CSP`,
      );
      fs.writeFileSync(
        `${process.env.SA_STAGE_EVIDENCE || "/downloads/supreme-paypal"}/checkout-source.html`,
        await page.content(),
      );
      check(
        await page
          .locator(".payment_method_ppcp-gateway p")
          .evaluate((e) => e.getBoundingClientRect().width > 200),
        `${width} sandbox message readable full width`,
      );
      const body = await page.locator("body").innerText();
      fs.writeFileSync(
        `${process.env.SA_STAGE_EVIDENCE || "/downloads/supreme-paypal"}/cart-checkout-${width}.txt`,
        body,
      );
      check(
        body.includes("PayPal sandbox"),
        `${width} sandbox option in actual checkout`,
      );
      check(
        body.includes("Waiting for the sandbox client ID and secret"),
        `${width} empty-key state honest`,
      );
      check(
        (await page.locator('input[name="payment_method"]:enabled').count()) ===
          0,
        `${width} no enabled payment methods`,
      );
      check(
        await page
          .locator('button:has-text("Waiting for sandbox credentials")')
          .isDisabled(),
        `${width} payment button disabled`,
      );
      check(
        !(await page.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        )),
        `${width} checkout no horizontal overflow`,
      );
      await page.screenshot({
        path: `${process.env.SA_STAGE_EVIDENCE || "/downloads/supreme-paypal"}/cart-checkout-${width}.png`,
        fullPage: true,
      });
      await page
        .locator("#payment")
        .screenshot({
          path: `${process.env.SA_STAGE_EVIDENCE || "/downloads/supreme-paypal"}/cart-payment-${width}.png`,
        });
      const nonce = await page
        .locator('input[name="woocommerce-process-checkout-nonce"]')
        .inputValue();
      const deny = await page.request.post(
        "http://127.0.0.1:8097/?wc-ajax=checkout",
        {
          form: {
            "woocommerce-process-checkout-nonce": nonce,
            billing_first_name: "Fictional",
            billing_last_name: "Reviewer",
            billing_country: "US",
            billing_address_1: "1 Fixture Street",
            billing_city: "Portland",
            billing_state: "OR",
            billing_postcode: "97230",
            billing_email: "fixture@example.invalid",
            payment_method: "ppcp-gateway",
            terms: "on",
          },
        },
      );
      check(
        (await deny.text()).includes("PayPal sandbox credentials are empty"),
        `${width} forged checkout submission denied before order`,
      );
      const api = await page.request.post(
        "http://127.0.0.1:8097/?wc-ajax=ppc-create-order",
        { form: { amount: "1", currency: "USD" } },
      );
      check(api.status() === 503, `${width} direct PayPal create blocked503`);
      console.log("EXTERNAL", externalSucceeded);
      check(
        externalSucceeded.filter(
          (u) => !u.startsWith("blob:") && !u.startsWith("data:"),
        ).length === 0,
        `${width} no successful external browser resources`,
      );
      console.log("BODY", body.slice(-1400));
      await page.close();
    }
    console.log(
      `PASS ${assertions} real local WordPress/WooCommerce browser assertions, no PayPal payment`,
    );
  } finally {
    await b.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
