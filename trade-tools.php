<?php
require_once __DIR__ . '/app/bootstrap.php';
$rates = fx_rates();
$pageTitle = 'Currency & Duty Tools';
require_once __DIR__ . '/app/includes/header.php';
?>
<main class="container section">
 <h1>Pan-African Currency Converter & AfCFTA Duty Calculator</h1>
 <div class="grid-2">
  <div class="panel card-featured">
   <h3>Multi-Currency Converter</h3>
   <label>Amount</label><input type="number" id="fxAmount" value="10000">
   <div class="form-row">
    <div><label>From</label><select id="fxFrom"><?php foreach ($rates as $c => $r): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select></div>
    <div><label>To</label><select id="fxTo"><?php foreach ($rates as $c => $r): ?><option value="<?= $c ?>" <?= $c === 'USD' ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
   </div>
   <div id="fxOutput" data-rates='<?= e(json_encode($rates)) ?>' style="margin-top:18px;font-size:26px;font-weight:800;color:#ea580c;"></div>
  </div>
  <div class="panel">
   <h3>AfCFTA Cross-Border Duty Estimator</h3>
   <label>Cargo Value (USD)</label><input type="number" id="dutyCif" value="5000">
   <label>Origin Regime</label><select id="dutyOrigin"><option value="afcfta">AfCFTA Member State (0% Duty)</option><option value="non_afcfta">Non-AfCFTA (25% Duty)</option></select>
   <div id="dutyResult" style="margin-top:16px;"></div>
  </div>
 </div>
</main>
<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
