/**
 * BIZPOZ Print Server - sample print payloads, in the exact format the POS sends
 * (laundry-main ReceiptController: receiptBillJson for BILL, receiptJsonPhpPrint for KOT).
 * Used by index.php and test.php. Printer fields are overridden by the page.
 */
window.BIZPOZ_SAMPLES = {
    "BILL": {
        "type": "windows",
        "connection_type": "php_print_server",
        "printer_ip_address": "",
        "printer_port": 9100,
        "path": "POS-80C",
        "printer_name": "BILL",
        "profile_": "default",
        "characters_per_line": 48,
        "store_name": "BizPoz Cafe",
        "sale_no_p": "ORD-0125",
        "date": "08/11/2026",
        "time_inv": "02:30 PM",
        "sales_associate": "Ahmed",
        "address": "Al Sadd Street, Doha",
        "phone": "+974 4444 5555",
        "customer_name": "",
        "customer_address": "",
        "waiter_name": "",
        "customer_table": "Table 05",
        "order_type": "Dine In",
        "sale_type": "Dine In",
        "items": "\u001bE\u0001Order Type : Dine In\u001bE\u0000\n------------------------------------------------\nItem                        Rate   Qty     Total\n------------------------------------------------\nCappuccino - كابتشينو\nQAR12.00 x2:                            QAR24.00\nChicken Sandwich - ساندويتش دجاج\n  + Extra Cheese (QAR3.00)\n  * NOTE: No onion\nQAR18.00 x1:                            QAR21.00\n------------------------------------------------\n",
        "items_list": [
            {
                "name": "Cappuccino - كابتشينو",
                "variant": "",
                "rate": 12,
                "qty": 2,
                "total": 24,
                "modifiers": [],
                "notes": ""
            },
            {
                "name": "Chicken Sandwich - ساندويتش دجاج",
                "variant": "",
                "rate": 18,
                "qty": 1,
                "total": 21,
                "modifiers": [
                    "+ Extra Cheese (QAR3.00)"
                ],
                "notes": "No onion"
            }
        ],
        "totals": "Total QTY:                                     3\n\nSub Total:                           QAR.  45.00\nDiscount:                             QAR. -0.00\nTax  (0%):                           QAR. - 0.00\n\n\u001bE\u0001Amount Payable:                        QAR.45.00\u001bE\u0000\n",
        "totals_data": {
            "total_qty": 3,
            "subtotal": 45,
            "discount": 0,
            "tax": 0,
            "grand_total": 45
        },
        "payments": "------------------------------------------------\nPaid\n - Cash : QAR.45\n",
        "payments_data": {
            "method": "Cash",
            "paid": 45,
            "change": 0
        },
        "logo_url": "",
        "logo_base64": "",
        "invoice_footer": "",
        "random_code": "125",
        "currency": "QAR",
        "tax_percentage": 0,
        "open_cash_drawer": "Yes",
        "open_cash_drawer_when_printing_invoice": "Yes",
        "print_type": "BILL"
    },
    "KOT": {
        "type": "windows",
        "connection_type": "php_print_server",
        "printer_ip_address": "",
        "printer_port": 9100,
        "path": "POS-80C",
        "printer_name": "KITCHEN",
        "profile_": "default",
        "characters_per_line": 48,
        "store_name": "BizPoz Cafe",
        "sale_no_p": "ORD-0125",
        "date": "08/11/2026",
        "time_inv": "02:30 PM",
        "sales_associate": "Ahmed",
        "lang_order_type": "Order Type",
        "lang_Invoice_No": "Order No",
        "lang_date": "Date",
        "lang_Sales_Associate": "Staff",
        "lang_customer": "Customer",
        "lang_address": "Address",
        "lang_waiter": "Waiter",
        "lang_table": "Table",
        "sale_type": "Dine In",
        "order_type": "Dine In",
        "customer_name": "Walk-In",
        "customer_address": "",
        "waiter_name": "",
        "customer_table": "Table 05",
        "items": "2       Cappuccino - كابتشينو\n1       Chicken Sandwich - ساندويتش دجاج\n        [+ Extra Cheese]\n        * No onion\n------------------------------------------------\nTotal Items:                                   3\n",
        "print_type": "KOT"
    }
};
