# Multi-Language Support Guide / Guia de Suporte Multi-idioma / 多语言支持指南

## English

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
3. Translate all strings
4. Compile with: `msgfmt brazil-checkout-fields-{locale}.po -o brazil-checkout-fields-{locale}.mo`

---

## Português (Brasil)

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
3. Traduza todas as strings
4. Compile com: `msgfmt brazil-checkout-fields-{locale}.po -o brazil-checkout-fields-{locale}.mo`

---

## 中文 (简体)

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
3. 翻译所有字符串
4. 使用以下命令编译：`msgfmt brazil-checkout-fields-{locale}.po -o brazil-checkout-fields-{locale}.mo`

---

## Translation Status / Status de Tradução / 翻译状态

| Language           | Code    | Status      | Coverage |
| ------------------ | ------- | ----------- | -------- |
| English            | `en_US` | ✅ Default  | 100%     |
| Português (Brasil) | `pt_BR` | ✅ Complete | 100%     |
| 中文 (简体)        | `zh_CN` | ✅ Complete | 100%     |

## Supported Interface Elements / Elementos da Interface Suportados / 支持的界面元素

- ✅ Admin panel labels / Etiquetas do painel admin / 管理面板标签
- ✅ Form field labels / Etiquetas de campos / 表单字段标签
- ✅ Validation messages / Mensagens de validação / 验证消息
- ✅ Success messages / Mensagens de sucesso / 成功消息
- ✅ Error messages / Mensagens de erro / 错误消息
- ✅ Migration tools / Ferramentas de migração / 迁移工具
- ✅ Debug interface / Interface de debug / 调试界面
- ✅ JavaScript alerts / Alertas JavaScript / JavaScript 警告

## Technical Details / Detalhes Técnicos / 技术详情

### Text Domain

- **Domain**: `brazil-checkout-fields`
- **Path**: `/languages/`
- **Load Priority**: WordPress site language setting

### File Structure / Estrutura de Arquivos / 文件结构

```
languages/
├── brazil-checkout-fields.pot      # Template
├── brazil-checkout-fields-pt_BR.po # Portuguese source
├── brazil-checkout-fields-pt_BR.mo # Portuguese compiled
├── brazil-checkout-fields-zh_CN.po # Chinese source
└── brazil-checkout-fields-zh_CN.mo # Chinese compiled
```

### WordPress Integration / Integração WordPress / WordPress 集成

- Uses `load_plugin_textdomain()` for automatic language loading
- Respects WordPress locale settings
- Falls back to English if translation not available

---

**Note**: All translations include complete coverage of admin interface, form validation, and user-facing messages.

**Nota**: Todas as traduções incluem cobertura completa da interface de administração, validação de formulários e mensagens do usuário.

**注意**: 所有翻译都包括管理界面、表单验证和用户界面消息的完整覆盖。
