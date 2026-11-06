/**
 * Enhanced Customer Portal JavaScript - Simplified Version
 * Adds real-time notifications and improved UX (No PWA complexity)
 */

class LPGCustomerPortal {
    constructor() {
        this.userId = window.currentUserId;
        this.eventSource = null;
        this.notificationQueue = [];
        this.initializeRealTimeFeatures();
        this.initializeEnhancements();
    }

    // Real-time notifications using Server-Sent Events
    initializeRealTimeFeatures() {
        if (typeof(EventSource) !== "undefined") {
            this.eventSource = new EventSource('/api/sse-notifications.php');
            
            this.eventSource.onmessage = (event) => {
                const data = JSON.parse(event.data);
                this.handleNotification(data);
            };

            this.eventSource.onerror = (event) => {
                console.log('SSE connection error', event);
                // Retry connection after 5 seconds
                setTimeout(() => {
                    this.initializeRealTimeFeatures();
                }, 5000);
            };
        }
    }

    // Handle incoming notifications
    handleNotification(notification) {
        if (notification.type === 'heartbeat') return;

        // Add to notification queue
        this.notificationQueue.push(notification);
        
        // Show notification
        this.showNotification(notification);
        
        // Update relevant UI sections
        this.updateUI(notification);
    }

    // Show notification popup
    showNotification(notification) {
        // Create notification element
        const notificationEl = document.createElement('div');
        notificationEl.className = 'notification-popup show';
        notificationEl.innerHTML = `
            <div class="notification-content">
                <div class="notification-icon">
                    <i class="fas fa-${this.getNotificationIcon(notification.type)}"></i>
                </div>
                <div class="notification-text">
                    <h4>${notification.title}</h4>
                    <p>${notification.message}</p>
                </div>
                <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        // Add to page
        document.body.appendChild(notificationEl);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notificationEl.parentElement) {
                notificationEl.remove();
            }
        }, 5000);
    }

    getNotificationIcon(type) {
        const icons = {
            'order_update': 'shopping-cart',
            'delivery_update': 'truck',
            'payment_success': 'credit-card',
            'system_alert': 'exclamation-triangle'
        };
        return icons[type] || 'bell';
    }

    // Update UI based on notification
    updateUI(notification) {
        switch (notification.type) {
            case 'order_update':
                this.updateOrderStatus(notification.data);
                break;
            case 'delivery_update':
                this.updateDeliveryTracking(notification.data);
                break;
        }
    }

    updateOrderStatus(orderData) {
        const orderElement = document.querySelector(`[data-order-id="${orderData.id}"]`);
        if (orderElement) {
            const statusElement = orderElement.querySelector('.order-status');
            if (statusElement) {
                statusElement.textContent = orderData.status;
                statusElement.className = `order-status status-${orderData.status.replace(' ', '-')}`;
            }
        }
    }

    // Enhanced order tracking with live updates
    initializeOrderTracking() {
        const trackingElements = document.querySelectorAll('.order-tracking');
        trackingElements.forEach(element => {
            const orderId = element.dataset.orderId;
            this.setupOrderTracking(orderId, element);
        });
    }

    setupOrderTracking(orderId, element) {
        // Fetch current order status
        fetch(`/api/order-status.php?order_id=${orderId}`)
            .then(response => response.json())
            .then(data => {
                this.renderOrderTracking(element, data);
            });
    }

    renderOrderTracking(element, orderData) {
        const trackingSteps = [
            { status: 'pending', label: 'Order Placed', icon: 'shopping-cart' },
            { status: 'confirmed', label: 'Confirmed', icon: 'check-circle' },
            { status: 'processing', label: 'Processing', icon: 'cog' },
            { status: 'out_for_delivery', label: 'Out for Delivery', icon: 'truck' },
            { status: 'delivered', label: 'Delivered', icon: 'home' }
        ];

        const currentStatusIndex = trackingSteps.findIndex(step => step.status === orderData.status);

        let trackingHTML = '<div class="tracking-steps">';
        trackingSteps.forEach((step, index) => {
            const isCompleted = index <= currentStatusIndex;
            const isCurrent = index === currentStatusIndex;
            
            trackingHTML += `
                <div class="tracking-step ${isCompleted ? 'completed' : ''} ${isCurrent ? 'current' : ''}">
                    <div class="step-icon">
                        <i class="fas fa-${step.icon}"></i>
                    </div>
                    <div class="step-label">${step.label}</div>
                    ${index < trackingSteps.length - 1 ? '<div class="step-connector"></div>' : ''}
                </div>
            `;
        });
        trackingHTML += '</div>';

        element.innerHTML = trackingHTML;
    }

    // Initialize enhanced features (simplified)
    initializeEnhancements() {
        this.initializeOrderTracking();
        this.initializeQuickReorder();
        this.initializeFeedbackSystem();
    }

    // Quick reorder functionality
    initializeQuickReorder() {
        document.querySelectorAll('.quick-reorder-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const orderId = e.target.dataset.orderId;
                this.quickReorder(orderId);
            });
        });
    }

    quickReorder(orderId) {
        // Pre-fill order form with previous order details
        fetch(`/api/order-details.php?order_id=${orderId}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('cylinder_type').value = data.cylinder_type;
                document.getElementById('quantity').value = data.quantity;
                document.getElementById('delivery_address').value = data.delivery_address;
                document.getElementById('contact_number').value = data.contact_number;
                
                // Switch to booking tab
                this.switchTab('booking');
                
                // Highlight pre-filled form
                document.querySelector('.booking-form').classList.add('pre-filled');
                setTimeout(() => {
                    document.querySelector('.booking-form').classList.remove('pre-filled');
                }, 2000);
            });
    }

    // Feedback system
    initializeFeedbackSystem() {
        document.querySelectorAll('.rate-delivery-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const deliveryId = e.target.dataset.deliveryId;
                this.showFeedbackModal(deliveryId);
            });
        });
    }

    showFeedbackModal(deliveryId) {
        const modal = document.createElement('div');
        modal.className = 'feedback-modal';
        modal.innerHTML = `
            <div class="modal-content">
                <h3>Rate Your Delivery Experience</h3>
                <div class="rating-stars">
                    ${[1,2,3,4,5].map(star => 
                        `<span class="star" data-rating="${star}">⭐</span>`
                    ).join('')}
                </div>
                <textarea placeholder="Share your feedback..." id="feedback-text"></textarea>
                <div class="modal-actions">
                    <button onclick="lpgPortal.submitFeedback(${deliveryId})" class="btn-primary">Submit</button>
                    <button onclick="this.parentElement.parentElement.parentElement.remove()" class="btn-secondary">Cancel</button>
                </div>
            </div>
        `;

        // Add star rating interaction
        modal.querySelectorAll('.star').forEach(star => {
            star.addEventListener('click', (e) => {
                const rating = e.target.dataset.rating;
                modal.querySelectorAll('.star').forEach((s, i) => {
                    s.style.opacity = i < rating ? '1' : '0.3';
                });
                modal.dataset.rating = rating;
            });
        });

        document.body.appendChild(modal);
    }

    submitFeedback(deliveryId) {
        const modal = document.querySelector('.feedback-modal');
        const rating = modal.dataset.rating;
        const feedback = document.getElementById('feedback-text').value;

        fetch('/api/submit-feedback.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                delivery_id: deliveryId,
                rating: rating,
                feedback: feedback
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showNotification({
                    type: 'success',
                    title: 'Thank You!',
                    message: 'Your feedback has been submitted successfully.'
                });
            }
            modal.remove();
        });
    }

    // Utility function to switch tabs
    switchTab(tabName) {
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });

        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
        document.getElementById(tabName).classList.add('active');
    }

    // Cleanup when page unloads
    destroy() {
        if (this.eventSource) {
            this.eventSource.close();
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.lpgPortal = new LPGCustomerPortal();
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.lpgPortal) {
        window.lpgPortal.destroy();
    }
});