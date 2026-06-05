
// =====================================================
// GLOBAL INITIALIZATION
// =====================================================
document.addEventListener('DOMContentLoaded', () => {
    loadProducts();
});

async function loadProducts() {
    const grid = document.getElementById('main-product-grid');
    if (!grid) {
        initAuctions();
        initChatButtons();
        return;
    }
    
    try {
        const products = await api.getProducts();
        
        if (products.length === 0) {
            grid.innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #64748b;">No products available.</p>';
            return;
        }

        grid.innerHTML = products.map(product => {
            const isAuction = product.listing_type === 'auction';
            
            let badgeHtml = '';
            if (product.condition === 'new') badgeHtml = `<span class="product-badge badge-new">NEW</span>`;
            else if (product.condition === 'like-new') badgeHtml = `<span class="product-badge badge-like-new">LIKE NEW</span>`;
            else if (isAuction) badgeHtml = `<span class="product-badge badge-auction">AUCTION</span>`;
            else badgeHtml = `<span class="product-badge badge-good">GOOD</span>`;

            let actionHtml = '';
            if (isAuction) {
                actionHtml = `<button class="cta-button nav-cta-btn place-bid-btn" style="height: 38px; padding: 0 16px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; margin: 0;" onclick="openBiddingModal(${product.id}, '${product.title}', this.closest('.product-card').getAttribute('data-current-bid') || ${product.price})">Place Bid</button>`;
            } else {
                actionHtml = `<button class="product-cart-btn" aria-label="Add to cart" onclick="addToCart(${product.id}, '${product.title}', ${product.price})">
                                <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                              </button>`;
            }

            return `
                <div class="product-card" data-id="${product.id}" data-current-bid="${product.price}">
                    ${badgeHtml}
                    <div class="product-img-wrapper">
                        <img src="${product.image_url || 'headphones.png'}" alt="${product.title}">
                    </div>
                    <div class="product-card-body">
                        <div class="product-meta">
                            <span class="product-category">${product.category ? product.category.toUpperCase() : 'GENERAL'}</span>
                            <span class="product-seller">
                                <svg class="seller-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                                ${product.seller_name || 'Seller'} <span class="verified-seller-badge"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>Verified</span>
                            </span>
                        </div>
                        <h3 class="product-title">${product.title}</h3>
                        ${isAuction ? `<div class="product-auction-info">
                            <span class="auction-label">Current Bid</span>
                            <span class="product-price" style="font-size: 16px; font-weight: 700; color: #0f172a;">R${product.price}</span>
                        </div>` : ''}
                        <p class="product-desc">${product.description || ''}</p>
                        <hr class="product-divider">
                        <div class="product-footer">
                            ${!isAuction ? `<span class="product-price">R${product.price}</span>` : '<span></span>'}
                            <div style="display: flex; align-items: center;">
                                <button class="product-chat-btn" aria-label="Chat with seller" data-seller="${product.seller_name || 'Seller'}" data-product-id="${product.id}" data-seller-id="${product.seller_id}">
                                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                </button>
                                ${actionHtml}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        initAuctions();
        initChatButtons();
        
    } catch (err) {
        console.error("Failed to load products", err);
    }
}

// Helper: Show standard toast notifications (defined in cart.js but duplicated here in case cart.js is not loaded)
function notify(message) {
    if (typeof showToastNotification === 'function') {
        showToastNotification(message);
    } else {
        alert(message);
    }
}

// =====================================================
// GOAL 1: OTP SMS VERIFICATION MOCKUP
// =====================================================
function openOtpModal(phoneNumber, onComplete) {
    // Create the modal container overlay
    const overlay = document.createElement('div');
    overlay.className = 'simple-modal-overlay';
    overlay.id = 'otp-modal';

    overlay.innerHTML = `
        <div class="simple-modal-box">
            <button class="simple-modal-close" onclick="closeOtpModal()">&times;</button>
            <h3>Verify Phone Number</h3>
            <p class="otp-helper-msg">We sent a mock 4-digit SMS verification code to <strong>${phoneNumber}</strong>.</p>
            <div class="otp-input-row">
                <input type="text" class="otp-digit" maxlength="1" onkeyup="moveOtpFocus(this, 'otp-2')" id="otp-1">
                <input type="text" class="otp-digit" maxlength="1" onkeyup="moveOtpFocus(this, 'otp-3')" id="otp-2">
                <input type="text" class="otp-digit" maxlength="1" onkeyup="moveOtpFocus(this, 'otp-4')" id="otp-3">
                <input type="text" class="otp-digit" maxlength="1" id="otp-4">
            </div>
            <p class="otp-helper-msg" style="color: #64748b; font-size: 11px;">Hint: Type any digits (e.g. 1 2 3 4) to verify.</p>
            <button class="place-order-btn" onclick="submitOtpVerification()">Verify & Complete</button>
        </div>
    `;

    document.body.appendChild(overlay);
    
    // Store the callback function globally so we can access it on submit
    window._otpCallback = onComplete;
}

function moveOtpFocus(current, nextFieldId) {
    if (current.value.length === 1) {
        document.getElementById(nextFieldId).focus();
    }
}

function closeOtpModal() {
    const modal = document.getElementById('otp-modal');
    if (modal) modal.remove();
}

function submitOtpVerification() {
    const d1 = document.getElementById('otp-1').value;
    const d2 = document.getElementById('otp-2').value;
    const d3 = document.getElementById('otp-3').value;
    const d4 = document.getElementById('otp-4').value;
    
    if (!d1 || !d2 || !d3 || !d4) {
        alert("Please enter all 4 digits of the verification code.");
        return;
    }
    
    closeOtpModal();
    notify("Phone number verified successfully!");
    if (typeof window._otpCallback === 'function') {
        window._otpCallback();
    }
}


// =====================================================
// GOAL 2 & 3: LOGISTICS SHIPPING & ESCROW
// =====================================================

// Mock downloading shipping label for C2C deliveries
function downloadShippingLabel(orderId, sellerName, buyerName, destinationCity) {
    // We open a new window and render a clean, printable shipping label mock
    const labelWindow = window.open('', '_blank', 'width=600,height=400');
    labelWindow.document.write(`
        <html>
        <head>
            <title>Shipping Label - #${orderId}</title>
            <style>
                body { font-family: 'Inter', sans-serif; padding: 20px; color: #000; }
                .label-box { border: 4px solid #000; padding: 20px; max-width: 500px; margin: 0 auto; }
                .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
                .title { font-size: 24px; font-weight: 800; text-transform: uppercase; }
                .barcode { background: repeating-linear-gradient(90deg, #000, #000 2px, #fff 2px, #fff 8px); height: 60px; margin: 20px 0; }
                .info-section { font-size: 14px; line-height: 1.6; margin-bottom: 20px; }
                .footer { font-size: 12px; text-align: center; border-top: 1px dashed #000; padding-top: 10px; margin-top: 20px; }
            </style>
        </head>
        <body onload="window.print()">
            <div class="label-box">
                <div class="header">
                    <span class="title">nsmfy EXPRESS</span>
                    <span><strong>TRACKING #:</strong> ${orderId}</span>
                </div>
                <div class="info-section">
                    <p><strong>FROM (SELLER):</strong><br>${sellerName}<br>Verified Seller Dashboard pickup</p>
                    <p><strong>TO (BUYER):</strong><br>${buyerName}<br>${destinationCity}, South Africa</p>
                    <p><strong>METHOD:</strong> Lock-to-Locker Secure Handover</p>
                </div>
                <div class="barcode"></div>
                <div class="footer">
                    nsmfy C2C Logistics System &bull; Safe Escrow Payment Guard Enabled
                </div>
            </div>
        </body>
        </html>
    `);
    labelWindow.document.close();
    notify("Mock shipping label print layout opened!");
}


// =====================================================
// GOAL 4: AUCTIONS AND BIDDING SYSTEM
// =====================================================

// Initialize and render all bids on product cards on screen
async function initAuctions() {
    const auctionCards = document.querySelectorAll('.product-card');
    
    for (let card of auctionCards) {
        const isAuction = card.querySelector('.badge-auction');
        if (!isAuction) continue;
        
        const productId = card.getAttribute('data-id');
        if (!productId) continue;
        
        try {
            const data = await api.getHighestBid(productId);
            if (data.highest_bid && data.highest_bid > 0) {
                const priceEl = card.querySelector('.product-price');
                if (priceEl) {
                    priceEl.textContent = `R${data.highest_bid}`;
                }
                card.setAttribute('data-current-bid', data.highest_bid);
            }
        } catch (e) {
            console.error("Failed to load bid for", productId);
        }
    }
}

// Open custom bidding input dialog
function openBiddingModal(productId, productTitle, currentBidVal) {
    const activeBid = parseFloat(currentBidVal);
    const minBid = activeBid + 50; // increment by R50 minimum
    
    const overlay = document.createElement('div');
    overlay.className = 'simple-modal-overlay';
    overlay.id = 'bidding-modal';
    
    overlay.innerHTML = `
        <div class="simple-modal-box">
            <button class="simple-modal-close" onclick="closeBiddingModal()">&times;</button>
            <h3>Place Your Bid</h3>
            <div class="bidding-modal-summary">
                <p><strong>Item:</strong> ${productTitle}</p>
                <p><strong>Current Highest Bid:</strong> R${activeBid.toLocaleString('en-ZA')}</p>
                <p><strong>Minimum Bid:</strong> R${minBid.toLocaleString('en-ZA')}</p>
            </div>
            
            <div class="checkout-form-group" style="margin-bottom: 20px;">
                <label for="bid-amount-input">Your Bid Amount (ZAR)</label>
                <input type="number" id="bid-amount-input" value="${minBid}" min="${minBid}">
            </div>
            
            <button class="place-order-btn" onclick="submitBid(${productId}, '${productTitle.replace(/'/g, "\\'")}', ${minBid})">Confirm Bid</button>
        </div>
    `;
    
    document.body.appendChild(overlay);
}

function closeBiddingModal() {
    const modal = document.getElementById('bidding-modal');
    if (modal) modal.remove();
}

async function submitBid(productId, productTitle, minAmount) {
    const inputEl = document.getElementById('bid-amount-input');
    const userAmount = parseFloat(inputEl.value);
    
    if (isNaN(userAmount) || userAmount < minAmount) {
        alert(`Your bid must be at least R${minAmount.toLocaleString('en-ZA')}`);
        return;
    }
    
    try {
        await api.placeBid(productId, userAmount);
        closeBiddingModal();
        notify(`Bid of R${userAmount.toLocaleString('en-ZA')} placed successfully!`);
        initAuctions();
    } catch (err) {
        alert(err.message || "Failed to place bid. Make sure you are logged in.");
    }
}


// =====================================================
// GOAL 5: MESSENGER / BUYER-SELLER CHAT SYSTEM
// =====================================================

// Initialize chat action buttons on product cards
function initChatButtons() {
    const chatButtons = document.querySelectorAll('.product-chat-btn');
    
    chatButtons.forEach(btn => {
        btn.addEventListener('click', function(event) {
            event.preventDefault();
            const sellerName = this.getAttribute('data-seller');
            const productId = this.getAttribute('data-product-id');
            const sellerId = this.getAttribute('data-seller-id');
            openChatWidget(sellerName, productId, sellerId);
        });
    });
}

// Create or open the overlay chat box at the bottom right
async function openChatWidget(sellerName, productId, sellerId) {
    window._activeChatSellerName = sellerName;
    window._activeChatProductId = productId;
    window._activeChatSellerId = sellerId;
    
    // fetch existing messages
    let existingMsgs = [];
    try {
        const result = await api.getMessages(productId, sellerId);
        if (result.messages) existingMsgs = result.messages;
    } catch (e) {
        if(e.message && e.message.includes("sign in")) {
            alert("Please sign in to chat.");
            return;
        }
    }

    const existingChat = document.getElementById('nsmfy-chat-widget');
    if (existingChat) existingChat.remove();
    
    const chatWidget = document.createElement('div');
    chatWidget.className = 'live-chat-widget';
    chatWidget.id = 'nsmfy-chat-widget';
    
    let msgsHtml = existingMsgs.map(m => `<div class="live-chat-bubble ${m.sender_name === sellerName ? 'received' : 'sent'}">${m.message_text}</div>`).join('');
    if (existingMsgs.length === 0) {
        msgsHtml = `<div class="live-chat-bubble received">Hello there! Thanks for reaching out about my listing. How can I help you?</div>`;
    }

    chatWidget.innerHTML = `
        <div class="live-chat-header" onclick="toggleChatMinization()">
            <span>Chatting with ${sellerName}</span>
            <div class="live-chat-header-actions">
                <button onclick="event.stopPropagation(); closeChatWidget();">&times;</button>
            </div>
        </div>
        <div class="live-chat-body" id="chat-body-messages">
            ${msgsHtml}
        </div>
        <div class="live-chat-footer">
            <input type="text" class="live-chat-input" id="chat-user-msg" placeholder="Type a message..." onkeydown="if(event.key==='Enter') sendChatMessage()">
            <button class="live-chat-send-btn" onclick="sendChatMessage()">Send</button>
        </div>
    `;
    
    document.body.appendChild(chatWidget);
    scrollChatBottom();
}

function closeChatWidget() {
    const chat = document.getElementById('nsmfy-chat-widget');
    if (chat) chat.remove();
}

function toggleChatMinization() {
    const chat = document.getElementById('nsmfy-chat-widget');
    if (chat) {
        chat.classList.toggle('minimized');
    }
}

function scrollChatBottom() {
    const body = document.getElementById('chat-body-messages');
    if (body) {
        body.scrollTop = body.scrollHeight;
    }
}

async function sendChatMessage() {
    const input = document.getElementById('chat-user-msg');
    const messageText = input.value.trim();
    if (!messageText) return;
    
    const productId = window._activeChatProductId;
    const sellerId = window._activeChatSellerId;

    try {
        await api.sendMessage(productId, sellerId, messageText);
        
        const body = document.getElementById('chat-body-messages');
        const userBubble = document.createElement('div');
        userBubble.className = 'live-chat-bubble sent';
        userBubble.textContent = messageText;
        body.appendChild(userBubble);
        
        input.value = '';
        scrollChatBottom();
    } catch (err) {
        alert(err.message || "Failed to send message. Are you logged in?");
    }
}
