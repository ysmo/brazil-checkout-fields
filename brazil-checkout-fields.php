<?php
/**
 * Plugin Name: Brazil Checkout Fields - Block Editor Compatible
 * Description: 适配WooCommerce块编辑器的巴西结账字段 - 智能CPF/CNPJ输入
 * Version: 2.3.0
 */

if (!defined('ABSPATH')) exit;

// 声明HPOS兼容性
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * 主插件类
 */
class Brazil_Checkout_Fields_Blocks {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // 注册块编辑器扩展
        add_action('woocommerce_blocks_loaded', array($this, 'register_checkout_blocks'));
        
        // 添加传统钩子作为后备
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));
        
        // 使用JavaScript添加字段到块编辑器
        add_action('wp_footer', array($this, 'inject_checkout_fields_js'));
        
        // 后端验证 - 多个钩子确保验证生效
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_fields_process'));
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_checkout_fields'), 10, 2);
        add_filter('woocommerce_checkout_posted_data', array($this, 'validate_checkout_posted_data'));
        
        // AJAX验证（用于块编辑器）
        add_action('wp_ajax_validate_brazil_fields', array($this, 'ajax_validate_fields'));
        add_action('wp_ajax_nopriv_validate_brazil_fields', array($this, 'ajax_validate_fields'));
        
        // 保存字段数据
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_checkout_fields'), 10, 2);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_fields_fallback'), 10, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_checkout_fields_create_order'), 10, 2);
        
        // 显示字段在订单页面
        add_action('woocommerce_order_details_after_customer_details', array($this, 'display_fields_in_order'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_fields_in_admin_order'));
    }
    
    /**
     * 注册结账块扩展
     */
    public function register_checkout_blocks() {
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data(array(
                'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
                'namespace' => 'brazil-checkout',
                'data_callback' => array($this, 'checkout_data_callback'),
                'schema_callback' => array($this, 'checkout_schema_callback'),
                'schema_type' => ARRAY_A,
            ));
        }
    }
    
    /**
     * 数据回调
     */
    public function checkout_data_callback() {
        return array(
            'brazil_document' => '',
        );
    }
    
    /**
     * 模式回调
     */
    public function checkout_schema_callback() {
        return array(
            'brazil_document' => array(
                'description' => 'CPF or CNPJ document number',
                'type' => 'string',
                'context' => array('view', 'edit'),
            ),
        );
    }
    
    /**
     * 加载结账脚本
     */
    public function enqueue_checkout_scripts() {
        if (is_checkout()) {
            wp_enqueue_script('jquery');
            
            // 添加自定义CSS
            wp_add_inline_style('wp-block-library', '
                .brazil-checkout-fields {
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 20px 0;
                    box-sizing: border-box;
                    max-width: 100%;
                    overflow: hidden;
                }
                .brazil-checkout-fields h4 {
                    margin: 0 0 15px 0;
                    color: #495057;
                    font-size: 18px;
                }
                .brazil-field-row {
                    margin-bottom: 15px;
                    width: 100%;
                    box-sizing: border-box;
                }
                .brazil-field-row label {
                    display: block;
                    margin-bottom: 5px;
                    font-weight: 600;
                    color: #495057;
                }
                .brazil-field-row input,
                .brazil-field-row select {
                    width: 100%;
                    max-width: 100%;
                    padding: 12px;
                    border: 1px solid #ced4da;
                    border-radius: 4px;
                    font-size: 16px;
                    transition: border-color 0.3s;
                    box-sizing: border-box;
                }
                .brazil-field-row input:focus,
                .brazil-field-row select:focus {
                    outline: none;
                    border-color: #007cba;
                    box-shadow: 0 0 0 2px rgba(0, 124, 186, 0.2);
                }
                .brazil-field-row input.brazil-field-invalid {
                    border-color: #dc3545;
                    box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.2);
                }
                .brazil-field-row input.brazil-field-valid {
                    border-color: #28a745;
                    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.2);
                }
                .brazil-field-hidden {
                    display: none !important;
                }
                .brazil-field-error {
                    color: #dc3545;
                    font-size: 14px;
                    margin-top: 5px;
                    display: block;
                }
                .brazil-field-success {
                    color: #28a745;
                    font-size: 14px;
                    margin-top: 5px;
                    display: block;
                }
                .brazil-document-hint {
                    font-size: 12px;
                    color: #666;
                    margin-top: 3px;
                    display: block;
                }
                .brazil-checkout-validation-summary {
                    background: #f8d7da;
                    border: 1px solid #f5c6cb;
                    color: #721c24;
                    padding: 15px;
                    border-radius: 4px;
                    margin: 15px 0;
                    display: none;
                }
                .brazil-checkout-validation-summary.show {
                    display: block;
                }
                .brazil-checkout-validation-summary ul {
                    margin: 5px 0 0 20px;
                    padding: 0;
                }
            ');
            
            // 本地化脚本数据
            wp_localize_script('jquery', 'brazil_checkout_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('brazil_checkout_nonce'),
                'messages' => array(
                    'document_required' => 'CPF ou CNPJ é obrigatório.',
                    'document_invalid' => 'CPF ou CNPJ inválido. Verifique o número digitado.',
                    'cpf_valid' => 'CPF válido ✓',
                    'cnpj_valid' => 'CNPJ válido ✓',
                    'document_hint_cpf' => 'Digite seu CPF (11 dígitos)',
                    'document_hint_cnpj' => 'Digite seu CNPJ (14 dígitos)'
                )
            ));
        }
    }
    
    /**
     * 使用JavaScript注入字段到块编辑器结账表单
     */
    public function inject_checkout_fields_js() {
        if (!is_checkout()) return;
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('Brazil Checkout Fields: 开始注入字段到块编辑器');
            
            var brazilValidation = {
                errors: [],
                
                // 根据输入长度检测文档类型
                detectDocumentType: function(value) {
                    var cleanValue = value.replace(/[^0-9]/g, '');
                    if (cleanValue.length <= 11) {
                        return 'cpf';
                    } else {
                        return 'cnpj';
                    }
                },
                
                // CPF验证算法
                validateCPF: function(cpf) {
                    cpf = cpf.replace(/[^0-9]/g, '');
                    
                    if (cpf.length !== 11) return false;
                    if (/^(\d)\1{10}$/.test(cpf)) return false;
                    
                    var sum = 0;
                    for (var i = 0; i < 9; i++) {
                        sum += parseInt(cpf.charAt(i)) * (10 - i);
                    }
                    var remainder = sum % 11;
                    var digit1 = remainder < 2 ? 0 : 11 - remainder;
                    
                    if (parseInt(cpf.charAt(9)) !== digit1) return false;
                    
                    sum = 0;
                    for (var i = 0; i < 10; i++) {
                        sum += parseInt(cpf.charAt(i)) * (11 - i);
                    }
                    remainder = sum % 11;
                    var digit2 = remainder < 2 ? 0 : 11 - remainder;
                    
                    return parseInt(cpf.charAt(10)) === digit2;
                },
                
                // CNPJ验证算法
                validateCNPJ: function(cnpj) {
                    cnpj = cnpj.replace(/[^0-9]/g, '');
                    
                    if (cnpj.length !== 14) return false;
                    if (/^(\d)\1{13}$/.test(cnpj)) return false;
                    
                    var weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
                    var weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
                    
                    var sum = 0;
                    for (var i = 0; i < 12; i++) {
                        sum += parseInt(cnpj.charAt(i)) * weights1[i];
                    }
                    var remainder = sum % 11;
                    var digit1 = remainder < 2 ? 0 : 11 - remainder;
                    
                    if (parseInt(cnpj.charAt(12)) !== digit1) return false;
                    
                    sum = 0;
                    for (var i = 0; i < 13; i++) {
                        sum += parseInt(cnpj.charAt(i)) * weights2[i];
                    }
                    remainder = sum % 11;
                    var digit2 = remainder < 2 ? 0 : 11 - remainder;
                    
                    return parseInt(cnpj.charAt(13)) === digit2;
                },
                
                // 验证文档
                validateDocument: function(value) {
                    if (!value || !value.trim()) {
                        return false;
                    }
                    
                    var documentType = this.detectDocumentType(value);
                    if (documentType === 'cpf') {
                        return this.validateCPF(value);
                    } else {
                        return this.validateCNPJ(value);
                    }
                },
                
                // 验证所有巴西字段
                validateAll: function() {
                    this.errors = [];
                    
                    var document = $('#brazil_document').val();
                    
                    if (!document || !document.trim()) {
                        this.errors.push(brazil_checkout_ajax.messages.document_required);
                        return false;
                    }
                    
                    if (!this.validateDocument(document)) {
                        this.errors.push(brazil_checkout_ajax.messages.document_invalid);
                        return false;
                    }
                    
                    return true;
                },
                
                // 显示验证错误
                showErrors: function() {
                    var summaryHtml = '<div class="brazil-checkout-validation-summary show">' +
                        '<strong>Por favor, corrija os seguintes erros:</strong>' +
                        '<ul>';
                    
                    for (var i = 0; i < this.errors.length; i++) {
                        summaryHtml += '<li>' + this.errors[i] + '</li>';
                    }
                    
                    summaryHtml += '</ul></div>';
                    
                    $('.brazil-checkout-validation-summary').remove();
                    $('.brazil-checkout-fields').prepend(summaryHtml);
                    
                    // 滚动到错误区域
                    $('html, body').animate({
                        scrollTop: $('.brazil-checkout-fields').offset().top - 50
                    }, 500);
                },
                
                // 隐藏验证错误
                hideErrors: function() {
                    $('.brazil-checkout-validation-summary').removeClass('show').hide();
                }
            };
            
            // 等待块编辑器加载完成
            function waitForCheckoutBlocks() {
                var attempts = 0;
                var maxAttempts = 100; // 增加等待时间
                
                var interval = setInterval(function() {
                    attempts++;
                    
                    // 查找地址块
                    var addressBlock = $('.wp-block-woocommerce-checkout-billing-address-block, .wp-block-woocommerce-checkout-shipping-address-block');
                    var fieldsBlock = $('.wp-block-woocommerce-checkout-fields-block');
                    var checkoutBlock = $('.wp-block-woocommerce-checkout');
                    
                    if (addressBlock.length > 0) {
                        console.log('找到地址块，注入巴西字段到地址下面');
                        clearInterval(interval);
                        injectBrazilFields();
                    } else if (fieldsBlock.length > 0 && attempts > 20) {
                        console.log('找到字段块，注入巴西字段');
                        clearInterval(interval);
                        injectBrazilFieldsToFieldsBlock();
                    } else if (checkoutBlock.length > 0 && attempts > 40) {
                        console.log('找到结账块，注入巴西字段到顶部');
                        clearInterval(interval);
                        injectBrazilFieldsToCheckoutBlock();
                    } else if (attempts >= maxAttempts) {
                        console.log('未找到WooCommerce块编辑器元素，尝试传统方法');
                        clearInterval(interval);
                        injectBrazilFieldsFallback();
                    }
                }, 200);
            }
            
            function injectBrazilFields() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                
                // 优先查找账单地址块
                var billingBlock = $('.wp-block-woocommerce-checkout-billing-address-block');
                if (billingBlock.length > 0) {
                    billingBlock.after(brazilFieldsHtml);
                    console.log('巴西字段已插入到账单地址块后面');
                } else {
                    // 查找配送地址块
                    var shippingBlock = $('.wp-block-woocommerce-checkout-shipping-address-block');
                    if (shippingBlock.length > 0) {
                        shippingBlock.after(brazilFieldsHtml);
                        console.log('巴西字段已插入到配送地址块后面');
                    } else {
                        // 查找任何地址相关的块
                        var anyAddressBlock = $('[class*="address-block"], [class*="contact-information"]').last();
                        if (anyAddressBlock.length > 0) {
                            anyAddressBlock.after(brazilFieldsHtml);
                            console.log('巴西字段已插入到地址相关块后面');
                        } else {
                            // 插入到字段块内
                            $('.wp-block-woocommerce-checkout-fields-block').append(brazilFieldsHtml);
                            console.log('巴西字段已插入到字段块内');
                        }
                    }
                }
                
                // 设置事件监听器
                setupFieldListeners();
                setupValidation();
            }
            
            function injectBrazilFieldsToFieldsBlock() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                $('.wp-block-woocommerce-checkout-fields-block').append(brazilFieldsHtml);
                console.log('巴西字段已插入到字段块');
                
                setupFieldListeners();
                setupValidation();
            }
            
            function injectBrazilFieldsToCheckoutBlock() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                
                // 尝试插入到主要内容区域
                var mainContent = $('.wp-block-woocommerce-checkout .wc-block-checkout__main');
                if (mainContent.length > 0) {
                    mainContent.append(brazilFieldsHtml);
                } else {
                    $('.wp-block-woocommerce-checkout').append(brazilFieldsHtml);
                }
                
                console.log('巴西字段已插入到结账块');
                
                setupFieldListeners();
                setupValidation();
            }
            
            function injectBrazilFieldsFallback() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                $('body').prepend('<div style="position: relative; z-index: 999; max-width: 600px; margin: 20px auto;">' + brazilFieldsHtml + '</div>');
                console.log('使用后备方法插入巴西字段');
                
                setupFieldListeners();
                setupValidation();
            }
            
            function setupFieldListeners() {
                // 智能文档输入处理
                $(document).on('input', '#brazil_document', function() {
                    var value = $(this).val().replace(/[^0-9]/g, '');
                    var documentType = brazilValidation.detectDocumentType(value);
                    var formattedValue = '';
                    var maxLength = 18;
                    var placeholder = '';
                    var hint = '';
                    
                    // 根据检测到的类型格式化输入
                    if (documentType === 'cpf') {
                        if (value.length >= 11) {
                            value = value.substring(0, 11);
                        }
                        if (value.length > 9) {
                            formattedValue = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                        } else if (value.length > 6) {
                            formattedValue = value.replace(/(\d{3})(\d{3})(\d+)/, '$1.$2.$3');
                        } else if (value.length > 3) {
                            formattedValue = value.replace(/(\d{3})(\d+)/, '$1.$2');
                        } else {
                            formattedValue = value;
                        }
                        maxLength = 14;
                        placeholder = '000.000.000-00';
                        hint = brazil_checkout_ajax.messages.document_hint_cpf;
                        
                        // 更新隐藏字段
                        $('#brazil_customer_type').val('pessoa_fisica');
                        $('#brazil_cpf').val(formattedValue);
                        $('#brazil_cnpj').val('');
                    } else {
                        if (value.length >= 14) {
                            value = value.substring(0, 14);
                        }
                        if (value.length > 12) {
                            formattedValue = value.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
                        } else if (value.length > 8) {
                            formattedValue = value.replace(/(\d{2})(\d{3})(\d{3})(\d+)/, '$1.$2.$3/$4');
                        } else if (value.length > 5) {
                            formattedValue = value.replace(/(\d{2})(\d{3})(\d+)/, '$1.$2.$3');
                        } else if (value.length > 2) {
                            formattedValue = value.replace(/(\d{2})(\d+)/, '$1.$2');
                        } else {
                            formattedValue = value;
                        }
                        maxLength = 18;
                        placeholder = '00.000.000/0000-00';
                        hint = brazil_checkout_ajax.messages.document_hint_cnpj;
                        
                        // 更新隐藏字段
                        $('#brazil_customer_type').val('pessoa_juridica');
                        $('#brazil_cpf').val('');
                        $('#brazil_cnpj').val(formattedValue);
                    }
                    
                    $(this).val(formattedValue);
                    $(this).attr('placeholder', placeholder);
                    $(this).attr('maxlength', maxLength);
                    
                    // 更新提示信息
                    var hintContainer = $('.brazil-document-hint');
                    hintContainer.text(hint).css({
                        'font-size': '12px',
                        'color': '#666',
                        'margin-top': '3px'
                    });
                    
                    // 实时验证
                    validateDocumentReal(formattedValue, documentType);
                });
                
                console.log('巴西字段事件监听器已设置');
            }
            
            function createBrazilFieldsHtml() {
                return `
                    <div class="brazil-checkout-fields">
                        <div class="brazil-field-row">
                            <label for="brazil_document">🇧🇷 CPF / CNPJ *</label>
                            <input type="text" id="brazil_document" name="brazil_document" 
                                   placeholder="CPF (000.000.000-00) ou CNPJ (00.000.000/0000-00)" 
                                   maxlength="18" required>
                            <div class="brazil-document-hint"></div>
                            <div class="brazil-document-error brazil-field-error"></div>
                            <div class="brazil-document-success brazil-field-success"></div>
                        </div>
                        
                        <!-- Hidden fields for backward compatibility -->
                        <input type="hidden" id="brazil_customer_type" name="brazil_customer_type" value="">
                        <input type="hidden" id="brazil_cpf" name="brazil_cpf" value="">
                        <input type="hidden" id="brazil_cnpj" name="brazil_cnpj" value="">
                    </div>
                `;
            }
            
            function injectBrazilFieldsFallback() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                $('body').prepend('<div style="position: relative; z-index: 999; max-width: 600px; margin: 20px auto;">' + brazilFieldsHtml + '</div>');
                console.log('使用后备方法插入巴西字段');
                
                setupFieldListeners();
                setupValidation();
                
                setTimeout(function() {
                    $('#brazil_customer_type').val('pessoa_fisica').trigger('change');
                }, 500);
            }
            
            function setupFieldListeners() {
                // 客户类型切换
                $(document).on('change', '#brazil_customer_type', function() {
                    var customerType = $(this).val();
                    brazilValidation.hideErrors();
                    
                    if (customerType === 'pessoa_fisica') {
                        $('.cpf-field').removeClass('brazil-field-hidden');
                        $('.cnpj-field').addClass('brazil-field-hidden');
                        $('#brazil_cpf').prop('required', true);
                        $('#brazil_cnpj').prop('required', false).val('');
                    } else if (customerType === 'pessoa_juridica') {
                        $('.cpf-field').addClass('brazil-field-hidden');
                        $('.cnpj-field').removeClass('brazil-field-hidden');
                        $('#brazil_cpf').prop('required', false).val('');
                        $('#brazil_cnpj').prop('required', true);
                    }
                });
                
                // CPF格式化和实时验证
                $(document).on('input', '#brazil_cpf', function() {
                    var value = $(this).val().replace(/[^0-9]/g, '');
                    if (value.length >= 11) {
                        value = value.substring(0, 11);
                        value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                    }
                    $(this).val(value);
                    
                    // 实时验证
                    validateFieldReal('#brazil_cpf', 'cpf');
                });
                
                // CNPJ格式化和实时验证
                $(document).on('input', '#brazil_cnpj', function() {
                    var value = $(this).val().replace(/[^0-9]/g, '');
                    if (value.length >= 14) {
                        value = value.substring(0, 14);
                        value = value.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
                    }
                    $(this).val(value);
                    
                    // 实时验证
                    validateFieldReal('#brazil_cnpj', 'cnpj');
                });
                
                console.log('巴西字段事件监听器已设置');
            }
            
            function setupValidation() {
                // 创建全局验证函数
                window.validateBrazilFields = function() {
                    console.log('验证巴西字段被调用');
                    var isValid = brazilValidation.validateAll();
                    if (!isValid) {
                        brazilValidation.showErrors();
                    } else {
                        brazilValidation.hideErrors();
                    }
                    return isValid;
                };
                
                // 拦截表单提交 - 使用多种方法
                $(document).on('submit', 'form', function(e) {
                    console.log('表单提交拦截 - 验证巴西字段');
                    if (!window.validateBrazilFields()) {
                        console.log('巴西字段验证失败，阻止提交');
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        return false;
                    }
                    console.log('巴西字段验证通过');
                });
                
                // 拦截所有按钮点击
                $(document).on('click', 'button[type="submit"], input[type="submit"], .wc-block-components-checkout-place-order-button', function(e) {
                    console.log('提交按钮点击拦截');
                    if (!window.validateBrazilFields()) {
                        console.log('阻止按钮提交');
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        return false;
                    }
                });
                
                // 监听WooCommerce特定事件
                $(document.body).on('checkout_place_order', function() {
                    console.log('checkout_place_order 事件触发');
                    return window.validateBrazilFields();
                });
                
                // 使用MutationObserver监听DOM变化，确保验证函数绑定到新的按钮
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes.length > 0) {
                            $(mutation.addedNodes).find('button[type="submit"], .wc-block-components-checkout-place-order-button').each(function() {
                                $(this).off('click.brazil-validation').on('click.brazil-validation', function(e) {
                                    console.log('新按钮点击拦截');
                                    if (!window.validateBrazilFields()) {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        e.stopImmediatePropagation();
                                        return false;
                                    }
                                });
                            });
                        }
                    });
                });
                
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
                
                console.log('验证监听器已设置');
            }
            
            function validateDocumentReal(value, documentType) {
                var field = $('#brazil_document');
                var errorContainer = $('.brazil-document-error');
                var successContainer = $('.brazil-document-success');
                
                errorContainer.hide().text('');
                successContainer.hide().text('');
                field.removeClass('brazil-field-invalid brazil-field-valid');
                
                if (!value.trim()) {
                    return;
                }
                
                var isValid = false;
                if (documentType === 'cpf') {
                    isValid = brazilValidation.validateCPF(value);
                    if (isValid) {
                        successContainer.text(brazil_checkout_ajax.messages.cpf_valid).show();
                    } else {
                        errorContainer.text('CPF inválido').show();
                    }
                } else if (documentType === 'cnpj') {
                    isValid = brazilValidation.validateCNPJ(value);
                    if (isValid) {
                        successContainer.text(brazil_checkout_ajax.messages.cnpj_valid).show();
                    } else {
                        errorContainer.text('CNPJ inválido').show();
                    }
                }
                
                if (isValid) {
                    field.addClass('brazil-field-valid');
                } else {
                    field.addClass('brazil-field-invalid');
                }
            }
            
            // 开始等待并注入字段
            waitForCheckoutBlocks();
        });
        </script>
        <?php
    }
    
    /**
     * 后端验证 - 结账处理过程
     */
    public function validate_checkout_fields_process() {
        error_log('Brazil Checkout: validate_checkout_fields_process called');
        error_log('Brazil Checkout: POST data: ' . print_r($_POST, true));
        
        $validation_errors = $this->perform_validation(false);
        
        if (!empty($validation_errors)) {
            foreach ($validation_errors as $error) {
                wc_add_notice($error, 'error');
                error_log('Brazil Checkout: 添加错误通知: ' . $error);
            }
            
            // 确保验证失败时停止处理
            wp_die('验证失败');
        }
    }
    
    /**
     * 后端验证 - 结账验证钩子
     */
    public function validate_checkout_fields($data, $errors) {
        error_log('Brazil Checkout: validate_checkout_fields called');
        
        $validation_errors = $this->perform_validation(false);
        
        if (!empty($validation_errors)) {
            foreach ($validation_errors as $error) {
                $errors->add('validation', $error);
            }
        }
    }
    
    /**
     * 后端验证 - 检查提交的数据
     */
    public function validate_checkout_posted_data($data) {
        error_log('Brazil Checkout: validate_checkout_posted_data called');
        
        $validation_errors = $this->perform_validation(false);
        
        if (!empty($validation_errors)) {
            foreach ($validation_errors as $error) {
                wc_add_notice($error, 'error');
            }
        }
        
        return $data;
    }
    
    /**
     * 执行验证逻辑
     */
    private function perform_validation($die_on_error = true) {
        $errors = array();
        
        // 检查新的统一文档字段
        $document = isset($_POST['brazil_document']) ? sanitize_text_field($_POST['brazil_document']) : '';
        
        // 后备兼容性：检查旧字段
        if (empty($document)) {
            $customer_type = isset($_POST['brazil_customer_type']) ? sanitize_text_field($_POST['brazil_customer_type']) : '';
            if ($customer_type === 'pessoa_fisica' && isset($_POST['brazil_cpf'])) {
                $document = sanitize_text_field($_POST['brazil_cpf']);
            } elseif ($customer_type === 'pessoa_juridica' && isset($_POST['brazil_cnpj'])) {
                $document = sanitize_text_field($_POST['brazil_cnpj']);
            }
        }
        
        error_log('Brazil Checkout: 验证文档: ' . $document);
        
        if (empty($document)) {
            $errors[] = 'CPF ou CNPJ é obrigatório.';
        } else {
            $clean_document = preg_replace('/[^0-9]/', '', $document);
            
            if (strlen($clean_document) === 11) {
                // CPF验证
                if (!$this->is_valid_cpf($clean_document)) {
                    $errors[] = 'CPF inválido. Verifique o número digitado.';
                }
            } elseif (strlen($clean_document) === 14) {
                // CNPJ验证
                if (!$this->is_valid_cnpj($clean_document)) {
                    $errors[] = 'CNPJ inválido. Verifique o número digitado.';
                }
            } else {
                $errors[] = 'CPF deve ter 11 dígitos ou CNPJ deve ter 14 dígitos.';
            }
        }
        
        if (!empty($errors)) {
            error_log('Brazil Checkout: 验证失败: ' . implode(', ', $errors));
        } else {
            error_log('Brazil Checkout: 验证通过');
        }
        
        return $errors;
    }
    
    /**
     * AJAX验证字段
     */
    public function ajax_validate_fields() {
        check_ajax_referer('brazil_checkout_nonce', 'nonce');
        
        $document = sanitize_text_field($_POST['document']);
        
        $response = array('valid' => true, 'errors' => array());
        
        if (empty($document)) {
            $response['valid'] = false;
            $response['errors'][] = 'CPF ou CNPJ é obrigatório.';
        } else {
            $clean_document = preg_replace('/[^0-9]/', '', $document);
            
            if (strlen($clean_document) === 11) {
                // CPF验证
                if (!$this->is_valid_cpf($clean_document)) {
                    $response['valid'] = false;
                    $response['errors'][] = 'CPF inválido. Verifique o número digitado.';
                }
            } elseif (strlen($clean_document) === 14) {
                // CNPJ验证
                if (!$this->is_valid_cnpj($clean_document)) {
                    $response['valid'] = false;
                    $response['errors'][] = 'CNPJ inválido. Verifique o número digitado.';
                }
            } else {
                $response['valid'] = false;
                $response['errors'][] = 'CPF deve ter 11 dígitos ou CNPJ deve ter 14 dígitos.';
            }
        }
        
        wp_send_json($response);
    }
    
    /**
     * 保存结账字段数据
     */
    public function save_checkout_fields($order, $request) {
        $document = isset($_POST['brazil_document']) ? sanitize_text_field($_POST['brazil_document']) : '';
        
        if (!empty($document)) {
            $clean_document = preg_replace('/[^0-9]/', '', $document);
            
            if (strlen($clean_document) === 11) {
                // CPF
                $order->update_meta_data('_customer_type', 'pessoa_fisica');
                $order->update_meta_data('_cpf', $document);
                $order->update_meta_data('_brazil_document', $document);
                $order->update_meta_data('_brazil_document_type', 'cpf');
            } elseif (strlen($clean_document) === 14) {
                // CNPJ
                $order->update_meta_data('_customer_type', 'pessoa_juridica');
                $order->update_meta_data('_cnpj', $document);
                $order->update_meta_data('_brazil_document', $document);
                $order->update_meta_data('_brazil_document_type', 'cnpj');
            }
        }
        
        // 后备兼容性：保存旧字段
        if (isset($_POST['brazil_cpf']) && !empty($_POST['brazil_cpf'])) {
            $order->update_meta_data('_customer_type', 'pessoa_fisica');
            $order->update_meta_data('_cpf', sanitize_text_field($_POST['brazil_cpf']));
        }
        
        if (isset($_POST['brazil_cnpj']) && !empty($_POST['brazil_cnpj'])) {
            $order->update_meta_data('_customer_type', 'pessoa_juridica');
            $order->update_meta_data('_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        }
    }
    
    /**
     * 保存字段数据 - 后备方法
     */
    public function save_checkout_fields_fallback($order_id) {
        $document = isset($_POST['brazil_document']) ? sanitize_text_field($_POST['brazil_document']) : '';
        
        if (!empty($document)) {
            $clean_document = preg_replace('/[^0-9]/', '', $document);
            
            if (strlen($clean_document) === 11) {
                // CPF
                update_post_meta($order_id, '_customer_type', 'pessoa_fisica');
                update_post_meta($order_id, '_cpf', $document);
                update_post_meta($order_id, '_brazil_document', $document);
                update_post_meta($order_id, '_brazil_document_type', 'cpf');
            } elseif (strlen($clean_document) === 14) {
                // CNPJ
                update_post_meta($order_id, '_customer_type', 'pessoa_juridica');
                update_post_meta($order_id, '_cnpj', $document);
                update_post_meta($order_id, '_brazil_document', $document);
                update_post_meta($order_id, '_brazil_document_type', 'cnpj');
            }
        }
        
        // 后备兼容性：保存旧字段
        if (isset($_POST['brazil_cpf']) && !empty($_POST['brazil_cpf'])) {
            update_post_meta($order_id, '_customer_type', 'pessoa_fisica');
            update_post_meta($order_id, '_cpf', sanitize_text_field($_POST['brazil_cpf']));
        }
        
        if (isset($_POST['brazil_cnpj']) && !empty($_POST['brazil_cnpj'])) {
            update_post_meta($order_id, '_customer_type', 'pessoa_juridica');
            update_post_meta($order_id, '_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        }
    }
    
    /**
     * 保存字段数据 - 创建订单时
     */
    public function save_checkout_fields_create_order($order, $data) {
        $document = isset($_POST['brazil_document']) ? sanitize_text_field($_POST['brazil_document']) : '';
        
        if (!empty($document)) {
            $clean_document = preg_replace('/[^0-9]/', '', $document);
            
            if (strlen($clean_document) === 11) {
                // CPF
                $order->update_meta_data('_customer_type', 'pessoa_fisica');
                $order->update_meta_data('_cpf', $document);
                $order->update_meta_data('_brazil_document', $document);
                $order->update_meta_data('_brazil_document_type', 'cpf');
            } elseif (strlen($clean_document) === 14) {
                // CNPJ
                $order->update_meta_data('_customer_type', 'pessoa_juridica');
                $order->update_meta_data('_cnpj', $document);
                $order->update_meta_data('_brazil_document', $document);
                $order->update_meta_data('_brazil_document_type', 'cnpj');
            }
        }
        
        // 后备兼容性：保存旧字段
        if (isset($_POST['brazil_cpf']) && !empty($_POST['brazil_cpf'])) {
            $order->update_meta_data('_customer_type', 'pessoa_fisica');
            $order->update_meta_data('_cpf', sanitize_text_field($_POST['brazil_cpf']));
        }
        
        if (isset($_POST['brazil_cnpj']) && !empty($_POST['brazil_cnpj'])) {
            $order->update_meta_data('_customer_type', 'pessoa_juridica');
            $order->update_meta_data('_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        }
    }
    
    /**
     * CPF验证算法
     */
    private function is_valid_cpf($cpf) {
        if (strlen($cpf) !== 11) return false;
        if (preg_match('/(\d)\1{10}/', $cpf)) return false;
        
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += intval($cpf[$i]) * (10 - $i);
        }
        $remainder = $sum % 11;
        $digit1 = $remainder < 2 ? 0 : 11 - $remainder;
        
        if (intval($cpf[9]) !== $digit1) return false;
        
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += intval($cpf[$i]) * (11 - $i);
        }
        $remainder = $sum % 11;
        $digit2 = $remainder < 2 ? 0 : 11 - $remainder;
        
        return intval($cpf[10]) === $digit2;
    }
    
    /**
     * CNPJ验证算法
     */
    private function is_valid_cnpj($cnpj) {
        if (strlen($cnpj) !== 14) return false;
        if (preg_match('/(\d)\1{13}/', $cnpj)) return false;
        
        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += intval($cnpj[$i]) * $weights1[$i];
        }
        $remainder = $sum % 11;
        $digit1 = $remainder < 2 ? 0 : 11 - $remainder;
        
        if (intval($cnpj[12]) !== $digit1) return false;
        
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += intval($cnpj[$i]) * $weights2[$i];
        }
        $remainder = $sum % 11;
        $digit2 = $remainder < 2 ? 0 : 11 - $remainder;
        
        return intval($cnpj[13]) === $digit2;
    }
    
    /**
     * 在订单详情显示字段
     */
    public function display_fields_in_order($order) {
        // 优先检查新的统一文档字段
        $document = $order->get_meta('_brazil_document');
        $document_type = $order->get_meta('_brazil_document_type');
        
        if (!empty($document) && !empty($document_type)) {
            if ($document_type === 'cpf') {
                echo '<p><strong>Tipo de Cliente:</strong> Pessoa Física</p>';
                echo '<p><strong>CPF:</strong> ' . esc_html($document) . '</p>';
            } elseif ($document_type === 'cnpj') {
                echo '<p><strong>Tipo de Cliente:</strong> Pessoa Jurídica</p>';
                echo '<p><strong>CNPJ:</strong> ' . esc_html($document) . '</p>';
            }
            return;
        }
        
        // 后备兼容性：检查旧字段
        $customer_type = $order->get_meta('_customer_type');
        $cpf = $order->get_meta('_cpf');
        $cnpj = $order->get_meta('_cnpj');
        
        if ($customer_type === 'pessoa_fisica' && $cpf) {
            echo '<p><strong>Tipo de Cliente:</strong> Pessoa Física</p>';
            echo '<p><strong>CPF:</strong> ' . esc_html($cpf) . '</p>';
        } elseif ($customer_type === 'pessoa_juridica' && $cnpj) {
            echo '<p><strong>Tipo de Cliente:</strong> Pessoa Jurídica</p>';
            echo '<p><strong>CNPJ:</strong> ' . esc_html($cnpj) . '</p>';
        }
    }
    
    /**
     * 在后台订单页面显示字段
     */
    public function display_fields_in_admin_order($order) {
        // 优先检查新的统一文档字段
        $document = $order->get_meta('_brazil_document');
        $document_type = $order->get_meta('_brazil_document_type');
        
        if (!empty($document) && !empty($document_type)) {
            echo '<div class="address"><p><strong>Informações Fiscais:</strong></p>';
            
            if ($document_type === 'cpf') {
                echo '<p><strong>Tipo:</strong> Pessoa Física<br>';
                echo '<strong>CPF:</strong> ' . esc_html($document) . '</p>';
            } elseif ($document_type === 'cnpj') {
                echo '<p><strong>Tipo:</strong> Pessoa Jurídica<br>';
                echo '<strong>CNPJ:</strong> ' . esc_html($document) . '</p>';
            }
            
            echo '</div>';
            return;
        }
        
        // 后备兼容性：检查旧字段
        $customer_type = $order->get_meta('_customer_type');
        $cpf = $order->get_meta('_cpf');
        $cnpj = $order->get_meta('_cnpj');
        
        if ($customer_type && ($cpf || $cnpj)) {
            echo '<div class="address"><p><strong>Informações Fiscais:</strong></p>';
            
            if ($customer_type === 'pessoa_fisica' && $cpf) {
                echo '<p><strong>Tipo:</strong> Pessoa Física<br>';
                echo '<strong>CPF:</strong> ' . esc_html($cpf) . '</p>';
            } elseif ($customer_type === 'pessoa_juridica' && $cnpj) {
                echo '<p><strong>Tipo:</strong> Pessoa Jurídica<br>';
                echo '<strong>CNPJ:</strong> ' . esc_html($cnpj) . '</p>';
            }
            
            echo '</div>';
        }
    }
}

// 初始化插件
Brazil_Checkout_Fields_Blocks::get_instance();
