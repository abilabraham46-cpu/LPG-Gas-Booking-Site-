# Project Overview

## Lost & Found App (Mobile)

A mobile-first application for reporting, searching, and recovering lost items.

Features:
- User-friendly reporting flow for lost and found items
- Structured item details including location, category, description, and photos
- Search and filter tools for quick item discovery
- Real-time status tracking so owners and finders stay updated
- Secure user authentication and account management
- Notifications for new matches, status updates, and recovery messages
- Workflow to connect finders and owners efficiently and safely

Use cases:
- Students, commuters, and event attendees reporting lost personal items
- Businesses managing found property and returning items to owners
- Secure communication and verification to protect user privacy

## LPG Web App

This repository implements an LPG gas booking and delivery management system with full customer, delivery, and admin workflows.

Key features:
- Customer portal for LPG cylinder booking and order tracking
- Secure login and registration for customers, delivery staff, and administrators
- Admin dashboard for order management, inventory, and system settings
- Delivery portal for tracking deliveries, assignments, and realtime updates
- Notification system for order status, delivery updates, and alerts
- Inventory and cylinder management with restocking support
- Built with PHP, HTML, CSS, JavaScript, and MySQL on a WAMP-compatible stack

Core modules:
- `customer-portal.php`, `customer-auth.php`, `customer-register.php`
- `admin-portal.php`, `manage-users.php`, `system-settings.php`, `system-status.php`
- `delivery-portal.php`, `delivery-login.php`, `delivery-operations.php`, `delivery-tracking.php`
- `api/notifications.php`, `api/sse-notifications.php`, `api/inventory.php`, `api/order-status.php`
- `admin/cylinder-inventory.php`, `admin/restock_cylinders.php`, `admin/payment-verification.php`

## Notes

- The current workspace is built around the LPG gas booking system.
- The Lost & Found app description is provided here as a separate concept for planning or future implementation.
- Existing documentation in `README.txt` records a cleanup report and preserved core system files.
