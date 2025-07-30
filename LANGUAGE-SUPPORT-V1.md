# Multi-Language Support Guide / Guia de Suporte Multi-idioma / 多语言支持指南

**Plugin:** Brazil Checkout Fields  
**Version:** 1.0  
**Author:** ysmo

## English

### About Multi-Language Support

Brazil Checkout Fields provides complete internationalization support with automatic language detection based on your WordPress site settings. The plugin includes native translations for Portuguese, Chinese, and English.

### Enabling Brazilian Portuguese

1. Go to **Settings → General** in WordPress admin
2. Set **Site Language** to "Português do Brasil"
3. The plugin will automatically display in Portuguese

### Enabling Chinese (Simplified)

1. Go to **Settings → General** in WordPress admin
2. Set **Site Language** to "简体中文"
3. The plugin will automatically display in Chinese

### Creating New Translations

1. Copy `languages/brazil-checkout-fields.pot`
2. Rename to `brazil-checkout-fields-{locale}.po`
3. Translate all strings using a PO editor (like Poedit)
4. Compile with: `msgfmt brazil-checkout-fields-{locale}.po -o brazil-checkout-fields-{locale}.mo`
5. Place both .po and .mo files in the `/languages/` directory

---

## Português (Brasil)

### Sobre o Suporte Multi-idioma

O Brazil Checkout Fields oferece suporte completo à internacionalização com detecção automática de idioma baseada nas configurações do seu site WordPress. O plugin inclui traduções nativas para português, chinês e inglês.

### Como Ativar o Português Brasileiro

1. Vá para **Configurações → Geral** no painel do WordPress
2. Defina **Idioma do Site** como "Português do Brasil"
3. O plugin será exibido automaticamente em português

### Como Ativar o Chinês (Simplificado)

1. Vá para **Configurações → Geral** no painel do WordPress
2. Defina **Idioma do Site** como "简体中文"
3. O plugin será exibido automaticamente em chinês

### Criando Novas Traduções

1. Copie o arquivo `languages/brazil-checkout-fields.pot`
2. Renomeie para `brazil-checkout-fields-{locale}.po`
3. Traduza todas as strings usando um editor PO (como Poedit)
4. Compile com: `msgfmt brazil-checkout-fields-{locale}.po -o brazil-checkout-fields-{locale}.mo`
5. Coloque os arquivos .po e .mo no diretório `/languages/`

---

## 中文 (简体)

### 关于多语言支持

Brazil Checkout Fields 提供完整的国际化支持，基于您的 WordPress 站点设置自动检测语言。插件包含葡萄牙语、中文和英语的原生翻译。

### 启用巴西葡萄牙语

1. 转到 WordPress 管理面板的 **设置 → 常规**
2. 将 **站点语言** 设置为 "Português do Brasil"
3. 插件将自动以葡萄牙语显示

### 启用中文（简体）

1. 转到 WordPress 管理面板的 **设置 → 常规**
2. 将 **站点语言** 设置为 "简体中文"
3. 插件将自动以中文显示

### 创建新的翻译

1. 复制文件 `languages/brazil-checkout-fields.pot`
2. 重命名为 `brazil-checkout-fields-{locale}.po`
3. 使用 PO 编辑器（如 Poedit）翻译所有字符串
4. 使用以下命令编译：`msgfmt brazil-checkout-fields-{locale}.po -o brazil-checkout-fields-{locale}.mo`
5. 将 .po 和 .mo 文件放在 `/languages/` 目录中

---

## Translation Status / Status de Tradução / 翻译状态

| Language / Idioma / 语言 | Code / Código / 代码 | Status / Estado / 状态        | Coverage / Cobertura / 覆盖率 |
| ------------------------ | -------------------- | ----------------------------- | ----------------------------- |
| English                  | `en_US`              | ✅ Default / Padrão / 默认    | 100%                          |
| Português (Brasil)       | `pt_BR`              | ✅ Complete / Completo / 完成 | 100%                          |
| 中文 (简体)              | `zh_CN`              | ✅ Complete / Completo / 完成 | 100%                          |

## Available for Translation / Disponível para Tradução / 可翻译内容

### User Interface Elements / Elementos da Interface / 界面元素

- ✅ Admin panel labels and buttons / Etiquetas e botões do painel / 管理面板标签和按钮
- ✅ Form field labels and placeholders / Etiquetas e placeholders / 表单字段标签和占位符
- ✅ Validation error messages / Mensagens de erro / 验证错误消息
- ✅ Success confirmation messages / Mensagens de sucesso / 成功确认消息
- ✅ Migration tool interface / Interface de migração / 迁移工具界面
- ✅ Debug information panel / Painel de debug / 调试信息面板
- ✅ JavaScript alert messages / Alertas JavaScript / JavaScript 警告消息
- ✅ Order data display labels / Etiquetas de exibição / 订单数据显示标签

### Administrative Interface / Interface Administrativa / 管理界面

- ✅ Settings page / Página de configurações / 设置页面
- ✅ Help text and descriptions / Texto de ajuda / 帮助文本和描述
- ✅ Field configuration options / Opções de configuração / 字段配置选项
- ✅ Statistics dashboard / Painel de estatísticas / 统计仪表板
- ✅ Data migration tools / Ferramentas de migração / 数据迁移工具

## Technical Implementation / Implementação Técnica / 技术实现

### Text Domain Configuration / Configuração do Domínio / 文本域配置

```php
// Plugin header
Text Domain: brazil-checkout-fields
Domain Path: /languages

// Load textdomain
load_plugin_textdomain('brazil-checkout-fields', false,
    dirname(plugin_basename(__FILE__)) . '/languages/');
```

### File Structure / Estrutura de Arquivos / 文件结构

```
brazil-checkout-fields/
├── languages/
│   ├── brazil-checkout-fields.pot      # Translation template
│   ├── brazil-checkout-fields-pt_BR.po # Portuguese source
│   ├── brazil-checkout-fields-pt_BR.mo # Portuguese compiled
│   ├── brazil-checkout-fields-zh_CN.po # Chinese source
│   └── brazil-checkout-fields-zh_CN.mo # Chinese compiled
├── brazil-cpf-cnpj.php                 # Main plugin file
├── README.md                           # Multi-language documentation
└── LANGUAGE-SUPPORT-V1.md              # This file
```

### String Examples / Exemplos de Strings / 字符串示例

#### PHP Internationalization

```php
// Simple string
__('Customer Type', 'brazil-checkout-fields')

// String with variables
sprintf(__('Processing %d orders', 'brazil-checkout-fields'), $count)

// Direct output
_e('Save Settings', 'brazil-checkout-fields')
```

#### JavaScript Internationalization

```javascript
// Escaped for JavaScript
alert('<?php echo esc_js(__("Invalid CPF", "brazil-checkout-fields")); ?>');
```

## Contributing Translations / Contribuindo com Traduções / 贡献翻译

### For Translators / Para Tradutores / 面向翻译者

1. **Download** the POT template file
2. **Use** a PO editor like Poedit, Lokalise, or online tools
3. **Translate** all strings maintaining context and meaning
4. **Test** the translation in a WordPress environment
5. **Submit** your translation via GitHub or contact the author

### Translation Guidelines / Diretrizes de Tradução / 翻译指南

- **Maintain** technical terms (CPF, CNPJ, WooCommerce)
- **Preserve** HTML tags and placeholders
- **Keep** button text concise
- **Test** all admin interface elements
- **Verify** form validation messages work correctly

### Quality Assurance / Garantia de Qualidade / 质量保证

- **Context**: Ensure translations fit the interface context
- **Consistency**: Use consistent terminology throughout
- **Formatting**: Preserve string formatting and variables
- **Testing**: Test in actual WordPress/WooCommerce environment

---

## Support / Suporte / 支持

### Getting Help / Obtendo Ajuda / 获取帮助

- **GitHub Issues**: Report translation bugs or request new languages
- **Documentation**: Refer to README.md for general plugin information
- **WordPress.org**: Check plugin compatibility and reviews

### Contributing / Contribuindo / 贡献

- **New Languages**: We welcome translations for additional languages
- **Improvements**: Help improve existing translations
- **Testing**: Test translations in different WordPress environments

---

**Developed by / Desenvolvido por / 开发者**: ysmo  
**Version / Versão / 版本**: 1.0  
**License / Licença / 许可证**: GPL v2 or later
