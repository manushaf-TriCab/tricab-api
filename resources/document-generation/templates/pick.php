<?php

declare(strict_types=1);

use TriCab\Api\DocumentGeneration\Rendering\Format;

$header = $doc->record('S006');
$contact = $doc->record('S012');
$dispatch = $doc->record('S015');
$published = $doc->record('S018');
$showManufacturer = trim($header?->field(23) ?? '') === 'Y';
$reference = trim($header?->field(0) ?? '') . '-' . trim($header?->field(20) ?? '');
$companyName = trim($doc->field('S007', 1, 'TriCab'));
?>
<table class="full header-layout">
    <tr>
        <td style="width: 35mm"><img class="brand-logo" src="<?= $assets['logo'] ?>" alt="TriCab"></td>
        <td class="company-address" style="width:55mm">
            <?php foreach ($doc->addressLines('S007', 6) as $line): ?><div><?= Format::html($line) ?></div><?php endforeach; ?>
            <div><?= Format::html($header?->field(5) ?? '') ?></div>
            <div><?= Format::html($header?->field(6) ?? '') ?></div>
        </td>
        <td>
            <div class="document-title" style="font-size:16pt">PICK LIST <?= Format::html($reference) ?></div>
            <div class="page-number">Page 1 of 1</div>
        </td>
    </tr>
</table>

<table class="full address-and-meta">
    <tr>
        <td class="customer-address">
            <strong>Attn: <?= Format::html($contact?->field(1) ?? '') ?></strong>
            <?php foreach ($doc->addressLines('S008') as $line): ?><div><?= Format::html($line) ?></div><?php endforeach; ?>
        </td>
        <td style="width: 72mm">
            <div class="meta-box" style="width:72mm">
                <table>
                    <tr><td class="meta-label">Date:</td><td class="meta-value"><?= Format::html(Format::shortDate($header?->field(2) ?? '')) ?></td></tr>
                    <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
                    <tr><td>Cust PO Ref:</td><td class="meta-value"><strong><?= Format::html($header?->field(10) ?? '') ?></strong></td></tr>
                    <tr><td>Cust Code:</td><td class="meta-value"><?= Format::html($header?->field(9) ?? '') ?></td></tr>
                    <tr><td><strong>Order Date:</strong></td><td class="meta-value"><?= Format::html(Format::shortDate($header?->field(16) ?? '')) ?></td></tr>
                    <tr><td>Entered By:</td><td class="meta-value"><?= Format::html($header?->field(7) ?? '') ?></td></tr>
                    <tr><td>Printed:</td><td class="meta-value"><?= Format::html(Format::shortDate($published?->field(3) ?? '') . '   ' . trim($published?->field(4) ?? '')) ?></td></tr>
                    <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
                    <tr><td><strong>Deliver By:</strong></td><td class="meta-value"><?= Format::html($header?->field(18) ?? '') ?></td></tr>
                </table>
            </div>
        </td>
    </tr>
</table>

<table class="items" style="margin-top:5mm">
    <thead><tr>
        <th style="width: 25mm; text-align:right">Itm</th><th>Product Description</th><th style="width: 10mm">Wh</th>
        <th style="width: 20mm; text-align:right">Pack</th><th style="width: 17mm; text-align:right">Quantity</th>
        <th style="width: 9mm">Unit</th><?php if ($showManufacturer): ?><th style="width: 48mm">Manuf</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($doc->items() as $itemData): $item = $itemData['record']; ?>
        <tr class="item-start">
            <td class="number"><?= Format::html($item->field(1)) ?></td>
            <td colspan="<?= $showManufacturer ? 6 : 5 ?>"><?= Format::html($item->field(8)) ?></td>
        </tr>
        <tr>
            <td></td>
            <td class="product-code"><?= Format::html(trim($item->field(2) . ' ' . $item->field(3))) ?></td>
            <td class="product-code"><?= Format::html($item->field(12)) ?></td>
            <td class="numeric product-code"><?= Format::html(trim($item->field(10)) . ' X ' . trim($item->field(11))) ?></td>
            <td class="numeric"><?= Format::html($item->field(4)) ?></td>
            <td><?= Format::html($item->field(5)) ?></td>
            <?php if ($showManufacturer): ?><td class="product-code"><?= Format::html($item->field(14)) ?></td><?php endif; ?>
        </tr>
        <?php foreach ($itemData['comments'] as $comment): ?>
            <tr class="item-end"><td></td><td colspan="<?= $showManufacturer ? 6 : 5 ?>"><?php if (trim($comment->field(2)) !== ''): ?><span class="item-comment-label"><?= Format::html($comment->field(2)) ?>:</span>&nbsp;<?php endif; ?><?= Format::html($comment->field(3)) ?></td></tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="pick-footer">
    <table class="pick-footer-table"><tr>
        <td style="width:72%">
            <div class="section-label" style="font-size:13pt">DELIVER TO:</div>
            <?php foreach ($doc->addressLines('S013', 6) as $line): ?><div class="section-label" style="font-family:Courier,monospace;font-size:11pt"><?= Format::html($line) ?></div><?php endforeach; ?>
            <div style="margin-top:2mm"><span class="section-label">Contact:</span>&nbsp;&nbsp;<?= Format::html($doc->field('S020', 1)) ?></div>
            <div><span class="section-label">Cust PO Ref:</span>&nbsp;&nbsp;<?= Format::html($header?->field(10) ?? '') ?></div>
            <div class="pick-ref">Ref: <?= Format::html($reference) ?></div>
            <?php if (trim($header?->field(21) ?? '') === 'Y'): ?><div class="credit-hold">CREDIT HOLD</div><?php endif; ?>
        </td>
        <td>
            <div><span class="section-label">Carrier:</span>&nbsp;&nbsp;<?= Format::html($dispatch?->field(1) ?? '') ?></div>
            <div><span class="section-label">Weight:</span>&nbsp;&nbsp;<?= Format::html(trim(($dispatch?->field(4) ?? '') . ' ' . ($dispatch?->field(5) ?? ''))) ?></div>
            <?php if (trim($header?->field(24) ?? '') === 'N'): ?><div class="section-label" style="margin-top:2mm">NO EARLIER</div><?php endif; ?>
        </td>
    </tr></table>
</div>
<div class="legal-note pick-legal">All spools and drums used by <?= Format::html($companyName) ?> (TCA) in the supply of cable under any order/invoice, are the property of TCA and are not sold by TCA and at all times remain the property of TCA, and must be set aside for collection by TCA. If spools and drums are not made available for collection on request then TCA may at its sole discretion charge such amount as is fair and reasonable for the said items.</div>
