<?php

declare(strict_types=1);

use TriCab\Api\DocumentGeneration\Rendering\Format;

$header = $doc->record('S006');
$company = $doc->record('S007');
$customerContact = $doc->record('S012');
$delivery = $doc->record('S013');
$dispatch = $doc->record('S015');
$entered = $doc->event('S016', '6301');
$published = $doc->record('S018');
$email = trim($entered?->field(10) ?? '') ?: trim($published?->field(10) ?? '');
$companyName = trim($company?->field(1) ?? 'TriCab');
$taxText = trim($header?->field(17) ?? '') === 'Y'
    ? 'ALL PRICES INCLUSIVE OF GST/VAT'
    : 'ALL PRICES EXCLUSIVE OF GST/VAT';
?>
<table class="full header-layout">
    <tr>
        <td style="width: 35mm"><img class="brand-logo" src="<?= $assets['logo'] ?>" alt="TriCab"></td>
        <td class="company-address" style="width:56mm">
            <?php foreach ($doc->addressLines('S007') as $line): ?>
                <div><?= Format::html($line) ?></div>
            <?php endforeach; ?>
            <?php if (trim($header?->field(5) ?? '') !== ''): ?><div><?= Format::html($header->field(5)) ?></div><?php endif; ?>
            <div><?= Format::html($header?->field(6) ?? '') ?></div>
        </td>
        <td>
            <div class="document-title">ORDER CONFIRMATION <?= Format::html($header?->field(0) ?? '') ?></div>
            <div class="page-number">Page 1 of 1</div>
        </td>
    </tr>
</table>

<table class="full address-and-meta">
    <tr>
        <td class="customer-address">
            <strong>Attn: <?= Format::html($customerContact?->field(1) ?? '') ?></strong>
            <?php foreach ($doc->addressLines('S008') as $line): ?><div><?= Format::html($line) ?></div><?php endforeach; ?>
        </td>
        <td style="width: 82mm">
            <div class="meta-box">
                <table>
                    <tr><td class="meta-label">Cust PO Ref:</td><td class="meta-value"><strong><?= Format::html($header?->field(10) ?? '') ?></strong></td></tr>
                    <tr><td>Cust Code:</td><td class="meta-value"><?= Format::html($header?->field(9) ?? '') ?></td></tr>
                    <tr><td>Entered:</td><td class="meta-value"><?= Format::html(Format::shortDate($entered?->field(3) ?? '') . '   ' . trim($entered?->field(4) ?? '')) ?></td></tr>
                    <tr><td>By:</td><td class="meta-value"><?= Format::html($entered?->field(6) ?? $header?->field(7) ?? '') ?></td></tr>
                    <tr><td>Printed:</td><td class="meta-value"><?= Format::html(Format::shortDate($published?->field(3) ?? '') . '   ' . trim($published?->field(4) ?? '')) ?></td></tr>
                    <tr><td>By:</td><td class="meta-value"><?= Format::html($published?->field(6) ?? '') ?></td></tr>
                    <tr><td>Total:</td><td class="meta-value"><?= Format::html(($header?->field(11) ?? '') . ' ' . ($header?->field(12) ?? '')) ?></td></tr>
                </table>
                <div class="tax-mode"><?= $taxText ?></div>
            </div>
        </td>
    </tr>
</table>

<div class="comment-box">
    <div class="comment-title">Comments:</div>
    <div class="comment-body">
        <div><?= Format::html($companyName) ?> confirms receipt of your Purchase Order Ref: <?= Format::html($header?->field(10) ?? '') ?>.</div>
        <div>Please thoroughly check all details below and advise any discrepancies to <?= Format::html($email) ?></div>
    </div>
</div>

<table class="items">
    <thead><tr>
        <th style="width: 8mm">Itm</th><th>Product Description</th><th style="width: 10mm">Wh</th>
        <th style="width: 22mm; text-align:right">Pack</th><th style="width: 20mm; text-align:right">Quantity</th>
        <th style="width: 10mm">Unit</th><th style="width: 24mm; text-align:right">Unit Price</th><th style="width: 25mm; text-align:right">Total</th>
    </tr></thead>
    <tbody>
    <?php foreach ($doc->items() as $itemData): $item = $itemData['record']; ?>
        <tr class="item-start">
            <td class="number"><?= Format::html($item->field(1)) ?></td>
            <td colspan="7"><?= Format::html($item->field(8)) ?></td>
        </tr>
        <tr>
            <td></td>
            <td class="product-code"><?= Format::html(trim($item->field(2) . ' ' . $item->field(3))) ?></td>
            <td class="product-code"><?= Format::html($item->field(12)) ?></td>
            <td class="numeric product-code"><?= Format::html(trim($item->field(10)) . ' X ' . trim($item->field(11))) ?></td>
            <td class="numeric"><?= Format::html($item->field(4)) ?></td>
            <td><?= Format::html($item->field(5)) ?></td>
            <td class="numeric"><?= Format::html($item->field(6)) ?></td>
            <td class="numeric"><?= Format::html($item->field(9)) ?></td>
        </tr>
        <tr class="item-end"><td></td><td colspan="7" class="product-code"><?php if (trim($item->field(13)) !== ''): ?>Est Del Date:&nbsp;&nbsp;<?= Format::html(Format::longDate($item->field(13))) ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<table class="totals"><tr><td class="total-label">TOTAL</td><td class="total-currency"><?= Format::html($header?->field(11) ?? '') ?></td><td class="total-value"><?= Format::html($header?->field(12) ?? '') ?></td></tr></table>

<table class="bottom-columns">
    <tr>
        <td>
            <div class="section-label">Deliver to:</div>
            <?php foreach ($doc->addressLines('S013', 6) as $line): ?><div class="section-label"><?= Format::html($line) ?></div><?php endforeach; ?>
            <div style="margin-top:3mm"><span class="section-label">Carrier:</span>&nbsp;&nbsp;<?= Format::html($dispatch?->field(1) ?? '') ?></div>
        </td>
        <td>
            <div class="section-label">Terms:</div>
            <?php foreach ($doc->variableLines('V4293', '0') as $line): ?><div><?= Format::html($line) ?></div><?php endforeach; ?>
        </td>
    </tr>
</table>

<img class="stranding" src="<?= $assets['stranding'] ?>" alt="Conductor stranding table">
<div class="legal-note">All spools and drums used by <?= Format::html($companyName) ?> (TCA) in the supply of cable under any order/invoice, are the property of TCA and are not sold by TCA and at all times remain the property of TCA, and must be set aside for collection by TCA. If spools and drums are not made available for collection on request then TCA may at its sole discretion charge such amount as is fair and reasonable for the said items.</div>
