<?php

declare(strict_types=1);

use TriCab\Api\DocumentGeneration\Rendering\Format;

$header = $doc->record('S006');
$sales = $doc->record('S011');
$contact = $doc->record('S012');
$metal = $doc->record('S026');
$unit = trim($metal?->field(3) ?? '') === 'LBS' ? 'Pound' : 'Tonne';
$market = trim($metal?->field(4) ?? '');
$taxText = trim($header?->field(17) ?? '') === 'Y'
    ? 'ALL PRICES INCLUSIVE OF GST/VAT'
    : 'ALL PRICES EXCLUSIVE OF GST/VAT';
?>
<div style="position:relative;height:23mm">
    <img class="brand-logo" style="position:absolute;left:0;top:0" src="<?= $assets['logo'] ?>" alt="TriCab">
    <img class="australian-made" style="position:absolute;left:43mm;top:0" src="<?= $assets['australianMade'] ?>" alt="Australian Made">
    <div style="position:absolute;right:0;top:0;width:130mm">
        <div class="document-title" style="font-size:18pt">QUOTATION No <?= Format::html($header?->field(1) ?? '') ?></div>
        <?php if (trim($header?->field(19) ?? '') === 'D'): ?><div class="document-title" style="font-size:13pt">DRAFT</div><?php endif; ?>
        <div class="page-number">Page 1 of 1</div>
    </div>
</div>

<table class="full address-and-meta" style="margin-top:3mm">
    <tr>
        <td class="customer-address">
            <strong>Attn: <?= Format::html($contact?->field(1) ?? '') ?></strong>
            <?php foreach ($doc->addressLines('S008') as $line): ?><div><?= Format::html($line) ?></div><?php endforeach; ?>
        </td>
        <td style="width: 84mm">
            <div class="meta-box" style="width:84mm">
                <table>
                    <tr><td class="meta-label">Quote No:</td><td class="meta-value"><?= Format::html($header?->field(1) ?? '') ?></td></tr>
                    <tr><td>Raised By:</td><td class="meta-value"><?= Format::html($sales?->field(1) ?? '') ?></td></tr>
                    <?php if (trim($sales?->field(2) ?? '') !== ''): ?><tr><td></td><td class="meta-value"><strong><?= Format::html($sales->field(2)) ?></strong></td></tr><?php endif; ?>
                    <tr><td>Quote Date:</td><td class="meta-value"><?= Format::html(Format::shortDate($header?->field(2) ?? '') . '   ' . trim($header?->field(3) ?? '')) ?></td></tr>
                    <tr><td colspan="2"><strong>Based on Copper/<?= $unit ?>:</strong></td></tr>
                    <tr><td></td><td class="meta-value nowrap"><?= $market === 'COMEX' ? 'USD ' . Format::html($metal?->field(1) ?? '') : 'USD ' . Format::html($metal?->field(1) ?? '') . '  AUD ' . Format::html($metal?->field(2) ?? '') ?></td></tr>
                    <?php if (trim($metal?->field(5) ?? '') !== ''): ?>
                        <tr><td colspan="2"><strong>Aluminium/<?= $unit ?>:</strong></td></tr>
                        <tr><td></td><td class="meta-value nowrap"><?= $market === 'COMEX' ? 'USD ' . Format::html($metal?->field(5) ?? '') : 'USD ' . Format::html($metal?->field(5) ?? '') . '  AUD ' . Format::html($metal?->field(6) ?? '') ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Valid Till:</td><td class="meta-value"><?= Format::html(Format::shortDate($header?->field(16) ?? '')) ?></td></tr>
                    <tr><td>Cust Code:</td><td class="meta-value"><?= Format::html($header?->field(9) ?? '') ?></td></tr>
                    <tr><td>Total:</td><td class="meta-value"><?= Format::html(($header?->field(11) ?? '') . ' ' . ($header?->field(12) ?? '')) ?></td></tr>
                </table>
                <div class="tax-mode"><?= $taxText ?></div>
            </div>
        </td>
    </tr>
</table>

<?php if (trim($header?->field(10) ?? '') !== ''): ?><div class="customer-reference"><strong>Customer Ref:</strong>&nbsp;&nbsp;<?= Format::html($header->field(10)) ?></div><?php endif; ?>
<div class="comment-box">
    <div class="comment-title">Comments:</div>
    <div class="comment-body">Payment Terms: <?= Format::html($header?->field(14) ?? '') ?></div>
</div>

<table class="items">
    <thead><tr>
        <th style="width: 16mm; text-align:right">Itm</th><th>Product Description</th><th style="width: 20mm; text-align:right">Quantity</th>
        <th style="width: 15mm">Unit</th><th style="width: 27mm; text-align:right">Unit Price</th><th style="width: 30mm; text-align:right">Total</th>
    </tr></thead>
    <tbody>
    <?php foreach ($doc->items() as $itemData): $item = $itemData['record']; ?>
        <tr class="item-start">
            <td class="number"><?= Format::html($item->field(1)) ?></td>
            <td colspan="5"><?= Format::html($item->field(8)) ?></td>
        </tr>
        <tr>
            <td></td>
            <td class="product-code"><?= Format::html(trim($item->field(2) . ' ' . $item->field(3))) ?></td>
            <td class="numeric"><?= Format::html($item->field(4)) ?></td>
            <td><?= Format::html($item->field(5)) ?></td>
            <td class="numeric"><?= Format::html($item->field(6)) ?></td>
            <td class="numeric"><?= Format::html($item->field(12)) ?></td>
        </tr>
        <?php foreach ($itemData['comments'] as $comment): ?><tr class="item-end"><td></td><td colspan="5"><?= Format::html($comment->field(3)) ?></td></tr><?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<table class="totals"><tr><td class="total-label">TOTAL</td><td class="total-currency"><?= Format::html($header?->field(11) ?? '') ?></td><td class="total-value"><?= Format::html($header?->field(12) ?? '') ?></td></tr></table>

<img class="stranding" src="<?= $assets['stranding'] ?>" alt="Conductor stranding table">

<table class="full quote-footer"><tr>
    <td><?= Format::html($doc->field('S007', 1)) ?><br><?= Format::html($header?->field(5) ?? '') ?></td>
    <td><?php foreach (array_slice($doc->addressLines('S007'), 1, 2) as $line): ?><?= Format::html($line) ?><br><?php endforeach; ?></td>
    <td><?php foreach (array_slice($doc->addressLines('S007'), 3, 2) as $line): ?><?= Format::html(preg_replace('/^T\s+/', '', $line) ?? $line) ?><br><?php endforeach; ?></td>
</tr></table>
