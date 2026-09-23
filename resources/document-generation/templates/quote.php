<?php

declare(strict_types=1);

use TriCab\Api\DocumentGeneration\Rendering\Format;
use TriCab\Api\DocumentGeneration\Rendering\QuoteItemPageAllocator;

$header = $doc->record('S006');
$sales = $doc->record('S011');
$contact = $doc->record('S012');
$metal = $doc->record('S026');
$unit = trim($metal?->field(3) ?? '') === 'LBS' ? 'Pound' : 'Tonne';
$market = trim($metal?->field(4) ?? '');
$taxText = trim($header?->field(17) ?? '') === 'Y'
    ? 'ALL PRICES INCLUSIVE OF GST/VAT'
    : 'ALL PRICES EXCLUSIVE OF GST/VAT';

$conductorMatrix = \TriCab\Api\DocumentGeneration\Rendering\ConductorComparisonMatrix::fromRows($doc->conductorComparison());
$itemPages = QuoteItemPageAllocator::allocate($doc->items(), !$conductorMatrix->isEmpty());
?>
<div class="quote-page-footer">
    <div class="quote-footer-chrome-spacer">&nbsp;</div>
    <table class="full quote-company-footer"><tr>
        <td><?= Format::html($doc->field('S007', 1)) ?><br><?= Format::html($header?->field(5) ?? '') ?></td>
        <td><?php foreach (array_slice($doc->addressLines('S007'), 1, 2) as $line): ?><?= Format::html($line) ?><br><?php endforeach; ?></td>
        <td><?php foreach (array_slice($doc->addressLines('S007'), 3, 2) as $line): ?><?= Format::html(preg_replace('/^T\s+/', '', $line) ?? $line) ?><br><?php endforeach; ?></td>
    </tr></table>
</div>

<div style="position:relative;height:23mm">
    <img class="brand-logo" style="position:absolute;left:0;top:0" src="<?= $assets['logo'] ?>" alt="TriCab">
    <img class="australian-made" style="position:absolute;left:43mm;top:0" src="<?= $assets['australianMade'] ?>" alt="Australian Made">
    <div style="position:absolute;right:0;top:0;width:130mm">
        <div class="document-title" style="font-size:18pt">QUOTATION No <?= Format::html($header?->field(1) ?? '') ?></div>
        <?php if (trim($header?->field(19) ?? '') === 'D'): ?><div class="document-title" style="font-size:13pt">DRAFT</div><?php endif; ?>
        <div class="page-number quote-page-number-placeholder" style="visibility:hidden">Page</div>
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

<?php
$lastItemPageIndex = count($itemPages) - 1;
foreach ($itemPages as $pageIndex => $pageItems):
    $isFinalItemPage = $pageIndex === $lastItemPageIndex;
    if ($isFinalItemPage):
?>
<div class="quote-final-block">
<?php endif; ?>
<table class="items<?= $pageIndex > 0 ? ' quote-items-continued' : '' ?>">
    <thead><tr>
        <th style="width: 16mm; text-align:right">Itm</th><th>Product Description</th><th style="width: 20mm; text-align:right">Quantity</th>
        <th style="width: 15mm">Unit</th><th style="width: 27mm; text-align:right">Unit Price</th><th style="width: 30mm; text-align:right">Total</th>
    </tr></thead>
    <tbody>
    <?php foreach ($pageItems as $itemData): $item = $itemData['record']; ?>
        <tr class="quote-item">
            <td class="number"><?= Format::html($item->field(1)) ?></td>
            <td>
                <div class="item-description"><?= Format::html($item->field(8)) ?></div>
                <?php
                $productCodeLine = trim((string) $item->field(2));
                $packLabel = trim((string) $item->field(18));
                if ($packLabel !== '') {
                    $productCodeLine = $productCodeLine === '' ? $packLabel : ($productCodeLine . ' — ' . $packLabel);
                }
                ?>
                <?php if ($productCodeLine !== ''): ?>
                <div class="product-code"><?= Format::html($productCodeLine) ?></div>
                <?php endif; ?>
                <?php foreach ($itemData['comments'] as $comment): ?>
                    <div class="item-comment"><?= Format::html($comment->field(3)) ?></div>
                <?php endforeach; ?>
            </td>
            <td class="numeric"><?= Format::html($item->field(4)) ?></td>
            <td><?= Format::html($item->field(5)) ?></td>
            <td class="numeric"><?= Format::html($item->field(6)) ?></td>
            <td class="numeric"><?= Format::html($item->field(12)) ?></td>
        </tr>
    <?php endforeach; ?>
        <tr class="quote-item-table-close"><td colspan="6"></td></tr>
    </tbody>
</table>
<?php if ($isFinalItemPage): ?>
<div class="quote-closing">
<table class="totals"><tr><td class="total-label">TOTAL</td><td class="total-currency"><?= Format::html($header?->field(11) ?? '') ?></td><td class="total-value"><?= Format::html($header?->field(12) ?? '') ?></td></tr></table>

<?php if (!$conductorMatrix->isEmpty()): ?>
<div class="conductor-comparison">
    <div class="conductor-comparison-title">
        <strong>Conductor Comparison - TriCab versus Class 5 &amp; 6</strong>
        <span> (approximate no.of wires)</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Size mm²</th>
                <?php foreach ($conductorMatrix->sizes as $size): ?>
                    <th><?= Format::html($size) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>TriCab</td>
                <?php foreach ($conductorMatrix->tricab as $value): ?>
                    <td><?= $value !== null ? Format::html($value) : '' ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td>Class5</td>
                <?php foreach ($conductorMatrix->class5 as $value): ?>
                    <td><?= $value !== null ? Format::html($value) : '' ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td>Class6</td>
                <?php foreach ($conductorMatrix->class6 as $value): ?>
                    <td><?= $value !== null ? Format::html($value) : '' ?></td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>
</div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
<?php endforeach; ?>
