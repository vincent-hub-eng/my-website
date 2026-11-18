<footer class="footer">
 <div class="container">
  <div class="footer-grid">
   <div>
    <div class="brand-title" style="font-size:20px;margin-bottom:10px;">AFRICA <span style="color:#f59e0b;">TRADE OS</span></div>
    <p class="footer-desc">Connecting verified African sellers, suppliers, logistics partners and buyers with product listings, manual identity review, M-Pesa payment records and delivery tracking.</p>
   </div>
   <div>
    <h4>Marketplace</h4>
    <ul class="footer-list">
     <li><a href="<?= e(url('marketplace.php')) ?>">Browse All Products</a></li>
     <li><a href="<?= e(url('rfq.php')) ?>">Request Bulk Quote (RFQ)</a></li>
     <li><a href="<?= e(url('payments.php')) ?>">Payments & Receipts</a></li>
     <li><a href="<?= e(url('terms.php')) ?>">Terms & Conditions</a></li>
    </ul>
   </div>
   <div>
    <h4>Partner With Us</h4>
    <ul class="footer-list">
     <li><a href="<?= e(url('auth/register.php?role=seller')) ?>">Open a Seller Store</a></li>
     <li><a href="<?= e(url('auth/register.php?role=supplier')) ?>">Register as Bulk Supplier</a></li>
     <li><a href="<?= e(url('auth/register.php?role=logistics')) ?>">Join as Delivery Partner</a></li>
     <li><a href="<?= e(url('trade-tools.php')) ?>">Currency & Duty Tools</a></li>
    </ul>
   </div>
  </div>
  <div class="footer-bottom">
   <span>© <?= date('Y') ?> Africa Trade OS. All Rights Reserved.</span>
   <span>PAN AFR AI Assistant • M-Pesa STK Push • AfCFTA Ready</span>
  </div>
 </div>
</footer>

<!-- PAN AFR AI Floating Circular Assistant (Bottom-Right Corner) -->
<div id="panAfrAiRoot" data-endpoint="<?= e(url('api/pan-afr-ai.php')) ?>">
 <button type="button" id="panAfrAiToggle" title="Chat with PAN AFR AI" aria-label="Open PAN AFR AI Assistant">
  <span class="ai-label">PAN AFR<br><strong>AI</strong></span>
 </button>

 <div id="panAfrAiWindow" style="display:none;">
  <div class="ai-header">
   <div>
    <div style="font-weight:800;font-size:14.5px;color:#fff;">PAN AFR AI Assistant</div>
    <div style="font-size:11px;color:#fbbf24;">● Online • Domestic & Cross-Border Trade Guide</div>
   </div>
   <button type="button" id="panAfrAiClose" style="background:transparent;border:0;color:#fff;font-size:18px;cursor:pointer;">✕</button>
  </div>
  <div id="panAfrAiMessages" class="ai-messages">
   <div class="ai-msg bot">Jambo! I am <strong>PAN AFR AI</strong>. Ask me how to buy with M-Pesa STK Push, upload products, verify your ID &amp; Selfie, or source bulk RFQs across Africa.</div>
  </div>
  <div class="ai-quick-chips">
   <button type="button" class="ai-chip" data-q="How do I pay with M-Pesa STK Push?">M-Pesa STK</button>
   <button type="button" class="ai-chip" data-q="How do I sell and verify my ID?">How to Sell</button>
   <button type="button" class="ai-chip" data-q="How do bulk RFQs and AfCFTA work?">Bulk RFQ</button>
  </div>
  <form id="panAfrAiForm" class="ai-input-row">
   <input type="text" id="panAfrAiInput" placeholder="Ask PAN AFR AI anything..." autocomplete="off" required>
   <button type="submit">Send</button>
  </form>
 </div>
</div>

<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
