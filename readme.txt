=== VisioFex for WooCommerce ===
Contributors: your-company
Tags: payments, checkout, woocommerce, visiofex, blocks, refunds
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.5.7
License: MIT
License URI: https://opensource.org/licenses/MIT

Hosted checkout session for VisioFex with refunds and WooCommerce Blocks support.

== Description ==
- **Complete Order Integration**: Forwards all order components to VisioFex including products, coupon discounts, shipping, fees, and taxes
- **Automatic Order Sync**: Pending orders are automatically refreshed when visiting the Orders page - no manual sync required
- **Enhanced Payment Flow**: Robust redirect fallback system handles plugin conflicts and ensures reliable checkout experience
- **Auto-Configuration**: Store URLs are automatically detected and configured to prevent setup errors
- **Comprehensive Logging**: Detailed diagnostic logging with sensitive data protection for easy troubleshooting
- **Settings page includes**: Test mode, Secret Key, Vendor ID, Auto-sync window (1-48 hours), and Debug logging
- **Hosted Checkout**: Creates a hosted checkout session and redirects customers to the secure VisioFex payment page
- **Full Refund Support**: Process refunds directly from the WooCommerce order screen with automatic synchronization
- **WooCommerce Blocks**: Full compatibility with modern WooCommerce block-based checkout
- **Professional Card Icons**: Protected CSS ensures card brand icons display properly in all themes and page builders

Requirements and tips:
- WooCommerce Cart, Checkout, and My Account pages should exist and permalinks should be set to Postname WordPress->Settings->Permalinks
- **Automatic Order Sync**: Orders with pending payments are automatically refreshed when you visit WooCommerce->Orders (configurable 1-48 hour window)
- **Auto-Configuration**: Store domain URLs are automatically detected - no manual setup required for return/success pages
- **Enhanced Debugging**: Enable debug logging for comprehensive diagnostics including order totals, coupon processing, and API communications
- **Troubleshooting**: Visit WooCommerce->Status->Logs->VisioFex for detailed logs with automatic sensitive data masking
- **Plugin Compatibility**: Built-in fallback system handles conflicts with other plugins (WhatsApp widgets, etc.)
- **Payment Flow**: The plugin redirects users during checkout - the enhanced fallback system ensures reliable redirects even with plugin interference
- **Order Processing**: Complete order details including all discounts and fees are automatically forwarded to VisioFex
- **Post-Payment**: Customers are redirected back to your site's order received page using auto-detected Store Domain
- **Refund Management**: Orders can be refunded directly by pressing the Refund button on the Order page with real-time synchronization
- **Order Synchronization**: Auto-sync handles most cases automatically, or manually click Actions->Sync with VisioFex on any order

== Installation ==
1. Upload the ZIP via Plugins > Add New > Upload Plugin and click activate.

== Configuration ==
In the VisioFex portal: (https://visiofex.com/login)
- Click Developer
- Click New App. You can name it whatever you would like.
- Locate your App ID (Should be directly below the "Manage App" this will be entered into the visiofex plugin's Vendor ID field.
- Click API Keys and note your production api key, this will be entered into visiofex plugin's Secret Key field.

In wordpress (https://yoursite/wp-admin): 
- Click Plugins and locate the VisioFex plugin and make sure it's activated
- Navigate to WooCommerce -> Settings -> Payments -> VisioFex Pay
- Paste your production key from VisioFex into the "Secret Key" field
- Paste your app ID from VisioFex into the "Vendor ID" field
- **Store Domain** is automatically detected (shows your site URL) - only change if using a different domain
- Set **Auto Sync Window** to desired hours (1-48, default 24h) for automatic order refresh frequency
- For production, uncheck "Enable test mode"
- **Debugging**: Check "Enable debugging" for comprehensive logging including auto-sync operations (recommended during setup)
- Check the "Enable" checkbox at the top to activate the payment method
- Save changes to enable the gateway

== Changelog ==

= 1.5.7 =
* **CRITICAL FIX**: Fixed autosync timezone issue that was causing orders in wrong timezone to be processed
* **TIMEZONE**: Changed autosync cutoff calculation from UTC to site local timezone to properly match WooCommerce order dates
* **IMPROVED**: Autosync now correctly respects WordPress site timezone settings instead of always using UTC
* **RELIABILITY**: Fixed issue where 1-hour autosync would incorrectly find "future" orders due to timezone mismatch

= 1.5.6 =
* **CRITICAL FIX**: Fixed "back to store" button on VisioFex payment portal - now correctly returns to checkout page instead of order received page
* **IMPROVED**: Separated returnURL (checkout page) from successURL (thank-you page) for proper user flow
* **ENHANCED**: Better distinction between payment success redirect and store navigation
* **RELIABILITY**: Customers can now properly return to checkout if they need to change payment details

= 1.5.5 =
* **CRITICAL FIX**: Removed duplicate WooCommerce Blocks registration that was causing WordPress notices and header warnings
* **CLEANUP**: Eliminated duplicate payment method title fixing hooks outside the main plugins_loaded action
* **IMPROVED**: Cleaner, more maintainable code structure with reduced redundancy
* **STABILITY**: Resolved "visiofex is already registered" error and cascading header modification warnings
* **PERFORMANCE**: Streamlined plugin loading process with proper duplicate prevention

= 1.5.4 =
* **CRITICAL FIX**: Added comprehensive duplicate registration protection for WooCommerce Blocks integration
* **NEW**: Static flag system prevents multiple WooCommerce Blocks registration attempts during WordPress initialization
* **NEW**: Registry check validation ensures payment method isn't registered multiple times
* **ENHANCED**: Two-layer protection against duplicate registration: static flag + registry validation
* **FIX**: Resolved WordPress notices about duplicate payment method registration
* **IMPROVED**: More robust WooCommerce Blocks integration with proper error prevention

= 1.5.3 =
* **CRITICAL FIX**: Corrected WooCommerce Blocks class name from "WC_Gateway_VisioFex_Blocks_Support" to "WC_Gateway_VisioFex_Blocks"
* **FIX**: Resolved fatal PHP error that prevented WordPress from loading when plugin was activated before WooCommerce
* **IMPROVED**: WooCommerce Blocks integration now properly instantiates the correct class
* **STABILITY**: Plugin now loads gracefully regardless of activation order relative to WooCommerce

= 1.5.2 =
* **CRITICAL FIX**: Consolidated all WooCommerce-dependent code inside plugins_loaded action with proper safety checks
* **SECURITY**: Added validation to ensure WooCommerce classes exist before attempting to extend them
* **IMPROVED**: Plugin now handles activation in any order relative to WooCommerce without fatal errors
* **ENHANCED**: Better error messaging when WooCommerce is not active - shows admin notice instead of fatal error
* **STABILITY**: Eliminated PHP fatal errors when WooCommerce is deactivated or not installed

= 1.5.1 =
* **CRITICAL FIX**: Fixed return URL redirect issue that was sending customers back to checkout instead of order confirmation page
* **IMPROVED**: Now uses WooCommerce's built-in `get_return_url()` and `get_cancel_order_url()` helper methods for proper URL generation
* **ENHANCED**: URLs are now future-proof and work correctly with any permalink structure or WooCommerce endpoint configuration
* **RELIABILITY**: Customers will now properly land on the "Thank you for your order" page after completing payment through VisioFex

= 1.5.0 =
* **REFACTOR**: Simplified auto-update mechanism to check only the `master` branch, removing multi-environment logic.
* **REFACTOR**: Removed temporary debugging code related to the Plugin Update Checker.
* **MAINTENANCE**: Updated plugin version to 1.5.0 for new release.

= 1.4.9 =
* **NEW**: Automatic plugin updates via GitHub integration - plugin will now auto-update from official repository
* **NEW**: Staging branch support for testing updates before production release
* **SECURITY**: Enhanced session ID validation - prevents duplicate session IDs across multiple orders
* **SECURITY**: Session ID conflict detection with proper error logging and admin notifications
* **IMPROVED**: Comprehensive protection against session ID manipulation vulnerabilities
* **DEVELOPER**: Integrated Plugin Update Checker v5.6 for seamless GitHub-based updates
* **DEVELOPER**: Auto-sync now includes duplicate session tracking to prevent cross-order contamination

= 1.4.8 =
* **NEW**: Automatic order synchronization - pending VisioFex orders are automatically refreshed when visiting Orders page
* **NEW**: Configurable auto-sync time window (1-48 hours, default 24h) with settings integration in payment gateway options
* **NEW**: Smart transient locking prevents duplicate API calls during auto-sync operations (15-second lock duration)
* **NEW**: Auto-detection of site URLs for return/success/cancel URLs - no more manual configuration errors
* **NEW**: Enhanced protective CSS for card brand icons prevents oversizing in Elementor and other page builders
* **NEW**: Comprehensive auto-sync logging shows eligible orders, API responses, and transaction ID resolution
* **ENHANCED**: Auto-sync only processes orders with session IDs but missing transaction IDs within configured window
* **ENHANCED**: Store domain field now auto-populated with site URL on activation and shows helpful placeholder text
* **FIX**: Card brand icons (Visa, Mastercard, etc.) now properly constrained to prevent oversizing in theme builders
* **FIX**: Auto-sync handles both classic WooCommerce orders and modern HPOS order storage systems
* **IMPROVED**: Settings page shows "Auto-detected from your site" description for store domain field
* **IMPROVED**: Auto-sync logs detailed information when debug logging is enabled for easy troubleshooting
* **DEVELOPER**: CSS protection includes max-height constraints and object-fit rules for consistent card icon display
* **DEVELOPER**: Auto-sync uses proper WordPress transient system and WooCommerce order query methods

= 1.4.7 =
* NEW: Setting to show/hide the VisioFex logo on checkout next to the payment title
* ENHANCED: Classic and Blocks checkout both respect the logo toggle; Blocks icon hidden when disabled
* FIX: Order admin page now always shows plain text “Payment via visiofex” (no HTML rendered)
* FIX: Orders list Billing column consistently shows “via visiofex”
* DEV: Cleaned up temporary debug output and simplified title logic across contexts
* DEV: Local Docker environment now uses http://localhost:8000 (removed ngrok override)

= 1.4.6 =
* **MAJOR**: Fixed payment method display inconsistency across admin and order pages
* **FIX**: Orders page now consistently shows "via VisioFex" instead of mixed "via Secure Payment"/"via VisioFex Pay"
* **FIX**: New orders now save with correct "VisioFex" payment method title in database
* **ENHANCED**: Simplified title logic - checkout shows enhanced branding, all other contexts show "VisioFex"
* **ENHANCED**: Comprehensive debug logging for payment method title resolution across different contexts
* **IMPROVED**: Consistent branding experience from checkout through order management
* **DEVELOPER**: Overrode gateway title property to ensure consistent database storage for new orders

= 1.4.5 =
* **MAJOR**: Enhanced refund system with robust error handling and retry logic
* **NEW**: Smart refund retry detection - handles cases where WooCommerce has refund records but VisioFex doesn't
* **NEW**: Comprehensive refund debugging with detailed logging of existing refunds and validation steps
* **NEW**: Floating point precision handling with tolerance-based amount validation (prevents $2.06 vs $2.06 comparison failures)
* **NEW**: Enhanced refund UI - ensures refund amount fields remain editable for VisioFex orders
* **NEW**: Custom refund reason dropdown with predefined options (requested_by_customer, duplicate, fraudulent)
* **ENHANCED**: Refund validation now uses WooCommerce decimal formatting with 0.05 tolerance for precision issues
* **ENHANCED**: Detailed refund logging shows raw vs formatted amounts, existing refund analysis, and API response tracking
* **ENHANCED**: Improved error messages with specific failure reasons (authentication, payment not found, server errors)
* **FIX**: Resolved refund amount validation failures caused by floating point precision differences
* **FIX**: Fixed refund UI interference - amount fields now remain editable and responsive
* **FIX**: Improved handling of retry scenarios where previous refund attempts partially failed
* **SECURITY**: Enhanced validation for admin operations with proper capability checks and nonce verification

= 1.4.8 =
* **FIX**: Resolved payment method title display inconsistency - now consistently shows "Payment via visiofex" in WooCommerce orders admin
* **ENHANCED**: Added global hooks with high priority to ensure consistent payment method display across all contexts
* **IMPROVED**: Cleaner code structure with removal of redundant filter methods and simplified logging

= 1.4.4 =
* **NEW**: Enhanced checkout UI with professional card brand icons (Visa, Mastercard, Amex, Discover)
* **NEW**: Dynamic payment method branding - VisioFex logo with "Secure Payment" text on checkout
* **NEW**: Context-aware title display - shows "VisioFex" in admin/orders, enhanced branding on checkout
* **NEW**: Professional SVG card icons for crisp display across all devices and screen sizes
* **NEW**: Rich text description formatting - first line automatically bolded without HTML editing
* **ENHANCED**: Responsive icon sizing for optimal display on desktop and mobile devices
* **ENHANCED**: Clean admin display - removed HTML formatting from order pages for better readability
* **ENHANCED**: Improved CSS organization with streamlined, maintainable stylesheet
* **FIX**: Fixed description fallback when settings field is left empty - now properly shows default text
* **FIX**: Consistent branding between classic checkout and WooCommerce Blocks checkout
* **IMPROVED**: Visual hierarchy with VisioFex logo prominently displayed alongside payment title

= 1.4.3 =
* **MAJOR**: Simplified line items to single branded order total entry for cleaner customer experience
* **ENHANCED**: Line item now displays as "[Store Name] Order Total" instead of complex per-product breakdown
* **FIX**: Eliminates confusing tax calculation displays while maintaining VisioFex API compliance
* **IMPROVED**: Cleaner, more professional appearance on VisioFex payment page
* **MAINTAINED**: All existing functionality including coupon discounts, shipping, and refund processing

= 1.4.2 =
* **FIX**: Fixed line item display issue - product prices now show original amounts before discounts
* **FIX**: Eliminated confusing "Order Adjustment" line items caused by double-discount calculation
* **ENHANCED**: Line items now display more clearly: products at original price, separate discount line items

= 1.4.1 =
* **FIX**: Fixed "back to store" button on VisioFex portal - now correctly returns to checkout page instead of order received page
* **ENHANCED**: Improved URL logging to show return URL in debug logs

= 1.4.0 =
* **MAJOR**: Fixed coupon discounts not being forwarded to VisioFex - now sends complete order breakdown
* **NEW**: Comprehensive line items now include products, coupon discounts, shipping, fees, and taxes
* **NEW**: Added total amount validation with automatic adjustment for rounding differences
* **ENHANCED**: Line items now show final prices after all discounts are applied
* **ENHANCED**: Improved metadata sent to VisioFex includes coupon count and shipping status
* **ENHANCED**: Better logging shows complete order breakdown and line item details
* **FIX**: Discount amounts now properly calculated including discount tax

= 1.3.0 =
* **NEW**: Comprehensive logging system for customer troubleshooting and support
* **NEW**: Sensitive data masking in logs (vendor ID, secret keys automatically protected)
* **NEW**: Enhanced API request/response logging with timing and error details
* **NEW**: Detailed checkout process logging including validation, line items, and redirects
* **NEW**: Complete refund operation logging with amount validation and API responses
* **NEW**: Transaction capture logging with polling sequence and status tracking
* **NEW**: Order synchronization logging including payment status and refund reconciliation
* **ENHANCED**: Log levels support (info, debug, warning, error) for better diagnostics
* **ENHANCED**: Gateway availability logging with detailed validation messages
* **IMPROVED**: Better error messages and response structure analysis in logs

= 1.2.4 =
* **MAJOR**: Enhanced redirect fallback system for improved payment flow reliability
* **NEW**: Robust JSON extraction handles mixed HTML/JSON responses (resolves plugin conflicts)
* **NEW**: Multiple JSON parsing strategies for maximum compatibility with theme/plugin interference
* **ENHANCED**: Both fetch() and XHR request monitoring for comprehensive coverage
* **ENHANCED**: Better error handling and logging for failed redirects
* **FIX**: Resolved conflicts with WhatsApp widgets and other Must Use plugins
* **FIX**: Improved handling of contaminated API responses
* **IMPROVED**: More reliable automatic redirects to VisioFex payment pages

= 1.2.1 =
* **FIX**: PHP 8.2 compatibility - added explicit public properties to resolve dynamic property deprecation warnings
* **ENHANCED**: Better fallback script version management
* **IMPROVED**: Code structure for better maintainability

= 1.2.0 =
* Simplified setup (Secret Key, Vendor ID, Store Domain). Added refund support and Blocks integration.
