
// 1. Get cart items from DB via api.js
async function getCart() {
    try {
        return await api.getCart();
    } catch (err) {
        console.error("Failed to fetch cart:", err);
        return [];
    }
}

// 2. Calculate total quantity of items in the cart and update the navbar badge
async function updateCartCount() {
    const cart = await getCart();
    const totalCount = cart.reduce((sum, item) => sum + parseInt(item.quantity), 0);
    
    const cartBadge = document.getElementById('cart-count');
    if (cartBadge) {
        cartBadge.textContent = totalCount;
        cartBadge.setAttribute('data-empty', totalCount === 0 ? 'true' : 'false');
    }
}

// 3. Extract numeric value from ZAR price string (e.g., "R2,999" -> 2999)
function parsePriceString(priceStr) {
    const cleaned = priceStr.replace(/[R\s,]/g, '');
    return parseFloat(cleaned) || 0;
}

// 4. Add a product to the cart (async database-backed version)
async function addToCart(productId, title) {
    try {
        await api.addToCart(productId, 1);
        await updateCartCount();
        showToastNotification(`Added "${title}" to your cart!`);
    } catch (err) {
        console.error("Error adding to cart:", err);
        showToastNotification("Failed to add item to cart. Please try again.");
    }
}

// 5. Create and show a temporary toast notification alert
function showToastNotification(message) {
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        document.body.appendChild(toastContainer);
    }
    
    const toast = document.createElement('div');
    toast.className = 'toast-alert';
    toast.textContent = message;
    
    toastContainer.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 3000);
}

// 6. Setup click event handlers for all "Add to Cart" buttons
function setupAddToCartButtons() {
    // Use event delegation for dynamic product cards
    document.addEventListener('click', async function(event) {
        const btn = event.target.closest('.product-cart-btn');
        if (!btn) return;
        
        event.preventDefault();
        
        const productCard = btn.closest('.product-card');
        if (!productCard) return;
        
        const titleEl = productCard.querySelector('.product-title');
        const title = titleEl ? titleEl.textContent.trim() : 'Product';
        const productId = productCard.getAttribute('data-id');
        
        if (productId) {
            await addToCart(parseInt(productId), title);
        } else {
            console.error("Missing data-id for product:", title);
        }
    });
}

// 7. Initialize navbar cart badge count and button handlers once the page loads
document.addEventListener('DOMContentLoaded', () => {
    updateCartCount();
    setupAddToCartButtons();
});
