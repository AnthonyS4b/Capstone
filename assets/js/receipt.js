/**
 * receipt.js — one receipt layout for the whole system.
 * Used by the POS (after a sale) and Sales Transactions (viewing past sales),
 * on screen and when printing.
 *
 *   Receipt.html({
 *       number: 'TRX-001527', date: Date|string, cashier: 'Name',
 *       items: [{ name, price, quantity }],   // price = unit price charged
 *       total, payment, change,
 *       method: 'cash' | 'gcash', reference: '123456',
 *       status: 'completed' | 'voided'
 *   })  → HTML string
 *
 *   Receipt.print(html)  → prints that receipt from a hidden frame (no new tab)
 */
(function () {
    // Receipt printer paper. OJ-58K / POS-58 rolls are 58 mm wide with about
    // 48 mm printable; for an 80 mm printer set 80 and 72.
    const PAPER_MM = 58;
    const PRINT_WIDTH_MM = 48;

    const CSS = `
.rc { font-family: 'Inter', -apple-system, 'Segoe UI', Arial, sans-serif; color: #16221a; font-size: 13px; line-height: 1.45; font-variant-numeric: tabular-nums; position: relative; }
.rc-store { text-align: center; padding-bottom: 12px; }
.rc-logo { width: 40px; height: 40px; object-fit: contain; display: block; margin: 0 auto 6px; }
.rc-store-name { font-size: 15px; font-weight: 700; color: #2c5530; margin: 0; }
.rc-store-sub { font-size: 11.5px; color: #61706a; margin: 0; }
.rc-meta { display: grid; grid-template-columns: auto 1fr; gap: 2px 12px; padding: 10px 0; margin: 0; border-top: 1px dashed #cfd4cf; border-bottom: 1px dashed #cfd4cf; font-size: 12px; }
.rc-meta dt { color: #61706a; font-weight: 500; margin: 0; }
.rc-meta dd { margin: 0; text-align: right; }
.rc-items { padding: 8px 0; border-bottom: 1px dashed #cfd4cf; }
.rc-item { padding: 5px 0; }
.rc-item-name { font-weight: 500; overflow-wrap: anywhere; }
.rc-item-line { display: flex; justify-content: space-between; gap: 12px; color: #61706a; font-size: 12px; }
.rc-item-line span:last-child { color: #16221a; font-weight: 600; font-size: 13px; }
.rc-totals { padding: 8px 0 4px; }
.rc-row { display: flex; justify-content: space-between; gap: 12px; padding: 2px 0; }
.rc-row.rc-muted { color: #61706a; font-size: 12px; }
.rc-row.rc-total { font-size: 16px; font-weight: 700; padding: 8px 0; margin: 6px 0; border-top: 1px solid #16221a; border-bottom: 1px solid #16221a; }
.rc-row.rc-change span:last-child { font-weight: 700; }
.rc-thanks { text-align: center; padding-top: 12px; margin-top: 8px; border-top: 1px dashed #cfd4cf; font-size: 12px; color: #61706a; }
.rc-thanks strong { display: block; color: #16221a; font-size: 13px; }
.rc-void { text-align: center; margin: 0 0 10px; padding: 6px; border: 2px solid #b42318; border-radius: 6px; color: #b42318; font-weight: 800; letter-spacing: 0.2em; font-size: 13px; }
.rc.is-voided .rc-items, .rc.is-voided .rc-totals { opacity: 0.55; }
`;

    function esc(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDate(value) {
        const d = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
        if (isNaN(d)) return esc(value);
        return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
            + ' · ' + d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    }

    // Inject once so the on-screen receipt uses the same rules as the printed one
    if (!document.getElementById('receiptStyles')) {
        const style = document.createElement('style');
        style.id = 'receiptStyles';
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    function html(r) {
        const items = r.items || [];
        const total = Number(r.total) || 0;
        const isGcash = r.method === 'gcash';
        const voided = r.status === 'voided';

        // Prices are VAT-inclusive (12%, Philippines)
        const subtotal = total / 1.12;
        const vat = total - subtotal;
        const units = items.reduce((n, i) => n + Number(i.quantity || 0), 0);

        const itemsHtml = items.length
            ? items.map(i => `
                <div class="rc-item">
                    <div class="rc-item-name">${esc(i.name)}</div>
                    <div class="rc-item-line">
                        <span>${Number(i.quantity)} × ${Number(i.price) === 0 ? 'Free' : peso(i.price)}</span>
                        <span>${peso(Number(i.price) * Number(i.quantity))}</span>
                    </div>
                </div>`).join('')
            : '<div class="rc-item rc-muted">No items recorded</div>';

        const paymentRows = isGcash
            ? `<div class="rc-row"><span>Paid via GCash</span><span>${peso(r.payment ?? total)}</span></div>
               ${r.reference ? `<div class="rc-row rc-muted"><span>Reference no.</span><span>${esc(r.reference)}</span></div>` : ''}`
            : `<div class="rc-row"><span>Cash</span><span>${peso(r.payment)}</span></div>
               <div class="rc-row rc-change"><span>Change</span><span>${peso(r.change)}</span></div>`;

        return `
            <div class="rc${voided ? ' is-voided' : ''}">
                <div class="rc-store">
                    <img class="rc-logo" src="assets/images/logo-mark.png" alt="">
                    <p class="rc-store-name">Espenida's Pet &amp; Poultry Supply</p>
                    <p class="rc-store-sub">Sales receipt</p>
                </div>
                ${voided ? '<div class="rc-void">VOIDED</div>' : ''}
                <dl class="rc-meta">
                    <dt>Receipt no.</dt><dd>${esc(r.number || '—')}</dd>
                    <dt>Date</dt><dd>${formatDate(r.date)}</dd>
                    <dt>Cashier</dt><dd>${esc(r.cashier || 'Staff')}</dd>
                </dl>
                <div class="rc-items">${itemsHtml}</div>
                <div class="rc-totals">
                    <div class="rc-row rc-muted"><span>Subtotal (VAT excl.)</span><span>${peso(subtotal)}</span></div>
                    <div class="rc-row rc-muted"><span>VAT 12%</span><span>${peso(vat)}</span></div>
                    <div class="rc-row rc-total"><span>Total</span><span>${peso(total)}</span></div>
                    ${paymentRows}
                </div>
                <div class="rc-thanks"><strong>Thank you for shopping!</strong>${units} item${units === 1 ? '' : 's'} sold</div>
            </div>`;
    }

    // Thermal printers only print black dots: greys come out speckled or faint,
    // so the printed copy is pure black, bolder, and exactly the roll's printable width.
    const THERMAL_CSS = `
/* No page size: the printer driver's roll size (e.g. 58 x 3276 mm) applies */
@page { margin: 0; }
html, body { margin: 0; padding: 0; background: #fff; }
body { width: ${PAPER_MM}mm; }
.rc { width: ${PRINT_WIDTH_MM}mm; margin: 0 auto; padding: 2mm 0 6mm;
      font-family: Arial, 'Segoe UI', sans-serif; font-size: 11px; line-height: 1.35; color: #000; }
.rc * { color: #000 !important; }
.rc-store { padding-bottom: 6px; }
.rc-logo { width: 28px; height: 28px; margin-bottom: 3px; filter: grayscale(1) contrast(1.6) brightness(1.15); }
.rc-store-name { font-size: 13px; }
.rc-store-sub, .rc-meta, .rc-item-line, .rc-row.rc-muted, .rc-thanks { font-size: 10.5px; }
.rc-meta { gap: 1px 6px; padding: 5px 0; border-color: #000; }
.rc-meta dt { font-weight: 400; }
.rc-items { padding: 4px 0; border-color: #000; }
.rc-item { padding: 3px 0; }
.rc-item-name { font-weight: 700; }
.rc-item-line span:last-child { font-size: 11px; }
.rc-totals { padding: 4px 0 2px; }
.rc-row { gap: 6px; padding: 1px 0; }
.rc-row.rc-total { font-size: 14px; padding: 4px 0; margin: 4px 0; border-color: #000; }
.rc-thanks { padding-top: 6px; margin-top: 4px; border-color: #000; }
.rc-thanks strong { font-size: 11.5px; }
.rc-void { margin-bottom: 6px; padding: 3px; border-color: #000; font-size: 12px; }
.rc.is-voided .rc-items, .rc.is-voided .rc-totals { opacity: 1; text-decoration: line-through; }
@media screen { body { padding: 12px 0; background: #eee; } .rc { background: #fff; padding: 3mm 2mm; } }
`;

    // Prints from a hidden iframe on the current page, so no new tab opens.
    // Browsers always show their print dialog; to skip it and print straight to
    // the default printer, run Chrome with --kiosk-printing on the POS machine.
    let printing = false;

    function print(receiptHtml) {
        if (!receiptHtml || printing) return;
        printing = true;

        document.getElementById('receiptPrintFrame')?.remove();
        const frame = document.createElement('iframe');
        frame.id = 'receiptPrintFrame';
        frame.setAttribute('aria-hidden', 'true');
        // Off-screen but with a real size: Chrome can print a 0x0 or hidden frame blank
        frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:400px;height:600px;border:0;';
        document.body.appendChild(frame);

        let done = false;
        const cleanup = () => {
            if (done) return;
            done = true;
            printing = false;
            // Removing the frame before the print job is spooled cancels it
            setTimeout(() => frame.remove(), 1000);
        };

        frame.onload = function () {
            const win = frame.contentWindow;
            win.onafterprint = cleanup;
            win.focus();
            win.print();
            // Chrome blocks in print() until the dialog closes; this covers
            // browsers that don't fire afterprint
            setTimeout(cleanup, 60000);
        };

        // srcdoc fires onload after the logo image has loaded, so it prints.
        // <base> keeps the logo's relative path working inside the frame.
        frame.srcdoc = `<!DOCTYPE html><html>
            <head>
                <meta charset="UTF-8">
                <base href="${location.href.replace(/[^/]*$/, '')}">
                <title>Receipt</title>
                <style>
                    ${CSS}
                    ${THERMAL_CSS}
                </style>
            </head>
            <body>${receiptHtml}</body>
        </html>`;
    }

    window.Receipt = { html, print, peso, formatDate };
})();
