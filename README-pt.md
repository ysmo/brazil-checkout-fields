# Brazil Checkout Fields

[🇺🇸 English](README-en.md) | [🇧🇷 Português](README-pt.md) | [🇨🇳 中文](README-zh.md)

Plugin WordPress/WooCommerce para validação de CPF/CNPJ em campos de checkout brasileiros.

**Versão:** 1.0  
**Autor:** ysmo  
**Licença:** GPL v2 ou posterior

---

## Descrição

Plugin WordPress/WooCommerce desenvolvido especificamente para lojas brasileiras. Adiciona automaticamente campos de validação CPF/CNPJ ao processo de checkout com detecção inteligente do tipo de documento e validação em tempo real.

## Recursos Principais

- ✅ **Detecção Inteligente**: Detecta automaticamente CPF ou CNPJ baseado na entrada
- ✅ **Validação em Tempo Real**: Validação instantânea usando algoritmos oficiais brasileiros
- ✅ **Suporte a Blocos**: Compatibilidade total com checkout baseado em blocos
- ✅ **Compatível com HPOS**: Suporta Armazenamento de Pedidos de Alta Performance
- ✅ **Suporte Multi-idioma**: Disponível em inglês, português e chinês
- ✅ **Campos Configuráveis**: Nomes de campos e valores personalizáveis
- ✅ **Ferramentas de Migração**: Migração fácil entre configurações de campos
- ✅ **Painel Administrativo**: Interface de estatísticas e gerenciamento

## Instalação

### Método 1: Git Clone (Recomendado)

1. **Navegue** até o diretório de plugins do WordPress
2. **Execute** o seguinte comando em `/wp-content/plugins`:
   ```bash
   git clone https://github.com/ysmo/brazil-checkout-fields.git
   ```
3. **Ative** o plugin no painel administrativo do WordPress
4. **Configure** as opções em WooCommerce → Brazil CPF/CNPJ

### Método 2: Upload Manual

1. **Baixe** os arquivos do plugin
2. **Faça upload** para `/wp-content/plugins/brazil-checkout-fields/`
3. **Ative** o plugin no painel administrativo do WordPress
4. **Configure** as opções em WooCommerce → Brazil CPF/CNPJ

## Configuração

### Configurações Básicas

Navegue até **WooCommerce → Brazil CPF/CNPJ** para configurar:

- **Campo Tipo de Cliente**: Nome do campo no banco (padrão: `_brazil_customer_type`)
- **Campo Documento**: Campo para armazenar CPF/CNPJ (padrão: `_brazil_document`)
- **Valor CPF**: Valor para clientes pessoa física (padrão: `pessoa_fisica`)
- **Valor CNPJ**: Valor para clientes pessoa jurídica (padrão: `pessoa_juridica`)

### Configuração Avançada

Para desenvolvedores, você pode sobrescrever nomes de campos usando constantes:

```php
// Adicione ao functions.php do seu tema
define('BRAZIL_CUSTOMER_TYPE_FIELD', '_tipo_cliente_customizado');
define('BRAZIL_DOCUMENT_FIELD', '_documento_customizado');
```

## Como Usar

1. **Experiência do Cliente**: Usuários simplesmente inserem seu CPF ou CNPJ em um único campo
2. **Detecção Automática**: Plugin detecta o tipo de documento automaticamente
3. **Validação em Tempo Real**: Feedback instantâneo sobre a validade do documento
4. **Armazenamento**: Documentos válidos são armazenados com o pedido

## Capturas de Tela

### p1 - Página de Checkout

![Página de Checkout](images/p1-checkout.png)
_Página de checkout do cliente mostrando campo CPF/CNPJ com validação em tempo real_

### p2 - Detalhes do Pedido no Admin

![Detalhes do Pedido](images/p2-admin-order.png)
_Painel administrativo WordPress mostrando detalhes do pedido com informações fiscais brasileiras_

### p3 - Página de Configuração

![Página de Configuração](images/p3-config.png)
_Página de configurações do plugin para configurar nomes de campos e valores_

## Suporte de Idiomas

O plugin se adapta automaticamente à configuração de idioma do WordPress:

- **Inglês** (en_US) - Padrão
- **Português** (pt_BR) - Português Brasileiro
- **Chinês** (zh_CN) - Chinês Simplificado

Para alterar idioma: **Configurações → Geral → Idioma do Site**

## Requisitos

- WordPress 5.0+
- WooCommerce 6.0+
- PHP 7.4+

## Documentação Técnica

### Esquema do Banco

```php
// Campos meta do pedido
meta_key: '_brazil_customer_type'  // 'pessoa_fisica' | 'pessoa_juridica'
meta_key: '_brazil_document'       // 'CPF/CNPJ Formatado'
```

### Integração API

```php
// Obter dados do cliente
$customer_type = get_post_meta($order_id, '_brazil_customer_type', true);
$document = get_post_meta($order_id, '_brazil_document', true);

// Funções de validação
$is_valid_cpf = validate_cpf($document);
$is_valid_cnpj = validate_cnpj($document);
```

### Ganchos e Filtros

```php
// Personalizar nomes de campos
add_filter('brazil_checkout_field_names', function($fields) {
    return $fields;
});

// Validação personalizada
add_filter('brazil_document_validation', function($is_valid, $document) {
    return $is_valid;
}, 10, 2);
```

### Ferramentas de Migração

Acesse a interface de migração em:
**WooCommerce → Brazil CPF/CNPJ → Ferramentas de Migração**

Migrações disponíveis:

- Alterações de nomes de campos
- Atualizações de valores de tipo de cliente
- Processamento de dados em lote

## Suporte

### Solução de Problemas

**Problema**: Campos não aparecem  
**Solução**: Verifique se o tema é compatível com WooCommerce

**Problema**: Validação não funciona  
**Solução**: Limpe o cache do navegador

### Modo Debug

Adicione `?debug=1` à URL da página admin para habilitar informações de debug.

### Histórico de Versões

#### Versão 1.0

- ✅ Lançamento inicial
- ✅ Validação CPF/CNPJ
- ✅ Suporte multi-idioma
- ✅ Compatibilidade com Blocos
- ✅ Suporte HPOS
- ✅ Interface administrativa
- ✅ Ferramentas de migração

## Licença

Este plugin está licenciado sob GPL v2 ou posterior.

## Créditos

**Autor**: ysmo  
**Versão**: 1.0  
**Última Atualização**: Janeiro 2025

Desenvolvido para a comunidade brasileira WordPress/WooCommerce.
