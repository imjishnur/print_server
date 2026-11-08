/**
 * pos-canvas-renderer.js
 * Standalone High-Definition Thermal Receipt & KOT Canvas Rendering Engine (80mm / 576px)
 * Generates crisp, bilingual (Arabic & English) receipts as Base64 PNG images
 * for direct printing to ESC/POS print servers or QZ Tray.
 */
(function (window) {
    'use strict';

    function hasArabic(text) {
        if (!text || typeof text !== 'string') return false;
        return /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/.test(text);
    }

    function drawDottedLine(ctx, x1, y, x2) {
        ctx.save();
        ctx.strokeStyle = '#000000';
        ctx.lineWidth = 1.5;
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        ctx.moveTo(x1, y);
        ctx.lineTo(x2, y);
        ctx.stroke();
        ctx.restore();
    }

    function wrapCenteredText(ctx, text, x, y, maxWidth, lineHeight) {
        var words = text.split(' ');
        var line = '';
        var currentY = y;
        for (var n = 0; n < words.length; n++) {
            var testLine = line + words[n] + ' ';
            var metrics = ctx.measureText(testLine);
            if (metrics.width > maxWidth && n > 0) {
                ctx.fillText(line.trim(), x, currentY);
                line = words[n] + ' ';
                currentY += lineHeight;
            } else {
                line = testLine;
            }
        }
        ctx.fillText(line.trim(), x, currentY);
        return currentY + lineHeight;
    }

    /**
     * Render complete bilingual Arabic & English receipt onto a 576px thermal canvas
     * @param {Object} batch The JSON receipt data batch
     * @param {String} type 'INVOICE', 'BILL', 'KOT', etc.
     * @returns {Promise<String>} Resolves with the pure Base64 PNG string (without data:image/png;base64, prefix)
     */
    function renderArabicReceiptCanvas(batch, type) {
        var paperWidth = 576; // 80mm standard dot width
        var padding = 16;
        var contentWidth = paperWidth - (padding * 2);

        // Pre-create tall work canvas
        var canvas = document.createElement('canvas');
        canvas.width = paperWidth;
        canvas.height = 3600;
        var ctx = canvas.getContext('2d');

        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, paperWidth, 3600);
        ctx.fillStyle = '#000000';
        ctx.textBaseline = 'top';

        var y = 6;

        // 1. Draw Logo if present (BILL only or if KOT has explicit logo)
        var isKot = (type && type.toUpperCase() === 'KOT') || (batch.print_type && batch.print_type.toUpperCase() === 'KOT');
        var logoPromise = Promise.resolve();
        var logoSrc = '';
        if (!isKot || batch.print_kot_logo === 'Yes' || batch.print_kot_logo === true) {
            logoSrc = batch.logo_base64 ? ('data:image/png;base64,' + batch.logo_base64) : (batch.logo_url || '');
        }

        if (logoSrc && logoSrc.length > 5) {
            logoPromise = new Promise(function (resolve) {
                var img = new Image();
                img.crossOrigin = 'Anonymous';
                img.onload = function () {
                    var maxW = 380;
                    var origW = img.naturalWidth || img.width || 300;
                    var origH = img.naturalHeight || img.height || 100;
                    var targetW = Math.min(origW, maxW);
                    var targetH = Math.round(origH * (targetW / origW));
                    var offsetX = Math.round((paperWidth - targetW) / 2);
                    ctx.drawImage(img, offsetX, y, targetW, targetH);
                    y += targetH + 12;
                    resolve();
                };
                img.onerror = function () {
                    resolve();
                };
                img.src = logoSrc;
            });
        }

        return logoPromise.then(function () {
            var fontMain = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Tahoma, Arial, "Noto Sans Arabic", sans-serif';
            // currency from Master settings > Finance > Currency symbol (sent in the bill data)
            var cur = batch.currency ? String(batch.currency).trim() + ' ' : '';

            function drawRow(left, right, rowY, opts) {
                opts = opts || {};
                var fSize = opts.size || 19;
                ctx.font = (opts.bold ? 'bold ' : '') + fSize + 'px ' + fontMain;
                ctx.fillStyle = opts.color || '#000000';

                ctx.textAlign = 'left';
                ctx.fillText(left, padding, rowY);

                if (right !== undefined && right !== null && right !== '') {
                    ctx.font = (opts.boldRight || opts.bold ? 'bold ' : '') + fSize + 'px ' + fontMain;
                    ctx.textAlign = 'right';
                    ctx.fillText(String(right), paperWidth - padding, rowY);
                }
                return rowY + (opts.lineHeight || 27);
            }

            // Wrap text (keeping its line breaks) to a width; returns the lines
            function wrapToLines(text, maxW) {
                var out = [];
                String(text || '').split(/\r?\n/).forEach(function (part) {
                    var words = part.trim().split(/\s+/), line = '';
                    for (var wi = 0; wi < words.length; wi++) {
                        if (!words[wi]) continue;
                        var test = line ? line + ' ' + words[wi] : words[wi];
                        if (ctx.measureText(test).width > maxW && line) { out.push(line); line = words[wi]; } else { line = test; }
                    }
                    if (line) out.push(line);
                });
                return out;
            }

            var hasArabicHeader = !isKot && (batch.store_name_ar || batch.address_ar);
            if (hasArabicHeader) {
                // 2+3. Bilingual header: English name + address on the left, Arabic on the right
                var colGap = 16;
                var colW = (contentWidth - colGap) / 2;
                var drawColumn = function (name, address, rtl) {
                    var cy = y;
                    var x = rtl ? paperWidth - padding : padding;
                    ctx.direction = rtl ? 'rtl' : 'ltr';
                    ctx.textAlign = rtl ? 'right' : 'left';
                    ctx.font = 'bold 22px ' + fontMain;
                    wrapToLines(name, colW).forEach(function (l) { ctx.fillText(l, x, cy); cy += 28; });
                    ctx.font = '17px ' + fontMain;
                    wrapToLines(address, colW).forEach(function (l) { ctx.fillText(l, x, cy); cy += 22; });
                    ctx.direction = 'ltr';
                    return cy;
                };
                var leftEnd = drawColumn(batch.store_name, batch.address, false);
                var rightEnd = drawColumn(batch.store_name_ar, batch.address_ar, true);
                y = Math.max(leftEnd, rightEnd) + 4;
            } else {
                // 2. Store Name (Centered, Bold, Large)
                if (batch.store_name) {
                    ctx.font = 'bold 26px ' + fontMain;
                    ctx.textAlign = 'center';
                    y = wrapCenteredText(ctx, batch.store_name, paperWidth / 2, y, contentWidth, 32);
                }
            }

            // 3. Address & Phone (skip for KOT to save paper)
            if (!isKot) {
                ctx.font = '18px ' + fontMain;
                ctx.textAlign = 'center';
                if (batch.address && !hasArabicHeader) {
                    wrapToLines(batch.address, contentWidth).forEach(function (l) { ctx.fillText(l, paperWidth / 2, y); y += 25; });
                }
                if (batch.phone) {
                    ctx.fillText('Tel / هاتف : ' + '\u2066' + batch.phone + '\u2069', paperWidth / 2, y); // LRI..PDI keeps +974 numbers left-to-right next to Arabic
                    y += 25;
                }
            }

            // 4. Ticket Banner
            var bannerType = (type || batch.print_type || (isKot ? 'KOT' : 'INVOICE')).toUpperCase();
            // BILL = before payment (BILL button), INVOICE = after checkout.
            // Bill / invoice heading on one line: Arabic on the left, bold English on the right ("فاتورة  INVOICE")
            var bannerAr = !isKot ? ({ BILL: 'فاتورة مبدئية', INVOICE: 'فاتورة' }[bannerType] || '') : '';
            if (bannerAr) {
                y += 6;
                drawDottedLine(ctx, padding, y, paperWidth - padding);
                y += 12;
                var enFont = 'bold 30px ' + fontMain, arFont = '30px ' + fontMain, gapW = 18;
                ctx.font = enFont; var enW = ctx.measureText(bannerType).width;
                ctx.font = arFont; var arW = ctx.measureText(bannerAr).width;
                var startX = (paperWidth - (arW + gapW + enW)) / 2;
                ctx.textAlign = 'left';
                ctx.direction = 'rtl';
                ctx.textAlign = 'right';
                ctx.fillText(bannerAr, startX + arW, y);   // rtl text, right edge at startX + arW
                ctx.direction = 'ltr';
                ctx.textAlign = 'left';
                ctx.font = enFont;
                ctx.fillText(bannerType, startX + arW + gapW, y);
                y += 40;
            } else {
                y += 4;
                ctx.font = 'bold 22px ' + fontMain;
                ctx.textAlign = 'center';
                ctx.fillText('*** ' + bannerType + ' ***', paperWidth / 2, y);
                y += 28;
            }

            // Separator
            drawDottedLine(ctx, padding, y, paperWidth - padding);
            y += 12;

            // 5. Order Metadata (Two Columns aligned)
            if (batch.sale_no_p) y = drawRow(isKot ? 'Token / Order No' : 'Order No / رقم الطلب', batch.sale_no_p, y, { boldRight: true, size: isKot ? 21 : 19 });
            if (batch.date) y = drawRow('Date / التاريخ', batch.date + (batch.time_inv ? ' ' + batch.time_inv : ''), y);
            if (batch.sale_type) y = drawRow('Order Type / نوع الطلب', batch.sale_type, y);
            // cashier = user who created the order (bill / invoice only)
            if (!isKot && batch.sales_associate) y = drawRow('Cashier / الكاشير', batch.sales_associate, y);
            if (batch.customer_table) y = drawRow('Table / طاولة', batch.customer_table, y, { boldRight: true, size: 20 });
            if (batch.waiter_name) y = drawRow('Waiter / النادل', batch.waiter_name, y);
            if (batch.customer_name && !isKot) y = drawRow('Customer / العميل', batch.customer_name, y);

            // Separator
            y += 4;
            drawDottedLine(ctx, padding, y, paperWidth - padding);
            y += 10;

            // 6. Items Table Header Bar
            var colItemX = padding + 4;
            var colPriceX = 350;
            var colQtyX = 430;
            var colTotalX = paperWidth - padding - 4;

            // KOT columns: Qty first, then the item name (wrapped to the paper width)
            var kotNameX = padding + 130;
            var kotNameW = paperWidth - padding - kotNameX;
            function kotText(text, startY, font, lineH) {
                ctx.font = font;
                ctx.textAlign = 'left';
                var words = String(text).split(' ');
                var line = '';
                var ly = startY;
                for (var w = 0; w < words.length; w++) {
                    var test = line ? line + ' ' + words[w] : words[w];
                    if (ctx.measureText(test).width > kotNameW && line) {
                        ctx.fillText(line, kotNameX, ly);
                        ly += lineH;
                        line = words[w];
                    } else {
                        line = test;
                    }
                }
                if (line) ctx.fillText(line, kotNameX, ly);
                return ly + lineH;
            }

            // Bill: two-line header (English on top, Arabic underneath); KOT: single line
            var headerH = isKot ? 30 : 52;
            ctx.fillStyle = '#f2f2f2';
            ctx.fillRect(padding, y, contentWidth, headerH);
            ctx.fillStyle = '#000000';
            ctx.font = 'bold 18px ' + fontMain;

            if (!isKot) {
                var billHeaders = [
                    ['Item', 'الصنف', colItemX, 'left'],
                    ['Price', 'السعر', colPriceX, 'right'],
                    ['Qty', 'الكمية', colQtyX, 'right'],
                    ['Total', 'المجموع', colTotalX, 'right']
                ];
                for (var h = 0; h < billHeaders.length; h++) {
                    ctx.textAlign = billHeaders[h][3];
                    ctx.font = 'bold 18px ' + fontMain;
                    ctx.fillText(billHeaders[h][0], billHeaders[h][2], y + 4);
                    ctx.font = 'bold 16px ' + fontMain;
                    ctx.fillText(billHeaders[h][1], billHeaders[h][2], y + 27);
                }
            } else {
                // KOT: quantity is the first column, the item name follows
                ctx.textAlign = 'left';
                ctx.fillText('Qty / الكمية', colItemX, y + 5);
                ctx.fillText('Item / الصنف', kotNameX, y + 5);
            }

            drawDottedLine(ctx, padding, y, paperWidth - padding);
            drawDottedLine(ctx, padding, y + headerH, paperWidth - padding);
            y += headerH + 10;

            // 7. Items Render (Structured array or Raw Text)
            var hasStructuredItems = Boolean(batch.items_list && batch.items_list.length > 0);

            if (hasStructuredItems) {
                for (var i = 0; i < batch.items_list.length; i++) {
                    var itm = batch.items_list[i];

                    if (isKot) {
                        // KOT row: qty in the first column, name / modifiers / note in the item column
                        ctx.fillStyle = '#000000';
                        ctx.font = 'bold 24px ' + fontMain;
                        ctx.textAlign = 'left';
                        ctx.fillText(String(itm.qty || 1), colItemX, y);
                        y = kotText(itm.name + (itm.variant ? ' [' + itm.variant + ']' : ''), y, 'bold 22px ' + fontMain, 28);
                        ctx.fillStyle = '#222222';
                        for (var km = 0; itm.modifiers && km < itm.modifiers.length; km++) {
                            y = kotText(itm.modifiers[km], y, '18px ' + fontMain, 23);
                        }
                        if (itm.notes) {
                            y = kotText('* ' + itm.notes, y, 'italic 18px ' + fontMain, 23);
                        }
                        ctx.fillStyle = '#000000';
                        y += 6;
                        continue;
                    }

                    // Item Title (Bold)
                    ctx.font = (isKot ? 'bold 22px ' : 'bold 20px ') + fontMain;
                    ctx.fillStyle = '#000000';
                    ctx.textAlign = 'left';
                    var displayName = (i + 1) + '. ' + itm.name + (itm.variant ? ' [' + itm.variant + ']' : '');
                    ctx.fillText(displayName, colItemX, y);
                    y += 24;

                    // Modifiers / Addons
                    if (itm.modifiers && itm.modifiers.length > 0) {
                        ctx.font = '17px ' + fontMain;
                        ctx.fillStyle = '#222222';
                        for (var modIdx = 0; modIdx < itm.modifiers.length; modIdx++) {
                            ctx.fillText('   • ' + itm.modifiers[modIdx], colItemX, y);
                            y += 22;
                        }
                    }

                    // Cooking Note
                    if (itm.notes) {
                        ctx.font = 'italic 17px ' + fontMain;
                        ctx.fillStyle = '#444444';
                        ctx.fillText('   * ' + itm.notes, colItemX, y);
                        y += 22;
                    }

                    // Columns (Price, Qty, Total)
                    if (!isKot) {
                        ctx.font = '19px ' + fontMain;
                        ctx.fillStyle = '#000000';
                        ctx.textAlign = 'right';

                        var rateVal = (itm.rate !== undefined && itm.rate !== '') ? (typeof itm.rate === 'number' ? itm.rate.toFixed(2) : itm.rate) : '';
                        var qtyVal = itm.qty || 1;
                        var totalVal = (itm.total !== undefined && itm.total !== '') ? (typeof itm.total === 'number' ? itm.total.toFixed(2) : itm.total) : '';

                        ctx.fillText(rateVal ? (cur + rateVal) : '', colPriceX, y);
                        ctx.fillText(String(qtyVal), colQtyX, y);
                        ctx.font = 'bold 19px ' + fontMain;
                        ctx.fillText(totalVal ? (cur + totalVal) : '', colTotalX, y);
                        y += 28;
                    } else {
                        // KOT: Large Qty on the right
                        ctx.font = 'bold 24px ' + fontMain;
                        ctx.fillStyle = '#000000';
                        ctx.textAlign = 'right';
                        ctx.fillText('x ' + (itm.qty || 1), colTotalX, y - 10);
                        y += 18;
                    }

                    // Subtle separator between items
                    if (i < batch.items_list.length - 1) {
                        ctx.save();
                        ctx.strokeStyle = '#cccccc';
                        ctx.lineWidth = 1;
                        ctx.setLineDash([2, 3]);
                        ctx.beginPath();
                        ctx.moveTo(padding + 10, y - 6);
                        ctx.lineTo(paperWidth - padding - 10, y - 6);
                        ctx.stroke();
                        ctx.restore();
                        y += 6;
                    }
                }
            } else if (batch.items) {
                // Fallback: Render each line of raw items text cleanly
                var rawLines = batch.items.split('\n');
                for (var r = 0; r < rawLines.length; r++) {
                    var rLine = rawLines[r].trim();
                    if (!rLine) {
                        y += 6;
                        continue;
                    }
                    if (rLine.indexOf('---') === 0 || rLine.indexOf('===') === 0) {
                        drawDottedLine(ctx, padding, y + 6, paperWidth - padding);
                        y += 14;
                        continue;
                    }
                    if (rLine.indexOf('Order Type :') !== -1 || rLine.indexOf('Item') === 0) {
                        continue;
                    }

                    if (isKot) {
                        // KOT text from the POS: "4       Burger Deluxe" rows, indented "[+ modifier]" lines
                        var kRow = /^(\d+(?:\.\d+)?)\s{2,}(.+)$/.exec(rLine);
                        var kTotal = /^Total Items:\s*(.*)$/i.exec(rLine);
                        ctx.fillStyle = '#000000';
                        if (kRow) {
                            ctx.font = 'bold 24px ' + fontMain;
                            ctx.textAlign = 'left';
                            ctx.fillText(kRow[1], colItemX, y);
                            y = kotText(kRow[2], y, 'bold 22px ' + fontMain, 28);
                        } else if (kTotal) {
                            // count in the Qty column, label in the Item column (keeps Arabic and the number apart)
                            ctx.font = 'bold 24px ' + fontMain;
                            ctx.textAlign = 'left';
                            ctx.fillText(kTotal[1].trim(), colItemX, y);
                            ctx.font = 'bold 20px ' + fontMain;
                            ctx.fillText('Total Items / إجمالي الأصناف', kotNameX, y + 2);
                            y += 30;
                        } else if (/^\s/.test(rawLines[r])) {
                            ctx.fillStyle = '#222222';
                            y = kotText(rLine, y, '18px ' + fontMain, 23);
                            ctx.fillStyle = '#000000';
                        } else {
                            ctx.font = 'bold 20px ' + fontMain;
                            ctx.textAlign = 'left';
                            ctx.fillText(rLine, colItemX, y);
                            y += 26;
                        }
                        continue;
                    }

                    ctx.textAlign = 'left';
                    if (rLine.indexOf('+') === 0 || rLine.indexOf('  +') === 0) {
                        ctx.font = '17px ' + fontMain;
                        ctx.fillStyle = '#222222';
                        ctx.fillText('   • ' + rLine.replace(/^\s*\+\s*/, ''), padding + 6, y);
                        y += 22;
                    } else if (rLine.indexOf('*') === 0 || rLine.indexOf('  *') === 0) {
                        ctx.font = 'italic 17px ' + fontMain;
                        ctx.fillStyle = '#444444';
                        ctx.fillText('   * ' + rLine.replace(/^\s*\*\s*/, ''), padding + 6, y);
                        y += 22;
                    } else if (!isKot && rLine.indexOf(':') !== -1 && (rLine.indexOf('AED') !== -1 || rLine.indexOf('$') !== -1 || rLine.indexOf('x') !== -1 || /\b\d+\.\d+\b/.test(rLine))) {
                        var parts = rLine.split(':');
                        ctx.font = '19px ' + fontMain;
                        ctx.fillStyle = '#000000';
                        ctx.fillText(parts[0].trim(), padding + 6, y);
                        if (parts[1]) {
                            ctx.textAlign = 'right';
                            ctx.font = 'bold 19px ' + fontMain;
                            ctx.fillText(parts[1].trim(), paperWidth - padding - 6, y);
                        }
                        y += 26;
                    } else {
                        ctx.font = 'bold 20px ' + fontMain;
                        ctx.fillStyle = '#000000';
                        ctx.fillText(rLine, padding + 4, y);
                        y += 26;
                    }
                }
            }

            // 8. Totals Block (Skip for KOT)
            if (!isKot) {
                y += 4;
                drawDottedLine(ctx, padding, y, paperWidth - padding);
                y += 12;

                if (batch.totals_data) {
                    var td = batch.totals_data;
                    if (td.total_qty) y = drawRow('Total QTY / إجمالي الكمية', td.total_qty + ' Items', y, { size: 19 });
                    if (td.subtotal) y = drawRow('Sub Total / المجموع الجزئي', cur + Number(td.subtotal).toFixed(2), y, { size: 19 });
                    if (td.discount) y = drawRow('Discount / الخصم', '-' + cur + Number(td.discount).toFixed(2), y, { size: 19, color: '#222222' });
                    if (td.tax) y = drawRow((batch.tax_percentage ? 'Tax (' + batch.tax_percentage + '%)' : 'Tax') + ' / الضريبة', cur + Number(td.tax).toFixed(2), y, { size: 19 });
                } else if (batch.totals) {
                    var tLines = batch.totals.split('\n');
                    for (var j = 0; j < tLines.length; j++) {
                        var tLine = tLines[j].trim();
                        if (!tLine || tLine.indexOf('---') === 0) continue;
                        if (tLine.indexOf('Grand Total') !== -1 || tLine.indexOf('الإجمالي الكلي') !== -1) continue;

                        var colonIdx = tLine.indexOf(':');
                        if (colonIdx !== -1) {
                            var kLabel = tLine.substring(0, colonIdx).trim();
                            var vVal = tLine.substring(colonIdx + 1).trim();
                            y = drawRow(kLabel, vVal, y, { size: 19 });
                        } else {
                            ctx.font = '19px ' + fontMain;
                            ctx.textAlign = 'left';
                            ctx.fillText(tLine, padding, y);
                            y += 26;
                        }
                    }
                }

                // 9. Grand Total Box (Bold, Large, Boxed)
                var grandTotalVal = batch.grand_total || cur + '0.00';
                if (batch.totals_data && batch.totals_data.grand_total !== undefined && batch.totals_data.grand_total !== null) {   // 0 is a valid total (fully discounted bill)
                    grandTotalVal = cur + Number(batch.totals_data.grand_total).toFixed(2);
                } else if (batch.totals) {
                    var gtMatch = batch.totals.match(/(?:Grand Total|الإجمالي الكلي|Amount Payable)[^:]*:\s*([^\n]+)/i);
                    if (gtMatch) grandTotalVal = gtMatch[1].trim();
                }

                y += 8;
                var gtBoxH = 46;
                ctx.fillStyle = '#f6f6f6';
                ctx.fillRect(padding, y, contentWidth, gtBoxH);
                ctx.fillStyle = '#000000';

                drawDottedLine(ctx, padding, y, paperWidth - padding);
                drawDottedLine(ctx, padding, y + gtBoxH, paperWidth - padding);

                ctx.font = 'bold 22px ' + fontMain;
                ctx.textAlign = 'left';
                ctx.fillText('Grand Total / الإجمالي الكلي', padding + 8, y + 12);

                ctx.font = 'bold 25px ' + fontMain;
                ctx.textAlign = 'right';
                ctx.fillText(grandTotalVal, paperWidth - padding - 8, y + 10);
                y += gtBoxH + 14;

                // 10. Payments Block
                // payment_status comes with v2 bill data: BILL shows NOT PAID + amount due,
                // INVOICE shows what was really paid and PAID / PARTIALLY PAID / UNPAID
                var statusPill = function (text) {
                    y += 6;
                    ctx.font = 'bold 22px ' + fontMain;
                    ctx.textAlign = 'center';
                    var w = Math.min(contentWidth, ctx.measureText(text).width + 40);
                    ctx.save();
                    ctx.strokeStyle = '#000000';
                    ctx.lineWidth = 2;
                    ctx.strokeRect((paperWidth - w) / 2, y - 4, w, 36);
                    ctx.restore();
                    ctx.fillStyle = '#000000';
                    ctx.fillText(text, paperWidth / 2, y + 3);
                    y += 44;
                };
                if (batch.payment_status !== undefined && bannerType === 'BILL') {
                    y = drawRow('Amount Due / المبلغ المستحق', cur + Number(batch.balance_due || 0).toFixed(2), y, { size: 20, bold: true });
                    statusPill('NOT PAID / غير مدفوع');
                } else if (batch.payment_status !== undefined) {
                    var pdv = batch.payments_data || {};
                    var plist = batch.payments_list || [];
                    if (plist.length > 1) {
                        // split payment: one line per method (e.g. Cash + Card)
                        for (var pi = 0; pi < plist.length; pi++) {
                            y = drawRow(plist[pi].method + ' / ' + plist[pi].method_ar, cur + Number(plist[pi].amount).toFixed(2), y, { size: 19 });
                        }
                    } else if (Number(batch.paid_amount) > 0 && (plist.length ? plist[0].method : pdv.method)) {
                        y = drawRow('Payment Method / طريقة الدفع', plist.length ? plist[0].method : pdv.method, y, { size: 19 });
                    }
                    y = drawRow('Paid / المبلغ المدفوع', cur + Number(batch.paid_amount || 0).toFixed(2), y, { size: 19 });
                    if (Number(pdv.change) > 0) y = drawRow('Change Due / الباقي', cur + Number(pdv.change).toFixed(2), y, { size: 19, bold: true });
                    if (Number(batch.balance_due) > 0) y = drawRow('Balance Due / المبلغ المتبقي', cur + Number(batch.balance_due).toFixed(2), y, { size: 20, bold: true });
                    statusPill({ paid: 'PAID / مدفوع', partial: 'PARTIALLY PAID / مدفوع جزئياً', unpaid: 'UNPAID / غير مدفوع' }[batch.payment_status] || 'UNPAID / غير مدفوع');
                } else if (batch.payments_data) {
                    var pd = batch.payments_data;
                    if (pd.method) y = drawRow('Payment Method / طريقة الدفع', pd.method, y, { size: 19 });
                    if (pd.paid) y = drawRow('Paid / المبلغ المدفوع', cur + Number(pd.paid).toFixed(2), y, { size: 19 });
                    if (pd.change) y = drawRow('Change Due / الباقي', cur + Number(pd.change).toFixed(2), y, { size: 19, bold: true });
                } else if (batch.payments) {
                    var pLines = batch.payments.split('\n');
                    for (var k = 0; k < pLines.length; k++) {
                        var pLine = pLines[k].trim();
                        if (!pLine || pLine.indexOf('---') === 0) continue;
                        var pColon = pLine.indexOf(':');
                        if (pColon !== -1) {
                            var pKey = pLine.substring(0, pColon).replace(/^-\s*/, '').trim();
                            var pVal = pLine.substring(pColon + 1).trim();
                            y = drawRow(pKey, pVal, y, { size: 19 });
                        } else {
                            ctx.font = '19px ' + fontMain;
                            ctx.textAlign = 'left';
                            ctx.fillText(pLine, padding, y);
                            y += 26;
                        }
                    }
                }

                // 11. Footer
                if (batch.invoice_footer) {
                    y += 8;
                    drawDottedLine(ctx, padding, y, paperWidth - padding);
                    y += 16;
                    ctx.font = '18px ' + fontMain;
                    ctx.textAlign = 'center';
                    var fLines = batch.invoice_footer.split('\n');
                    for (var m = 0; m < fLines.length; m++) {
                        var fLine = fLines[m].trim();
                        if (!fLine) continue;
                        ctx.fillText(fLine, paperWidth / 2, y);
                        y += 25;
                    }
                }

                // 12. Powered by (bill and invoice only, not KOT)
                y += 14;
                ctx.font = '15px ' + fontMain;
                ctx.fillStyle = '#333333';
                ctx.textAlign = 'center';
                ctx.fillText('Powered by bizpoz.co', paperWidth / 2, y);
                ctx.fillStyle = '#000000';
                y += 20;
            } else {
                // KOT bottom divider & timestamp
                y += 10;
                drawDottedLine(ctx, padding, y, paperWidth - padding);
                y += 16;
                ctx.font = '16px ' + fontMain;
                ctx.textAlign = 'center';
                ctx.fillText('KOT Printed: ' + (batch.date || '') + ' ' + (batch.time_inv || ''), paperWidth / 2, y);
                y += 22;
            }

            y += 24;

            // Crop canvas tightly to used height
            var finalCanvas = document.createElement('canvas');
            finalCanvas.width = paperWidth;
            finalCanvas.height = y;
            var finalCtx = finalCanvas.getContext('2d');
            finalCtx.drawImage(canvas, 0, 0, paperWidth, y, 0, 0, paperWidth, y);

            return finalCanvas.toDataURL('image/png').split(',')[1];
        });
    }

    // Attach globally
    window.hasArabic = hasArabic;
    window.drawDottedLine = drawDottedLine;
    window.wrapCenteredText = wrapCenteredText;
    window.renderArabicReceiptCanvas = renderArabicReceiptCanvas;
    window.renderReceiptCanvas = renderArabicReceiptCanvas;

})(window);
