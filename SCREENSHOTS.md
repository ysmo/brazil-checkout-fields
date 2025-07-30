# Screenshots - Brazil Checkout Fields Plugin

This document provides detailed descriptions of the plugin screenshots used in documentation.

## Overview

The Brazil Checkout Fields plugin provides three main interfaces:

1. **p1** - Customer checkout page
2. **p2** - WordPress admin order details
3. **p3** - Plugin configuration page

---

## p1 - Checkout Page (images/p1-checkout.png)

### Description

Shows the customer-facing checkout page where users enter their CPF or CNPJ document numbers.

### Key Features Demonstrated

- **Single Input Field**: Unified field for both CPF and CNPJ entry
- **Real-time Validation**: Green checkmark showing "CPF válido" (Valid CPF)
- **Smart Detection**: Plugin automatically detects document type (11 digits = CPF, 14 digits = CNPJ)
- **Integrated Design**: Seamlessly integrated with WooCommerce checkout flow
- **Brazilian Context**: Shows Brazilian address fields (São Paulo, Brazil)

### Technical Details

- Field Name: "🇧🇷 CPF / CNPJ \*"
- Validation Message: "CPF válido" with green styling
- Document Shown: 373.486.722-30 (Valid CPF format)
- Form Integration: Part of shipping address section

---

## p2 - Admin Order Details (images/p2-admin-order.png)

### Description

WordPress admin interface showing order details with Brazilian tax information stored by the plugin.

### Key Features Demonstrated

- **Order Management**: Complete order details view (#118)
- **Brazilian Tax Info**: "🇧🇷 Informações Fiscais do Brasil" section
- **Document Storage**: CPF displayed as "255.182.316-10"
- **Customer Type**: Shows "Pessoa Física" (Individual Person)
- **Admin Integration**: Seamlessly integrated with WooCommerce order management

### Technical Details

- Order Number: #118
- Customer Type: "Pessoa Física" (Individual)
- Document: "255.182.316-10" (CPF format)
- Section: "Informações Fiscais do Brasil" (Brazilian Tax Information)
- Integration: Native WooCommerce order meta display

---

## p3 - Configuration Page (images/p3-config.png)

### Description

Plugin administration page for configuring field names, values, and migration settings.

### Key Features Demonstrated

- **Field Configuration**: Customizable field names and database storage
- **Value Settings**: Configure CPF/CNPJ customer type values
- **Version Display**: Shows "Versão do Plugin: 1.0"
- **Migration Tools**: "Ferramenta de Migração de Dados" section
- **Portuguese Interface**: Localized admin interface

### Configuration Options Shown

#### Field Names

- **Customer Type Field**: `_brazil_customer_type`
- **Document Field**: `_brazil_document`

#### Customer Type Values

- **CPF Value**: `individual` (for pessoa física)
- **CNPJ Value**: `company` (for pessoa jurídica)

#### Migration Tools

- **Source Customer Type Field**: `_brazil_customer_type`
- **Source Document Field**: `_brazil_document`

### Technical Details

- Plugin Version: 1.0
- Interface Language: Portuguese (pt_BR)
- Settings Location: WooCommerce → Brazil CPF/CNPJ
- Save Button: "Salvar Configurações" (Save Settings)
- Reset Button: "Redefinir para Padrões" (Reset to Defaults)

---

## Image Specifications

### File Naming Convention

- `p1-checkout.png` - Customer checkout interface
- `p2-admin-order.png` - Admin order management interface
- `p3-config.png` - Plugin configuration interface

### Technical Requirements

- Format: PNG (recommended) or JPEG
- Resolution: Minimum 1200px width for clear documentation
- Compression: Optimized for web display
- Alt Text: Descriptive text for accessibility

### Usage in Documentation

These screenshots are referenced in:

- Main README.md (all three language versions)
- SCREENSHOTS.md (this document)
- Plugin documentation
- Installation guides

---

## Localization Notes

### Multilingual Screenshots

The current screenshots show:

- **p1**: English interface with Portuguese validation messages
- **p2**: Portuguese admin interface
- **p3**: Portuguese configuration interface

### Translation Coverage

For complete documentation, consider capturing screenshots in:

- English (en_US)
- Portuguese (pt_BR) ✅ Current
- Chinese (zh_CN)

---

## Maintenance

### Update Schedule

Screenshots should be updated when:

- UI design changes significantly
- New features are added
- Plugin version updates include interface changes
- Language translations are updated

### Quality Checklist

- [ ] Clear, readable text
- [ ] Proper functionality demonstration
- [ ] Consistent styling with current plugin version
- [ ] Accurate reflection of described features
- [ ] Optimized file size for documentation

---

## Contact

For questions about screenshots or documentation:

- **Author**: ysmo
- **Plugin Version**: 1.0
- **Last Updated**: January 2025
