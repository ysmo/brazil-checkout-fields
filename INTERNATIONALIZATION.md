## Brazil Checkout Fields - 国际化测试

### 测试国际化功能

要验证国际化功能是否正常工作，请按照以下步骤操作：

#### 1. 验证翻译文件加载

检查以下文件是否存在：

- `languages/brazil-checkout-fields.pot` - 翻译模板文件
- `languages/brazil-checkout-fields-zh_CN.po` - 中文翻译源文件
- `languages/brazil-checkout-fields-zh_CN.mo` - 中文翻译二进制文件

#### 2. 测试管理页面

1. 进入 WordPress 管理后台
2. 导航到 WooCommerce → Brazil CPF/CNPJ
3. 检查页面文本是否根据 WordPress 语言设置显示正确的语言

#### 3. 语言切换测试

在 WordPress 设置中切换语言：

- **英文环境**: 设置 → 常规 → 站点语言 选择 "English (United States)"
- **中文环境**: 设置 → 常规 → 站点语言 选择 "简体中文"

#### 4. 验证翻译字符串

关键翻译字符串检查：

| 英文原文                        | 中文翻译               | 检查位置     |
| ------------------------------- | ---------------------- | ------------ |
| "Brazil CPF/CNPJ Configuration" | "Brazil CPF/CNPJ 配置" | 页面标题     |
| "Customer Type Field Name"      | "客户类型字段名"       | 表单标签     |
| "Save Settings"                 | "保存设置"             | 保存按钮     |
| "Data Migration Tool"           | "数据迁移工具"         | 迁移工具标题 |

#### 5. 功能测试

确认以下功能在不同语言环境下都能正常工作：

- 设置保存成功消息
- 验证错误提示
- 迁移工具反馈
- JavaScript 确认对话框

#### 6. 添加新语言支持

要添加新语言翻译：

1. 使用 `languages/brazil-checkout-fields.pot` 作为模板
2. 创建新的 `.po` 文件：`brazil-checkout-fields-{locale}.po`
3. 翻译所有字符串
4. 编译为 `.mo` 文件：`msgfmt brazil-checkout-fields-{locale}.po -o brazil-checkout-fields-{locale}.mo`
5. 将文件放入 `languages/` 目录

#### 7. 开发者注意事项

- 所有用户可见文本都应使用 `__()` 或 `_e()` 函数
- JavaScript 中的文本使用 `esc_js(__())` 包装
- 文本域统一使用：`brazil-checkout-fields`
- 新增翻译字符串后需要更新 `.pot` 文件

### 常见问题

**Q: 翻译没有生效？**
A: 检查 `.mo` 文件是否存在，WordPress 语言设置是否正确

**Q: 如何强制重新加载翻译？**  
A: 清理插件缓存，或者重新激活插件

**Q: 添加新翻译字符串后怎么办？**
A: 更新 `.pot` 模板文件，然后更新对应的 `.po` 和 `.mo` 文件
