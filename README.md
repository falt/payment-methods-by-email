# WooCommerce Payment Methods by Email

Filter WooCommerce payment methods based on customer email domains with automatic updates from GitHub.

## Features

- Filter payment gateways by customer email domain
- Admin interface for managing domain rules  
- Real-time checkout validation without page refresh
- Automatic plugin updates from GitHub releases

## Installation

1. Download the plugin files
2. Upload to your WordPress site's `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure rules in WooCommerce > Payment by Email

## Auto-Updates

This plugin automatically checks for updates from GitHub releases. When you create a new release with a higher version number, sites with this plugin installed will receive update notifications in their WordPress admin.

## Version Management

To release a new version:

1. Update the `Version:` field in `wc-payment-methods-by-email.php`
2. Commit and push to master branch
3. GitHub Actions will automatically create a release
4. Installed sites will detect the update within 24 hours

## Configuration

Navigate to **WooCommerce > Payment by Email** to configure domain-based payment method rules.

## License

This plugin is open source and available under the GPL v2 or later license.