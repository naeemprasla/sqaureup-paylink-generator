# Square Invoice Plugin for WordPress

A WordPress plugin that provides a multi-step form for generating and sending Square payment invoices via a payment link. The form collects customer details and invoice info, and automatically creates a Square invoice using the Square API.

## 🚀 Features

- Multi-step frontend form via shortcode `[square_invoice_form]`
- Sends invoice via Square Payment Link
- Admin settings page for Square API keys and environment toggle
- Stores booking entries as a custom post type (`bookings`)
- ACF-powered admin view with read-only invoice details
- Optional invoice scheduling with date picker
- Responsive and styled with Flatpickr & custom CSS

---

## 📦 Installation

1. **Clone or download** this repository into your `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/yourusername/square-invoice-plugin.git

## Install dependencies via Composer:
2. **Clone or download** this repository into your `wp-content/plugins/` directory:
   ```composer
   cd square-invoice-plugin
   composer install

## 🔧 Configuration
  Navigate to Square Config in the WP Admin sidebar.
  
  Enter:
   - Square Access Token
   - Square Location ID
   - Select Environment (Sandbox or Production)
   - Save settings.

## 🧾 Using the Plugin
   - Use the shortcode [square_invoice_form] on any post or page to render the invoice form.

## 🛠 Requirements
  - WordPress 5.0+
  - PHP 7.4+
  - Advanced Custom Fields (ACF)
  - Composer (for installing Square SDK)

## 📚 Developer Notes
 - The plugin uses the Square PHP SDK to generate payment links.
 - Invoices are saved as custom posts of type bookings.
 - ACF is used to manage and display booking metadata in the admin panel.
 - All ACF fields are marked as read-only to prevent edits after submission.

## ✍️ License
 -MIT License

## 🙋‍♂️ Author
 - Naeem Prasla
 - Built with ❤️ for easier invoice handling via Square

