# Brazil Checkout Fields - WooCommerce Plugin

## 版本 2.3.0 - 智能 CPF/CNPJ 输入

### 新功能特点

- **简化用户界面**: 将原来的两个输入框（CPF 和 CNPJ 选择器 + 对应输入框）简化为一个智能输入框
- **自动类型检测**: 根据用户输入的数字长度自动判断是 CPF（11 位）还是 CNPJ（14 位）
- **智能格式化**:
  - CPF 自动格式化为：`000.000.000-00`
  - CNPJ 自动格式化为：`00.000.000/0000-00`
- **实时验证**: 在用户输入时提供即时的格式验证和算法验证
- **向后兼容**: 保持与旧版本数据格式的完全兼容性

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
