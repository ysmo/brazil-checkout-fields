# Brazil Checkout Fields - Project Overview

**Version:** 1.0  
**Author:** ysmo  
**License:** GPL v2 or later

## Quick Summary

Brazil Checkout Fields is a comprehensive WordPress/WooCommerce plugin specifically designed for Brazilian e-commerce stores. It provides intelligent CPF/CNPJ validation with automatic document type detection and complete multi-language support.

## Key Features

🚀 **Smart Document Detection** - Automatically detects CPF or CNPJ based on user input  
✅ **Real-time Validation** - Uses official Brazilian validation algorithms  
🌍 **Multi-language Support** - Available in English, Portuguese, and Chinese  
🛒 **WooCommerce Integration** - Full compatibility with both classic and block checkout  
⚡ **HPOS Compatible** - Supports High-Performance Order Storage  
🔧 **Configurable** - Customizable field names and values  
📊 **Admin Dashboard** - Statistics and management interface  
🔄 **Migration Tools** - Easy data migration between configurations

## File Structure

```
brazil-checkout-fields/
├── brazil-cpf-cnpj.php              # Main plugin file
├── README.md                        # Multi-language documentation
├── LANGUAGE-SUPPORT.md              # Translation guide
├── INTERNATIONALIZATION.md          # Technical i18n documentation
└── languages/                       # Translation files
    ├── brazil-checkout-fields.pot   # Translation template
    ├── brazil-checkout-fields-pt_BR.po # Portuguese source
    ├── brazil-checkout-fields-pt_BR.mo # Portuguese compiled
    ├── brazil-checkout-fields-zh_CN.po # Chinese source
    └── brazil-checkout-fields-zh_CN.mo # Chinese compiled
```

## Technical Specifications

- **WordPress:** 5.0+
- **WooCommerce:** 6.0+
- **PHP:** 7.4+
- **Text Domain:** `brazil-checkout-fields`
- **Internationalization:** Complete i18n support
- **Storage:** HPOS and Legacy compatible

## Installation

1. Upload plugin files to `/wp-content/plugins/brazil-checkout-fields/`
2. Activate plugin in WordPress admin
3. Configure settings in **WooCommerce → Brazil CPF/CNPJ**

## Language Support

- 🇺🇸 **English** (en_US) - Default
- 🇧🇷 **Português** (pt_BR) - Brazilian Portuguese
- 🇨🇳 **中文** (zh_CN) - Simplified Chinese

Language automatically adapts to WordPress site language setting.

## User Experience

**Before:** Multiple fields, manual type selection  
**After:** Single intelligent input field with automatic detection

```
User Input: 123.456.789-01
└── Plugin detects: CPF
    └── Validates: ✅ Valid
        └── Stores: pessoa_fisica + formatted CPF
```

## Development Info

**Version:** 1.0 (Initial Release)  
**Release Date:** January 2025  
**Compatibility:** WordPress 5.0+ | WooCommerce 6.0+ | PHP 7.4+  
**Testing:** Fully tested with modern WordPress and WooCommerce versions

## Support

- **Issues:** GitHub Issues
- **Documentation:** README.md (multi-language)
- **Translations:** LANGUAGE-SUPPORT.md

---

Built for the Brazilian WordPress/WooCommerce community with ❤️ by ysmo
