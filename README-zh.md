# Brazil Checkout Fields

[🇺🇸 English](README-en.md) | [🇧🇷 Português](README-pt.md) | [🇨🇳 中文](README-zh.md)

专为巴西结账字段设计的 WordPress/WooCommerce 插件，提供 CPF/CNPJ 验证功能。

**版本:** 1.0  
**作者:** ysmo  
**许可证:** GPL v2 或更高版本

---

## 描述

专为巴西电子商务商店设计的 WordPress/WooCommerce 插件。自动添加 CPF/CNPJ 验证字段到结账流程，具有智能文档类型检测和实时验证功能。

## 主要功能

- ✅ **智能文档检测**: 根据输入自动检测 CPF 或 CNPJ
- ✅ **实时验证**: 使用巴西官方算法进行即时验证
- ✅ **区块支持**: 完全兼容现代基于区块的结账
- ✅ **HPOS 兼容**: 支持高性能订单存储
- ✅ **多语言支持**: 提供英语、葡萄牙语和中文版本
- ✅ **可配置字段**: 可自定义字段名称和值
- ✅ **数据迁移工具**: 轻松在字段配置间迁移
- ✅ **管理面板**: 统计和管理界面

## 安装方法

### 方法 1: Git 克隆（推荐）

1. **导航** 到 WordPress 插件目录
2. **在** `/wp-content/plugins` **执行**以下命令：
   ```bash
   git clone https://github.com/ysmo/brazil-checkout-fields.git
   ```
3. **激活** 插件在 WordPress 管理面板中
4. **配置** 设置在 WooCommerce → Brazil CPF/CNPJ

### 方法 2: 手动上传

1. **下载** 插件文件
2. **上传** 到 `/wp-content/plugins/brazil-checkout-fields/`
3. **激活** 插件在 WordPress 管理面板中
4. **配置** 设置在 WooCommerce → Brazil CPF/CNPJ

## 配置说明

### 基础设置

导航到 **WooCommerce → Brazil CPF/CNPJ** 进行配置：

- **客户类型字段**: 数据库字段名称（默认：`_brazil_customer_type`）
- **文档字段**: 存储 CPF/CNPJ 的字段（默认：`_brazil_document`）
- **CPF 值**: 个人客户的值（默认：`pessoa_fisica`）
- **CNPJ 值**: 企业客户的值（默认：`pessoa_juridica`）

### 高级配置

对于开发者，可以使用常量覆盖字段名称：

```php
// 添加到主题的 functions.php
define('BRAZIL_CUSTOMER_TYPE_FIELD', '_custom_customer_type');
define('BRAZIL_DOCUMENT_FIELD', '_custom_document');
```

## 使用方法

1. **客户体验**: 用户只需在单个字段中输入 CPF 或 CNPJ
2. **自动检测**: 插件自动检测文档类型
3. **实时验证**: 文档有效性的即时反馈
4. **订单存储**: 有效文档与订单一起存储

## 截图展示

### p1 - 结账页面

![结账页面](images/p1-checkout.png)
_客户结账页面显示具有实时验证的 CPF/CNPJ 字段_

### p2 - 管理后台订单详情

![管理后台订单详情](images/p2-admin-order.png)
_WordPress 管理后台显示带有巴西税务信息的订单详情_

### p3 - 配置页面

![配置页面](images/p3-config.png)
_插件设置页面，用于配置字段名称和值_

## 语言支持

插件自动适应您的 WordPress 语言设置：

- **英语** (en_US) - 默认
- **葡萄牙语** (pt_BR) - 巴西葡萄牙语
- **中文** (zh_CN) - 简体中文

更改语言：**设置 → 常规 → 站点语言**

## 系统要求

- WordPress 5.0+
- WooCommerce 6.0+
- PHP 7.4+

## 技术文档

### 数据库架构

```php
// 订单元字段
meta_key: '_brazil_customer_type'  // 'pessoa_fisica' | 'pessoa_juridica'
meta_key: '_brazil_document'       // '格式化的 CPF/CNPJ'
```

### API 集成

```php
// 获取客户数据
$customer_type = get_post_meta($order_id, '_brazil_customer_type', true);
$document = get_post_meta($order_id, '_brazil_document', true);

// 验证函数
$is_valid_cpf = validate_cpf($document);
$is_valid_cnpj = validate_cnpj($document);
```

### 钩子和过滤器

```php
// 自定义字段名称
add_filter('brazil_checkout_field_names', function($fields) {
    return $fields;
});

// 自定义验证
add_filter('brazil_document_validation', function($is_valid, $document) {
    return $is_valid;
}, 10, 2);
```

### 迁移工具

访问迁移界面：
**WooCommerce → Brazil CPF/CNPJ → 迁移工具**

可用迁移功能：

- 字段名称更改
- 客户类型值更新
- 批量数据处理

## 支持

### 故障排除

**问题**: 字段不显示  
**解决方案**: 检查主题是否与 WooCommerce 兼容

**问题**: 验证不工作  
**解决方案**: 清除浏览器缓存

### 调试模式

在管理页面 URL 后添加 `?debug=1` 启用调试信息。

### 版本历史

#### 版本 1.0

- ✅ 初始版本
- ✅ CPF/CNPJ 验证
- ✅ 多语言支持
- ✅ WooCommerce 区块兼容性
- ✅ HPOS 支持
- ✅ 管理界面
- ✅ 迁移工具

## 许可证

此插件采用 GPL v2 或更高版本许可证。

## 致谢

**作者**: ysmo  
**版本**: 1.0  
**最后更新**: 2025 年 1 月

为巴西 WordPress/WooCommerce 社区开发。
