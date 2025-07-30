# Brazil Checkout Fields

A WordPress/WooCommerce plugin for handling Brazilian CPF/CNPJ validation and checkout fields with configurable field names and customer type values.

## Features

- ✅ **CPF/CNPJ Validation**: Real-time validation of Brazilian tax documents
- ✅ **Store API Support**: Full compatibility with WooCommerce Block Editor
- ✅ **Configurable Field Names**: Customize database field names through admin panel
- ✅ **Customer Type Values**: Configure CPF/CNPJ customer type values
- ✅ **Data Migration Tools**: Migrate existing order data between field configurations
- ✅ **HPOS Compatibility**: Supports both HPOS and Legacy order storage
- ✅ **Session Management**: Persistent cart data across page loads
- ✅ **Statistics Dashboard**: View order statistics and recent data
- ✅ **Internationalization**: Multi-language support with Chinese translations

## Installation

1. Upload the plugin files to `/wp-content/plugins/brazil-checkout-fields/`
2. Activate the plugin through the WordPress admin panel
3. Configure field names and customer type values in WooCommerce → Brazil CPF/CNPJ

## Configuration

### Field Name Settings

- **Customer Type Field**: Database field name for storing customer type (default: `_brazil_customer_type`)
- **Document Field**: Database field name for storing formatted CPF/CNPJ (default: `_brazil_document`)
- **CPF Customer Type Value**: Value saved when user enters CPF (default: `pessoa_fisica`)
- **CNPJ Customer Type Value**: Value saved when user enters CNPJ (default: `pessoa_juridica`)

### Data Migration

If you change field names or customer type values, use the migration tools to update existing order data:

1. **Field Migration**: Migrate data from old field names to new ones
2. **Customer Type Migration**: Update customer type values in existing orders

## Internationalization

The plugin supports multiple languages through WordPress's translation system.

### Available Languages

- **English** (default)
- **Chinese (Simplified)** - `zh_CN`

### Adding New Translations

1. Use the template file: `languages/brazil-checkout-fields.pot`
2. Create your translation file: `languages/brazil-checkout-fields-{locale}.po`
3. Compile to binary format: `languages/brazil-checkout-fields-{locale}.mo`

### Text Domain

All translatable strings use the text domain: `brazil-checkout-fields`

## API Integration

### Store API Endpoints

The plugin automatically integrates with WooCommerce Store API for block-based checkout:

```javascript
// Store API automatically handles these fields:
wp.data.select("wc/store/cart").getCartData().extensions.brazil_checkout;
```

### Session Data

Cart session data is automatically managed:

```php
// Get session data
$session_data = WC()->session->get('brazil_checkout_data');

// Set session data
WC()->session->set('brazil_checkout_data', $data);
```

## Technical Details

### Storage Compatibility

- **HPOS Mode**: Uses `wp_wc_orders_meta` table
- **Legacy Mode**: Uses `wp_postmeta` table
- **Auto-detection**: Automatically detects and uses appropriate storage method

### Field Validation

- Field names must match pattern: `/^[a-zA-Z_][a-zA-Z0-9_]*$/`
- CPF: 11 digits with validation algorithm
- CNPJ: 14 digits with validation algorithm

### Caching

- Statistics data cached for 12 hours
- Recent orders cached for 5 minutes
- Manual cache clearing available in admin panel

## Version History

### 2.4.0

- Added internationalization support
- Chinese (Simplified) translations included
- Improved admin interface with translatable strings
- Enhanced migration tools with multilingual messages

### Previous Versions

- Store API integration and session management
- Configurable field names and customer type values
- Data migration tools
- HPOS compatibility
- Statistics dashboard

## Support

For issues and feature requests, please check the plugin documentation or contact support.

## License

This plugin is licensed under the GPL v2 or later.

---

**Note**: This plugin is specifically designed for Brazilian e-commerce requirements and includes validation for CPF (Cadastro de Pessoas Físicas) and CNPJ (Cadastro Nacional da Pessoa Jurídica) tax documents.

### 技术特性

#### 客户端验证

- ✅ 实时 CPF/CNPJ 算法验证
- ✅ 自动格式化输入
- ✅ 智能类型检测
- ✅ 表单提交前验证
- ✅ 错误提示和成功反馈

#### 服务端验证

- ✅ 后端 CPF/CNPJ 算法验证
- ✅ 多层验证钩子保护
- ✅ 数据清理和验证
- ✅ 错误消息本地化

#### 数据存储

- ✅ 新的统一字段：`brazil_document`
- ✅ 自动类型检测存储
- ✅ 向后兼容旧字段结构
- ✅ HPOS（高性能订单存储）兼容

#### WooCommerce 集成

- ✅ 块编辑器兼容
- ✅ 传统结账页面兼容
- ✅ 多种插入位置支持
- ✅ 订单详情显示
- ✅ 后台订单管理显示

### 用户体验改进

**之前的用户流程：**

1. 选择客户类型（个人/企业）
2. 在对应的输入框中输入 CPF 或 CNPJ

**现在的用户流程：**

1. 在单个输入框中输入 CPF 或 CNPJ
2. 系统自动识别类型并格式化
3. 实时验证反馈

### 技术实现细节

#### 智能检测逻辑

```javascript
detectDocumentType: function(value) {
    var cleanValue = value.replace(/[^0-9]/g, '');
    if (cleanValue.length <= 11) {
        return 'cpf';
    } else {
        return 'cnpj';
    }
}
```

#### 自动格式化

- **CPF**: 根据输入长度动态添加点和横线
- **CNPJ**: 根据输入长度动态添加点、斜线和横线
- **最大长度限制**: CPF 14 字符，CNPJ 18 字符

#### 验证算法

保持原有的巴西官方 CPF 和 CNPJ 验证算法，确保 100%准确性。

### 安装和使用

1. 上传插件文件到 `/wp-content/plugins/` 目录
2. 在 WordPress 后台激活插件
3. 插件会自动在 WooCommerce 结账页面添加巴西文档字段
4. 用户只需在一个输入框中输入 CPF 或 CNPJ 即可

### 兼容性

- **WordPress**: 5.0+
- **WooCommerce**: 5.0+
- **PHP**: 7.4+
- **WooCommerce 块编辑器**: 完全支持
- **HPOS**: 完全兼容

### 更新日志

#### 版本 2.3.0

- 🎉 简化为单一智能输入框
- 🚀 自动 CPF/CNPJ 类型检测
- ✨ 改进用户体验
- 🔧 保持完全向后兼容
- 🐛 修复边缘案例验证问题

#### 版本 2.2.0

- 块编辑器支持
- HPOS 兼容性
- 多层验证保护

### 开发者说明

该插件现在使用新的字段结构：

- `brazil_document`: 统一的文档输入
- `_brazil_document_type`: 自动检测的类型（cpf/cnpj）
- `_brazil_document`: 存储的文档值

同时保持旧字段的兼容性：

- `brazil_customer_type`, `brazil_cpf`, `brazil_cnpj`
- `_customer_type`, `_cpf`, `_cnpj`

这确保了从旧版本的无缝升级。
