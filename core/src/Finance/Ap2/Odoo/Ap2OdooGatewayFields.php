<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * The Odoo 19 models and fields Tri-AP reads, in one place so a field Odoo renames is fixed here once.
 *
 * Checked against the Odoo 19 documentation and source, NOT yet against Tricab's staging Odoo (no staging key on the
 * build machine). The ones most worth confirming on staging: `account.move.matched_payment_ids` (Odoo 17+),
 * `res.partner.bank.allow_out_payment` (Odoo 16+) and `stock.picking.date_done`.
 */
final class Ap2OdooGatewayFields
{
    public const RATE_CURRENCIES = ['USD', 'NZD', 'SGD'];

    public const PURCHASE_ORDER = ['id', 'name', 'state', 'partner_id', 'company_id', 'currency_id', 'date_order', 'order_line', 'picking_ids'];

    /** `display_type` set means a section or note line, which is not a product. */
    public const PURCHASE_ORDER_LINE = ['id', 'order_id', 'product_id', 'name', 'display_type', 'product_qty', 'qty_received', 'qty_invoiced', 'price_unit'];

    public const PICKING = ['id', 'name', 'state', 'date_done', 'picking_type_code'];

    public const MOVE = ['id', 'name', 'ref', 'move_type', 'state', 'payment_state', 'amount_total', 'amount_residual', 'currency_id', 'partner_id', 'company_id', 'matched_payment_ids'];

    public const PAYMENT = ['id', 'name', 'date', 'amount', 'state'];

    public const RATE = ['id', 'name', 'rate', 'currency_id', 'company_id'];

    /** `acc_number` is read only to be masked at once; it is never kept. */
    public const PARTNER_BANK = ['id', 'partner_id', 'acc_number', 'bank_id', 'active', 'allow_out_payment', 'create_date', 'write_date'];

    public const XMLID = ['id', 'module', 'name', 'model', 'res_id'];

    /** The module Odoo's CSV import gives an External ID without one, so a CSV-imported and an API-posted bill share a key. */
    public const XMLID_MODULE = '__import__';
}
