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
        
        // AJAX保存session数据
        add_action('wp_ajax_save_brazil_session_data', array($this, 'ajax_save_session_data'));
        add_action('wp_ajax_nopriv_save_brazil_session_data', array($this, 'ajax_save_session_data'));
        
        // AJAX调试（仅管理员）
        add_action('wp_ajax_debug_brazil_order', array($this, 'debug_brazil_order_ajax'));
        
        // Store API扩展 - 让WooCommerce块编辑器识别我们的字段
        add_action('woocommerce_blocks_loaded', array($this, 'register_checkout_fields_block_support'));
        add_action('init', array($this, 'init_store_api_support'));
        
        // 确保在Store API请求前设置字段
        add_action('rest_api_init', array($this, 'register_store_api_fields'));
        
        // 添加调试hook来监控所有Store API请求
        add_action('rest_api_init', array($this, 'debug_store_api_requests'));
        add_filter('rest_pre_dispatch', array($this, 'debug_rest_request'), 10, 3);
        
        // 保存字段数据 - 多个Hook确保保存成功
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_checkout_fields'), 10, 2);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_fields_fallback'), 10, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_checkout_fields_create_order'), 10, 2);
        
        // 添加更多保存Hook来确保数据保存
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'store_api_order_processed'), 10, 1);
        add_filter('woocommerce_store_api_checkout_data', array($this, 'process_store_api_data'), 10, 2);
        
        // 额外的保存Hook - 确保所有情况都覆盖
        add_action('woocommerce_checkout_order_processed', array($this, 'save_checkout_fields_processed'), 10, 3);
        add_action('woocommerce_new_order', array($this, 'save_checkout_fields_new_order'), 10, 1);
        add_action('woocommerce_thankyou', array($this, 'save_checkout_fields_thankyou'), 5, 1);
        
        // 显示字段在订单页面 - 多个位置确保显示
        add_action('woocommerce_order_details_after_customer_details', array($this, 'display_fields_in_order'));
        add_action('woocommerce_view_order_details', array($this, 'display_fields_in_order_details'), 20);
        add_action('woocommerce_thankyou', array($this, 'display_fields_in_thankyou'), 20);
        
        // 额外的用户端显示Hook
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_fields_after_order_table'), 10);
        add_action('woocommerce_view_order', array($this, 'display_fields_in_account_order'), 20);
        add_action('woocommerce_order_details_before_order_table', array($this, 'display_fields_before_order_table'), 20);
        
        // 客户详情相关Hook
        add_action('woocommerce_order_details_after_customer_details', array($this, 'display_fields_after_customer_details'), 25);
        
        // 后台管理订单页面显示
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_fields_in_admin_order'));
        add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_fields_in_admin_order_shipping'));
        
        // 订单邮件中显示
        add_action('woocommerce_email_customer_details', array($this, 'display_fields_in_email'), 20, 3);
        add_action('woocommerce_email_order_details', array($this, 'display_fields_in_email_order'), 15, 4);
        
        // 调试Hook来确认执行
        add_action('woocommerce_order_details_after_customer_details', array($this, 'debug_hook_execution'), 1);
        add_action('woocommerce_view_order', array($this, 'debug_view_order_hook'), 1);
        
        // 添加管理员工具栏调试链接（仅供开发调试）
        if (current_user_can('manage_options')) {
            add_action('wp_footer', array($this, 'add_debug_tools'));
        }
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
                    display: none !important;
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
                .brazil-checkout-fields.brazil-hidden {
                    display: none !important;
                }
                .brazil-checkout-fields.brazil-visible {
                    display: block !important;
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
                    
                    // 检查是否选择了巴西国家
                    var isBrazilSelected = this.isBrazilCountrySelected();
                    
                    console.log('validateAll: 是否选择巴西:', isBrazilSelected);
                    
                    // 如果不是巴西，跳过验证
                    if (!isBrazilSelected) {
                        console.log('validateAll: 不是巴西，跳过验证');
                        return true;
                    }
                    
                    // 检查面板是否可见
                    var brazilPanel = $('.brazil-checkout-fields');
                    if (brazilPanel.length === 0 || (!brazilPanel.is(':visible') && !brazilPanel.hasClass('brazil-visible'))) {
                        console.log('validateAll: 巴西面板不可见，跳过验证');
                        return true;
                    }
                    
                    var documentField = $('#brazil_document');
                    var document = documentField.val();
                    console.log('validateAll: 检查文档字段值:', document);
                    
                    // 1. 检查是否为空
                    if (!document || !document.trim()) {
                        console.log('validateAll: 文档字段为空，添加错误');
                        this.errors.push(brazil_checkout_ajax.messages.document_required);
                        return false;
                    }
                    
                    // 2. 检查字段是否已经标记为无效
                    if (documentField.hasClass('brazil-field-invalid')) {
                        console.log('validateAll: 字段已标记为无效');
                        this.errors.push(brazil_checkout_ajax.messages.document_invalid);
                        return false;
                    }
                    
                    // 3. 执行完整的文档验证
                    if (!this.validateDocument(document)) {
                        console.log('validateAll: 文档验证失败');
                        this.errors.push(brazil_checkout_ajax.messages.document_invalid);
                        return false;
                    }
                    
                    console.log('validateAll: 验证通过');
                    return true;
                },
                
                // 检查是否选择了巴西国家
                isBrazilCountrySelected: function() {
                    var countrySelectors = [
                        'select[name="billing_country"]',
                        'select[name="shipping_country"]', 
                        '#billing_country',
                        '#shipping_country',
                        '[data-field="country"] select',
                        'select[id*="country"]',
                        'select[name*="country"]'
                    ];
                    
                    for (var i = 0; i < countrySelectors.length; i++) {
                        var countryField = $(countrySelectors[i]);
                        if (countryField.length > 0) {
                            var selectedCountry = countryField.val();
                            if (selectedCountry === 'BR') {
                                return true;
                            }
                        }
                    }
                    
                    return false;
                },
                
                // 显示验证错误
                showErrors: function() {
                    // 如果没有错误，隐藏摘要
                    if (this.errors.length === 0) {
                        this.hideErrors();
                        return;
                    }
                    
                    var summaryHtml = '<div class="brazil-checkout-validation-summary show">' +
                        '<strong>Por favor, corrija os seguintes erros:</strong>' +
                        '<ul>';
                    
                    for (var i = 0; i < this.errors.length; i++) {
                        summaryHtml += '<li>' + this.errors[i] + '</li>';
                    }
                    
                    summaryHtml += '</ul></div>';
                    
                    // 移除旧的摘要
                    $('.brazil-checkout-validation-summary').remove();
                    
                    // 只有在面板可见时才显示错误摘要
                    var brazilPanel = $('.brazil-checkout-fields');
                    if (brazilPanel.is(':visible') || brazilPanel.hasClass('brazil-visible')) {
                        brazilPanel.prepend(summaryHtml);
                        
                        // 滚动到错误区域
                        $('html, body').animate({
                            scrollTop: brazilPanel.offset().top - 50
                        }, 500);
                    }
                },
                
                // 隐藏验证错误
                hideErrors: function() {
                    $('.brazil-checkout-validation-summary').removeClass('show').fadeOut(300, function() {
                        $(this).remove();
                    });
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
                
                // 设置事件监听器和初始状态
                setupFieldListeners();
                setupValidation();
                
                // 初始化面板显示状态
                setTimeout(function() {
                    checkCountryAndToggleBrazilFields();
                }, 1000);
            }
            
            function injectBrazilFieldsToFieldsBlock() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                $('.wp-block-woocommerce-checkout-fields-block').append(brazilFieldsHtml);
                console.log('巴西字段已插入到字段块');
                
                setupFieldListeners();
                setupValidation();
                
                // 初始化面板显示状态
                setTimeout(function() {
                    checkCountryAndToggleBrazilFields();
                }, 1000);
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
                
                // 初始化面板显示状态
                setTimeout(function() {
                    checkCountryAndToggleBrazilFields();
                }, 1000);
            }
            
            function injectBrazilFieldsFallback() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                $('body').prepend('<div style="position: relative; z-index: 999; max-width: 600px; margin: 20px auto;">' + brazilFieldsHtml + '</div>');
                console.log('使用后备方法插入巴西字段');
                
                setupFieldListeners();
                setupValidation();
                
                // 初始化面板显示状态
                setTimeout(function() {
                    checkCountryAndToggleBrazilFields();
                }, 1000);
            }
            
            // 全局的国家检查和面板切换函数
            function checkCountryAndToggleBrazilFields() {
                // 查找各种可能的国家选择器
                var countrySelectors = [
                    'select[name="billing_country"]',
                    'select[name="shipping_country"]', 
                    '#billing_country',
                    '#shipping_country',
                    '[data-field="country"] select',
                    'select[id*="country"]',
                    'select[name*="country"]'
                ];
                
                var isBrazilSelected = false;
                var foundCountryField = false;
                
                for (var i = 0; i < countrySelectors.length; i++) {
                    var countryField = $(countrySelectors[i]);
                    if (countryField.length > 0) {
                        foundCountryField = true;
                        var selectedCountry = countryField.val();
                        console.log('检测到国家选择器:', countrySelectors[i], '选择的国家:', selectedCountry);
                        
                        if (selectedCountry === 'BR') {
                            isBrazilSelected = true;
                            break;
                        }
                    }
                }
                
                console.log('找到国家字段:', foundCountryField, '是否选择巴西:', isBrazilSelected);
                
                // 确保面板存在
                var brazilPanel = $('.brazil-checkout-fields');
                if (brazilPanel.length === 0) {
                    console.log('巴西面板未找到，跳过切换');
                    return;
                }
                
                if (isBrazilSelected) {
                    console.log('显示巴西面板');
                    brazilPanel.removeClass('brazil-hidden').addClass('brazil-visible').hide().slideDown(300);
                    $('#brazil_document').prop('required', true);
                    
                    // 确保验证函数被正确绑定
                    setTimeout(function() {
                        if (typeof window.validateBrazilFields === 'function') {
                            console.log('巴西验证函数已就绪');
                        } else {
                            console.log('警告：巴西验证函数未就绪');
                        }
                    }, 500);
                } else {
                    console.log('隐藏巴西面板');
                    brazilPanel.slideUp(300, function() {
                        $(this).removeClass('brazil-visible').addClass('brazil-hidden');
                    });
                    $('#brazil_document').prop('required', false).val('');
                    // 清空隐藏字段
                    $('#brazil_customer_type').val('');
                    $('#brazil_cpf').val('');
                    $('#brazil_cnpj').val('');
                    // 清除验证状态
                    $('.brazil-document-error').hide();
                    $('.brazil-document-success').hide();
                    $('#brazil_document').removeClass('brazil-field-invalid brazil-field-valid');
                    if (typeof brazilValidation !== 'undefined') {
                        brazilValidation.hideErrors();
                    }
                }
            }
            
            function setupFieldListeners() {
                // 初始检查 - 延迟执行确保DOM完全加载
                setTimeout(function() {
                    console.log('执行初始国家检查');
                    checkCountryAndToggleBrazilFields();
                }, 500);
                
                // 再次检查，确保捕获到所有情况
                setTimeout(function() {
                    console.log('执行第二次国家检查');
                    checkCountryAndToggleBrazilFields();
                }, 2000);
                
                // 强制检查 - 确保非巴西国家时面板保持隐藏
                setTimeout(function() {
                    console.log('执行强制检查');
                    var brazilPanel = $('.brazil-checkout-fields');
                    if (brazilPanel.length > 0 && !brazilValidation.isBrazilCountrySelected()) {
                        console.log('强制隐藏非巴西面板');
                        brazilPanel.removeClass('brazil-visible').addClass('brazil-hidden').hide();
                    }
                }, 3000);
                
                // 监听国家选择变化 - 使用事件委托
                $(document).on('change', 'select[name="billing_country"], select[name="shipping_country"], #billing_country, #shipping_country, select[id*="country"], select[name*="country"]', function() {
                    console.log('国家选择发生变化:', $(this).attr('name') || $(this).attr('id'), '新值:', $(this).val());
                    setTimeout(checkCountryAndToggleBrazilFields, 100);
                });
                
                // 监听输入事件（有些主题可能使用输入而不是选择）
                $(document).on('input', 'select[name="billing_country"], select[name="shipping_country"], #billing_country, #shipping_country', function() {
                    console.log('国家输入发生变化:', $(this).attr('name') || $(this).attr('id'), '新值:', $(this).val());
                    setTimeout(checkCountryAndToggleBrazilFields, 100);
                });
                
                // 使用MutationObserver监听DOM变化，以捕获动态生成的国家选择器
                var countryObserver = new MutationObserver(function(mutations) {
                    var shouldCheck = false;
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes.length > 0) {
                            $(mutation.addedNodes).find('select[name*="country"], select[id*="country"]').each(function() {
                                console.log('检测到新的国家选择器:', $(this).attr('name') || $(this).attr('id'));
                                $(this).on('change input', function() {
                                    console.log('新国家选择器变化:', $(this).val());
                                    setTimeout(checkCountryAndToggleBrazilFields, 100);
                                });
                                shouldCheck = true;
                            });
                        }
                        
                        // 检查是否有属性变化（如value变化）
                        if (mutation.type === 'attributes' && (mutation.attributeName === 'value' || mutation.attributeName === 'selected')) {
                            var target = $(mutation.target);
                            if (target.is('select') && (target.attr('name') || target.attr('id') || '').toLowerCase().includes('country')) {
                                console.log('检测到国家字段属性变化:', target.attr('name') || target.attr('id'), '新值:', target.val());
                                shouldCheck = true;
                            }
                        }
                    });
                    
                    if (shouldCheck) {
                        setTimeout(checkCountryAndToggleBrazilFields, 100);
                    }
                });
                
                countryObserver.observe(document.body, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['value', 'selected']
                });
                
                // 智能文档输入处理
                $(document).on('input', '#brazil_document', function() {
                    var value = $(this).val().replace(/[^0-9]/g, '');
                    var documentType = brazilValidation.detectDocumentType(value);
                    var formattedValue = '';
                    var maxLength = 18;
                    var placeholder = '';
                    var hint = '';
                    
                    // 清除之前的验证错误摘要（当用户开始输入时）
                    if (value.length > 0) {
                        brazilValidation.hideErrors();
                    }
                    
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
                    <div class="brazil-checkout-fields brazil-hidden">
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
                    checkCountryAndToggleBrazilFields();
                }, 1000);
            }
            
            function setupValidation() {
                // 创建全局验证函数
                window.validateBrazilFields = function() {
                    console.log('🔍 验证巴西字段被调用');
                    
                    // 检查是否选择了巴西国家
                    var isBrazilSelected = brazilValidation.isBrazilCountrySelected();
                    console.log('🌍 全局验证: 是否选择巴西:', isBrazilSelected);
                    
                    // 如果不是巴西，跳过验证
                    if (!isBrazilSelected) {
                        console.log('✅ 全局验证: 跳过验证（不是巴西）');
                        brazilValidation.errors = [];
                        brazilValidation.hideErrors();
                        return true;
                    }
                    
                    // 检查面板是否存在且可见
                    var brazilPanel = $('.brazil-checkout-fields');
                    var panelVisible = brazilPanel.length > 0 && (brazilPanel.is(':visible') || brazilPanel.hasClass('brazil-visible'));
                    console.log('👁️ 全局验证: 面板可见:', panelVisible);
                    
                    if (!panelVisible) {
                        console.log('✅ 全局验证: 跳过验证（面板不可见）');
                        brazilValidation.errors = [];
                        brazilValidation.hideErrors();
                        return true;
                    }
                    
                    // 执行巴西字段验证
                    console.log('🧪 全局验证: 执行巴西字段验证');
                    
                    var documentField = $('#brazil_document');
                    var documentValue = documentField.val() || '';
                    
                    console.log('📄 全局验证: 文档字段值:', '"' + documentValue + '"');
                    console.log('⚠️ 全局验证: 字段是否标记为无效:', documentField.hasClass('brazil-field-invalid'));
                    
                    // 重置错误数组
                    brazilValidation.errors = [];
                    
                    // 1. 检查是否为空
                    if (!documentValue.trim()) {
                        console.log('❌ 全局验证: 文档字段为空');
                        brazilValidation.errors.push('CPF ou CNPJ é obrigatório para endereços brasileiros.');
                        brazilValidation.showErrors();
                        return false;
                    }
                    
                    // 2. 检查字段是否已经标记为无效
                    if (documentField.hasClass('brazil-field-invalid')) {
                        console.log('❌ 全局验证: 字段已标记为无效');
                        brazilValidation.errors.push('CPF ou CNPJ inválido. Verifique o número digitado.');
                        brazilValidation.showErrors();
                        return false;
                    }
                    
                    // 3. 执行完整的文档验证（双重检查）
                    var isValidDoc = brazilValidation.validateDocument(documentValue);
                    console.log('📋 全局验证: 文档验证结果:', isValidDoc);
                    
                    if (!isValidDoc) {
                        console.log('❌ 全局验证: 文档格式无效');
                        brazilValidation.errors.push('CPF ou CNPJ inválido. Verifique o número digitado.');
                        brazilValidation.showErrors();
                        return false;
                    }
                    
                    console.log('✅ 全局验证: 验证通过');
                    brazilValidation.errors = [];
                    brazilValidation.hideErrors();
                    return true;
                };
                
                // 拦截表单提交 - 使用多种方法
                $(document).on('submit', 'form', function(e) {
                    console.log('📝 表单提交拦截 - 验证巴西字段');
                    
                    // 首先执行最终验证，确保字段状态是最新的
                    var documentField = $('#brazil_document');
                    var currentValue = documentField.val() || '';
                    
                    // 检查是否需要巴西验证
                    var isBrazilSelected = brazilValidation.isBrazilCountrySelected();
                    var brazilPanel = $('.brazil-checkout-fields');
                    var panelVisible = brazilPanel.length > 0 && (brazilPanel.is(':visible') || brazilPanel.hasClass('brazil-visible'));
                    
                    if (isBrazilSelected && panelVisible) {
                        console.log('🔍 表单提交时执行最终文档验证:', currentValue);
                        
                        // 保存巴西数据到session（通过AJAX）
                        if (currentValue.trim()) {
                            console.log('💾 保存巴西数据到session');
                            $.post(brazil_checkout_ajax.ajax_url, {
                                action: 'save_brazil_session_data',
                                nonce: brazil_checkout_ajax.nonce,
                                brazil_document: currentValue,
                                billing_country: $('select[name="billing_country"], #billing_country').val() || 'BR'
                            }, function(response) {
                                console.log('Session save response:', response);
                            });
                        }
                        
                        // 清除之前的验证状态
                        documentField.removeClass('brazil-field-invalid brazil-field-valid');
                        
                        if (currentValue.trim()) {
                            // 执行最终验证并更新字段状态
                            var documentType = brazilValidation.detectDocumentType(currentValue);
                            var isValidDocument = false;
                            
                            if (documentType === 'cpf') {
                                isValidDocument = brazilValidation.validateCPF(currentValue);
                            } else {
                                isValidDocument = brazilValidation.validateCNPJ(currentValue);
                            }
                            
                            // 更新字段状态
                            if (isValidDocument) {
                                documentField.addClass('brazil-field-valid');
                                console.log('🟢 最终验证: 文档有效');
                            } else {
                                documentField.addClass('brazil-field-invalid');
                                console.log('🔴 最终验证: 文档无效');
                            }
                        } else {
                            // 空值情况，直接标记为无效
                            console.log('🔴 最终验证: 文档为空');
                        }
                    }
                    
                    var isValid = window.validateBrazilFields();
                    if (!isValid) {
                        console.log('🛑 巴西字段验证失败，阻止提交');
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        
                        // 显示错误摘要
                        brazilValidation.showErrors();
                        
                        // 滚动到错误位置
                        var brazilPanel = $('.brazil-checkout-fields');
                        if (brazilPanel.length > 0) {
                            $('html, body').animate({
                                scrollTop: brazilPanel.offset().top - 100
                            }, 500);
                        }
                        
                        return false;
                    }
                    console.log('✅ 巴西字段验证通过');
                });
                
                // 拦截所有按钮点击 - 扩展选择器以包含更多WooCommerce块编辑器按钮
                $(document).on('click', 'button[type="submit"], input[type="submit"], .wc-block-components-checkout-place-order-button, .wc-block-checkout__place-order-button, [class*="place-order"], [class*="checkout-place-order"], button[class*="place-order"], button[aria-label*="Place order"], button[aria-label*="下单"], button:contains("Place order"), button:contains("下单")', function(e) {
                    console.log('🖱️ 提交按钮点击拦截:', $(this).attr('class') || 'unknown', '按钮文本:', $(this).text().trim());
                    
                    // 首先执行最终验证，确保字段状态是最新的
                    var documentField = $('#brazil_document');
                    var currentValue = documentField.val() || '';
                    
                    // 检查是否需要巴西验证
                    var isBrazilSelected = brazilValidation.isBrazilCountrySelected();
                    var brazilPanel = $('.brazil-checkout-fields');
                    var panelVisible = brazilPanel.length > 0 && (brazilPanel.is(':visible') || brazilPanel.hasClass('brazil-visible'));
                    
                    if (isBrazilSelected && panelVisible) {
                        console.log('🔍 按钮点击时执行最终文档验证:', currentValue);
                        
                        // 清除之前的验证状态
                        documentField.removeClass('brazil-field-invalid brazil-field-valid');
                        
                        if (currentValue.trim()) {
                            // 执行最终验证并更新字段状态
                            var documentType = brazilValidation.detectDocumentType(currentValue);
                            var isValidDocument = false;
                            
                            if (documentType === 'cpf') {
                                isValidDocument = brazilValidation.validateCPF(currentValue);
                            } else {
                                isValidDocument = brazilValidation.validateCNPJ(currentValue);
                            }
                            
                            // 更新字段状态
                            if (isValidDocument) {
                                documentField.addClass('brazil-field-valid');
                                console.log('🟢 最终验证: 文档有效');
                            } else {
                                documentField.addClass('brazil-field-invalid');
                                console.log('🔴 最终验证: 文档无效');
                            }
                        } else {
                            // 空值情况，直接标记为无效
                            console.log('🔴 最终验证: 文档为空');
                        }
                    }
                    
                    var isValid = window.validateBrazilFields();
                    if (!isValid) {
                        console.log('🛑 按钮点击验证失败，阻止提交');
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        
                        // 显示错误摘要
                        brazilValidation.showErrors();
                        
                        // 聚焦到错误字段并滚动
                        var brazilPanel = $('.brazil-checkout-fields');
                        if (brazilPanel.length > 0) {
                            var documentField = $('#brazil_document');
                            if (documentField.length > 0) {
                                documentField.focus();
                            }
                            $('html, body').animate({
                                scrollTop: brazilPanel.offset().top - 100
                            }, 500);
                        }
                        
                        return false;
                    }
                    console.log('✅ 按钮点击验证通过');
                });
                
                // 监听WooCommerce特定事件
                $(document.body).on('checkout_place_order', function(e) {
                    console.log('🛒 checkout_place_order 事件触发');
                    
                    // 首先执行最终验证，确保字段状态是最新的
                    var documentField = $('#brazil_document');
                    var currentValue = documentField.val() || '';
                    
                    // 检查是否需要巴西验证
                    var isBrazilSelected = brazilValidation.isBrazilCountrySelected();
                    var brazilPanel = $('.brazil-checkout-fields');
                    var panelVisible = brazilPanel.length > 0 && (brazilPanel.is(':visible') || brazilPanel.hasClass('brazil-visible'));
                    
                    if (isBrazilSelected && panelVisible) {
                        console.log('🔍 WooCommerce事件时执行最终文档验证:', currentValue);
                        
                        // 清除之前的验证状态
                        documentField.removeClass('brazil-field-invalid brazil-field-valid');
                        
                        if (currentValue.trim()) {
                            // 执行最终验证并更新字段状态
                            var documentType = brazilValidation.detectDocumentType(currentValue);
                            var isValidDocument = false;
                            
                            if (documentType === 'cpf') {
                                isValidDocument = brazilValidation.validateCPF(currentValue);
                            } else {
                                isValidDocument = brazilValidation.validateCNPJ(currentValue);
                            }
                            
                            // 更新字段状态
                            if (isValidDocument) {
                                documentField.addClass('brazil-field-valid');
                                console.log('🟢 最终验证: 文档有效');
                            } else {
                                documentField.addClass('brazil-field-invalid');
                                console.log('🔴 最终验证: 文档无效');
                            }
                        } else {
                            // 空值情况，直接标记为无效
                            console.log('🔴 最终验证: 文档为空');
                        }
                    }
                    
                    var isValid = window.validateBrazilFields();
                    if (!isValid) {
                        console.log('🛑 checkout_place_order 验证失败');
                        brazilValidation.showErrors();
                        // 对于WooCommerce事件，我们需要阻止事件传播
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    return isValid;
                });
                
                // 使用MutationObserver监听DOM变化，确保验证函数绑定到新的按钮
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes.length > 0) {
                            // 为新添加的提交按钮绑定验证 - 扩展选择器
                            $(mutation.addedNodes).find('button[type="submit"], .wc-block-components-checkout-place-order-button, .wc-block-checkout__place-order-button, [class*="place-order"], [class*="checkout-place-order"], button[class*="place-order"], button[aria-label*="Place order"], button[aria-label*="下单"]').each(function() {
                                var $btn = $(this);
                                if (!$btn.data('brazil-validation-bound')) {
                                    console.log('🆕 绑定新按钮验证:', $btn.attr('class') || 'unknown');
                                    $btn.data('brazil-validation-bound', true);
                                    $btn.on('click.brazil-validation', function(e) {
                                        console.log('🖱️ 动态按钮点击拦截');
                                        
                                        // 首先执行最终验证，确保字段状态是最新的
                                        var documentField = $('#brazil_document');
                                        var currentValue = documentField.val() || '';
                                        
                                        // 检查是否需要巴西验证
                                        var isBrazilSelected = brazilValidation.isBrazilCountrySelected();
                                        var brazilPanel = $('.brazil-checkout-fields');
                                        var panelVisible = brazilPanel.length > 0 && (brazilPanel.is(':visible') || brazilPanel.hasClass('brazil-visible'));
                                        
                                        if (isBrazilSelected && panelVisible) {
                                            console.log('🔍 动态按钮点击时执行最终文档验证:', currentValue);
                                            
                                            // 清除之前的验证状态
                                            documentField.removeClass('brazil-field-invalid brazil-field-valid');
                                            
                                            if (currentValue.trim()) {
                                                // 执行最终验证并更新字段状态
                                                var documentType = brazilValidation.detectDocumentType(currentValue);
                                                var isValidDocument = false;
                                                
                                                if (documentType === 'cpf') {
                                                    isValidDocument = brazilValidation.validateCPF(currentValue);
                                                } else {
                                                    isValidDocument = brazilValidation.validateCNPJ(currentValue);
                                                }
                                                
                                                // 更新字段状态
                                                if (isValidDocument) {
                                                    documentField.addClass('brazil-field-valid');
                                                    console.log('🟢 最终验证: 文档有效');
                                                } else {
                                                    documentField.addClass('brazil-field-invalid');
                                                    console.log('� 最终验证: 文档无效');
                                                }
                                            } else {
                                                // 空值情况，直接标记为无效
                                                console.log('🔴 最终验证: 文档为空');
                                            }
                                        }
                                        
                                        var isValid = window.validateBrazilFields();
                                        if (!isValid) {
                                            console.log('🛑 动态按钮验证失败');
                                            e.preventDefault();
                                            e.stopPropagation();
                                            e.stopImmediatePropagation();
                                            brazilValidation.showErrors();
                                            return false;
                                        }
                                        return true;
                                    });
                                }
                            });
                        }
                    });
                });
                
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
                
                console.log('验证监听器已设置');
                
                // 额外的按钮检测和绑定机制
                function bindSubmitButtons() {
                    var submitButtonSelectors = [
                        'button[type="submit"]',
                        '.wc-block-components-checkout-place-order-button',
                        '.wc-block-checkout__place-order-button',
                        '[class*="place-order"]',
                        '[class*="checkout-place-order"]',
                        'button[class*="place-order"]',
                        'button[aria-label*="Place order"]',
                        'button[aria-label*="下单"]'
                    ];
                    
                    submitButtonSelectors.forEach(function(selector) {
                        $(selector).each(function() {
                            var $btn = $(this);
                            if (!$btn.data('brazil-validation-bound')) {
                                console.log('🔗 主动绑定提交按钮:', selector, '按钮类:', $btn.attr('class') || 'none', '按钮文本:', $btn.text().trim());
                                $btn.data('brazil-validation-bound', true);
                                $btn.on('click.brazil-validation', function(e) {
                                    console.log('🖱️ 主动绑定按钮点击拦截');
                                    
                                    // 首先执行最终验证，确保字段状态是最新的
                                    var documentField = $('#brazil_document');
                                    var currentValue = documentField.val() || '';
                                    
                                    // 检查是否需要巴西验证
                                    var isBrazilSelected = brazilValidation.isBrazilCountrySelected();
                                    var brazilPanel = $('.brazil-checkout-fields');
                                    var panelVisible = brazilPanel.length > 0 && (brazilPanel.is(':visible') || brazilPanel.hasClass('brazil-visible'));
                                    
                                    if (isBrazilSelected && panelVisible) {
                                        console.log('🔍 主动绑定按钮点击时执行最终文档验证:', currentValue);
                                        
                                        // 清除之前的验证状态
                                        documentField.removeClass('brazil-field-invalid brazil-field-valid');
                                        
                                        if (currentValue.trim()) {
                                            // 执行最终验证并更新字段状态
                                            var documentType = brazilValidation.detectDocumentType(currentValue);
                                            var isValidDocument = false;
                                            
                                            if (documentType === 'cpf') {
                                                isValidDocument = brazilValidation.validateCPF(currentValue);
                                            } else {
                                                isValidDocument = brazilValidation.validateCNPJ(currentValue);
                                            }
                                            
                                            // 更新字段状态
                                            if (isValidDocument) {
                                                documentField.addClass('brazil-field-valid');
                                                console.log('🟢 最终验证: 文档有效');
                                            } else {
                                                documentField.addClass('brazil-field-invalid');
                                                console.log('🔴 最终验证: 文档无效');
                                            }
                                        } else {
                                            // 空值情况，直接标记为无效
                                            console.log('🔴 最终验证: 文档为空');
                                        }
                                    }
                                    
                                    var isValid = window.validateBrazilFields();
                                    if (!isValid) {
                                        console.log('🛑 主动绑定按钮验证失败');
                                        e.preventDefault();
                                        e.stopPropagation();
                                        e.stopImmediatePropagation();
                                        brazilValidation.showErrors();
                                        return false;
                                    }
                                    console.log('✅ 主动绑定按钮验证通过');
                                    return true;
                                });
                            }
                        });
                    });
                }
                
                // 定期检查和绑定按钮
                setInterval(bindSubmitButtons, 2000);
                
                // 立即执行一次
                setTimeout(bindSubmitButtons, 1000);
                setTimeout(bindSubmitButtons, 3000);
                setTimeout(bindSubmitButtons, 5000);
                
                // 添加全局点击监听器作为最后的后备
                $(document).on('click', '*', function(e) {
                    var $target = $(e.target);
                    var isSubmitButton = false;
                    
                    // 检查是否是提交按钮
                    if ($target.is('button') || $target.is('input[type="submit"]')) {
                        var buttonText = $target.text().toLowerCase().trim();
                        var buttonClass = $target.attr('class') || '';
                        var buttonAriaLabel = $target.attr('aria-label') || '';
                        
                        if (buttonText.includes('place order') || 
                            buttonText.includes('下单') || 
                            buttonText.includes('submit') ||
                            buttonText.includes('完成订单') ||
                            buttonClass.includes('place-order') ||
                            buttonClass.includes('checkout') ||
                            buttonAriaLabel.includes('Place order') ||
                            buttonAriaLabel.includes('下单')) {
                            isSubmitButton = true;
                        }
                    }
                    
                    if (isSubmitButton && !$target.data('brazil-validation-checked')) {
                        console.log('🎯 全局点击监听器捕获到提交按钮:', $target.attr('class') || 'none', '文本:', $target.text().trim());
                        $target.data('brazil-validation-checked', true);
                        
                        // 检查是否需要巴西验证
                        var isBrazilSelected = brazilValidation.isBrazilCountrySelected();
                        var brazilPanel = $('.brazil-checkout-fields');
                        var panelVisible = brazilPanel.length > 0 && (brazilPanel.is(':visible') || brazilPanel.hasClass('brazil-visible'));
                        
                        if (isBrazilSelected && panelVisible) {
                            console.log('🔍 全局点击监听器执行最终文档验证');
                            
                            var documentField = $('#brazil_document');
                            var currentValue = documentField.val() || '';
                            
                            // 清除之前的验证状态
                            documentField.removeClass('brazil-field-invalid brazil-field-valid');
                            
                            if (currentValue.trim()) {
                                // 执行最终验证并更新字段状态
                                var documentType = brazilValidation.detectDocumentType(currentValue);
                                var isValidDocument = false;
                                
                                if (documentType === 'cpf') {
                                    isValidDocument = brazilValidation.validateCPF(currentValue);
                                } else {
                                    isValidDocument = brazilValidation.validateCNPJ(currentValue);
                                }
                                
                                // 更新字段状态
                                if (isValidDocument) {
                                    documentField.addClass('brazil-field-valid');
                                    console.log('🟢 全局验证: 文档有效');
                                } else {
                                    documentField.addClass('brazil-field-invalid');
                                    console.log('🔴 全局验证: 文档无效');
                                }
                            } else {
                                console.log('🔴 全局验证: 文档为空');
                            }
                            
                            var isValid = window.validateBrazilFields();
                            if (!isValid) {
                                console.log('🛑 全局点击监听器验证失败，阻止提交');
                                e.preventDefault();
                                e.stopPropagation();
                                e.stopImmediatePropagation();
                                brazilValidation.showErrors();
                                return false;
                            }
                            console.log('✅ 全局点击监听器验证通过');
                        }
                    }
                });
                
                console.log('验证监听器已设置');
                
                // 确保验证函数在全局可用
                setTimeout(function() {
                    if (typeof window.validateBrazilFields === 'function') {
                        console.log('✓ 巴西验证函数已正确注册');
                    } else {
                        console.error('✗ 巴西验证函数注册失败');
                    }
                }, 1000);
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
                        field.addClass('brazil-field-valid');
                        // 隐藏验证错误摘要
                        brazilValidation.hideErrors();
                    } else {
                        errorContainer.text('CPF inválido').show();
                        field.addClass('brazil-field-invalid');
                    }
                } else if (documentType === 'cnpj') {
                    isValid = brazilValidation.validateCNPJ(value);
                    if (isValid) {
                        successContainer.text(brazil_checkout_ajax.messages.cnpj_valid).show();
                        field.addClass('brazil-field-valid');
                        // 隐藏验证错误摘要
                        brazilValidation.hideErrors();
                    } else {
                        errorContainer.text('CNPJ inválido').show();
                        field.addClass('brazil-field-invalid');
                    }
                }
            }
            
            // Session数据保存功能
            function saveBrazilDataToSession() {
                var documentField = $('.brazil-checkout-fields input[name="brazil_document"]');
                var countryField = $('select[name="billing_country"], input[name="billing_country"]');
                
                if (documentField.length && countryField.length) {
                    var documentValue = documentField.val();
                    var countryValue = countryField.val();
                    
                    if (documentValue && countryValue === 'BR') {
                        console.log('💾 Saving Brazil data to session:', documentValue);
                        
                        var formData = new FormData();
                        formData.append('action', 'save_brazil_session_data');
                        formData.append('cpf_cnpj', documentValue);
                        formData.append('billing_country', countryValue);
                        formData.append('_wpnonce', brazilCheckoutVars.nonce);
                        
                        fetch(brazilCheckoutVars.ajaxurl, {
                            method: 'POST',
                            body: formData
                        }).then(function(response) {
                            return response.json();
                        }).then(function(data) {
                            console.log('🔄 Brazil data saved to session:', data);
                        }).catch(function(error) {
                            console.log('❌ Session save error:', error);
                        });
                    }
                }
            }
            
            // WooCommerce Store API拦截 - 关键修复
            function interceptStoreAPIRequests() {
                // 拦截所有fetch请求
                var originalFetch = window.fetch;
                window.fetch = function(url, options) {
                    // 检查是否是WooCommerce Store API checkout请求
                    if (url && url.includes('/wp-json/wc/store/v1/checkout')) {
                        console.log('🔍 拦截Store API请求:', url);
                        
                        try {
                            var documentField = $('.brazil-checkout-fields input[name="brazil_document"]');
                            var documentValue = documentField.val();
                            
                            if (documentValue && options && options.body) {
                                var requestData = JSON.parse(options.body);
                                console.log('📦 原始请求数据:', requestData);
                                
                                // 确保additional_fields存在
                                if (!requestData.additional_fields) {
                                    requestData.additional_fields = {};
                                }
                                
                                // 添加巴西字段到请求数据
                                requestData.additional_fields.brazil_document = documentValue;
                                
                                // 检测文档类型并添加相关字段
                                var documentType = brazilValidation.detectDocumentType(documentValue);
                                if (documentType === 'cpf') {
                                    requestData.additional_fields.brazil_cpf = documentValue;
                                    requestData.additional_fields.brazil_customer_type = 'pessoa_fisica';
                                    requestData.additional_fields.brazil_cnpj = ''; // 确保CNPJ为空字符串
                                } else {
                                    requestData.additional_fields.brazil_cnpj = documentValue;
                                    requestData.additional_fields.brazil_customer_type = 'pessoa_juridica';
                                    requestData.additional_fields.brazil_cpf = ''; // 确保CPF为空字符串
                                }
                                
                                // 更新请求体
                                options.body = JSON.stringify(requestData);
                                console.log('✅ 已将巴西字段添加到Store API请求:', {
                                    brazil_document: documentValue,
                                    document_type: documentType
                                });
                                console.log('📤 修改后的请求数据:', requestData);
                            }
                        } catch (error) {
                            console.error('❌ Store API拦截错误:', error);
                        }
                    }
                    
                    return originalFetch.apply(this, arguments);
                };
                
                console.log('🔗 Store API拦截器已设置');
            }
            
            // 初始化Store API拦截
            interceptStoreAPIRequests();
            
            // 拦截所有可能的表单提交
            $(document).on('submit', 'form', function(e) {
                console.log('📝 Form submission intercepted');
                saveBrazilDataToSession();
            });
            
            // 拦截块编辑器按钮点击
            $(document).on('click', '[type="submit"], .wc-block-components-checkout-place-order-button, .wp-block-woocommerce-checkout .wc-block-checkout__actions button', function(e) {
                console.log('🔘 Submit button clicked, saving data to session');
                saveBrazilDataToSession();
            });
            
            // 页面卸载前保存数据
            $(window).on('beforeunload', function() {
                saveBrazilDataToSession();
            });
            
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
        
        // 检查是否选择了巴西国家
        $billing_country = isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '';
        $shipping_country = isset($_POST['shipping_country']) ? sanitize_text_field($_POST['shipping_country']) : '';
        
        // 如果不是巴西，跳过验证
        if ($billing_country !== 'BR' && $shipping_country !== 'BR') {
            error_log('Brazil Checkout: 不是巴西地址，跳过验证. Billing: ' . $billing_country . ', Shipping: ' . $shipping_country);
            return $errors;
        }
        
        error_log('Brazil Checkout: 检测到巴西地址，执行CPF/CNPJ验证');
        
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
            $errors[] = 'CPF ou CNPJ é obrigatório para endereços brasileiros.';
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
     * AJAX保存session数据
     */
    public function ajax_save_session_data() {
        try {
            // 开启session
            if (!session_id()) {
                session_start();
            }
            
            // 验证nonce
            if (!wp_verify_nonce($_POST['_wpnonce'], 'brazil_fields_nonce')) {
                wp_die('Security check failed');
            }
            
            // 获取并保存数据
            $cpf_cnpj = sanitize_text_field($_POST['cpf_cnpj'] ?? '');
            $country = sanitize_text_field($_POST['billing_country'] ?? '');
            
            if (!empty($cpf_cnpj)) {
                $_SESSION['brazil_cpf_cnpj'] = $cpf_cnpj;
                $_SESSION['brazil_billing_country'] = $country;
                $_SESSION['brazil_data_timestamp'] = current_time('timestamp');
                
                error_log("Brazil Fields Session Save: CPF/CNPJ={$cpf_cnpj}, Country={$country}");
                
                wp_send_json_success(array(
                    'message' => 'Session data saved',
                    'cpf_cnpj' => $cpf_cnpj,
                    'country' => $country
                ));
            } else {
                wp_send_json_error('No data to save');
            }
        } catch (Exception $e) {
            error_log("Brazil Fields Session Save Error: " . $e->getMessage());
            wp_send_json_error('Failed to save session data');
        }
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
     * 保存结账字段数据 - 增强版本
     */
    public function save_checkout_fields($order, $request) {
        error_log('🔥 BRAZIL CHECKOUT: save_checkout_fields MAIN FUNCTION CALLED - Order ID: ' . ($order ? $order->get_id() : 'null'));
        
        if (!$order) {
            error_log('Brazil Checkout: No order object provided');
            return;
        }
        
        $order_id = $order->get_id();
        error_log('Brazil Checkout: Processing order ID: ' . $order_id);
        
        // 检查请求参数
        $request_params = $request ? $request->get_params() : array();
        error_log('Brazil Checkout: Request params keys: ' . implode(', ', array_keys($request_params)));
        
        // 检查POST数据
        error_log('Brazil Checkout: POST data keys: ' . implode(', ', array_keys($_POST)));
        
        // 检查是否选择了巴西国家
        $billing_country = '';
        $shipping_country = '';
        
        // 从多个来源获取国家信息
        if (isset($request_params['billing_address']['country'])) {
            $billing_country = $request_params['billing_address']['country'];
        } elseif (isset($_POST['billing_country'])) {
            $billing_country = sanitize_text_field($_POST['billing_country']);
        }
        
        if (isset($request_params['shipping_address']['country'])) {
            $shipping_country = $request_params['shipping_address']['country'];
        } elseif (isset($_POST['shipping_country'])) {
            $shipping_country = sanitize_text_field($_POST['shipping_country']);
        }
        
        error_log('Brazil Checkout: Countries - Billing: ' . $billing_country . ', Shipping: ' . $shipping_country);
        
        // 如果不是巴西，不保存字段
        if ($billing_country !== 'BR' && $shipping_country !== 'BR') {
            error_log('Brazil Checkout: Not Brazil address, skipping save');
            return;
        }
        
        // 查找文档数据 - 检查多个可能的来源
        $document = '';
        
        // 1. 检查Store API的additional_fields
        if (isset($request_params['additional_fields']['brazil_document'])) {
            $document = sanitize_text_field($request_params['additional_fields']['brazil_document']);
            error_log('Brazil Checkout: Found document in additional_fields: ' . $document);
        }
        
        // 2. 检查Store API的extensions
        elseif (isset($request_params['extensions']['brazil-checkout-fields']['brazil_document'])) {
            $document = sanitize_text_field($request_params['extensions']['brazil-checkout-fields']['brazil_document']);
            error_log('Brazil Checkout: Found document in extensions: ' . $document);
        }
        
        // 3. 检查直接的请求参数
        elseif (isset($request_params['brazil_document'])) {
            $document = sanitize_text_field($request_params['brazil_document']);
            error_log('Brazil Checkout: Found document in request params: ' . $document);
        }
        
        // 4. 检查POST数据
        else {
            $possible_fields = array(
                'brazil_document',
                'brazil_cpf',
                'brazil_cnpj',
                'billing_brazil_document',
                'cpf_cnpj',
                'document'
            );
            
            foreach ($possible_fields as $field) {
                if (isset($_POST[$field]) && !empty($_POST[$field])) {
                    $document = sanitize_text_field($_POST[$field]);
                    error_log('Brazil Checkout: Found document in POST field: ' . $field . ' = ' . $document);
                    break;
                }
            }
        }
        
        // 5. 如果还是没找到，尝试从session获取
        if (empty($document)) {
            if (!session_id()) {
                session_start();
            }
            if (isset($_SESSION['brazil_cpf_cnpj']) && !empty($_SESSION['brazil_cpf_cnpj'])) {
                $document = sanitize_text_field($_SESSION['brazil_cpf_cnpj']);
                error_log('Brazil Checkout: Found document in session: ' . $document);
            }
        }
        
        if (!empty($document)) {
            $clean_document = preg_replace('/[^0-9]/', '', $document);
            error_log('Brazil Checkout: Clean document: ' . $clean_document . ' (length: ' . strlen($clean_document) . ')');
            
            if (strlen($clean_document) === 11) {
                // CPF
                error_log('Brazil Checkout: Saving CPF data for order ' . $order_id);
                $order->update_meta_data('_customer_type', 'pessoa_fisica');
                $order->update_meta_data('_cpf', $document);
                $order->update_meta_data('_brazil_document', $document);
                $order->update_meta_data('_brazil_document_type', 'cpf');
                
                // 也保存无前缀版本
                $order->update_meta_data('brazil_document', $document);
                $order->update_meta_data('brazil_document_type', 'cpf');
                
            } elseif (strlen($clean_document) === 14) {
                // CNPJ
                error_log('Brazil Checkout: Saving CNPJ data for order ' . $order_id);
                $order->update_meta_data('_customer_type', 'pessoa_juridica');
                $order->update_meta_data('_cnpj', $document);
                $order->update_meta_data('_brazil_document', $document);
                $order->update_meta_data('_brazil_document_type', 'cnpj');
                
                // 也保存无前缀版本
                $order->update_meta_data('brazil_document', $document);
                $order->update_meta_data('brazil_document_type', 'cnpj');
                
            } else {
                error_log('Brazil Checkout: Invalid document length: ' . strlen($clean_document));
            }
            
            // 保存更改
            $order->save();
            error_log('Brazil Checkout: Order ' . $order_id . ' saved with Brazil data: ' . $document);
            
            // 清理session数据
            if (isset($_SESSION['brazil_cpf_cnpj'])) {
                unset($_SESSION['brazil_cpf_cnpj'], $_SESSION['brazil_billing_country'], $_SESSION['brazil_data_timestamp']);
            }
            
        } else {
            error_log('Brazil Checkout: No document data found in Store API request, POST, or session');
            
            // 调试：记录完整的请求结构
            error_log('Brazil Checkout: Full request structure: ' . print_r($request_params, true));
        }
        
        // 后备兼容性：保存旧字段
        if (isset($_POST['brazil_cpf']) && !empty($_POST['brazil_cpf'])) {
            error_log('Brazil Checkout: Saving legacy CPF field');
            $order->update_meta_data('_customer_type', 'pessoa_fisica');
            $order->update_meta_data('_cpf', sanitize_text_field($_POST['brazil_cpf']));
            $order->save();
        }
        
        if (isset($_POST['brazil_cnpj']) && !empty($_POST['brazil_cnpj'])) {
            error_log('Brazil Checkout: Saving legacy CNPJ field');
            $order->update_meta_data('_customer_type', 'pessoa_juridica');
            $order->update_meta_data('_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
            $order->save();
        }
    }
    
    /**
     * 保存字段数据 - 后备方法 - 增强版本
     */
    public function save_checkout_fields_fallback($order_id) {
        error_log('Brazil Checkout: save_checkout_fields_fallback called - Order ID: ' . $order_id);
        error_log('Brazil Checkout: POST data: ' . print_r($_POST, true));
        
        if (!$order_id) {
            error_log('Brazil Checkout: No order ID provided');
            return;
        }
        
        // 检查是否选择了巴西国家
        $billing_country = isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '';
        $shipping_country = isset($_POST['shipping_country']) ? sanitize_text_field($_POST['shipping_country']) : '';
        
        error_log('Brazil Checkout: Countries - Billing: ' . $billing_country . ', Shipping: ' . $shipping_country);
        
        // 如果不是巴西，不保存字段
        if ($billing_country !== 'BR' && $shipping_country !== 'BR') {
            error_log('Brazil Checkout: Not Brazil address, skipping save');
            return;
        }
        
        $document = isset($_POST['brazil_document']) ? sanitize_text_field($_POST['brazil_document']) : '';
        error_log('Brazil Checkout: Document field value: ' . $document);
        
        if (!empty($document)) {
            $clean_document = preg_replace('/[^0-9]/', '', $document);
            error_log('Brazil Checkout: Clean document: ' . $clean_document . ' (length: ' . strlen($clean_document) . ')');
            
            if (strlen($clean_document) === 11) {
                // CPF
                error_log('Brazil Checkout: Saving CPF data via update_post_meta');
                update_post_meta($order_id, '_customer_type', 'pessoa_fisica');
                update_post_meta($order_id, '_cpf', $document);
                update_post_meta($order_id, '_brazil_document', $document);
                update_post_meta($order_id, '_brazil_document_type', 'cpf');
            } elseif (strlen($clean_document) === 14) {
                // CNPJ
                error_log('Brazil Checkout: Saving CNPJ data via update_post_meta');
                update_post_meta($order_id, '_customer_type', 'pessoa_juridica');
                update_post_meta($order_id, '_cnpj', $document);
                update_post_meta($order_id, '_brazil_document', $document);
                update_post_meta($order_id, '_brazil_document_type', 'cnpj');
            } else {
                error_log('Brazil Checkout: Invalid document length: ' . strlen($clean_document));
            }
            
            error_log('Brazil Checkout: Fallback save completed');
        } else {
            error_log('Brazil Checkout: No document data found in POST');
        }
        
        // 后备兼容性：保存旧字段
        if (isset($_POST['brazil_cpf']) && !empty($_POST['brazil_cpf'])) {
            error_log('Brazil Checkout: Saving legacy CPF field via update_post_meta');
            update_post_meta($order_id, '_customer_type', 'pessoa_fisica');
            update_post_meta($order_id, '_cpf', sanitize_text_field($_POST['brazil_cpf']));
        }
        
        if (isset($_POST['brazil_cnpj']) && !empty($_POST['brazil_cnpj'])) {
            error_log('Brazil Checkout: Saving legacy CNPJ field via update_post_meta');
            update_post_meta($order_id, '_customer_type', 'pessoa_juridica');
            update_post_meta($order_id, '_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        }
    }
    
    /**
     * 保存字段数据 - 创建订单时
     */
    public function save_checkout_fields_create_order($order, $data) {
        // 检查是否选择了巴西国家
        $billing_country = isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '';
        $shipping_country = isset($_POST['shipping_country']) ? sanitize_text_field($_POST['shipping_country']) : '';
        
        // 如果不是巴西，不保存字段
        if ($billing_country !== 'BR' && $shipping_country !== 'BR') {
            return;
        }
        
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
     * 在订单详情显示字段 - 增强版本
     */
    public function display_fields_in_order($order) {
        // 添加调试日志
        error_log('Brazil Checkout: display_fields_in_order called');
        
        if (!$order) {
            error_log('Brazil Checkout: No order object provided');
            return;
        }
        
        // 获取订单ID进行调试
        $order_id = $order->get_id();
        error_log('Brazil Checkout: Processing order ID: ' . $order_id);
        
        $brazil_info = $this->get_brazil_order_info($order);
        error_log('Brazil Checkout: Brazil info result: ' . print_r($brazil_info, true));
        
        if (!$brazil_info) {
            error_log('Brazil Checkout: No Brazil info found, checking raw meta data');
            
            // 调试所有订单meta数据
            $all_meta = $order->get_meta_data();
            foreach ($all_meta as $meta) {
                $key = $meta->get_data()['key'];
                $value = $meta->get_data()['value'];
                if (strpos($key, 'brazil') !== false || strpos($key, 'cpf') !== false || strpos($key, 'cnpj') !== false || strpos($key, 'customer') !== false) {
                    error_log('Brazil Checkout: Found meta - Key: ' . $key . ', Value: ' . $value);
                }
            }
            return;
        }
        
        echo '<div class="brazil-order-info" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 5px;">';
        echo '<h3 style="margin-top: 0; color: #495057; font-size: 1.2em;">🇧🇷 Informações Fiscais do Brasil</h3>';
        
        if ($brazil_info['type'] === 'cpf') {
            echo '<p style="margin: 8px 0;"><strong>Tipo de Cliente:</strong> <span style="color: #28a745;">Pessoa Física</span></p>';
            echo '<p style="margin: 8px 0;"><strong>CPF:</strong> <code style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">' . esc_html($brazil_info['document']) . '</code></p>';
        } elseif ($brazil_info['type'] === 'cnpj') {
            echo '<p style="margin: 8px 0;"><strong>Tipo de Cliente:</strong> <span style="color: #007bff;">Pessoa Jurídica</span></p>';
            echo '<p style="margin: 8px 0;"><strong>CNPJ:</strong> <code style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">' . esc_html($brazil_info['document']) . '</code></p>';
        }
        
        echo '</div>';
        
        error_log('Brazil Checkout: Successfully displayed Brazil info');
    }
    
    /**
     * 在订单详情页面显示 - 另一个位置
     */
    public function display_fields_in_order_details($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        echo '<section class="woocommerce-brazil-details">';
        echo '<h2 class="woocommerce-column__title">Informações Fiscais</h2>';
        echo '<table class="woocommerce-table woocommerce-table--brazil-info shop_table">';
        
        echo '<tr><th>Tipo de Cliente:</th><td>';
        if ($brazil_info['type'] === 'cpf') {
            echo 'Pessoa Física';
        } elseif ($brazil_info['type'] === 'cnpj') {
            echo 'Pessoa Jurídica';
        }
        echo '</td></tr>';
        
        echo '<tr><th>';
        echo ($brazil_info['type'] === 'cpf') ? 'CPF:' : 'CNPJ:';
        echo '</th><td>' . esc_html($brazil_info['document']) . '</td></tr>';
        
        echo '</table>';
        echo '</section>';
    }
    
    /**
     * 在感谢页面显示
     */
    public function display_fields_in_thankyou($order_id) {
        if (!$order_id) return;
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        echo '<div class="brazil-thankyou-info" style="margin: 20px 0; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; color: #155724;">';
        echo '<h3 style="margin-top: 0;">✅ Informações Fiscais Confirmadas</h3>';
        echo '<p><strong>' . ($brazil_info['type'] === 'cpf' ? 'CPF' : 'CNPJ') . ':</strong> ' . esc_html($brazil_info['document']) . '</p>';
        echo '<small>Suas informações fiscais foram salvas com segurança para este pedido.</small>';
        echo '</div>';
    }
    
    /**
     * 在后台订单页面显示字段 - 增强版本
     */
    public function display_fields_in_admin_order($order) {
        if (!$order) return;
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        echo '<div class="address">';
        echo '<p><strong>🇧🇷 Informações Fiscais do Brasil:</strong></p>';
        
        if ($brazil_info['type'] === 'cpf') {
            echo '<p><strong>Tipo:</strong> <span style="color: #28a745; font-weight: bold;">Pessoa Física</span><br>';
            echo '<strong>CPF:</strong> <span style="background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: monospace;">' . esc_html($brazil_info['document']) . '</span></p>';
        } elseif ($brazil_info['type'] === 'cnpj') {
            echo '<p><strong>Tipo:</strong> <span style="color: #007bff; font-weight: bold;">Pessoa Jurídica</span><br>';
            echo '<strong>CNPJ:</strong> <span style="background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: monospace;">' . esc_html($brazil_info['document']) . '</span></p>';
        }
        
        // 添加验证状态指示器
        $is_valid = $this->validate_document_format($brazil_info['document'], $brazil_info['type']);
        echo '<p><strong>Status:</strong> ';
        if ($is_valid) {
            echo '<span style="color: #28a745; font-weight: bold;">✓ Documento Válido</span>';
        } else {
            echo '<span style="color: #dc3545; font-weight: bold;">⚠ Verificar Documento</span>';
        }
        echo '</p>';
        
        echo '</div>';
    }
    
    /**
     * 在后台订单页面配送地址区域显示
     */
    public function display_fields_in_admin_order_shipping($order) {
        if (!$order) return;
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        echo '<div class="address" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-left: 4px solid #007cba;">';
        echo '<p><strong>Informações Fiscais:</strong> ';
        echo ($brazil_info['type'] === 'cpf' ? 'CPF: ' : 'CNPJ: ') . esc_html($brazil_info['document']);
        echo ' <small>(' . ($brazil_info['type'] === 'cpf' ? 'Pessoa Física' : 'Pessoa Jurídica') . ')</small></p>';
        echo '</div>';
    }
    
    /**
     * 在邮件中显示客户详情时显示巴西信息
     */
    public function display_fields_in_email($order, $sent_to_admin = false, $plain_text = false) {
        if (!$order) return;
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        if ($plain_text) {
            echo "\n" . __('Informações Fiscais:', 'woocommerce') . "\n";
            echo ($brazil_info['type'] === 'cpf' ? 'CPF: ' : 'CNPJ: ') . $brazil_info['document'] . "\n";
            echo ($brazil_info['type'] === 'cpf' ? 'Pessoa Física' : 'Pessoa Jurídica') . "\n";
        } else {
            echo '<div style="margin: 15px 0; padding: 10px; background: #f8f9fa; border-left: 4px solid #007cba;">';
            echo '<h3 style="margin-top: 0;">🇧🇷 Informações Fiscais</h3>';
            echo '<p><strong>' . ($brazil_info['type'] === 'cpf' ? 'CPF' : 'CNPJ') . ':</strong> ' . esc_html($brazil_info['document']) . '</p>';
            echo '<p><strong>Tipo:</strong> ' . ($brazil_info['type'] === 'cpf' ? 'Pessoa Física' : 'Pessoa Jurídica') . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * 在邮件订单详情中显示
     */
    public function display_fields_in_email_order($order, $sent_to_admin, $plain_text, $email) {
        if (!$order) return;
        
        // 只在确认邮件和发票邮件中显示
        if (!in_array($email->id, array('customer_processing_order', 'customer_completed_order', 'new_order'))) {
            return;
        }
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        if ($plain_text) {
            echo "\n=== INFORMAÇÕES FISCAIS ===\n";
            echo ($brazil_info['type'] === 'cpf' ? 'CPF: ' : 'CNPJ: ') . $brazil_info['document'] . "\n";
        } else {
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
            echo '<h3 style="margin-top: 0; color: #333;">Informações Fiscais</h3>';
            echo '<table style="width: 100%; border-collapse: collapse;">';
            echo '<tr><td style="padding: 5px; border-bottom: 1px solid #eee;"><strong>' . ($brazil_info['type'] === 'cpf' ? 'CPF' : 'CNPJ') . ':</strong></td>';
            echo '<td style="padding: 5px; border-bottom: 1px solid #eee;">' . esc_html($brazil_info['document']) . '</td></tr>';
            echo '<tr><td style="padding: 5px;"><strong>Tipo:</strong></td>';
            echo '<td style="padding: 5px;">' . ($brazil_info['type'] === 'cpf' ? 'Pessoa Física' : 'Pessoa Jurídica') . '</td></tr>';
            echo '</table>';
            echo '</div>';
        }
    }
    
    /**
     * 在账户页面订单查看中显示
     */
    public function display_fields_in_account_order($order_id) {
        error_log('Brazil Checkout: display_fields_in_account_order called with order ID: ' . $order_id);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Brazil Checkout: Could not get order object for ID: ' . $order_id);
            return;
        }
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) {
            error_log('Brazil Checkout: No Brazil info found for account order view');
            return;
        }
        
        echo '<div class="woocommerce-brazil-info" style="margin: 20px 0; padding: 15px; background: #f1f3f4; border-radius: 5px;">';
        echo '<h3>Informações Fiscais</h3>';
        echo '<dl class="variation">';
        echo '<dt>Tipo de Cliente:</dt>';
        echo '<dd>' . ($brazil_info['type'] === 'cpf' ? 'Pessoa Física' : 'Pessoa Jurídica') . '</dd>';
        echo '<dt>' . ($brazil_info['type'] === 'cpf' ? 'CPF' : 'CNPJ') . ':</dt>';
        echo '<dd>' . esc_html($brazil_info['document']) . '</dd>';
        echo '</dl>';
        echo '</div>';
        
        error_log('Brazil Checkout: Successfully displayed Brazil info in account order view');
    }
    
    /**
     * 在订单表格后显示
     */
    public function display_fields_after_order_table($order) {
        error_log('Brazil Checkout: display_fields_after_order_table called');
        
        if (!$order) return;
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        echo '<div class="brazil-order-info-table" style="margin: 30px 0; padding: 20px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;">';
        echo '<h3 style="margin-top: 0; color: #495057; border-bottom: 2px solid #007cba; padding-bottom: 10px;">🇧🇷 Informações Fiscais</h3>';
        echo '<table class="shop_table shop_table_responsive">';
        echo '<tbody>';
        echo '<tr><th>Tipo de Cliente:</th><td>' . ($brazil_info['type'] === 'cpf' ? 'Pessoa Física' : 'Pessoa Jurídica') . '</td></tr>';
        echo '<tr><th>' . ($brazil_info['type'] === 'cpf' ? 'CPF' : 'CNPJ') . ':</th><td><strong>' . esc_html($brazil_info['document']) . '</strong></td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
    
    /**
     * 在订单表格前显示
     */
    public function display_fields_before_order_table($order) {
        error_log('Brazil Checkout: display_fields_before_order_table called');
        
        if (!$order) return;
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        echo '<div class="brazil-order-notice" style="margin: 20px 0; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 4px;">';
        echo '<p style="margin: 0; font-weight: 500;">';
        echo '🇧🇷 <strong>Informações Fiscais:</strong> ';
        echo ($brazil_info['type'] === 'cpf' ? 'CPF' : 'CNPJ') . ': <code>' . esc_html($brazil_info['document']) . '</code>';
        echo ' (' . ($brazil_info['type'] === 'cpf' ? 'Pessoa Física' : 'Pessoa Jurídica') . ')';
        echo '</p>';
        echo '</div>';
    }
    
    /**
     * 在客户详情后显示 - 另一个位置
     */
    public function display_fields_after_customer_details($order) {
        error_log('Brazil Checkout: display_fields_after_customer_details called');
        
        if (!$order) return;
        
        $brazil_info = $this->get_brazil_order_info($order);
        if (!$brazil_info) return;
        
        echo '<section class="woocommerce-customer-brazil-details">';
        echo '<h2 class="woocommerce-column__title">Informações Fiscais Brasileiras</h2>';
        echo '<address>';
        echo '<strong>' . ($brazil_info['type'] === 'cpf' ? 'Pessoa Física' : 'Pessoa Jurídica') . '</strong><br>';
        echo ($brazil_info['type'] === 'cpf' ? 'CPF' : 'CNPJ') . ': ' . esc_html($brazil_info['document']);
        echo '</address>';
        echo '</section>';
    }
    
    /**
     * 调试Hook执行
     */
    public function debug_hook_execution($order) {
        error_log('Brazil Checkout: DEBUG - woocommerce_order_details_after_customer_details hook executed');
        error_log('Brazil Checkout: DEBUG - Order object type: ' . gettype($order));
        if (is_object($order)) {
            error_log('Brazil Checkout: DEBUG - Order ID: ' . $order->get_id());
        }
    }
    
    /**
     * 调试view order Hook
     */
    public function debug_view_order_hook($order_id) {
        error_log('Brazil Checkout: DEBUG - woocommerce_view_order hook executed with order ID: ' . $order_id);
        $order = wc_get_order($order_id);
        if ($order) {
            error_log('Brazil Checkout: DEBUG - Order object retrieved successfully');
        } else {
            error_log('Brazil Checkout: DEBUG - Failed to retrieve order object');
        }
    }
    
    /**
     * 获取订单的巴西信息 - 统一函数
     */
    private function get_brazil_order_info($order) {
        if (!$order) {
            error_log('Brazil Checkout: get_brazil_order_info - No order provided');
            return false;
        }
        
        $order_id = $order->get_id();
        error_log('Brazil Checkout: get_brazil_order_info - Processing order ID: ' . $order_id);
        
        // 优先检查新的统一文档字段
        $document = $order->get_meta('_brazil_document');
        $document_type = $order->get_meta('_brazil_document_type');
        
        error_log('Brazil Checkout: New unified fields - Document: ' . $document . ', Type: ' . $document_type);
        
        if (!empty($document) && !empty($document_type)) {
            error_log('Brazil Checkout: Found new unified fields data');
            return array(
                'document' => $document,
                'type' => $document_type
            );
        }
        
        // 后备兼容性：检查旧字段
        $customer_type = $order->get_meta('_customer_type');
        $cpf = $order->get_meta('_cpf');
        $cnpj = $order->get_meta('_cnpj');
        
        error_log('Brazil Checkout: Legacy fields - Customer Type: ' . $customer_type . ', CPF: ' . $cpf . ', CNPJ: ' . $cnpj);
        
        if ($customer_type === 'pessoa_fisica' && $cpf) {
            error_log('Brazil Checkout: Found legacy CPF data');
            return array(
                'document' => $cpf,
                'type' => 'cpf'
            );
        } elseif ($customer_type === 'pessoa_juridica' && $cnpj) {
            error_log('Brazil Checkout: Found legacy CNPJ data');
            return array(
                'document' => $cnpj,
                'type' => 'cnpj'
            );
        }
        
        // 额外检查：直接查找可能的字段变体
        $possible_fields = array(
            'brazil_document', 'brazil_cpf', 'brazil_cnpj', 'brazil_customer_type',
            'billing_brazil_document', 'billing_cpf', 'billing_cnpj'
        );
        
        foreach ($possible_fields as $field) {
            $value = $order->get_meta($field);
            if (!empty($value)) {
                error_log('Brazil Checkout: Found potential field - ' . $field . ': ' . $value);
            }
            
            // 也检查带下划线前缀的版本
            $value_with_prefix = $order->get_meta('_' . $field);
            if (!empty($value_with_prefix)) {
                error_log('Brazil Checkout: Found potential field with prefix - _' . $field . ': ' . $value_with_prefix);
            }
        }
        
        // 最后尝试：检查没有前缀的字段
        $document_no_prefix = $order->get_meta('brazil_document');
        $customer_type_no_prefix = $order->get_meta('brazil_customer_type');
        
        if (!empty($document_no_prefix)) {
            error_log('Brazil Checkout: Found document without prefix: ' . $document_no_prefix);
            
            // 尝试检测类型
            $detected_type = $this->detect_document_type($document_no_prefix);
            return array(
                'document' => $document_no_prefix,
                'type' => $detected_type
            );
        }
        
        error_log('Brazil Checkout: No Brazil info found in order meta');
        return false;
    }
    
    /**
     * 检测文档类型
     */
    private function detect_document_type($document) {
        $clean_document = preg_replace('/[^0-9]/', '', $document);
        if (strlen($clean_document) <= 11) {
            return 'cpf';
        } else {
            return 'cnpj';
        }
    }
    
    /**
     * 添加调试工具（仅管理员可见）
     */
    public function add_debug_tools() {
        if (!current_user_can('manage_options')) return;
        
        // 检查是否在订单查看页面
        global $wp;
        if (isset($wp->query_vars['view-order'])) {
            $order_id = $wp->query_vars['view-order'];
            ?>
            <div style="position: fixed; bottom: 10px; right: 10px; background: #333; color: white; padding: 10px; border-radius: 5px; z-index: 9999; font-size: 12px;">
                <strong>Brazil Checkout Debug</strong><br>
                Order ID: <?php echo $order_id; ?><br>
                <a href="javascript:void(0)" onclick="debugBrazilOrder(<?php echo $order_id; ?>)" style="color: #4CAF50;">Debug Order</a>
            </div>
            <script>
            function debugBrazilOrder(orderId) {
                console.log('Debugging Brazil order:', orderId);
                
                // AJAX调用调试函数
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'debug_brazil_order',
                    order_id: orderId,
                    nonce: '<?php echo wp_create_nonce('debug_brazil_order'); ?>'
                }, function(response) {
                    console.log('Debug response:', response);
                    alert('Debug info logged to console and error log');
                });
            }
            </script>
            <?php
        }
    }
    
    /**
     * AJAX调试订单数据
     */
    public function debug_brazil_order_ajax() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        check_ajax_referer('debug_brazil_order', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error('Order not found');
            return;
        }
        
        // 获取所有meta数据
        $all_meta = $order->get_meta_data();
        $debug_info = array(
            'order_id' => $order_id,
            'order_status' => $order->get_status(),
            'billing_country' => $order->get_billing_country(),
            'meta_data' => array()
        );
        
        foreach ($all_meta as $meta) {
            $key = $meta->get_data()['key'];
            $value = $meta->get_data()['value'];
            $debug_info['meta_data'][$key] = $value;
        }
        
        error_log('Brazil Checkout DEBUG - Full order data: ' . print_r($debug_info, true));
        
        wp_send_json_success($debug_info);
    }
    
    /**
     * Store API订单处理完成时的保存函数
     */
    public function store_api_order_processed($order) {
        error_log('Brazil Checkout: store_api_order_processed called - Order ID: ' . ($order ? $order->get_id() : 'null'));
        
        if (!$order) {
            error_log('Brazil Checkout: No order in store_api_order_processed');
            return;
        }
        
        // 尝试从session获取数据
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_SESSION['brazil_cpf_cnpj']) && !empty($_SESSION['brazil_cpf_cnpj'])) {
            $document = sanitize_text_field($_SESSION['brazil_cpf_cnpj']);
            error_log('Brazil Checkout: Found session data in store_api_order_processed: ' . $document);
            
            $this->save_document_to_order($order, $document);
        } else {
            error_log('Brazil Checkout: No session data found in store_api_order_processed');
        }
    }
    
    /**
     * 处理Store API数据
     */
    public function process_store_api_data($data, $request) {
        error_log('Brazil Checkout: process_store_api_data called');
        error_log('Brazil Checkout: Store API data keys: ' . implode(', ', array_keys($data)));
        
        $request_params = $request->get_params();
        
        // 检查additional_fields中的巴西数据
        if (isset($request_params['additional_fields'])) {
            $additional_fields = $request_params['additional_fields'];
            error_log('Brazil Checkout: Additional fields: ' . print_r($additional_fields, true));
            
            if (isset($additional_fields['brazil_document']) && !empty($additional_fields['brazil_document'])) {
                $document = sanitize_text_field($additional_fields['brazil_document']);
                error_log('Brazil Checkout: Found brazil_document in Store API: ' . $document);
                
                // 保存到session作为备份
                if (!session_id()) {
                    session_start();
                }
                $_SESSION['brazil_cpf_cnpj'] = $document;
                $_SESSION['brazil_billing_country'] = 'BR';
                $_SESSION['brazil_data_timestamp'] = current_time('timestamp');
                
                error_log('Brazil Checkout: Saved to session as backup');
            }
        }
        
        return $data;
    }
    
    /**
     * 统一的文档保存函数
     */
    private function save_document_to_order($order, $document) {
        if (!$order || !$document) {
            error_log('Brazil Checkout: save_document_to_order - Missing order or document');
            return false;
        }
        
        $order_id = $order->get_id();
        $clean_document = preg_replace('/[^0-9]/', '', $document);
        
        error_log('Brazil Checkout: save_document_to_order - Order: ' . $order_id . ', Document: ' . $document . ', Clean: ' . $clean_document);
        
        if (strlen($clean_document) === 11) {
            // CPF
            error_log('Brazil Checkout: Saving CPF to order ' . $order_id);
            $order->update_meta_data('_customer_type', 'pessoa_fisica');
            $order->update_meta_data('_cpf', $document);
            $order->update_meta_data('_brazil_document', $document);
            $order->update_meta_data('_brazil_document_type', 'cpf');
            
            // 也保存无前缀版本
            $order->update_meta_data('brazil_document', $document);
            $order->update_meta_data('brazil_document_type', 'cpf');
            
        } elseif (strlen($clean_document) === 14) {
            // CNPJ
            error_log('Brazil Checkout: Saving CNPJ to order ' . $order_id);
            $order->update_meta_data('_customer_type', 'pessoa_juridica');
            $order->update_meta_data('_cnpj', $document);
            $order->update_meta_data('_brazil_document', $document);
            $order->update_meta_data('_brazil_document_type', 'cnpj');
            
            // 也保存无前缀版本
            $order->update_meta_data('brazil_document', $document);
            $order->update_meta_data('brazil_document_type', 'cnpj');
            
        } else {
            error_log('Brazil Checkout: Invalid document length: ' . strlen($clean_document));
            return false;
        }
        
        // 保存订单
        $order->save();
        error_log('Brazil Checkout: Document saved successfully to order ' . $order_id);
        
        // 清理session
        if (session_id()) {
            unset($_SESSION['brazil_cpf_cnpj'], $_SESSION['brazil_billing_country'], $_SESSION['brazil_data_timestamp']);
        }
        
        return true;
    }

    /**
     * 新的保存函数 - 订单处理完成时
     */
    public function save_checkout_fields_processed($order_id, $posted_data, $order) {
        error_log('Brazil Checkout: save_checkout_fields_processed called - Order ID: ' . $order_id);
        error_log('Brazil Checkout: Posted data: ' . print_r($posted_data, true));
        
        $this->save_brazil_data($order_id, $posted_data);
    }
    
    /**
     * 新的保存函数 - 新订单创建时
     */
    public function save_checkout_fields_new_order($order_id) {
        error_log('Brazil Checkout: save_checkout_fields_new_order called - Order ID: ' . $order_id);
        
        $this->save_brazil_data($order_id, $_POST);
    }
    
    /**
     * 新的保存函数 - 感谢页面时（最后的机会）
     */
    public function save_checkout_fields_thankyou($order_id) {
        error_log('🔥 BRAZIL CHECKOUT: save_checkout_fields_thankyou called - Order ID: ' . $order_id);
        
        // 检查是否已经有数据
        $existing_document = get_post_meta($order_id, '_brazil_document', true);
        if (!empty($existing_document)) {
            error_log('✅ BRAZIL CHECKOUT: Brazil data already exists, skipping');
            return;
        }
        
        // 尝试从session获取数据
        if (!session_id()) {
            session_start();
        }
        
        $session_data = null;
        
        // 1. 先尝试从PHP session获取
        if (isset($_SESSION['brazil_checkout_data'])) {
            $session_data = $_SESSION['brazil_checkout_data'];
            error_log('🎯 BRAZIL CHECKOUT: Found data in PHP session: ' . print_r($session_data, true));
        }
        // 2. 再尝试从WooCommerce session获取
        elseif (WC()->session) {
            $wc_session_data = WC()->session->get('brazil_checkout_data');
            if ($wc_session_data) {
                $session_data = $wc_session_data;
                error_log('🎯 BRAZIL CHECKOUT: Found data in WC session: ' . print_r($session_data, true));
            }
        }
        
        if ($session_data && isset($session_data['brazil_document']) && !empty($session_data['brazil_document'])) {
            error_log('💾 BRAZIL CHECKOUT: Saving session data to order ' . $order_id);
            $this->save_brazil_data_from_request($session_data, $order_id);
            
            // 清理session数据
            unset($_SESSION['brazil_checkout_data']);
            if (WC()->session) {
                WC()->session->__unset('brazil_checkout_data');
            }
            error_log('🧹 BRAZIL CHECKOUT: Session data cleaned up');
        } else {
            error_log('❌ BRAZIL CHECKOUT: No valid session data found');
        }
    }
    
    /**
     * 统一的巴西数据保存函数
     */
    private function save_brazil_data($order_id, $data) {
        if (!$order_id) {
            error_log('Brazil Checkout: save_brazil_data - No order ID');
            return false;
        }
        
        // 调试：记录所有传入的数据
        error_log('Brazil Checkout: save_brazil_data called with order_id=' . $order_id);
        error_log('Brazil Checkout: Data keys: ' . implode(', ', array_keys($data)));
        
        // 记录所有可能包含巴西数据的字段
        $debug_fields = array();
        foreach ($data as $key => $value) {
            if (stripos($key, 'brazil') !== false || 
                stripos($key, 'cpf') !== false || 
                stripos($key, 'cnpj') !== false ||
                stripos($key, 'document') !== false ||
                $key === 'billing_country' ||
                $key === 'shipping_country') {
                $debug_fields[$key] = is_string($value) ? $value : gettype($value);
            }
        }
        error_log('Brazil Checkout: Relevant fields: ' . print_r($debug_fields, true));
        
        // 开启session以检查session数据
        if (!session_id()) {
            session_start();
        }
        
        // 检查是否是巴西地址
        $billing_country = isset($data['billing_country']) ? $data['billing_country'] : '';
        $shipping_country = isset($data['shipping_country']) ? $data['shipping_country'] : '';
        
        // 也检查session中的国家信息
        if (empty($billing_country) && isset($_SESSION['brazil_billing_country'])) {
            $billing_country = $_SESSION['brazil_billing_country'];
            error_log('Brazil Checkout: Using session billing country: ' . $billing_country);
        }
        
        if ($billing_country !== 'BR' && $shipping_country !== 'BR') {
            error_log('Brazil Checkout: save_brazil_data - Not Brazil address (billing: ' . $billing_country . ', shipping: ' . $shipping_country . ')');
            return false;
        }
        
        // 查找文档数据 - 先检查常规字段，再检查session
        $document = '';
        $possible_fields = array(
            'brazil_document', 
            'brazil_cpf', 
            'brazil_cnpj',
            'billing_brazil_document',
            'billing_brazil_cpf',
            'billing_brazil_cnpj',
            'cpf_cnpj',
            'brazil_cpf_cnpj',
            'billing_cpf_cnpj'
        );
        
        // 先在POST数据中查找
        foreach ($possible_fields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $document = sanitize_text_field($data[$field]);
                error_log('Brazil Checkout: Found document in field: ' . $field . ' = ' . $document);
                break;
            }
        }
        
        // 也检查JavaScript可能使用的字段名
        if (empty($document)) {
            $js_fields = array('document', 'brazil-document', 'cpf', 'cnpj');
            foreach ($js_fields as $field) {
                if (isset($data[$field]) && !empty($data[$field])) {
                    $document = sanitize_text_field($data[$field]);
                    error_log('Brazil Checkout: Found document in JS field: ' . $field . ' = ' . $document);
                    break;
                }
            }
        }
        
        // 如果没有找到文档数据，检查session
        if (empty($document) && isset($_SESSION['brazil_cpf_cnpj']) && !empty($_SESSION['brazil_cpf_cnpj'])) {
            $document = sanitize_text_field($_SESSION['brazil_cpf_cnpj']);
            error_log('Brazil Checkout: Found document in session: ' . $document);
        }
        
        if (empty($document)) {
            error_log('Brazil Checkout: No document data found in form data or session');
            return false;
        }
        
        $clean_document = preg_replace('/[^0-9]/', '', $document);
        error_log('Brazil Checkout: Clean document: ' . $clean_document . ' (length: ' . strlen($clean_document) . ')');
        
        // 保存数据
        if (strlen($clean_document) === 11) {
            // CPF
            error_log('Brazil Checkout: Saving unified CPF data for order ' . $order_id);
            update_post_meta($order_id, '_brazil_document', $document);
            update_post_meta($order_id, '_brazil_document_type', 'cpf');
            update_post_meta($order_id, '_customer_type', 'pessoa_fisica');
            update_post_meta($order_id, '_cpf', $document);
            
            // 也保存无前缀版本
            update_post_meta($order_id, 'brazil_document', $document);
            update_post_meta($order_id, 'brazil_document_type', 'cpf');
            
            // 清理session数据
            unset($_SESSION['brazil_cpf_cnpj'], $_SESSION['brazil_billing_country'], $_SESSION['brazil_data_timestamp']);
            
            error_log('Brazil Checkout: CPF saved successfully for order ' . $order_id);
            return true;
        } elseif (strlen($clean_document) === 14) {
            // CNPJ
            error_log('Brazil Checkout: Saving unified CNPJ data for order ' . $order_id);
            update_post_meta($order_id, '_brazil_document', $document);
            update_post_meta($order_id, '_brazil_document_type', 'cnpj');
            update_post_meta($order_id, '_customer_type', 'pessoa_juridica');
            update_post_meta($order_id, '_cnpj', $document);
            
            // 也保存无前缀版本
            update_post_meta($order_id, 'brazil_document', $document);
            update_post_meta($order_id, 'brazil_document_type', 'cnpj');
            
            // 清理session数据
            unset($_SESSION['brazil_cpf_cnpj'], $_SESSION['brazil_billing_country'], $_SESSION['brazil_data_timestamp']);
            
            error_log('Brazil Checkout: CNPJ saved successfully for order ' . $order_id);
            return true;
        } else {
            error_log('Brazil Checkout: Invalid document length: ' . strlen($clean_document));
            return false;
        }
    }
    
    /**
     * 验证文档格式
     */
    private function validate_document_format($document, $type) {
        if ($type === 'cpf') {
            return $this->validate_cpf($document);
        } elseif ($type === 'cnpj') {
            return $this->validate_cnpj($document);
        }
        return false;
    }
    
    /**
     * 注册WooCommerce块编辑器字段支持
     */
    public function register_checkout_fields_block_support() {
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data(array(
                'endpoint'        => 'checkout',
                'namespace'       => 'brazil-checkout-fields',
                'data_callback'   => array($this, 'store_api_checkout_data'),
                'schema_callback' => array($this, 'store_api_checkout_schema'),
                'schema_type'     => ARRAY_A,
            ));
        }
    }
    
    /**
     * 初始化Store API支持
     */
    public function init_store_api_support() {
        // 确保WooCommerce Store API可以处理我们的字段
        add_filter('woocommerce_store_api_checkout_data', array($this, 'add_brazil_fields_to_checkout_data'), 10, 2);
    }
    
    /**
     * 注册Store API字段
     */
    public function register_store_api_fields() {
        if (function_exists('register_rest_field')) {
            register_rest_field('checkout', 'brazil_document', array(
                'get_callback' => array($this, 'get_brazil_document_field'),
                'update_callback' => array($this, 'update_brazil_document_field'),
                'schema' => array(
                    'description' => 'Brazil document (CPF or CNPJ)',
                    'type' => 'string',
                    'context' => array('view', 'edit'),
                ),
            ));
        }
    }
    
    /**
     * Store API数据回调
     */
    public function store_api_checkout_data() {
        return array(
            'brazil_document' => '',
            'brazil_customer_type' => '',
            'brazil_cpf' => '',
            'brazil_cnpj' => '',
        );
    }
    
    /**
     * Store API架构回调
     */
    public function store_api_checkout_schema() {
        return array(
            'brazil_document' => array(
                'description' => 'Brazil document (CPF or CNPJ)',
                'type' => 'string',
                'context' => array('view', 'edit'),
                'default' => '',
            ),
            'brazil_customer_type' => array(
                'description' => 'Brazil customer type',
                'type' => 'string',
                'context' => array('view', 'edit'),
                'default' => '',
                'enum' => array('pessoa_fisica', 'pessoa_juridica', ''),
            ),
            'brazil_cpf' => array(
                'description' => 'Brazil CPF',
                'type' => 'string',
                'context' => array('view', 'edit'),
                'default' => '',
            ),
            'brazil_cnpj' => array(
                'description' => 'Brazil CNPJ',
                'type' => 'string',
                'context' => array('view', 'edit'),
                'default' => '',
            ),
        );
    }
    
    /**
     * 添加巴西字段到checkout数据
     */
    public function add_brazil_fields_to_checkout_data($data, $request) {
        $request_params = $request->get_params();
        
        // 检查是否有巴西字段数据
        if (isset($request_params['brazil_document'])) {
            $data['brazil_document'] = sanitize_text_field($request_params['brazil_document']);
            error_log('Brazil Fields Store API: Found brazil_document in request: ' . $request_params['brazil_document']);
        }
        
        return $data;
    }
    
    /**
     * 获取巴西文档字段
     */
    public function get_brazil_document_field($object) {
        return get_post_meta($object['id'], '_brazil_document', true);
    }
    
    /**
     * 调试Store API请求
     */
    public function debug_store_api_requests() {
        error_log('🔍 BRAZIL CHECKOUT: debug_store_api_requests initialized');
    }
    
    /**
     * 调试REST请求
     */
    public function debug_rest_request($result, $server, $request) {
        $route = $request->get_route();
        
        if (strpos($route, '/wc/store/v1/checkout') !== false) {
            error_log('� BRAZIL CHECKOUT: Store API checkout request detected');
            error_log('� Route: ' . $route);
            error_log('🔥 Method: ' . $request->get_method());
            
            // 获取JSON参数而不是普通参数
            $params = $request->get_json_params();
            if (!$params) {
                $params = $request->get_params();
            }
            
            error_log('🔥 All request data: ' . print_r($params, true));
            
            if (isset($params['additional_fields'])) {
                error_log('🎯 BRAZIL CHECKOUT: Additional fields found!');
                error_log('🎯 Additional fields: ' . print_r($params['additional_fields'], true));
                
                // 如果发现巴西数据，立即保存到session
                if (isset($params['additional_fields']['brazil_document'])) {
                    error_log('🚀 BRAZIL CHECKOUT: Found brazil_document, saving to session!');
                    $this->save_brazil_data_from_request($params['additional_fields']);
                }
            } else {
                error_log('❌ BRAZIL CHECKOUT: No additional_fields in request');
            }
            
            // 检查所有参数中是否有巴西相关数据
            $brazil_data = array();
            foreach ($params as $key => $value) {
                if (stripos($key, 'brazil') !== false || 
                    stripos($key, 'cpf') !== false || 
                    stripos($key, 'cnpj') !== false) {
                    $brazil_data[$key] = $value;
                }
                // 如果是数组，也检查内部
                if (is_array($value)) {
                    foreach ($value as $subkey => $subvalue) {
                        if (stripos($subkey, 'brazil') !== false || 
                            stripos($subkey, 'cpf') !== false || 
                            stripos($subkey, 'cnpj') !== false) {
                            $brazil_data[$key][$subkey] = $subvalue;
                        }
                    }
                }
            }
            
            if (!empty($brazil_data)) {
                error_log('🎯 BRAZIL CHECKOUT: Brazil data found in request!');
                error_log('🎯 Brazil data: ' . print_r($brazil_data, true));
            } else {
                error_log('❌ BRAZIL CHECKOUT: No Brazil data found in entire request');
            }
        }
        
        return $result;
    }

    /**
     * 从请求中保存巴西数据的通用函数
     */
    public function save_brazil_data_from_request($additional_fields, $order_id = null) {
        error_log('💾 BRAZIL CHECKOUT: save_brazil_data_from_request called');
        error_log('💾 Data: ' . print_r($additional_fields, true));
        error_log('💾 Order ID: ' . ($order_id ? $order_id : 'not provided'));
        
        if (!isset($additional_fields['brazil_document']) || empty($additional_fields['brazil_document'])) {
            error_log('❌ BRAZIL CHECKOUT: No brazil_document found in additional_fields');
            return false;
        }
        
        $brazil_document = sanitize_text_field($additional_fields['brazil_document']);
        $brazil_customer_type = isset($additional_fields['brazil_customer_type']) ? sanitize_text_field($additional_fields['brazil_customer_type']) : '';
        $brazil_cpf = isset($additional_fields['brazil_cpf']) ? sanitize_text_field($additional_fields['brazil_cpf']) : '';
        $brazil_cnpj = isset($additional_fields['brazil_cnpj']) ? sanitize_text_field($additional_fields['brazil_cnpj']) : '';
        
        error_log('📝 BRAZIL CHECKOUT: Processed data - Document: ' . $brazil_document . ', Type: ' . $brazil_customer_type);
        
        // 总是保存到session作为备份
        if (!session_id()) {
            session_start();
        }
        $_SESSION['brazil_checkout_data'] = array(
            'brazil_document' => $brazil_document,
            'brazil_customer_type' => $brazil_customer_type,
            'brazil_cpf' => $brazil_cpf,
            'brazil_cnpj' => $brazil_cnpj,
            'timestamp' => time()
        );
        error_log('✅ BRAZIL CHECKOUT: Data saved to session as backup');
        
        // 如果没有提供订单ID，只保存到session
        if (!$order_id) {
            error_log('💾 BRAZIL CHECKOUT: No order ID provided, data saved to session only');
            return true;
        }
        
        // 保存到订单
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('❌ BRAZIL CHECKOUT: Invalid order ID: ' . $order_id);
            return false;
        }
        
        error_log('📦 BRAZIL CHECKOUT: Saving to order ' . $order_id);
        
        // 保存新的统一字段
        $order->update_meta_data('_brazil_document', $brazil_document);
        $order->update_meta_data('_brazil_customer_type', $brazil_customer_type);
        
        // 保存旧的兼容字段
        if ($brazil_cpf) {
            $order->update_meta_data('_billing_cpf', $brazil_cpf);
        }
        if ($brazil_cnpj) {
            $order->update_meta_data('_billing_cnpj', $brazil_cnpj);
        }
        if ($brazil_customer_type) {
            $order->update_meta_data('_billing_persontype', $brazil_customer_type === 'pessoa_fisica' ? '1' : '2');
        }
        
        $order->save();
        
        error_log('✅ BRAZIL CHECKOUT: Data saved to order ' . $order_id . ' successfully!');
        return true;
    }

    /**
     * 更新巴西文档字段
     */
    public function update_brazil_document_field($value, $object) {
        return update_post_meta($object->ID, '_brazil_document', sanitize_text_field($value));
    }
}

// 初始化插件
Brazil_Checkout_Fields_Blocks::get_instance();
