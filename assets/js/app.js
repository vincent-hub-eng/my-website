document.addEventListener('DOMContentLoaded', () => {
    // Live Main Product Image Preview
    const imgInput = document.getElementById('productImageInput');
    const imgPreview = document.getElementById('productImagePreview');
    if (imgInput && imgPreview) {
        imgInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (ev) => {
                    imgPreview.src = ev.target.result;
                    imgPreview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // Dynamic Category Spec Tick-Boxes for Sellers (Laptops, Phones, Fashion, Solar, etc.)
    const catSelect = document.getElementById('productCategorySelect');
    const specContainer = document.getElementById('dynamicSpecContainer');
    function renderCategorySpecs() {
        if (!catSelect || !specContainer) return;
        const opt = catSelect.options[catSelect.selectedIndex];
        const raw = opt ? opt.getAttribute('data-specs') : '';
        if (!raw || raw === 'null' || raw === '{}') {
            specContainer.innerHTML = '<p class="muted small" style="margin:0;">Select a category above to tick hardware, size, or grade specifications.</p>';
            return;
        }
        try {
            const specObj = JSON.parse(raw);
            let html = '';
            for (const [groupName, options] of Object.entries(specObj)) {
                html += `<div style="margin-bottom:10px;">
                    <div style="font-size:12px;font-weight:700;color:#334155;">${groupName}:</div>
                    <div class="chip-group">`;
                options.forEach(val => {
                    html += `<label class="chip-check">
                        <input type="checkbox" name="specs[${groupName}][]" value="${val}">
                        <span>${val}</span>
                    </label>`;
                });
                html += `</div></div>`;
            }
            specContainer.innerHTML = html;
        } catch (err) {
            specContainer.innerHTML = '';
        }
    }
    if (catSelect && specContainer) {
        catSelect.addEventListener('change', renderCategorySpecs);
        renderCategorySpecs();
    }

    // FX Converter
    const fxAmount = document.getElementById('fxAmount');
    const fxFrom = document.getElementById('fxFrom');
    const fxTo = document.getElementById('fxTo');
    const fxOutput = document.getElementById('fxOutput');
    function calculateFx() {
        if (!fxAmount || !fxFrom || !fxTo || !fxOutput) return;
        const rates = JSON.parse(fxOutput.getAttribute('data-rates') || '{}');
        const amt = parseFloat(fxAmount.value) || 0;
        const converted = (amt / (rates[fxFrom.value] || 1)) * (rates[fxTo.value] || 1);
        fxOutput.textContent = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(converted) + ' ' + fxTo.value;
    }
    if (fxAmount && fxFrom && fxTo) {
        [fxAmount, fxFrom, fxTo].forEach(el => el.addEventListener('input', calculateFx));
        calculateFx();
    }

    // Duty Estimator
    const dutyCif = document.getElementById('dutyCif');
    const dutyOrigin = document.getElementById('dutyOrigin');
    const dutyResult = document.getElementById('dutyResult');
    function calculateDuty() {
        if (!dutyCif || !dutyOrigin || !dutyResult) return;
        const cif = parseFloat(dutyCif.value) || 0;
        const isAfcfta = dutyOrigin.value === 'afcfta';
        const duty = isAfcfta ? 0 : cif * 0.25;
        const vat = (cif + duty) * 0.16;
        const total = cif + duty + vat;
        dutyResult.innerHTML = `
            <p class="small">Customs Duty (${isAfcfta ? '0% AfCFTA' : '25% CET'}): <strong>$${duty.toFixed(2)}</strong></p>
            <p class="small">VAT (16%): <strong>$${vat.toFixed(2)}</strong></p>
            <p style="font-size:18px;color:#059669;font-weight:800;">Total Landed Cost: $${total.toFixed(2)}</p>
        `;
    }
    if (dutyCif && dutyOrigin) {
        [dutyCif, dutyOrigin].forEach(el => el.addEventListener('input', calculateDuty));
        calculateDuty();
    }
});

// PAN AFR AI Floating Chatbot Assistant Controller
document.addEventListener('DOMContentLoaded', () => {
    const aiRoot = document.getElementById('panAfrAiRoot');
    const aiToggle = document.getElementById('panAfrAiToggle');
    const aiWin = document.getElementById('panAfrAiWindow');
    const aiClose = document.getElementById('panAfrAiClose');
    const aiForm = document.getElementById('panAfrAiForm');
    const aiInput = document.getElementById('panAfrAiInput');
    const aiMsgs = document.getElementById('panAfrAiMessages');
    const aiChips = document.querySelectorAll('.ai-chip');

    if (aiRoot && aiToggle && aiWin) {
        const endpoint = aiRoot.getAttribute('data-endpoint');

        aiToggle.addEventListener('click', () => {
            aiWin.style.display = aiWin.style.display === 'none' ? 'flex' : 'none';
            if (aiWin.style.display === 'flex' && aiInput) aiInput.focus();
        });
        if (aiClose) {
            aiClose.addEventListener('click', () => { aiWin.style.display = 'none'; });
        }

        async function askPanAfrAi(text) {
            if (!text.trim()) return;
            const uDiv = document.createElement('div');
            uDiv.className = 'ai-msg user';
            uDiv.textContent = text;
            aiMsgs.appendChild(uDiv);
            aiMsgs.scrollTop = aiMsgs.scrollHeight;

            const bDiv = document.createElement('div');
            bDiv.className = 'ai-msg bot';
            bDiv.textContent = 'Thinking...';
            aiMsgs.appendChild(bDiv);
            aiMsgs.scrollTop = aiMsgs.scrollHeight;

            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: text })
                });
                const data = await res.json();
                bDiv.textContent = data.reply || 'How else can I assist you on Africa Trade OS?';
            } catch (err) {
                bDiv.textContent = 'Connection error. Please try again.';
            }
            aiMsgs.scrollTop = aiMsgs.scrollHeight;
        }

        if (aiForm) {
            aiForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const val = aiInput.value;
                aiInput.value = '';
                askPanAfrAi(val);
            });
        }

        aiChips.forEach(ch => {
            ch.addEventListener('click', () => {
                askPanAfrAi(ch.getAttribute('data-q') || ch.textContent);
            });
        });
    }
});
