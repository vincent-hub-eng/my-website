<?php
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

$raw = json_decode(file_get_contents('php://input'), true);
$q = strtolower(trim($raw['message'] ?? ''));

if ($q === '') {
    echo json_encode(['reply' => 'Jambo! I am **PAN AFR AI**, your Africa Trade OS assistant. Ask me about buying products, M-Pesa STK Push payments, seller ID & selfie verification, bulk RFQs, or AfCFTA cross-border shipping!']);
    exit;
}

$prodCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$catRows = $pdo->query("SELECT name FROM categories WHERE is_active=1 LIMIT 6")->fetchAll(PDO::FETCH_COLUMN);
$catsList = implode(', ', $catRows);

if (str_contains($q, 'mpesa') || str_contains($q, 'm-pesa') || str_contains($q, 'pay') || str_contains($q, 'stk') || str_contains($q, 'escrow')) {
    $reply = "To pay safely with **M-Pesa STK Push**:
1. Open any product in **All Products**, pick your color/specs & quantity, and click **Buy Now & Pay via M-Pesa**.
2. On the payment page, enter your Safaricom number (`07XXXXXXXX` or `2547XXXXXXXX`) and click **Send M-Pesa STK Push Prompt**.
3. Enter your M-Pesa PIN on your phone. The platform records payment and delivery status. Actual fund custody, refunds and payouts depend on the payment provider or a regulated escrow custodian.";
} elseif (str_contains($q, 'sell') || str_contains($q, 'upload') || str_contains($q, 'id') || str_contains($q, 'selfie') || str_contains($q, 'verify')) {
    $reply = "To become a **Seller or Wholesale Supplier**:
1. Register at **Join Free**, accept the Terms & Conditions, and confirm your **6-digit Email OTP**.
2. Complete the **secure ID and selfie capture** (Front ID, Back ID, and Live Selfie) in the Verification Center.
3. An administrator manually reviews the ID front, ID back and selfie before seller features are enabled.";
} elseif (str_contains($q, 'rfq') || str_contains($q, 'bulk') || str_contains($q, 'wholesale') || str_contains($q, 'quote')) {
    $reply = "Need bulk or wholesale pricing? Click **Post Bulk RFQ** in the navigation bar, enter your item, quantity, and destination city (domestic or cross-border AfCFTA), and verified suppliers will send competing quotes for you to compare and accept.";
} elseif (str_contains($q, 'afcfta') || str_contains($q, 'cross') || str_contains($q, 'border') || str_contains($q, 'duty') || str_contains($q, 'currency')) {
    $reply = "Africa Trade OS supports both **domestic delivery** and **cross-border AfCFTA trade** across Kenya, Nigeria, South Africa, Ghana, Tanzania, Uganda, Rwanda, Ethiopia, Egypt, and Morocco. Visit **FX & Duty Calculator** in the top bar to convert currencies or estimate 0% AfCFTA duty!";
} else {
    $reply = "I am **PAN AFR AI**! Here is how I can help right now:
• **Shop & Buy:** Browse {$prodCount} verified items and checkout with M-Pesa STK Push.
• **Sell Products:** Submit ID front, ID back and selfie images for manual review, then list items after approval.
• **Bulk Sourcing:** Post an RFQ for wholesale quotes across Africa.
Ask me any specific question!";
}

echo json_encode(['reply' => $reply]);
