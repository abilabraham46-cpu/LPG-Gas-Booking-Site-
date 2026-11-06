# Maintenance Mode Implementation Guide

## Overview
Maintenance mode has been implemented across all portal pages to block non-admin access during system maintenance.

## How It Works

### 1. **Database Storage**
- Table: `system_settings`
- Setting name: `maintenance_mode`
- Value: `0` (disabled) or `1` (enabled)

### 2. **Auth Helper Method**
Added to `includes/auth.php`:
```php
$auth->checkMaintenanceMode();
```
This method:
- Checks if maintenance_mode = 1 in database
- Allows admins to bypass (they can always access)
- Redirects non-admins to `maintenance.html`

### 3. **Protected Pages**

#### Customer Pages (Blocked during maintenance)
- ✅ `customer-auth.php` - Customer login
- ✅ `customer-register.php` - Customer registration
- ✅ `customer-portal.php` - Customer dashboard

#### Delivery Pages (Blocked during maintenance)
- ✅ `delivery-login.php` - Delivery staff login
- ✅ `delivery-portal.php` - Delivery dashboard

#### Admin Pages (Always accessible)
- ✅ `admin-portal.php` - Admin login/dashboard
- ✅ `system-settings.php` - System configuration

## Usage Instructions

### Enable Maintenance Mode
1. Login as admin
2. Go to **System Settings** page
3. Toggle "Enable Maintenance Mode" switch ON
4. Click **Save Changes**
5. Confirm the action

**Result:** All customers and delivery staff will be redirected to `maintenance.html` when they try to access any portal or login page.

### Disable Maintenance Mode
1. Login as admin (you can always login)
2. Go to **System Settings** page
3. Toggle "Enable Maintenance Mode" switch OFF
4. Click **Save Changes**

**Result:** Normal access restored for all users.

## What Happens During Maintenance

### For Non-Admin Users:
- ❌ Cannot login (redirected to maintenance page)
- ❌ Cannot register (redirected to maintenance page)
- ❌ Cannot access portals (redirected to maintenance page)
- ℹ️ See message: "Site is under maintenance, please try again later"

### For Admin Users:
- ✅ Can login normally
- ✅ Can access all admin pages
- ✅ Can manage the system
- ✅ Can toggle maintenance mode on/off
- ℹ️ See status indicator showing maintenance is active

## Status Indicators

The System Settings page shows:
- 🔴 **Red indicator**: Maintenance mode ACTIVE
  - "MAINTENANCE MODE ACTIVE"
  - "Customer and delivery portals are currently blocked"
  
- 🟢 **Green indicator**: System OPERATIONAL
  - "SYSTEM OPERATIONAL"
  - "All portals are accessible"

## Technical Implementation

### Code Location
```php
// In each protected page (customer/delivery pages):
require_once 'includes/auth.php';
$auth = new Auth();
$auth->checkMaintenanceMode(); // Blocks non-admins if maintenance is on
```

### Database Query
```sql
SELECT setting_value 
FROM system_settings 
WHERE setting_name = 'maintenance_mode'
```

### Update Query
```sql
INSERT INTO system_settings (setting_name, setting_value, updated_by) 
VALUES ('maintenance_mode', ?, ?) 
ON DUPLICATE KEY UPDATE 
  setting_value = ?,
  updated_by = ?,
  updated_at = CURRENT_TIMESTAMP
```

## Testing Checklist

- [ ] Enable maintenance mode as admin
- [ ] Try to access customer login → Should redirect to maintenance.html
- [ ] Try to access customer registration → Should redirect to maintenance.html
- [ ] Try to access delivery login → Should redirect to maintenance.html
- [ ] Login as admin → Should work normally
- [ ] Admin can access System Settings → Should work
- [ ] Disable maintenance mode as admin
- [ ] Customer login works again
- [ ] Delivery login works again
- [ ] Customer registration works again

## Files Modified

1. **includes/auth.php**
   - Added `isMaintenanceMode()` method
   - Added `checkMaintenanceMode()` method

2. **customer-auth.php**
   - Added maintenance mode check

3. **customer-register.php**
   - Added maintenance mode check

4. **customer-portal.php**
   - Added maintenance mode check

5. **delivery-login.php**
   - Added maintenance mode check

6. **delivery-portal.php**
   - Added maintenance mode check

7. **system-settings.php**
   - Already had maintenance mode toggle
   - Cleaned up debug logging

## Maintenance Page
Make sure `maintenance.html` exists with appropriate styling and message:
```html
<!-- Suggested content -->
<h1>🔧 Site Under Maintenance</h1>
<p>We're currently performing scheduled maintenance.</p>
<p>Please check back in a few minutes.</p>
```

## Notes
- Admins can ALWAYS access the system, even during maintenance
- Maintenance mode status is stored in database (persists across sessions)
- The toggle in System Settings provides instant feedback
- All changes are logged with admin user ID and timestamp
