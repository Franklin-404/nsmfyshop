
const API_BASE = 'api';

async function apiFetch(endpoint, method = 'GET', body = null, isMultipart = false) {
    const options = {
        method: method,
    };

    if (body) {
        if (isMultipart) {
            // For file uploads (FormData), do not set Content-Type header manually.
            // The browser will auto-set it along with the boundary.
            options.body = body;
        } else {
            options.headers = {
                'Content-Type': 'application/json'
            };
            options.body = JSON.stringify(body);
        }
    }

    try {
        const response = await fetch(`${API_BASE}/${endpoint}`, options);
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || `HTTP error! status: ${response.status}`);
        }
        return data;
    } catch (error) {
        console.error(`API Fetch Error [${endpoint}]:`, error);
        throw error;
    }
}

// Global Auth State and API Actions
const api = {
    // Auth Actions
    async getSession() {
        return apiFetch('auth.php');
    },

    async login(email, password) {
        return apiFetch('auth.php', 'POST', { action: 'login', email, password });
    },

    async register(formData) {
        // Registration uses FormData to support file upload
        return apiFetch('auth.php', 'POST', formData, true);
    },

    async logout() {
        const res = await apiFetch('auth.php', 'POST', { action: 'logout' });
        // Refresh page to clear states
        window.location.reload();
        return res;
    },

    // Product Actions
    async getProducts(filters = {}) {
        const queryParams = new URLSearchParams();
        for (const [key, val] of Object.entries(filters)) {
            if (val) queryParams.append(key, val);
        }
        const queryString = queryParams.toString();
        return apiFetch(`products.php${queryString ? '?' + queryString : ''}`);
    },

    async getProduct(id) {
        return apiFetch(`products.php?id=${id}`);
    },

    async addProduct(formData) {
        return apiFetch('products.php', 'POST', formData, true);
    },

    async markProductSold(productId) {
        return apiFetch('products.php', 'PUT', { action: 'mark_sold', id: productId });
    },

    async deleteProduct(productId) {
        return apiFetch(`products.php?id=${productId}`, 'DELETE');
    },

    // Cart Actions
    async getCart() {
        return apiFetch('cart.php');
    },

    async addToCart(productId, quantity = 1) {
        return apiFetch('cart.php', 'POST', { product_id: productId, quantity });
    },

    async updateCartQuantity(productId, quantity) {
        return apiFetch('cart.php', 'PUT', { product_id: productId, quantity });
    },

    async removeFromCart(productId) {
        return apiFetch(`cart.php?product_id=${productId}`, 'DELETE');
    },

    async clearCart() {
        return apiFetch('cart.php?action=clear', 'DELETE');
    },

    // Order Actions
    async placeOrder(orderDetails) {
        return apiFetch('orders.php', 'POST', orderDetails);
    },

    async getOrders() {
        return apiFetch('orders.php');
    },

    // Bidding Actions
    async getHighestBid(productId) {
        return apiFetch(`bids.php?product_id=${productId}`);
    },

    async placeBid(productId, amount) {
        return apiFetch('bids.php', 'POST', { product_id: productId, amount });
    },

    // Messaging Actions
    async getChatThreads() {
        return apiFetch('messages.php?my_threads=true');
    },

    async getMessages(productId, otherUserId = 0) {
        let url = `messages.php?product_id=${productId}`;
        if (otherUserId > 0) {
            url += `&other_user_id=${otherUserId}`;
        }
        return apiFetch(url);
    },

    async sendMessage(productId, recipientId, messageText) {
        return apiFetch('messages.php', 'POST', {
            product_id: productId,
            recipient_id: recipientId,
            message_text: messageText
        });
    },

    // Admin Actions
    async getAdminDashboard() {
        return apiFetch('admin.php');
    },

    async approveSeller(sellerId) {
        return apiFetch('admin.php', 'POST', { action: 'approve', seller_id: sellerId });
    },

    async rejectSeller(sellerId) {
        return apiFetch('admin.php', 'POST', { action: 'reject', seller_id: sellerId });
    },

    // Logistics Actions
    async submitTracking(orderId, courier, trackingNumber) {
        return apiFetch('tracking.php', 'POST', {
            order_id: orderId,
            courier,
            tracking_number: trackingNumber
        });
    }
};

// Expose globally
window.api = api;

// =====================================================
// ROLE-BASED ACCESS CONTROL + NAVBAR STATE
// =====================================================

// Page access rules:
// - admin.html        → admin only
// - seller.html       → approved seller only
// - checkout.html     → any logged-in user
// - cart.html         → any logged-in user
// - success.html      → any logged-in user
// - login.html        → redirect away if already logged in
// - index.html, products.html → public (no restriction)

document.addEventListener('DOMContentLoaded', async () => {
    const currentPath = window.location.pathname;

    // Helper to get current page filename
    function onPage(name) {
        return currentPath.includes(name);
    }

    try {
        const session = await api.getSession();
        const authBtn  = document.getElementById('auth-btn');
        const navLinks = document.querySelector('.nav-links');

        if (session.logged_in) {
            const user = session.user;

            // --- UPDATE AUTH BUTTON ---
            if (authBtn) {
                authBtn.textContent = `Sign Out (${user.full_name.split(' ')[0]})`;
                authBtn.onclick = async (e) => {
                    e.preventDefault();
                    await api.logout();
                    window.location.href = 'index.html';
                };
            }

            // --- REDIRECT AWAY FROM LOGIN PAGE IF ALREADY SIGNED IN ---
            if (onPage('login.html')) {
                window.location.href = 'index.html';
                return;
            }

            // --- ROLE-BASED PAGE GUARDS ---

            // Admin page: only admins allowed
            if (onPage('admin.html') && user.role !== 'admin') {
                showAccessDenied('Admin Portal', 'This page is for administrators only.');
                return;
            }

            // Seller page: only approved sellers allowed
            if (onPage('seller.html')) {
                if (user.role !== 'seller') {
                    showAccessDenied('Seller Dashboard', 'This page is for registered sellers only. Apply to become a seller first.');
                    return;
                }
                if (user.seller_status !== 'approved') {
                    showAccessDenied('Seller Dashboard', 'Your seller application is currently <strong>' + user.seller_status + '</strong>. Please wait for admin approval before accessing your dashboard.');
                    return;
                }
            }

            // --- NAVBAR ADJUSTMENTS BY ROLE ---
            if (navLinks) {
                // Remove "Sell" link for buyers and admins (only sellers see it)
                if (user.role !== 'seller') {
                    const sellLink = document.querySelector('a[href="seller.html"]');
                    if (sellLink) sellLink.remove();
                }

                // Show "Admin" link only for admins
                if (user.role === 'admin' && !document.querySelector('a[href="admin.html"]')) {
                    const adminLink = document.createElement('a');
                    adminLink.href = 'admin.html';
                    adminLink.textContent = 'Admin';
                    if (onPage('admin.html')) {
                        document.querySelectorAll('.nav-links a').forEach(a => a.classList.remove('active'));
                        adminLink.className = 'active';
                    }
                    navLinks.appendChild(adminLink);
                }

                // Rename "Sell" to "Dashboard" for approved sellers
                if (user.role === 'seller' && user.seller_status === 'approved') {
                    const sellLink = document.querySelector('a[href="seller.html"]');
                    if (sellLink) sellLink.textContent = 'Dashboard';
                }
            }

        } else {
            // --- NOT LOGGED IN ---

            if (authBtn) {
                authBtn.textContent = 'Sign In / Register';
                authBtn.onclick = (e) => {
                    e.preventDefault();
                    window.location.href = 'login.html';
                };
            }

            // Pages that require login
            const protectedPages = ['admin.html', 'seller.html', 'checkout.html', 'success.html'];
            if (protectedPages.some(p => onPage(p))) {
                window.location.href = 'login.html';
                return;
            }
        }

    } catch (err) {
        console.error('Session init error:', err);
    }
});

// Show a friendly access denied message overlay instead of a blank page
function showAccessDenied(pageName, reason) {
    document.body.style.display = 'flex';
    document.body.style.flexDirection = 'column';
    document.body.style.minHeight = '100vh';
    document.body.style.background = '#f8fafc';

    const main = document.querySelector('main') || document.body;
    main.innerHTML = `
        <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:60px 20px;">
            <div style="background:#fff;border-radius:20px;box-shadow:0 10px 40px rgba(0,0,0,0.08);padding:56px 48px;max-width:480px;text-align:center;">
                <div style="width:72px;height:72px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;">
                    <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                </div>
                <h2 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 10px;">Access Restricted</h2>
                <p style="color:#64748b;font-size:14px;margin:0 0 8px;">${pageName}</p>
                <p style="color:#94a3b8;font-size:13px;margin:0 0 32px;line-height:1.6;">${reason}</p>
                <a href="index.html" style="display:inline-block;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;font-weight:700;font-size:14px;padding:12px 28px;border-radius:10px;text-decoration:none;">Back to Home</a>
            </div>
        </div>
    `;
}
