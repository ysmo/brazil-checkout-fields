<?php
/**
 * Plugin Name: Brazil CPF/CNPJ
 * Description: Brazilian CPF/CNPJ fields for WooCommerce Block Editor - Smart input validation with configurable field names
 * Version: 2.4.0
 */

if (!defined('ABSPATH')) exit;

// Field name customization configuration - backend configuration support
if (!defined('BRAZIL_CUSTOMER_TYPE_FIELD')) {
    $customer_type_field = get_option('brazil_checkout_customer_type_field', '_brazil_customer_type');
    define('BRAZIL_CUSTOMER_TYPE_FIELD', $customer_type_field);
}
if (!defined('BRAZIL_DOCUMENT_FIELD')) {
    $document_field = get_option('brazil_checkout_document_field', '_brazil_document');
    define('BRAZIL_DOCUMENT_FIELD', $document_field);
}

// 声明HPOS兼容性
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * 主插件类 - Brazil CPF/CNPJ
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
        
        // 加载文本域
        $this->load_textdomain();
        
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
        
        // AJAX预览迁移数据（仅管理员）
        add_action('wp_ajax_brazil_preview_migration_data', array($this, 'ajax_preview_migration_data'));
        
        // Store API扩展 - 让WooCommerce块编辑器识别我们的字段
        add_action('woocommerce_blocks_loaded', array($this, 'register_checkout_fields_block_support'));
        add_action('init', array($this, 'init_store_api_support'));
        
        // 确保在Store API请求前设置字段
        add_action('rest_api_init', array($this, 'register_store_api_fields'));
        
        // 注册additional_fields支持
        add_action('woocommerce_init', array($this, 'register_additional_fields_support'));
        
        // 添加调试hook来监控所有Store API请求 - 仅在调试模式启用
        if (defined('WP_DEBUG') && WP_DEBUG && isset($_GET['brazil_debug'])) {
            add_action('rest_api_init', array($this, 'debug_store_api_requests'));
            add_filter('rest_pre_dispatch', array($this, 'debug_rest_request'), 10, 3);
        }
        
        // 保存字段数据 - 多个Hook确保保存成功
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_checkout_fields'), 10, 2);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_fields_fallback'), 10, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_checkout_fields_create_order'), 10, 2);
        
        // 添加更多保存Hook来确保数据保存
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'store_api_order_processed'), 10, 1);
        add_filter('woocommerce_store_api_checkout_data', array($this, 'process_store_api_data'), 10, 2);
        
        // 直接拦截Store API请求数据
        add_filter('rest_pre_dispatch', array($this, 'intercept_store_api_request'), 10, 3);
        
        // 额外的保存Hook - 确保所有情况都覆盖
        add_action('woocommerce_checkout_order_processed', array($this, 'save_checkout_fields_processed'), 10, 3);
        add_action('woocommerce_new_order', array($this, 'save_checkout_fields_new_order'), 10, 1);
        add_action('woocommerce_thankyou', array($this, 'save_checkout_fields_thankyou'), 5, 1);
        
        // 显示字段在订单页面 - 只保留最佳样式的显示
        add_action('woocommerce_order_details_after_customer_details', array($this, 'display_fields_in_order'));
        // add_action('woocommerce_view_order_details', array($this, 'display_fields_in_order_details'), 20);
        // add_action('woocommerce_thankyou', array($this, 'display_fields_in_thankyou'), 20);
        
        // 额外的用户端显示Hook - 已禁用重复显示
        // add_action('woocommerce_order_details_after_order_table', array($this, 'display_fields_after_order_table'), 10);
        // add_action('woocommerce_view_order', array($this, 'display_fields_in_account_order'), 20);
        // add_action('woocommerce_order_details_before_order_table', array($this, 'display_fields_before_order_table'), 20);
        
        // 客户详情相关Hook - 已禁用重复显示
        // add_action('woocommerce_order_details_after_customer_details', array($this, 'display_fields_after_customer_details'), 25);
        
        // 后台管理订单页面显示 - 只保留主要显示位置
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_fields_in_admin_order'));
        // add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_fields_in_admin_order_shipping')); // 禁用重复显示
        
        // 订单邮件中显示
        add_action('woocommerce_email_customer_details', array($this, 'display_fields_in_email'), 20, 3);
        add_action('woocommerce_email_order_details', array($this, 'display_fields_in_email_order'), 15, 4);
        
        // 调试Hook来确认执行
        add_action('woocommerce_order_details_after_customer_details', array($this, 'debug_hook_execution'), 1);
        add_action('woocommerce_view_order', array($this, 'debug_view_order_hook'), 1);
        
        // 添加管理员工具栏调试链接（仅供开发调试）
        if (current_user_can('manage_options')) {
            add_action('wp_footer', array($this, 'add_debug_tools'));
            add_action('admin_menu', array($this, 'add_admin_menu'));
        }
    }
    
    /**
     * 加载文本域
     */
    public function load_textdomain() {
        load_plugin_textdomain('brazil-checkout-fields', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * 获取CPF对应的客户类型值
     */
    private function get_cpf_customer_type_value() {
        return get_option('brazil_checkout_cpf_value', 'pessoa_fisica');
    }
    
    /**
     * 获取CNPJ对应的客户类型值
     */
    private function get_cnpj_customer_type_value() {
        return get_option('brazil_checkout_cnpj_value', 'pessoa_juridica');
    }
    
    /**
     * 根据文档类型获取客户类型值
     */
    private function get_customer_type_value_by_document_type($document_type) {
        if ($document_type === 'cpf') {
            return $this->get_cpf_customer_type_value();
        } elseif ($document_type === 'cnpj') {
            return $this->get_cnpj_customer_type_value();
        }
        return '';
    }
    
    /**
     * 判断客户类型值是否对应CPF
     */
    private function is_cpf_customer_type($customer_type) {
        return $customer_type === $this->get_cpf_customer_type_value();
    }
    
    /**
     * 判断客户类型值是否对应CNPJ
     */
    private function is_cnpj_customer_type($customer_type) {
        return $customer_type === $this->get_cnpj_customer_type_value();
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
            console.log('Brazil CPF/CNPJ: Starting field injection to block editor');
            
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
                    
                    console.log('validateAll: Is Brazil selected:', isBrazilSelected);
                    
                    // 如果不是巴西，跳过验证
                    if (!isBrazilSelected) {
                        console.log('validateAll: Not Brazil, skipping validation');
                        return true;
                    }
                    
                    // 检查面板是否可见
                    var brazilPanel = $('.brazil-checkout-fields');
                    if (brazilPanel.length === 0 || (!brazilPanel.is(':visible') && !brazilPanel.hasClass('brazil-visible'))) {
                        console.log('validateAll: Brazil panel not visible, skipping validation');
                        return true;
                    }
                    
                    var documentField = $('#brazil_document');
                    var document = documentField.val();
                    console.log('validateAll: Checking document field value:', document);
                    
                    // 1. 检查是否为空
                    if (!document || !document.trim()) {
                        console.log('validateAll: Document field is empty, adding error');
                        this.errors.push(brazil_checkout_ajax.messages.document_required);
                        return false;
                    }
                    
                    // 2. 检查字段是否已经标记为无效
                    if (documentField.hasClass('brazil-field-invalid')) {
                        console.log('validateAll: Field already marked as invalid');
                        this.errors.push(brazil_checkout_ajax.messages.document_invalid);
                        return false;
                    }
                    
                    // 3. 执行完整的文档验证
                    if (!this.validateDocument(document)) {
                        console.log('validateAll: Document validation failed');
                        this.errors.push(brazil_checkout_ajax.messages.document_invalid);
                        return false;
                    }
                    
                    console.log('validateAll: Validation passed');
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
                        console.log('Found address block, injecting Brazil fields below address');
                        clearInterval(interval);
                        injectBrazilFields();
                    } else if (fieldsBlock.length > 0 && attempts > 20) {
                        console.log('Found fields block, injecting Brazil fields');
                        clearInterval(interval);
                        injectBrazilFieldsToFieldsBlock();
                    } else if (checkoutBlock.length > 0 && attempts > 40) {
                        console.log('Found checkout block, injecting Brazil fields at top');
                        clearInterval(interval);
                        injectBrazilFieldsToCheckoutBlock();
                    } else if (attempts >= maxAttempts) {
                        console.log('WooCommerce block editor elements not found, trying traditional method');
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
                    console.log('Brazil fields inserted after billing address block');
                } else {
                    // 查找配送地址块
                    var shippingBlock = $('.wp-block-woocommerce-checkout-shipping-address-block');
                    if (shippingBlock.length > 0) {
                        shippingBlock.after(brazilFieldsHtml);
                        console.log('Brazil fields inserted after shipping address block');
                    } else {
                        // 查找任何地址相关的块
                        var anyAddressBlock = $('[class*="address-block"], [class*="contact-information"]').last();
                        if (anyAddressBlock.length > 0) {
                            anyAddressBlock.after(brazilFieldsHtml);
                            console.log('Brazil fields inserted after address-related block');
                        } else {
                            // 插入到字段块内
                            $('.wp-block-woocommerce-checkout-fields-block').append(brazilFieldsHtml);
                            console.log('Brazil fields inserted inside fields block');
                        }
                    }
                }
                
                // 设置事件监听器和初始状态
                setupFieldListeners();
                setupValidation();
                
                // 初始化面板显示状态将由setupFieldListeners处理，避免重复调用
            }
            
            function injectBrazilFieldsToFieldsBlock() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                $('.wp-block-woocommerce-checkout-fields-block').append(brazilFieldsHtml);
                console.log('Brazil fields inserted into fields block');
                
                setupFieldListeners();
                setupValidation();
                
                // 初始化面板显示状态将由setupFieldListeners处理，避免重复调用
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
                
                console.log('Brazil fields inserted into checkout block');
                
                setupFieldListeners();
                setupValidation();
                
                // 初始化面板显示状态将由setupFieldListeners处理，避免重复调用
            }
            
            function injectBrazilFieldsFallback() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                $('body').prepend('<div style="position: relative; z-index: 999; max-width: 600px; margin: 20px auto;">' + brazilFieldsHtml + '</div>');
                console.log('Using fallback method to insert Brazil fields');
                
                setupFieldListeners();
                setupValidation();
                
                // 初始化面板显示状态将由setupFieldListeners处理，避免重复调用
            }
            
            // 全局的国家检查和面板切换函数 - 添加防抖机制
            var brazilPanelToggleTimeout = null;
            var lastCountryCheckTime = 0;
            var isTogglingPanel = false;
            
            function checkCountryAndToggleBrazilFields() {
                // 防抖：如果正在切换或者刚刚检查过，跳过
                var now = Date.now();
                if (isTogglingPanel || (now - lastCountryCheckTime < 200)) {
                    console.log('Skipping duplicate country check (debounced)');
                    return;
                }
                
                lastCountryCheckTime = now;
                isTogglingPanel = true;
                
                // 清除之前的定时器
                if (brazilPanelToggleTimeout) {
                    clearTimeout(brazilPanelToggleTimeout);
                }
                
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
                    isTogglingPanel = false;
                    return;
                }
                
                if (isBrazilSelected) {
                    console.log('显示巴西面板');
                    if (!brazilPanel.hasClass('brazil-visible') && !brazilPanel.is(':animated')) {
                        brazilPanel.removeClass('brazil-hidden').addClass('brazil-visible').hide().slideDown(300);
                        $('#brazil_document').prop('required', true);
                    }
                } else {
                    console.log('隐藏巴西面板');
                    if (brazilPanel.hasClass('brazil-visible') && !brazilPanel.is(':animated')) {
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
                
                // 重置标志
                setTimeout(function() {
                    isTogglingPanel = false;
                }, 300);
            }
            
            function setupFieldListeners() {
                // 单次延迟初始检查 - 减少重复调用
                setTimeout(function() {
                    console.log('执行初始国家检查');
                    checkCountryAndToggleBrazilFields();
                }, 1000);
                
                // 防抖的事件处理函数
                function debouncedCountryCheck() {
                    if (brazilPanelToggleTimeout) {
                        clearTimeout(brazilPanelToggleTimeout);
                    }
                    brazilPanelToggleTimeout = setTimeout(function() {
                        checkCountryAndToggleBrazilFields();
                    }, 150);
                }
                
                // 监听国家选择变化 - 使用事件委托和防抖
                $(document).on('change', 'select[name="billing_country"], select[name="shipping_country"], #billing_country, #shipping_country, select[id*="country"], select[name*="country"]', function() {
                    console.log('国家选择发生变化:', $(this).attr('name') || $(this).attr('id'), '新值:', $(this).val());
                    debouncedCountryCheck();
                });
                
                // 监听输入事件（有些主题可能使用输入而不是选择）
                $(document).on('input', 'select[name="billing_country"], select[name="shipping_country"], #billing_country, #shipping_country', function() {
                    console.log('国家输入发生变化:', $(this).attr('name') || $(this).attr('id'), '新值:', $(this).val());
                    debouncedCountryCheck();
                });
                
                // 优化的MutationObserver - 减少触发频率
                var mutationObserverTimeout = null;
                var countryObserver = new MutationObserver(function(mutations) {
                    var shouldCheck = false;
                    
                    // 清除之前的定时器
                    if (mutationObserverTimeout) {
                        clearTimeout(mutationObserverTimeout);
                    }
                    
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes.length > 0) {
                            $(mutation.addedNodes).find('select[name*="country"], select[id*="country"]').each(function() {
                                console.log('检测到新的国家选择器:', $(this).attr('name') || $(this).attr('id'));
                                $(this).on('change input', function() {
                                    console.log('新国家选择器变化:', $(this).val());
                                    debouncedCountryCheck();
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
                        mutationObserverTimeout = setTimeout(function() {
                            debouncedCountryCheck();
                        }, 200);
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
                        $('#brazil_customer_type').val('<?php echo esc_js($this->get_cpf_customer_type_value()); ?>');
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
                        $('#brazil_customer_type').val('<?php echo esc_js($this->get_cnpj_customer_type_value()); ?>');
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
                console.log('Using fallback method to insert Brazil fields');
                
                setupFieldListeners();
                setupValidation();
                
                // 初始化面板显示状态将由setupFieldListeners处理，避免重复调用
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
                                    requestData.additional_fields.brazil_customer_type = '<?php echo esc_js($this->get_cpf_customer_type_value()); ?>';
                                    requestData.additional_fields.brazil_cnpj = ''; // 确保CNPJ为空字符串
                                } else {
                                    requestData.additional_fields.brazil_cnpj = documentValue;
                                    requestData.additional_fields.brazil_customer_type = '<?php echo esc_js($this->get_cnpj_customer_type_value()); ?>';
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
        error_log('Brazil CPF/CNPJ: validate_checkout_fields_process called');
        error_log('Brazil CPF/CNPJ: POST data: ' . print_r($_POST, true));
        
        $validation_errors = $this->perform_validation(false);
        
        if (!empty($validation_errors)) {
            foreach ($validation_errors as $error) {
                wc_add_notice($error, 'error');
                error_log('Brazil CPF/CNPJ: 添加错误通知: ' . $error);
            }
            
            // 确保验证失败时停止处理
            wp_die('验证失败');
        }
    }
    
    /**
     * 后端验证 - 结账验证钩子
     */
    public function validate_checkout_fields($data, $errors) {
        error_log('Brazil CPF/CNPJ: validate_checkout_fields called');
        
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
        error_log('Brazil CPF/CNPJ: validate_checkout_posted_data called');
        
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
            error_log('Brazil CPF/CNPJ: 不是巴西地址，跳过验证. Billing: ' . $billing_country . ', Shipping: ' . $shipping_country);
            return $errors;
        }
        
        error_log('Brazil CPF/CNPJ: 检测到巴西地址，执行CPF/CNPJ验证');
        
        // 检查新的统一文档字段
        $document = isset($_POST['brazil_document']) ? sanitize_text_field($_POST['brazil_document']) : '';
        
        // 后备兼容性：检查旧字段
        if (empty($document)) {
            $customer_type = isset($_POST['brazil_customer_type']) ? sanitize_text_field($_POST['brazil_customer_type']) : '';
            if ($customer_type === $this->get_cpf_customer_type_value() && isset($_POST['brazil_cpf'])) {
                $document = sanitize_text_field($_POST['brazil_cpf']);
            } elseif ($customer_type === $this->get_cnpj_customer_type_value() && isset($_POST['brazil_cnpj'])) {
                $document = sanitize_text_field($_POST['brazil_cnpj']);
            }
        }
        
        error_log('Brazil CPF/CNPJ: 验证文档: ' . $document);
        
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
            error_log('Brazil CPF/CNPJ: 验证失败: ' . implode(', ', $errors));
        } else {
            error_log('Brazil CPF/CNPJ: 验证通过');
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
        error_log('🔥 BRAZIL CPF/CNPJ: save_checkout_fields MAIN FUNCTION CALLED - Order ID: ' . ($order ? $order->get_id() : 'null'));
        
        if (!$order) {
            error_log('Brazil CPF/CNPJ: No order object provided');
            return;
        }
        
        $order_id = $order->get_id();
        error_log('Brazil CPF/CNPJ: Processing order ID: ' . $order_id);
        
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
        $customer_type = '';
        
        // 1. 检查Store API的additional_fields
        if (isset($request_params['additional_fields']['brazil_document']) && !empty($request_params['additional_fields']['brazil_document'])) {
            $document = sanitize_text_field($request_params['additional_fields']['brazil_document']);
            $customer_type = isset($request_params['additional_fields']['brazil_customer_type']) ? 
                sanitize_text_field($request_params['additional_fields']['brazil_customer_type']) : '';
            error_log('Brazil Checkout: Found document in additional_fields: ' . $document . ', customer_type: ' . $customer_type);
        }
        
        // 2. 检查Store API的extensions（多个可能的位置）
        elseif (isset($request_params['extensions']['brazil-checkout-fields']['brazil_document']) && !empty($request_params['extensions']['brazil-checkout-fields']['brazil_document'])) {
            $document = sanitize_text_field($request_params['extensions']['brazil-checkout-fields']['brazil_document']);
            $customer_type = isset($request_params['extensions']['brazil-checkout-fields']['brazil_customer_type']) ? 
                sanitize_text_field($request_params['extensions']['brazil-checkout-fields']['brazil_customer_type']) : '';
            error_log('Brazil Checkout: Found document in extensions[brazil-checkout-fields]: ' . $document . ', customer_type: ' . $customer_type);
        }
        elseif (isset($request_params['extensions']['brazil-checkout']['brazil_document']) && !empty($request_params['extensions']['brazil-checkout']['brazil_document'])) {
            $document = sanitize_text_field($request_params['extensions']['brazil-checkout']['brazil_document']);
            $customer_type = isset($request_params['extensions']['brazil-checkout']['brazil_customer_type']) ? 
                sanitize_text_field($request_params['extensions']['brazil-checkout']['brazil_customer_type']) : '';
            error_log('Brazil Checkout: Found document in extensions[brazil-checkout]: ' . $document . ', customer_type: ' . $customer_type);
        }
        elseif (isset($request_params['extensions']['brazil-cpf-cnpj']['brazil_document']) && !empty($request_params['extensions']['brazil-cpf-cnpj']['brazil_document'])) {
            $document = sanitize_text_field($request_params['extensions']['brazil-cpf-cnpj']['brazil_document']);
            $customer_type = isset($request_params['extensions']['brazil-cpf-cnpj']['brazil_customer_type']) ? 
                sanitize_text_field($request_params['extensions']['brazil-cpf-cnpj']['brazil_customer_type']) : '';
            error_log('Brazil Checkout: Found document in extensions[brazil-cpf-cnpj]: ' . $document . ', customer_type: ' . $customer_type);
        }
        
        // 3. 检查直接的请求参数
        elseif (isset($request_params['brazil_document']) && !empty($request_params['brazil_document'])) {
            $document = sanitize_text_field($request_params['brazil_document']);
            $customer_type = isset($request_params['brazil_customer_type']) ? 
                sanitize_text_field($request_params['brazil_customer_type']) : '';
            error_log('Brazil Checkout: Found document in request params: ' . $document . ', customer_type: ' . $customer_type);
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
        
        // 5. 如果还是没找到，尝试从拦截的session数据获取
        if (empty($document)) {
            if (!session_id()) {
                session_start();
            }
            
            // 检查拦截的数据
            if (isset($_SESSION['brazil_intercepted_data']['brazil_document']) && !empty($_SESSION['brazil_intercepted_data']['brazil_document'])) {
                $document = sanitize_text_field($_SESSION['brazil_intercepted_data']['brazil_document']);
                $customer_type = isset($_SESSION['brazil_intercepted_data']['brazil_customer_type']) ? 
                    sanitize_text_field($_SESSION['brazil_intercepted_data']['brazil_customer_type']) : '';
                error_log('Brazil Checkout: Found document in intercepted data: ' . $document . ', customer_type: ' . $customer_type);
            }
            // 检查旧的session数据
            elseif (isset($_SESSION['brazil_cpf_cnpj']) && !empty($_SESSION['brazil_cpf_cnpj'])) {
                $document = sanitize_text_field($_SESSION['brazil_cpf_cnpj']);
                error_log('Brazil Checkout: Found document in session: ' . $document);
            }
        }
        
        if (!empty($document)) {
            $clean_document = preg_replace('/[^0-9]/', '', $document);
            error_log('Brazil Checkout: Clean document: ' . $clean_document . ' (length: ' . strlen($clean_document) . ')');
            
            // 如果没有提供customer_type，根据文档长度推断
            if (empty($customer_type)) {
                if (strlen($clean_document) === 11) {
                    $customer_type = $this->get_cpf_customer_type_value();
                } elseif (strlen($clean_document) === 14) {
                    $customer_type = $this->get_cnpj_customer_type_value();
                }
                error_log('Brazil Checkout: Auto-detected customer_type: ' . $customer_type);
            }
            
            if (strlen($clean_document) === 11) {
                // CPF - 保存核心字段
                error_log('Brazil Checkout: Saving CPF data for order ' . $order_id);
                $order->update_meta_data(BRAZIL_CUSTOMER_TYPE_FIELD, $customer_type ?: $this->get_cpf_customer_type_value());
                $order->update_meta_data(BRAZIL_DOCUMENT_FIELD, $document);
                
            } elseif (strlen($clean_document) === 14) {
                // CNPJ - 保存核心字段
                error_log('Brazil Checkout: Saving CNPJ data for order ' . $order_id);
                $order->update_meta_data(BRAZIL_CUSTOMER_TYPE_FIELD, $customer_type ?: $this->get_cnpj_customer_type_value());
                $order->update_meta_data(BRAZIL_DOCUMENT_FIELD, $document);
                
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
            if (isset($_SESSION['brazil_intercepted_data'])) {
                unset($_SESSION['brazil_intercepted_data']);
            }
            
        } else {
            error_log('Brazil Checkout: No document data found in Store API request, POST, or session');
            
            // 调试：记录完整的请求结构
            error_log('Brazil Checkout: Full request structure: ' . print_r($request_params, true));
        }
        
        // 后备兼容性：移除旧字段保存
        // 不再保存冗余的旧格式字段
        // if (isset($_POST['brazil_cpf']) && !empty($_POST['brazil_cpf'])) {
        //     error_log('Brazil Checkout: Saving legacy CPF field');
        //     $order->update_meta_data('_customer_type', 'pessoa_fisica');
        //     $order->update_meta_data('_cpf', sanitize_text_field($_POST['brazil_cpf']));
        //     $order->save();
        // }
        
        // if (isset($_POST['brazil_cnpj']) && !empty($_POST['brazil_cnpj'])) {
        //     error_log('Brazil Checkout: Saving legacy CNPJ field');
        //     $order->update_meta_data('_customer_type', 'pessoa_juridica');
        //     $order->update_meta_data('_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        //     $order->save();
        // }
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
                // CPF - 只保留核心字段
                error_log('Brazil Checkout: Saving CPF data via update_post_meta');
                update_post_meta($order_id, BRAZIL_CUSTOMER_TYPE_FIELD, $this->get_cpf_customer_type_value());
                update_post_meta($order_id, BRAZIL_DOCUMENT_FIELD, $document);
            } elseif (strlen($clean_document) === 14) {
                // CNPJ - 只保留核心字段
                error_log('Brazil Checkout: Saving CNPJ data via update_post_meta');
                update_post_meta($order_id, BRAZIL_CUSTOMER_TYPE_FIELD, $this->get_cnpj_customer_type_value());
                update_post_meta($order_id, BRAZIL_DOCUMENT_FIELD, $document);
            } else {
                error_log('Brazil Checkout: Invalid document length: ' . strlen($clean_document));
            }
            
            error_log('Brazil Checkout: Fallback save completed');
        } else {
            error_log('Brazil Checkout: No document data found in POST');
        }
        
        // 后备兼容性：移除旧字段保存
        // 不再保存冗余的旧格式字段
        // if (isset($_POST['brazil_cpf']) && !empty($_POST['brazil_cpf'])) {
        //     error_log('Brazil Checkout: Saving legacy CPF field via update_post_meta');
        //     update_post_meta($order_id, '_customer_type', 'pessoa_fisica');
        //     update_post_meta($order_id, '_cpf', sanitize_text_field($_POST['brazil_cpf']));
        // }
        
        // if (isset($_POST['brazil_cnpj']) && !empty($_POST['brazil_cnpj'])) {
        //     error_log('Brazil Checkout: Saving legacy CNPJ field via update_post_meta');
        //     update_post_meta($order_id, '_customer_type', 'pessoa_juridica');
        //     update_post_meta($order_id, '_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        // }
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
                // CPF - 只保留核心字段
                $order->update_meta_data(BRAZIL_CUSTOMER_TYPE_FIELD, $this->get_cpf_customer_type_value());
                $order->update_meta_data(BRAZIL_DOCUMENT_FIELD, $document);
            } elseif (strlen($clean_document) === 14) {
                // CNPJ - 只保留核心字段
                $order->update_meta_data(BRAZIL_CUSTOMER_TYPE_FIELD, $this->get_cnpj_customer_type_value());
                $order->update_meta_data(BRAZIL_DOCUMENT_FIELD, $document);
            }
        }
        
        // 后备兼容性：移除旧字段保存
        // 不再保存冗余的旧格式字段
        // if (isset($_POST['brazil_cpf']) && !empty($_POST['brazil_cpf'])) {
        //     $order->update_meta_data('_customer_type', 'pessoa_fisica');
        //     $order->update_meta_data('_cpf', sanitize_text_field($_POST['brazil_cpf']));
        // }
        
        // if (isset($_POST['brazil_cnpj']) && !empty($_POST['brazil_cnpj'])) {
        //     $order->update_meta_data('_customer_type', 'pessoa_juridica');
        //     $order->update_meta_data('_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        // }
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
        
        // 1. 优先检查新的统一文档字段（使用可配置的字段名）
        $document = $order->get_meta(BRAZIL_DOCUMENT_FIELD);
        $customer_type = $order->get_meta(BRAZIL_CUSTOMER_TYPE_FIELD);
        
        error_log('Brazil Checkout: Configurable fields - Document: ' . $document . ', Customer Type: ' . $customer_type);
        
        if (!empty($document)) {
            error_log('Brazil Checkout: ✅ Found configurable document field');
            
            // 根据客户类型确定文档类型
            $document_type = '';
            if ($this->is_cpf_customer_type($customer_type)) {
                $document_type = 'cpf';
            } elseif ($this->is_cnpj_customer_type($customer_type)) {
                $document_type = 'cnpj';
            } else {
                // 尝试自动检测
                $document_type = $this->detect_document_type($document);
            }
            
            return array(
                'document' => $document,
                'type' => $document_type,
                'customer_type' => $customer_type
            );
        }
        
        // 2. 后备：检查旧的统一文档字段
        $legacy_document = $order->get_meta('_brazil_document');
        $legacy_document_type = $order->get_meta('_brazil_document_type');
        
        error_log('Brazil Checkout: Legacy unified fields - Document: ' . $legacy_document . ', Type: ' . $legacy_document_type);
        
        if (!empty($legacy_document)) {
            error_log('Brazil Checkout: ✅ Found legacy unified document field');
            
            // 如果没有类型，尝试从customer_type获取
            if (empty($legacy_document_type)) {
                $legacy_customer_type = $order->get_meta('_brazil_customer_type');
                if ($this->is_cpf_customer_type($legacy_customer_type)) {
                    $legacy_document_type = 'cpf';
                } elseif ($this->is_cnpj_customer_type($legacy_customer_type)) {
                    $legacy_document_type = 'cnpj';
                } else {
                    // 尝试自动检测
                    $legacy_document_type = $this->detect_document_type($legacy_document);
                }
                error_log('Brazil Checkout: ✅ Determined document type: ' . $legacy_document_type);
            }
            
            return array(
                'document' => $legacy_document,
                'type' => $legacy_document_type,
                'customer_type' => $order->get_meta('_brazil_customer_type')
            );
        }
        
        // 2. 检查带前缀的兼容字段
        $billing_cpf = $order->get_meta('_billing_cpf');
        $billing_cnpj = $order->get_meta('_billing_cnpj');
        $billing_persontype = $order->get_meta('_billing_persontype');
        
        error_log('Brazil Checkout: Legacy billing fields - CPF: ' . $billing_cpf . ', CNPJ: ' . $billing_cnpj . ', PersonType: ' . $billing_persontype);
        
        if (!empty($billing_cpf)) {
            error_log('Brazil Checkout: ✅ Found legacy CPF field');
            return array(
                'document' => $billing_cpf,
                'type' => 'cpf',
                'customer_type' => $this->get_cpf_customer_type_value()
            );
        }
        
        if (!empty($billing_cnpj)) {
            error_log('Brazil Checkout: ✅ Found legacy CNPJ field');
            return array(
                'document' => $billing_cnpj,
                'type' => 'cnpj',
                'customer_type' => $this->get_cnpj_customer_type_value()
            );
        }
        
        // 3. 检查旧格式字段
        $customer_type = $order->get_meta('_customer_type');
        $cpf = $order->get_meta('_cpf');
        $cnpj = $order->get_meta('_cnpj');
        
        error_log('Brazil Checkout: Old format fields - Customer Type: ' . $customer_type . ', CPF: ' . $cpf . ', CNPJ: ' . $cnpj);
        
        if ($this->is_cpf_customer_type($customer_type) && $cpf) {
            error_log('Brazil Checkout: ✅ Found old format CPF data');
            return array(
                'document' => $cpf,
                'type' => 'cpf',
                'customer_type' => $this->get_cpf_customer_type_value()
            );
        } elseif ($this->is_cnpj_customer_type($customer_type) && $cnpj) {
            error_log('Brazil Checkout: ✅ Found old format CNPJ data');
            return array(
                'document' => $cnpj,
                'type' => 'cnpj',
                'customer_type' => $this->get_cnpj_customer_type_value()
            );
        }
        
        // 4. 检查没有前缀的字段
        $document_no_prefix = $order->get_meta('brazil_document');
        if (!empty($document_no_prefix)) {
            error_log('Brazil Checkout: ✅ Found document without prefix: ' . $document_no_prefix);
            $detected_type = $this->detect_document_type($document_no_prefix);
            return array(
                'document' => $document_no_prefix,
                'type' => $detected_type,
                'customer_type' => $detected_type === 'cpf' ? $this->get_cpf_customer_type_value() : $this->get_cnpj_customer_type_value()
            );
        }
        
        error_log('Brazil Checkout: ❌ No Brazil info found in order meta');
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
     * AJAX预览迁移数据
     */
    public function ajax_preview_migration_data() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
            return;
        }
        
        check_ajax_referer('brazil_preview_migration', 'nonce');
        
        try {
            $customer_type_field = BRAZIL_CUSTOMER_TYPE_FIELD;
            $hpos_enabled = $this->detect_hpos_mode();
            
            if ($hpos_enabled) {
                $data = $this->get_migration_preview_hpos($customer_type_field);
            } else {
                $data = $this->get_migration_preview_legacy($customer_type_field);
            }
            
            $html = '<div class="migration-preview">';
            $html .= '<h4>📈 ' . __('Current Customer Type Value Distribution', 'brazil-checkout-fields') . '</h4>';
            $html .= '<table class="wp-list-table widefat fixed striped">';
            $html .= '<thead><tr><th>' . __('Field Name', 'brazil-checkout-fields') . '</th><th>' . __('Customer Type Value', 'brazil-checkout-fields') . '</th><th>' . __('Order Count', 'brazil-checkout-fields') . '</th><th>' . __('Document Type', 'brazil-checkout-fields') . '</th></tr></thead>';
            $html .= '<tbody>';
            
            if (empty($data)) {
                $html .= '<tr><td colspan="4" style="text-align: center; color: #666;">' . __('No data available', 'brazil-checkout-fields') . '</td></tr>';
            } else {
                foreach ($data as $row) {
                    $document_type = '';
                    if ($row['customer_type'] === get_option('brazil_checkout_cpf_value', 'pessoa_fisica')) {
                        $document_type = __('CPF (Individual)', 'brazil-checkout-fields');
                    } elseif ($row['customer_type'] === get_option('brazil_checkout_cnpj_value', 'pessoa_juridica')) {
                        $document_type = __('CNPJ (Business)', 'brazil-checkout-fields');
                    } elseif ($row['customer_type'] === 'pessoa_fisica') {
                        $document_type = __('CPF (Default)', 'brazil-checkout-fields');
                    } elseif ($row['customer_type'] === 'pessoa_juridica') {
                        $document_type = __('CNPJ (Default)', 'brazil-checkout-fields');
                    } else {
                        $document_type = __('Unknown', 'brazil-checkout-fields');
                    }
                    
                    $html .= '<tr>';
                    $html .= '<td><code>' . esc_html($row['field']) . '</code></td>';
                    $html .= '<td><strong>' . esc_html($row['customer_type']) . '</strong></td>';
                    $html .= '<td>' . number_format($row['count']) . '</td>';
                    $html .= '<td>' . $document_type . '</td>';
                    $html .= '</tr>';
                }
            }
            
            $html .= '</tbody></table>';
            $html .= '<p style="margin-top: 15px; font-size: 12px; color: #666;">';
            $html .= __('Storage Mode:', 'brazil-checkout-fields') . ' ' . ($hpos_enabled ? __('HPOS (High-Performance Order Storage)', 'brazil-checkout-fields') : __('Legacy (Traditional Post Storage)', 'brazil-checkout-fields'));
            $html .= '</p>';
            $html .= '</div>';
            
            wp_send_json_success($html);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error occurred while previewing data: ', 'brazil-checkout-fields') . $e->getMessage());
        }
    }
    
    /**
     * HPOS模式下获取迁移预览数据
     */
    private function get_migration_preview_hpos($customer_type_field) {
        global $wpdb;
        
        $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT meta_key as field, meta_value as customer_type, COUNT(*) as count
            FROM {$orders_meta_table} 
            WHERE meta_key IN (%s, '_customer_type') 
            AND meta_value != ''
            GROUP BY meta_key, meta_value
            ORDER BY meta_key, count DESC
        ", $customer_type_field), ARRAY_A);
        
        return $results ?: array();
    }
    
    /**
     * 传统模式下获取迁移预览数据
     */
    private function get_migration_preview_legacy($customer_type_field) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT meta_key as field, meta_value as customer_type, COUNT(*) as count
            FROM {$wpdb->postmeta} 
            WHERE meta_key IN (%s, '_customer_type') 
            AND meta_value != ''
            GROUP BY meta_key, meta_value
            ORDER BY meta_key, count DESC
        ", $customer_type_field), ARRAY_A);
        
        return $results ?: array();
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
            $order->update_meta_data('_customer_type', $this->get_cpf_customer_type_value());
            $order->update_meta_data('_cpf', $document);
            $order->update_meta_data('_brazil_document', $document);
            $order->update_meta_data('_brazil_document_type', 'cpf');
            
            // 也保存无前缀版本
            $order->update_meta_data('brazil_document', $document);
            $order->update_meta_data('brazil_document_type', 'cpf');
            
        } elseif (strlen($clean_document) === 14) {
            // CNPJ
            error_log('Brazil Checkout: Saving CNPJ to order ' . $order_id);
            $order->update_meta_data('_customer_type', $this->get_cnpj_customer_type_value());
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
            // CPF - 只保留核心字段
            error_log('Brazil Checkout: Saving unified CPF data for order ' . $order_id);
            update_post_meta($order_id, BRAZIL_DOCUMENT_FIELD, $document);
            update_post_meta($order_id, BRAZIL_CUSTOMER_TYPE_FIELD, $this->get_cpf_customer_type_value());
            
            // 清理session数据
            unset($_SESSION['brazil_cpf_cnpj'], $_SESSION['brazil_billing_country'], $_SESSION['brazil_data_timestamp']);
            
            error_log('Brazil Checkout: CPF saved successfully for order ' . $order_id . ' (core fields only)');
            return true;
        } elseif (strlen($clean_document) === 14) {
            // CNPJ - 只保留核心字段
            error_log('Brazil Checkout: Saving unified CNPJ data for order ' . $order_id);
            update_post_meta($order_id, BRAZIL_DOCUMENT_FIELD, $document);
            update_post_meta($order_id, BRAZIL_CUSTOMER_TYPE_FIELD, $this->get_cnpj_customer_type_value());
            
            // 清理session数据
            unset($_SESSION['brazil_cpf_cnpj'], $_SESSION['brazil_billing_country'], $_SESSION['brazil_data_timestamp']);
            
            error_log('Brazil Checkout: CNPJ saved successfully for order ' . $order_id . ' (core fields only)');
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
     * 验证CPF格式
     */
    private function validate_cpf($cpf) {
        // 移除非数字字符
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        // 检查长度
        if (strlen($cpf) !== 11) {
            return false;
        }
        
        // 检查是否所有数字都相同
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        
        // 验证第一个校验位
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += intval($cpf[$i]) * (10 - $i);
        }
        $remainder = $sum % 11;
        $digit1 = $remainder < 2 ? 0 : 11 - $remainder;
        
        if (intval($cpf[9]) !== $digit1) {
            return false;
        }
        
        // 验证第二个校验位
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += intval($cpf[$i]) * (11 - $i);
        }
        $remainder = $sum % 11;
        $digit2 = $remainder < 2 ? 0 : 11 - $remainder;
        
        return intval($cpf[10]) === $digit2;
    }
    
    /**
     * 验证CNPJ格式
     */
    private function validate_cnpj($cnpj) {
        // 移除非数字字符
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        
        // 检查长度
        if (strlen($cnpj) !== 14) {
            return false;
        }
        
        // 检查是否所有数字都相同
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        
        // 验证第一个校验位
        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += intval($cnpj[$i]) * $weights1[$i];
        }
        $remainder = $sum % 11;
        $digit1 = $remainder < 2 ? 0 : 11 - $remainder;
        
        if (intval($cnpj[12]) !== $digit1) {
            return false;
        }
        
        // 验证第二个校验位
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += intval($cnpj[$i]) * $weights2[$i];
        }
        $remainder = $sum % 11;
        $digit2 = $remainder < 2 ? 0 : 11 - $remainder;
        
        return intval($cnpj[13]) === $digit2;
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
     * 注册additional_fields支持
     */
    public function register_additional_fields_support() {
        // 使用 WooCommerce Store API 注册 additional_fields
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data(array(
                'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
                'namespace' => 'brazil-checkout-additional',
                'data_callback' => array($this, 'additional_fields_data_callback'),
                'schema_callback' => array($this, 'additional_fields_schema_callback'),
                'schema_type' => ARRAY_A,
            ));
        }
        
        // 另一种注册方法 - 使用过滤器
        add_filter('woocommerce_store_api_checkout_schema', array($this, 'extend_checkout_schema'));
        add_filter('woocommerce_store_api_checkout_additional_fields_schema', array($this, 'add_additional_fields_schema'));
    }
    
    /**
     * 扩展 checkout schema
     */
    public function extend_checkout_schema($schema) {
        if (!isset($schema['properties']['additional_fields'])) {
            $schema['properties']['additional_fields'] = array(
                'type' => 'object',
                'properties' => array(),
            );
        }
        
        $schema['properties']['additional_fields']['properties']['brazil_document'] = array(
            'type' => 'string',
            'description' => 'Brazil document (CPF or CNPJ)',
            'default' => '',
        );
        
        $schema['properties']['additional_fields']['properties']['brazil_customer_type'] = array(
            'type' => 'string',
            'description' => 'Brazil customer type',
            'default' => '',
        );
        
        $schema['properties']['additional_fields']['properties']['brazil_cpf'] = array(
            'type' => 'string',
            'description' => 'Brazil CPF',
            'default' => '',
        );
        
        $schema['properties']['additional_fields']['properties']['brazil_cnpj'] = array(
            'type' => 'string',
            'description' => 'Brazil CNPJ',
            'default' => '',
        );
        
        return $schema;
    }
    
    /**
     * 添加 additional_fields schema
     */
    public function add_additional_fields_schema($schema) {
        return array_merge($schema, array(
            'brazil_document' => array(
                'type' => 'string',
                'description' => 'Brazil document (CPF or CNPJ)',
                'default' => '',
            ),
            'brazil_customer_type' => array(
                'type' => 'string',
                'description' => 'Brazil customer type',
                'default' => '',
            ),
            'brazil_cpf' => array(
                'type' => 'string',
                'description' => 'Brazil CPF',
                'default' => '',
            ),
            'brazil_cnpj' => array(
                'type' => 'string',
                'description' => 'Brazil CNPJ',
                'default' => '',
            ),
        ));
    }
    
    /**
     * Additional fields数据回调
     */
    public function additional_fields_data_callback() {
        return array(
            'brazil_document' => '',
            'brazil_customer_type' => '',
            'brazil_cpf' => '',
            'brazil_cnpj' => '',
        );
    }
    
    /**
     * Additional fields架构回调
     */
    public function additional_fields_schema_callback() {
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
     * 调试Store API请求 - 仅在明确启用时使用
     */
    public function debug_store_api_requests() {
        // 只在调试模式且有特定参数时记录
        if (defined('WP_DEBUG') && WP_DEBUG && isset($_GET['brazil_debug'])) {
            error_log('Brazil CPF/CNPJ: debug_store_api_requests initialized');
        }
    }
    
    /**
     * 调试REST请求 - 仅在明确启用时使用
     */
    public function debug_rest_request($result, $server, $request) {
        // 只在调试模式且有特定参数时记录
        if (!defined('WP_DEBUG') || !WP_DEBUG || !isset($_GET['brazil_debug'])) {
            return $result;
        }
        
        $route = $request->get_route();
        
        if (strpos($route, '/wc/store/v1/checkout') !== false) {
            error_log('Brazil CPF/CNPJ: Store API checkout request detected');
            error_log('Route: ' . $route);
        }
        
        return $result;
    }

    /**
     * 拦截Store API请求以捕获additional_fields数据
     */
    public function intercept_store_api_request($result, $server, $request) {
        $route = $request->get_route();
        
        // 只处理checkout相关的Store API请求
        if (strpos($route, '/wc/store/v1/checkout') !== false) {
            $body = $request->get_body();
            $params = $request->get_params();
            
            error_log('Brazil Checkout: Intercepted Store API request to: ' . $route);
            error_log('Brazil Checkout: Request body: ' . $body);
            
            // 解析JSON body
            if (!empty($body)) {
                $json_data = json_decode($body, true);
                if ($json_data && isset($json_data['additional_fields'])) {
                    $additional_fields = $json_data['additional_fields'];
                    error_log('Brazil Checkout: Found additional_fields in JSON body: ' . print_r($additional_fields, true));
                    
                    // 保存到全局变量或session以供后续使用
                    if (isset($additional_fields['brazil_document']) && !empty($additional_fields['brazil_document'])) {
                        if (!session_id()) {
                            session_start();
                        }
                        $_SESSION['brazil_intercepted_data'] = $additional_fields;
                        error_log('Brazil Checkout: Saved intercepted data to session');
                    }
                }
            }
        }
        
        return $result;
    }

    /**
     * 从请求中保存巴西数据的通用函数
     */
    public function save_brazil_data_from_request($additional_fields, $order_id = null) {
        
        if (!isset($additional_fields['brazil_document']) || empty($additional_fields['brazil_document'])) {
            return false;
        }
        
        $brazil_document = sanitize_text_field($additional_fields['brazil_document']);
        $brazil_customer_type = isset($additional_fields['brazil_customer_type']) ? sanitize_text_field($additional_fields['brazil_customer_type']) : '';
        
        // 保存到session作为备份
        if (!session_id()) {
            session_start();
        }
        $_SESSION['brazil_checkout_data'] = array(
            'brazil_document' => $brazil_document,
            'brazil_customer_type' => $brazil_customer_type,
            'timestamp' => time()
        );
        
        // 如果没有提供订单ID，只保存到session
        if (!$order_id) {
            return true;
        }
        
        // 保存到订单
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // 保存核心字段
        $order->update_meta_data(BRAZIL_DOCUMENT_FIELD, $brazil_document);
        $order->update_meta_data(BRAZIL_CUSTOMER_TYPE_FIELD, $brazil_customer_type);
        $order->save();
        
        return true;
    }

    /**
     * 更新巴西文档字段
     */
    public function update_brazil_document_field($value, $object) {
        return update_post_meta($object->ID, BRAZIL_DOCUMENT_FIELD, sanitize_text_field($value));
    }
    
    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Brazil CPF/CNPJ',
            'Brazil CPF/CNPJ',
            'manage_options',
            'brazil-checkout-fields',
            array($this, 'admin_page')
        );
    }
    
    /**
     * 管理页面
     */
    public function admin_page() {
        // 处理表单提交
        if (isset($_POST['submit']) && check_admin_referer('brazil_checkout_settings', 'brazil_checkout_nonce')) {
            $customer_type_field = sanitize_text_field($_POST['customer_type_field']);
            $document_field = sanitize_text_field($_POST['document_field']);
            $cpf_value = sanitize_text_field($_POST['cpf_customer_type_value']);
            $cnpj_value = sanitize_text_field($_POST['cnpj_customer_type_value']);
            
            // 验证字段名称格式
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $customer_type_field)) {
                add_settings_error('brazil_checkout_messages', 'invalid_customer_type_field', __('Customer type field name format is invalid. Only letters, numbers and underscores are allowed, and must start with a letter or underscore.', 'brazil-checkout-fields'));
            } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $document_field)) {
                add_settings_error('brazil_checkout_messages', 'invalid_document_field', __('Document field name format is invalid. Only letters, numbers and underscores are allowed, and must start with a letter or underscore.', 'brazil-checkout-fields'));
            } elseif (empty($cpf_value) || empty($cnpj_value)) {
                add_settings_error('brazil_checkout_messages', 'empty_customer_type_values', __('Customer type values cannot be empty.', 'brazil-checkout-fields'));
            } else {
                update_option('brazil_checkout_customer_type_field', $customer_type_field);
                update_option('brazil_checkout_document_field', $document_field);
                update_option('brazil_checkout_cpf_value', $cpf_value);
                update_option('brazil_checkout_cnpj_value', $cnpj_value);
                
                // 自动清理缓存，确保统计数据使用新的客户类型值
                delete_transient('brazil_cpf_cnpj_stats');
                delete_transient('brazil_cpf_cnpj_recent_orders');
                
                add_settings_error('brazil_checkout_messages', 'settings_updated', __('Settings saved! Cache cleared, statistics will be recalculated using new customer type values.', 'brazil-checkout-fields'), 'updated');
            }
        }
        
        // 处理重置为默认设置
        if (isset($_POST['reset_defaults']) && check_admin_referer('brazil_checkout_reset', 'brazil_reset_nonce')) {
            update_option('brazil_checkout_customer_type_field', '_brazil_customer_type');
            update_option('brazil_checkout_document_field', '_brazil_document');
            update_option('brazil_checkout_cpf_value', 'pessoa_fisica');
            update_option('brazil_checkout_cnpj_value', 'pessoa_juridica');
            
            // 自动清理缓存，确保统计数据使用重置后的客户类型值
            delete_transient('brazil_cpf_cnpj_stats');
            delete_transient('brazil_cpf_cnpj_recent_orders');
            
            add_settings_error('brazil_checkout_messages', 'settings_reset', __('Settings have been reset to default values! Cache cleared, statistics will be recalculated.', 'brazil-checkout-fields'), 'updated');
        }
        
        $current_customer_type_field = get_option('brazil_checkout_customer_type_field', '_brazil_customer_type');
        $current_document_field = get_option('brazil_checkout_document_field', '_brazil_document');
        $current_cpf_value = get_option('brazil_checkout_cpf_value', 'pessoa_fisica');
        $current_cnpj_value = get_option('brazil_checkout_cnpj_value', 'pessoa_juridica');
        ?>
        <div class="wrap">
            <h1><?php _e('Brazil CPF/CNPJ Configuration', 'brazil-checkout-fields'); ?></h1>
            
            <?php settings_errors('brazil_checkout_messages'); ?>
            
            <div class="notice notice-info">
                <p><strong><?php _e('Field Name Customization Feature', 'brazil-checkout-fields'); ?></strong> - <?php _e('This plugin supports custom database field names', 'brazil-checkout-fields'); ?></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('brazil_checkout_settings', 'brazil_checkout_nonce'); ?>
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="customer_type_field"><?php _e('Customer Type Field Name', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="customer_type_field" 
                                       name="customer_type_field" 
                                       value="<?php echo esc_attr($current_customer_type_field); ?>" 
                                       class="regular-text" 
                                       placeholder="_brazil_customer_type"
                                       pattern="^[a-zA-Z_][a-zA-Z0-9_]*$" 
                                       title="<?php esc_attr_e('Only letters, numbers and underscores are allowed, and must start with a letter or underscore', 'brazil-checkout-fields'); ?>" />
                                <p class="description">
                                    <?php _e('Field name for storing customer type values', 'brazil-checkout-fields'); ?><br>
                                    <strong><?php _e('Current effective field name:', 'brazil-checkout-fields'); ?></strong> <code><?php echo esc_html(BRAZIL_CUSTOMER_TYPE_FIELD); ?></code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="cpf_customer_type_value"><?php _e('CPF Customer Type Value', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="cpf_customer_type_value" 
                                       name="cpf_customer_type_value" 
                                       value="<?php echo esc_attr($current_cpf_value); ?>" 
                                       class="regular-text" 
                                       placeholder="pessoa_fisica" />
                                <p class="description">
                                    <?php _e('Value saved in customer type field when user enters CPF', 'brazil-checkout-fields'); ?><br>
                                    <strong><?php _e('Current value:', 'brazil-checkout-fields'); ?></strong> <code><?php echo esc_html($current_cpf_value); ?></code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="cnpj_customer_type_value"><?php _e('CNPJ Customer Type Value', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="cnpj_customer_type_value" 
                                       name="cnpj_customer_type_value" 
                                       value="<?php echo esc_attr($current_cnpj_value); ?>" 
                                       class="regular-text" 
                                       placeholder="pessoa_juridica" />
                                <p class="description">
                                    <?php _e('Value saved in customer type field when user enters CNPJ', 'brazil-checkout-fields'); ?><br>
                                    <strong><?php _e('Current value:', 'brazil-checkout-fields'); ?></strong> <code><?php echo esc_html($current_cnpj_value); ?></code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="document_field"><?php _e('Document Field Name', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="document_field" 
                                       name="document_field" 
                                       value="<?php echo esc_attr($current_document_field); ?>" 
                                       class="regular-text" 
                                       placeholder="_brazil_document"
                                       pattern="^[a-zA-Z_][a-zA-Z0-9_]*$" 
                                       title="<?php esc_attr_e('Only letters, numbers and underscores are allowed, and must start with a letter or underscore', 'brazil-checkout-fields'); ?>" />
                                <p class="description">
                                    <?php _e('Stores formatted CPF/CNPJ numbers', 'brazil-checkout-fields'); ?><br>
                                    <strong><?php _e('Current effective field name:', 'brazil-checkout-fields'); ?></strong> <code><?php echo esc_html(BRAZIL_DOCUMENT_FIELD); ?></code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Plugin Version', 'brazil-checkout-fields'); ?></th>
                            <td><?php _e('2.4.0 - Brazil CPF/CNPJ supports backend field name configuration', 'brazil-checkout-fields'); ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <?php submit_button(__('Save Settings', 'brazil-checkout-fields'), 'primary', 'submit', false); ?>
                    &nbsp;&nbsp;
                    <button type="button" class="button" onclick="resetToDefaults()"><?php _e('Reset to Defaults', 'brazil-checkout-fields'); ?></button>
                </p>
            </form>
            
            <!-- 重置表单 -->
            <form method="post" action="" id="reset-form" style="display: none;">
                <?php wp_nonce_field('brazil_checkout_reset', 'brazil_reset_nonce'); ?>
                <input type="hidden" name="reset_defaults" value="1" />
            </form>
            
            <div class="notice notice-warning">
                <p><strong><?php _e('Important Notice:', 'brazil-checkout-fields'); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <li><?php _e('After changing field names, new orders will use the new field names to save data', 'brazil-checkout-fields'); ?></li>
                    <li><?php _e('Existing order data will not be automatically migrated to new field names', 'brazil-checkout-fields'); ?></li>
                    <li><?php _e('It is recommended to test in a staging environment before using in production', 'brazil-checkout-fields'); ?></li>
                    <li><?php _e('Field names can only contain letters, numbers and underscores, and must start with a letter or underscore', 'brazil-checkout-fields'); ?></li>
                </ul>
            </div>
            
            <h2><?php _e('Data Migration Tool', 'brazil-checkout-fields'); ?></h2>
            <div class="notice notice-info">
                <p><?php _e('If you have changed field names, you can use the following tool to migrate existing order data to new fields:', 'brazil-checkout-fields'); ?></p>
            </div>
            
            <form method="post" action="" style="margin-top: 20px;">
                <?php wp_nonce_field('brazil_checkout_migration', 'brazil_migration_nonce'); ?>
                <input type="hidden" name="action" value="migrate_data" />
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php _e('Migration Source Fields', 'brazil-checkout-fields'); ?></th>
                            <td>
                                <label>
                                    <?php _e('Customer Type Source Field:', 'brazil-checkout-fields'); ?>
                                    <input type="text" name="source_customer_type" value="_brazil_customer_type" class="regular-text" />
                                </label><br><br>
                                <label>
                                    <?php _e('Document Source Field:', 'brazil-checkout-fields'); ?>
                                    <input type="text" name="source_document" value="_brazil_document" class="regular-text" />
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Migration Target Fields', 'brazil-checkout-fields'); ?></th>
                            <td>
                                <label>
                                    <?php _e('Customer Type Target Field:', 'brazil-checkout-fields'); ?>
                                    <input type="text" name="target_customer_type" value="<?php echo esc_attr($current_customer_type_field); ?>" class="regular-text" readonly />
                                </label><br><br>
                                <label>
                                    <?php _e('Document Target Field:', 'brazil-checkout-fields'); ?>
                                    <input type="text" name="target_document" value="<?php echo esc_attr($current_document_field); ?>" class="regular-text" readonly />
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button(__('Start Data Migration', 'brazil-checkout-fields'), 'secondary', 'migrate_submit'); ?>
            </form>
            
            <?php
            // 处理数据迁移
            if (isset($_POST['migrate_submit']) && check_admin_referer('brazil_checkout_migration', 'brazil_migration_nonce')) {
                $this->handle_data_migration();
            }
            
            // 处理客户类型值迁移
            if (isset($_POST['migrate_customer_types']) && check_admin_referer('brazil_customer_type_migration', 'brazil_customer_type_nonce')) {
                $this->handle_customer_type_migration();
            }
            ?>
            
            <h2><?php _e('🔄 Customer Type Value Migration Tool', 'brazil-checkout-fields'); ?></h2>
            <div class="notice notice-info">
                <p><strong><?php _e('Customer Type Value Migration Feature', 'brazil-checkout-fields'); ?></strong> - <?php _e('Use this tool to update existing order data when you have modified CPF or CNPJ customer type values.', 'brazil-checkout-fields'); ?></p>
                <p><strong><?php _e('Use Case:', 'brazil-checkout-fields'); ?></strong> <?php _e('For example, when changing "pessoa_fisica" to "individual", or "pessoa_juridica" to "company".', 'brazil-checkout-fields'); ?></p>
            </div>
            
            <form method="post" action="" style="margin-top: 20px;">
                <?php wp_nonce_field('brazil_customer_type_migration', 'brazil_customer_type_nonce'); ?>
                <input type="hidden" name="action" value="migrate_customer_types" />
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="old_cpf_value"><?php _e('Original CPF Customer Type Value', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="old_cpf_value" id="old_cpf_value" value="pessoa_fisica" class="regular-text" />
                                <p class="description"><?php _e('Old CPF customer type value to be replaced (e.g.: pessoa_fisica)', 'brazil-checkout-fields'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="new_cpf_value"><?php _e('New CPF Customer Type Value', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="new_cpf_value" id="new_cpf_value" value="<?php echo esc_attr($current_cpf_value); ?>" class="regular-text" />
                                <p class="description"><?php printf(__('New CPF customer type value (current configuration: %s)', 'brazil-checkout-fields'), esc_html($current_cpf_value)); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="old_cnpj_value"><?php _e('Original CNPJ Customer Type Value', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="old_cnpj_value" id="old_cnpj_value" value="pessoa_juridica" class="regular-text" />
                                <p class="description"><?php _e('Old CNPJ customer type value to be replaced (e.g.: pessoa_juridica)', 'brazil-checkout-fields'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="new_cnpj_value"><?php _e('New CNPJ Customer Type Value', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="new_cnpj_value" id="new_cnpj_value" value="<?php echo esc_attr($current_cnpj_value); ?>" class="regular-text" />
                                <p class="description"><?php printf(__('New CNPJ customer type value (current configuration: %s)', 'brazil-checkout-fields'), esc_html($current_cnpj_value)); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="migrate_all_orders"><?php _e('Migration Options', 'brazil-checkout-fields'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="migrate_all_orders" id="migrate_all_orders" value="1" />
                                    <?php _e('Migrate all historical orders (including orders using default values)', 'brazil-checkout-fields'); ?>
                                </label>
                                <p class="description"><?php _e('Check this to also migrate orders using default customer type values', 'brazil-checkout-fields'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="notice notice-warning">
                    <p><strong><?php _e('⚠️ Important Notice:', 'brazil-checkout-fields'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php _e('This operation will bulk update customer type values in orders', 'brazil-checkout-fields'); ?></li>
                        <li><?php _e('It is recommended to backup the database before execution', 'brazil-checkout-fields'); ?></li>
                        <li><?php _e('A detailed migration report will be displayed after completion', 'brazil-checkout-fields'); ?></li>
                        <li><?php _e('If unsure, please test in a staging environment first', 'brazil-checkout-fields'); ?></li>
                    </ul>
                </div>
                
                <div style="margin: 20px 0; padding: 15px; background: #f0f8ff; border-radius: 5px;">
                    <h4><?php _e('📊 Data Preview', 'brazil-checkout-fields'); ?></h4>
                    <p><?php _e('Click the button to view current customer type value distribution in the database:', 'brazil-checkout-fields'); ?></p>
                    <button type="button" id="preview-migration-data" class="button button-secondary"><?php _e('Preview Data', 'brazil-checkout-fields'); ?></button>
                    <div id="migration-data-preview" style="margin-top: 10px; display: none;"></div>
                </div>
                
                <?php submit_button(__('Start Customer Type Value Migration', 'brazil-checkout-fields'), 'primary', 'migrate_customer_types'); ?>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                $('#preview-migration-data').on('click', function() {
                    var button = $(this);
                    var preview = $('#migration-data-preview');
                    
                    button.prop('disabled', true).text('<?php _e('Loading...', 'brazil-checkout-fields'); ?>');
                    
                    // AJAX请求获取数据预览
                    $.post(ajaxurl, {
                        action: 'brazil_preview_migration_data',
                        nonce: '<?php echo wp_create_nonce("brazil_preview_migration"); ?>'
                    }, function(response) {
                        if (response.success) {
                            preview.html(response.data).show();
                        } else {
                            preview.html('<div class="notice notice-error"><p><?php _e('Unable to load data preview:', 'brazil-checkout-fields'); ?> ' + response.data + '</p></div>').show();
                        }
                    }).fail(function() {
                        preview.html('<div class="notice notice-error"><p><?php _e('Error occurred while loading data preview', 'brazil-checkout-fields'); ?></p></div>').show();
                    }).always(function() {
                        button.prop('disabled', false).text('<?php _e('Preview Data', 'brazil-checkout-fields'); ?>');
                    });
                });
            });
            </script>
            </form>
            
            <?php
            // 处理缓存清理
            if (isset($_POST['clear_cache']) && check_admin_referer('brazil_clear_cache', 'cache_nonce')) {
                delete_transient('brazil_cpf_cnpj_stats');
                delete_transient('brazil_cpf_cnpj_recent_orders');
                // 清理WooCommerce相关缓存
                if (function_exists('wc_delete_shop_order_transients')) {
                    wc_delete_shop_order_transients();
                }
                // 清理对象缓存
                wp_cache_flush();
                echo '<div class="notice notice-success"><p>✅ ' . __('Cache cleared, data will be reloaded. Page will refresh automatically...', 'brazil-checkout-fields') . '</p></div>';
                echo '<script>setTimeout(function(){ window.location.reload(); }, 2000);</script>';
            }
            ?>
            
            <h2><?php _e('📊 Recent Order Data', 'brazil-checkout-fields'); ?></h2>
            
            <!-- 缓存管理 -->
            <div style="margin: 15px 0; padding: 10px; background: #f0f8ff; border-left: 4px solid #0073aa;">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('brazil_clear_cache', 'cache_nonce'); ?>
                    <p><?php _e('If data is not displaying correctly, you can clear cache to force reload:', 'brazil-checkout-fields'); ?> 
                    <input type="submit" name="clear_cache" value="<?php esc_attr_e('Clear Cache', 'brazil-checkout-fields'); ?>" class="button button-secondary" />
                    </p>
                </form>
            </div>
            
            <!-- 数据统计 -->
            <div style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
                <?php
                $stats = $this->get_brazil_data_statistics();
                ?>
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; min-width: 180px; text-align: center;">
                    <h4 style="margin: 0 0 10px 0; opacity: 0.9;"><?php _e('🇧🇷 Total Orders', 'brazil-checkout-fields'); ?></h4>
                    <span style="font-size: 32px; font-weight: bold;"><?php echo $stats['total']; ?></span>
                    <p style="margin: 5px 0 0 0; opacity: 0.8; font-size: 12px;"><?php _e('All Brazil orders', 'brazil-checkout-fields'); ?></p>
                </div>
                <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 20px; border-radius: 8px; min-width: 180px; text-align: center;">
                    <h4 style="margin: 0 0 10px 0; opacity: 0.9;"><?php _e('👤 CPF Orders', 'brazil-checkout-fields'); ?></h4>
                    <span style="font-size: 32px; font-weight: bold;"><?php echo $stats['cpf']; ?></span>
                    <p style="margin: 5px 0 0 0; opacity: 0.8; font-size: 12px;"><?php _e('Individual customers', 'brazil-checkout-fields'); ?></p>
                </div>
                <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 20px; border-radius: 8px; min-width: 180px; text-align: center;">
                    <h4 style="margin: 0 0 10px 0; opacity: 0.9;"><?php _e('🏢 CNPJ Orders', 'brazil-checkout-fields'); ?></h4>
                    <span style="font-size: 32px; font-weight: bold;"><?php echo $stats['cnpj']; ?></span>
                    <p style="margin: 5px 0 0 0; opacity: 0.8; font-size: 12px;"><?php _e('Business customers', 'brazil-checkout-fields'); ?></p>
                </div>
                <div style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; padding: 20px; border-radius: 8px; min-width: 180px; text-align: center;">
                    <h4 style="margin: 0 0 10px 0;"><?php _e('⚙️ Current Field', 'brazil-checkout-fields'); ?></h4>
                    <span style="font-size: 32px; font-weight: bold;"><?php echo $stats['current_field']; ?></span>
                    <p style="margin: 5px 0 0 0; font-size: 12px;"><?php echo esc_html($stats['current_field_name']); ?></p>
                </div>
            </div>
            
            <!-- 字段配置信息 -->
            <div style="background: #f8f9fa; border-left: 4px solid #007cba; padding: 15px; margin: 20px 0;">
                <h4 style="margin-top: 0;"><?php _e('🔧 Current Field Configuration', 'brazil-checkout-fields'); ?></h4>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 8px; font-weight: bold; width: 200px;"><?php _e('Customer Type Field:', 'brazil-checkout-fields'); ?></td>
                        <td style="padding: 8px;"><code><?php echo esc_html($stats['customer_type_field_name']); ?></code></td>
                    </tr>
                    <tr style="background: #f1f1f1;">
                        <td style="padding: 8px; font-weight: bold;"><?php _e('Document Field:', 'brazil-checkout-fields'); ?></td>
                        <td style="padding: 8px;"><code><?php echo esc_html($stats['current_field_name']); ?></code></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold;"><?php _e('Order Storage Mode:', 'brazil-checkout-fields'); ?></td>
                        <td style="padding: 8px;">
                            <?php 
                            $storage_type = isset($stats['storage_type']) ? $stats['storage_type'] : 'Unknown';
                            $storage_color = ($storage_type === 'HPOS') ? '#28a745' : (($storage_type === 'Legacy') ? '#ffc107' : '#dc3545');
                            $storage_icon = ($storage_type === 'HPOS') ? '🚀' : (($storage_type === 'Legacy') ? '📦' : '❌');
                            ?>
                            <span style="color: <?php echo $storage_color; ?>; font-weight: bold;">
                                <?php echo $storage_icon; ?> <?php echo esc_html($storage_type); ?>
                            </span>
                            <?php if ($storage_type === 'HPOS'): ?>
                                <small style="color: #666; margin-left: 10px;"><?php _e('(High-Performance Order Storage)', 'brazil-checkout-fields'); ?></small>
                            <?php elseif ($storage_type === 'Legacy'): ?>
                                <small style="color: #666; margin-left: 10px;"><?php _e('(Legacy Post Storage)', 'brazil-checkout-fields'); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php $this->display_recent_orders_table(); ?>
            
            <!-- 调试信息 -->
            <?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
            <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
                <h3>🔍 <?php _e('Debug Information', 'brazil-checkout-fields'); ?></h3>
                <?php $this->display_debug_info(); ?>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px; padding: 10px; background: #e3f2fd; border-radius: 5px;">
                <p><strong>💡 <?php _e('Tip:', 'brazil-checkout-fields'); ?></strong> <?php _e('If data display is incorrect, please click', 'brazil-checkout-fields'); ?> <a href="<?php echo admin_url('admin.php?page=brazil-checkout-fields&debug=1'); ?>"><?php _e('here to view debug information', 'brazil-checkout-fields'); ?></a></p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Field name validation
            $('#customer_type_field, #document_field').on('input', function() {
                var value = $(this).val();
                var isValid = /^[a-zA-Z_][a-zA-Z0-9_]*$/.test(value);
                
                if (value && !isValid) {
                    $(this).css('border-color', '#dc3232');
                    if (!$(this).next('.error-message').length) {
                        $(this).after('<span class="error-message" style="color: #dc3232; font-size: 12px; display: block;"><?php echo esc_js(__('Invalid field name format', 'brazil-checkout-fields')); ?></span>');
                    }
                } else {
                    $(this).css('border-color', '');
                    $(this).next('.error-message').remove();
                }
            });
            
            // 表单提交验证
            $('form').on('submit', function(e) {
                if ($(this).attr('id') === 'reset-form') return; // 跳过重置表单验证
                
                var hasError = false;
                $('#customer_type_field, #document_field').each(function() {
                    var value = $(this).val();
                    if (value && !/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(value)) {
                        hasError = true;
                        $(this).focus();
                        return false;
                    }
                });
                
                if (hasError) {
                    e.preventDefault();
                    alert('<?php echo esc_js(__('Please check the field name format. Only letters, numbers and underscores are allowed, and must start with a letter or underscore.', 'brazil-checkout-fields')); ?>');
                }
            });
        });
        
        // 重置为默认值
        function resetToDefaults() {
            if (confirm('<?php echo esc_js(__('Are you sure you want to reset to default configuration?', 'brazil-checkout-fields')); ?>\n\n<?php echo esc_js(__('Customer Type Field: _brazil_customer_type', 'brazil-checkout-fields')); ?>\n<?php echo esc_js(__('Document Field: _brazil_document', 'brazil-checkout-fields')); ?>\n<?php echo esc_js(__('CPF Customer Type Value: pessoa_fisica', 'brazil-checkout-fields')); ?>\n<?php echo esc_js(__('CNPJ Customer Type Value: pessoa_juridica', 'brazil-checkout-fields')); ?>\n\n<?php echo esc_js(__('This operation will take effect immediately.', 'brazil-checkout-fields')); ?>')) {
                document.getElementById('reset-form').submit();
            }
        }
        
        // 预览字段名变化
        function previewFieldNames() {
            var customerTypeField = document.getElementById('customer_type_field').value || '_brazil_customer_type';
            var documentField = document.getElementById('document_field').value || '_brazil_document';
            
            alert('<?php echo esc_js(__('Field Name Preview:', 'brazil-checkout-fields')); ?>\n\n<?php echo esc_js(__('Customer Type Field:', 'brazil-checkout-fields')); ?> ' + customerTypeField + '\n<?php echo esc_js(__('Document Field:', 'brazil-checkout-fields')); ?> ' + documentField);
        }
        </script>
        
        <style>
        .form-table th {
            width: 200px;
        }
        .regular-text {
            width: 300px;
        }
        .notice ul {
            margin: 10px 0;
        }
        .notice ul li {
            margin: 5px 0;
        }
        </style>
        <?php
    }
    
    /**
     * 处理数据迁移
     */
    private function handle_data_migration() {
        $source_customer_type = sanitize_text_field($_POST['source_customer_type']);
        $source_document = sanitize_text_field($_POST['source_document']);
        $target_customer_type = sanitize_text_field($_POST['target_customer_type']);
        $target_document = sanitize_text_field($_POST['target_document']);
        
        // 验证字段名
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $source_customer_type) ||
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $source_document) ||
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $target_customer_type) ||
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $target_document)) {
            add_settings_error('brazil_checkout_messages', 'invalid_migration_fields', __('Migration field name format is invalid.', 'brazil-checkout-fields'), 'error');
            return;
        }
        
        // 查找需要迁移的订单
        $orders = wc_get_orders(array(
            'limit' => -1,
            'meta_query' => array(
                array(
                    'key' => $source_document,
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        $migrated_count = 0;
        $error_count = 0;
        
        foreach ($orders as $order) {
            try {
                $customer_type_value = $order->get_meta($source_customer_type);
                $document_value = $order->get_meta($source_document);
                
                if ($document_value) {
                    // 更新到新字段
                    $order->update_meta_data($target_document, $document_value);
                    if ($customer_type_value) {
                        $order->update_meta_data($target_customer_type, $customer_type_value);
                    }
                    $order->save();
                    $migrated_count++;
                }
            } catch (Exception $e) {
                $error_count++;
                error_log('Brazil Checkout Migration Error: ' . $e->getMessage());
            }
        }
        
        if ($migrated_count > 0) {
            add_settings_error('brazil_checkout_messages', 'migration_success', 
                sprintf(__('Data migration completed! Successfully migrated %d orders.', 'brazil-checkout-fields'), $migrated_count), 'updated');
        }
        
        if ($error_count > 0) {
            add_settings_error('brazil_checkout_messages', 'migration_errors', 
                sprintf(__('%d orders had errors during migration.', 'brazil-checkout-fields'), $error_count), 'error');
        }
        
        if ($migrated_count === 0 && $error_count === 0) {
            add_settings_error('brazil_checkout_messages', 'no_data_to_migrate', 
                __('No data found to migrate.', 'brazil-checkout-fields'), 'notice-info');
        }
    }
    
    /**
     * 处理客户类型值迁移
     */
    private function handle_customer_type_migration() {
        $old_cpf_value = sanitize_text_field($_POST['old_cpf_value']);
        $new_cpf_value = sanitize_text_field($_POST['new_cpf_value']);
        $old_cnpj_value = sanitize_text_field($_POST['old_cnpj_value']);
        $new_cnpj_value = sanitize_text_field($_POST['new_cnpj_value']);
        $migrate_all = isset($_POST['migrate_all_orders']) && $_POST['migrate_all_orders'] === '1';
        
        // 验证输入
        if (empty($old_cpf_value) || empty($new_cpf_value) || empty($old_cnpj_value) || empty($new_cnpj_value)) {
            add_settings_error('brazil_checkout_messages', 'empty_migration_values', 
                __('Customer type values cannot be empty.', 'brazil-checkout-fields'), 'error');
            return;
        }
        
        $customer_type_field = BRAZIL_CUSTOMER_TYPE_FIELD;
        $document_field = BRAZIL_DOCUMENT_FIELD;
        
        // 查找需要迁移的订单
        $migrated_count = 0;
        $error_count = 0;
        $cpf_migrated = 0;
        $cnpj_migrated = 0;
        $report = array();
        
        try {
            // 设置更长的执行时间
            set_time_limit(300);
            
            // 检测存储模式
            $hpos_enabled = $this->detect_hpos_mode();
            
            if ($hpos_enabled) {
                $result = $this->migrate_customer_types_hpos($old_cpf_value, $new_cpf_value, $old_cnpj_value, $new_cnpj_value, $migrate_all);
            } else {
                $result = $this->migrate_customer_types_legacy($old_cpf_value, $new_cpf_value, $old_cnpj_value, $new_cnpj_value, $migrate_all);
            }
            
            $migrated_count = $result['total'];
            $cpf_migrated = $result['cpf'];
            $cnpj_migrated = $result['cnpj'];
            $error_count = $result['errors'];
            $report = $result['report'];
            
        } catch (Exception $e) {
            error_log('Brazil Checkout Customer Type Migration Error: ' . $e->getMessage());
            add_settings_error('brazil_checkout_messages', 'migration_exception', 
                __('Error occurred during migration: ', 'brazil-checkout-fields') . $e->getMessage(), 'error');
            return;
        }
        
        // 显示迁移结果
        if ($migrated_count > 0) {
            $message = sprintf(
                __('Customer type value migration completed! Total migrated %d orders (CPF: %d, CNPJ: %d).', 'brazil-checkout-fields'),
                $migrated_count,
                $cpf_migrated,
                $cnpj_migrated
            );
            add_settings_error('brazil_checkout_messages', 'migration_success', $message, 'updated');
            
            // 详细报告
            if (!empty($report)) {
                $report_html = '<div style="margin-top: 10px; padding: 10px; background: #f0f8ff; border-radius: 4px;">';
                $report_html .= '<h4>' . __('Migration Details:', 'brazil-checkout-fields') . '</h4>';
                $report_html .= '<ul>';
                foreach ($report as $item) {
                    $report_html .= '<li>' . esc_html($item) . '</li>';
                }
                $report_html .= '</ul></div>';
                
                add_settings_error('brazil_checkout_messages', 'migration_report', $report_html, 'updated');
            }
        }
        
        if ($error_count > 0) {
            add_settings_error('brazil_checkout_messages', 'migration_errors', 
                sprintf(__('%d orders had errors during migration. Please check error logs for details.', 'brazil-checkout-fields'), $error_count), 'error');
        }
        
        if ($migrated_count === 0 && $error_count === 0) {
            add_settings_error('brazil_checkout_messages', 'no_data_to_migrate', 
                __('No customer type value data found to migrate. All orders may already be using new customer type values.', 'brazil-checkout-fields'), 'notice-info');
        }
        
        // 清理缓存
        delete_transient('brazil_cpf_cnpj_stats');
        delete_transient('brazil_cpf_cnpj_recent_orders');
        
        // 清理WooCommerce缓存
        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients();
        }
    }
    
    /**
     * HPOS模式下的客户类型值迁移
     */
    private function migrate_customer_types_hpos($old_cpf, $new_cpf, $old_cnpj, $new_cnpj, $migrate_all) {
        global $wpdb;
        
        $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
        $customer_type_field = BRAZIL_CUSTOMER_TYPE_FIELD;
        $document_field = BRAZIL_DOCUMENT_FIELD;
        
        $migrated_count = 0;
        $cpf_migrated = 0;
        $cnpj_migrated = 0;
        $error_count = 0;
        $report = array();
        
        // 迁移 CPF 客户类型值
        $cpf_update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$orders_meta_table} 
                 SET meta_value = %s 
                 WHERE meta_key = %s AND meta_value = %s",
                $new_cpf,
                $customer_type_field,
                $old_cpf
            )
        );
        
        if ($cpf_update_result !== false) {
            $cpf_migrated = $cpf_update_result;
            $report[] = sprintf(__("CPF customer type value: updated from '%s' to '%s' - %d orders", 'brazil-checkout-fields'), $old_cpf, $new_cpf, $cpf_update_result);
        } else {
            $error_count++;
            error_log("Brazil Checkout: Failed to update CPF customer type values");
        }
        
        // 迁移 CNPJ 客户类型值
        $cnpj_update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$orders_meta_table} 
                 SET meta_value = %s 
                 WHERE meta_key = %s AND meta_value = %s",
                $new_cnpj,
                $customer_type_field,
                $old_cnpj
            )
        );
        
        if ($cnpj_update_result !== false) {
            $cnpj_migrated = $cnpj_update_result;
            $report[] = sprintf(__("CNPJ customer type value: updated from '%s' to '%s' - %d orders", 'brazil-checkout-fields'), $old_cnpj, $new_cnpj, $cnpj_update_result);
        } else {
            $error_count++;
            error_log("Brazil Checkout: Failed to update CNPJ customer type values");
        }
        
        $migrated_count = $cpf_migrated + $cnpj_migrated;
        
        // 如果选择迁移所有订单，还需要处理旧格式的字段
        if ($migrate_all) {
            $legacy_result = $this->migrate_legacy_customer_types_hpos($old_cpf, $new_cpf, $old_cnpj, $new_cnpj);
            $migrated_count += $legacy_result['total'];
            $report = array_merge($report, $legacy_result['report']);
        }
        
        return array(
            'total' => $migrated_count,
            'cpf' => $cpf_migrated,
            'cnpj' => $cnpj_migrated,
            'errors' => $error_count,
            'report' => $report
        );
    }
    
    /**
     * 传统模式下的客户类型值迁移
     */
    private function migrate_customer_types_legacy($old_cpf, $new_cpf, $old_cnpj, $new_cnpj, $migrate_all) {
        global $wpdb;
        
        $customer_type_field = BRAZIL_CUSTOMER_TYPE_FIELD;
        $migrated_count = 0;
        $cpf_migrated = 0;
        $cnpj_migrated = 0;
        $error_count = 0;
        $report = array();
        
        // 迁移 CPF 客户类型值
        $cpf_update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} 
                 SET meta_value = %s 
                 WHERE meta_key = %s AND meta_value = %s",
                $new_cpf,
                $customer_type_field,
                $old_cpf
            )
        );
        
        if ($cpf_update_result !== false) {
            $cpf_migrated = $cpf_update_result;
            $report[] = sprintf(__("CPF customer type value: updated from '%s' to '%s' - %d orders", 'brazil-checkout-fields'), $old_cpf, $new_cpf, $cpf_update_result);
        } else {
            $error_count++;
            error_log("Brazil Checkout: Failed to update CPF customer type values");
        }
        
        // 迁移 CNPJ 客户类型值
        $cnpj_update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} 
                 SET meta_value = %s 
                 WHERE meta_key = %s AND meta_value = %s",
                $new_cnpj,
                $customer_type_field,
                $old_cnpj
            )
        );
        
        if ($cnpj_update_result !== false) {
            $cnpj_migrated = $cnpj_update_result;
            $report[] = sprintf(__("CNPJ customer type value: updated from '%s' to '%s' - %d orders", 'brazil-checkout-fields'), $old_cnpj, $new_cnpj, $cnpj_update_result);
        } else {
            $error_count++;
            error_log("Brazil Checkout: Failed to update CNPJ customer type values");
        }
        
        $migrated_count = $cpf_migrated + $cnpj_migrated;
        
        // 如果选择迁移所有订单，还需要处理旧格式的字段
        if ($migrate_all) {
            $legacy_result = $this->migrate_legacy_customer_types_legacy($old_cpf, $new_cpf, $old_cnpj, $new_cnpj);
            $migrated_count += $legacy_result['total'];
            $report = array_merge($report, $legacy_result['report']);
        }
        
        return array(
            'total' => $migrated_count,
            'cpf' => $cpf_migrated,
            'cnpj' => $cnpj_migrated,
            'errors' => $error_count,
            'report' => $report
        );
    }
    
    /**
     * HPOS模式下迁移旧格式的客户类型字段
     */
    private function migrate_legacy_customer_types_hpos($old_cpf, $new_cpf, $old_cnpj, $new_cnpj) {
        global $wpdb;
        
        $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
        $migrated_count = 0;
        $report = array();
        
        // 迁移旧格式的 _customer_type 字段
        $legacy_cpf_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$orders_meta_table} 
                 SET meta_value = %s 
                 WHERE meta_key = '_customer_type' AND meta_value = %s",
                $new_cpf,
                $old_cpf
            )
        );
        
        if ($legacy_cpf_result !== false && $legacy_cpf_result > 0) {
            $migrated_count += $legacy_cpf_result;
            $report[] = "旧格式 CPF 客户类型：从 '{$old_cpf}' 更新为 '{$new_cpf}' - {$legacy_cpf_result} 个订单";
        }
        
        $legacy_cnpj_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$orders_meta_table} 
                 SET meta_value = %s 
                 WHERE meta_key = '_customer_type' AND meta_value = %s",
                $new_cnpj,
                $old_cnpj
            )
        );
        
        if ($legacy_cnpj_result !== false && $legacy_cnpj_result > 0) {
            $migrated_count += $legacy_cnpj_result;
            $report[] = "旧格式 CNPJ 客户类型：从 '{$old_cnpj}' 更新为 '{$new_cnpj}' - {$legacy_cnpj_result} 个订单";
        }
        
        return array(
            'total' => $migrated_count,
            'report' => $report
        );
    }
    
    /**
     * 传统模式下迁移旧格式的客户类型字段
     */
    private function migrate_legacy_customer_types_legacy($old_cpf, $new_cpf, $old_cnpj, $new_cnpj) {
        global $wpdb;
        
        $migrated_count = 0;
        $report = array();
        
        // 迁移旧格式的 _customer_type 字段
        $legacy_cpf_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} 
                 SET meta_value = %s 
                 WHERE meta_key = '_customer_type' AND meta_value = %s",
                $new_cpf,
                $old_cpf
            )
        );
        
        if ($legacy_cpf_result !== false && $legacy_cpf_result > 0) {
            $migrated_count += $legacy_cpf_result;
            $report[] = "旧格式 CPF 客户类型：从 '{$old_cpf}' 更新为 '{$new_cpf}' - {$legacy_cpf_result} 个订单";
        }
        
        $legacy_cnpj_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} 
                 SET meta_value = %s 
                 WHERE meta_key = '_customer_type' AND meta_value = %s",
                $new_cnpj,
                $old_cnpj
            )
        );
        
        if ($legacy_cnpj_result !== false && $legacy_cnpj_result > 0) {
            $migrated_count += $legacy_cnpj_result;
            $report[] = "旧格式 CNPJ 客户类型：从 '{$old_cnpj}' 更新为 '{$new_cnpj}' - {$legacy_cnpj_result} 个订单";
        }
        
        return array(
            'total' => $migrated_count,
            'report' => $report
        );
    }
    
    /**
     * 获取巴西数据统计
     */
    private function get_brazil_data_statistics() {
        // 强制清理缓存以测试新的HPOS检测
        if (isset($_GET['force_refresh'])) {
            delete_transient('brazil_cpf_cnpj_stats');
        }
        
        // 检查缓存
        $cache_key = 'brazil_cpf_cnpj_stats';
        $cached_stats = get_transient($cache_key);
        
        if ($cached_stats !== false && !isset($_GET['force_refresh'])) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        // 获取当前配置的字段名
        $customer_type_field = BRAZIL_CUSTOMER_TYPE_FIELD;
        $document_field = BRAZIL_DOCUMENT_FIELD;
        
        try {
            // 添加超时保护
            set_time_limit(30);
            
            // 简化的HPOS检测 - 检查WooCommerce设置和表
            $hpos_enabled = $this->detect_hpos_mode();
            
            if ($hpos_enabled) {
                // 使用HPOS表查询
                $stats = $this->get_hpos_statistics($customer_type_field, $document_field);
            } else {
                // 使用传统的postmeta表查询
                $stats = $this->get_legacy_statistics($customer_type_field, $document_field);
            }
            
            // 缓存结果5分钟
            set_transient($cache_key, $stats, 300);
            
            return $stats;
            
        } catch (Exception $e) {
            error_log('Brazil CPF/CNPJ: Statistics error: ' . $e->getMessage());
            
            // 返回默认值
            return array(
                'total' => 0,
                'cpf' => 0,
                'cnpj' => 0,
                'current_field' => 0,
                'current_field_name' => $document_field,
                'customer_type_field_name' => $customer_type_field,
                'storage_type' => 'error'
            );
        }
    }
    
    /**
     * 检测HPOS模式的统一方法 - 通过比较数据量来判断
     */
    private function detect_hpos_mode() {
        global $wpdb;
        
        $hpos_orders_count = 0;
        $legacy_orders_count = 0;
        
        try {
            // 检查HPOS表（wc_orders）中的订单数量
            $orders_table = $wpdb->prefix . 'wc_orders';
            $orders_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table)) === $orders_table;
            
            if ($orders_exists) {
                $hpos_orders_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$orders_table}"));
            }
            
            // 检查传统表（wp_posts）中shop_order类型的数量
            $legacy_orders_count = intval($wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->posts} 
                WHERE post_type = 'shop_order'
            "));
            
            // 比较两种存储模式的数据量
            // 如果HPOS表有更多数据，说明使用HPOS模式
            // 如果传统表有更多数据，说明使用传统模式
            // 如果两者都有数据但HPOS更多，优先选择HPOS
            
            $is_hpos = false;
            $detection_reason = '';
            
            if ($hpos_orders_count > 0 && $legacy_orders_count > 0) {
                // 两种表都有数据，选择数据更多的
                if ($hpos_orders_count >= $legacy_orders_count) {
                    $is_hpos = true;
                    $detection_reason = "HPOS表有 {$hpos_orders_count} 个订单，传统表有 {$legacy_orders_count} 个订单，选择HPOS";
                } else {
                    $is_hpos = false;
                    $detection_reason = "传统表有 {$legacy_orders_count} 个订单，HPOS表有 {$hpos_orders_count} 个订单，选择传统模式";
                }
            } elseif ($hpos_orders_count > 0) {
                // 只有HPOS表有数据
                $is_hpos = true;
                $detection_reason = "仅HPOS表有数据 ({$hpos_orders_count} 个订单)";
            } elseif ($legacy_orders_count > 0) {
                // 只有传统表有数据
                $is_hpos = false;
                $detection_reason = "仅传统表有数据 ({$legacy_orders_count} 个订单)";
            } else {
                // 两种表都没有数据，检查WooCommerce设置
                $hpos_setting = get_option('woocommerce_custom_orders_table_enabled', 'no');
                if ($hpos_setting === 'yes') {
                    $is_hpos = true;
                    $detection_reason = "无订单数据，根据WC设置选择HPOS";
                } else {
                    $is_hpos = false;
                    $detection_reason = "无订单数据，默认选择传统模式";
                }
            }
            
            // 将检测结果保存以供调试使用
            $this->hpos_detection_info = array(
                'hpos_orders_count' => $hpos_orders_count,
                'legacy_orders_count' => $legacy_orders_count,
                'is_hpos' => $is_hpos,
                'detection_reason' => $detection_reason,
                'hpos_table_exists' => $orders_exists
            );
            
            return $is_hpos;
            
        } catch (Exception $e) {
            // 如果检测失败，默认使用传统模式
            $this->hpos_detection_info = array(
                'hpos_orders_count' => 0,
                'legacy_orders_count' => 0,
                'is_hpos' => false,
                'detection_reason' => '检测失败，默认传统模式: ' . $e->getMessage(),
                'hpos_table_exists' => false
            );
            
            return false;
        }
    }
    
    /**
     * 获取HPOS统计数据
     */
    private function get_hpos_statistics($customer_type_field, $document_field) {
        global $wpdb;
        
        // HPOS表名
        $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
        
        // 获取当前配置的客户类型值
        $cpf_customer_type_value = $this->get_cpf_customer_type_value();
        $cnpj_customer_type_value = $this->get_cnpj_customer_type_value();
        
        // 统计当前字段的订单
        $current_field_orders = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT order_id) 
            FROM {$orders_meta_table} 
            WHERE meta_key = %s AND meta_value != ''
            LIMIT 1000
        ", $document_field));
        
        // 统计所有巴西字段的订单
        $all_brazil_orders = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT order_id) 
            FROM {$orders_meta_table} 
            WHERE (
                meta_key = %s 
                OR meta_key = '_brazil_document' 
                OR meta_key = '_billing_cpf' 
                OR meta_key = '_billing_cnpj'
            ) 
            AND meta_value != ''
            LIMIT 1000
        ", $document_field));
        
        // 统计CPF订单 - 使用当前配置的客户类型值
        $cpf_orders = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT order_id) 
            FROM {$orders_meta_table} 
            WHERE (
                (meta_key = %s AND meta_value = %s)
                OR (meta_key = '_customer_type' AND meta_value = %s)
                OR (meta_key = '_billing_cpf' AND meta_value != '')
            )
            LIMIT 1000
        ", $customer_type_field, $cpf_customer_type_value, $cpf_customer_type_value));
        
        // 统计CNPJ订单 - 使用当前配置的客户类型值
        $cnpj_orders = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT order_id) 
            FROM {$orders_meta_table} 
            WHERE (
                (meta_key = %s AND meta_value = %s)
                OR (meta_key = '_customer_type' AND meta_value = %s)
                OR (meta_key = '_billing_cnpj' AND meta_value != '')
            )
            LIMIT 1000
        ", $customer_type_field, $cnpj_customer_type_value, $cnpj_customer_type_value));
        
        return array(
            'total' => intval($all_brazil_orders),
            'cpf' => intval($cpf_orders),
            'cnpj' => intval($cnpj_orders),
            'current_field' => intval($current_field_orders),
            'current_field_name' => $document_field,
            'customer_type_field_name' => $customer_type_field,
            'storage_type' => 'HPOS'
        );
    }
    
    /**
     * 获取传统postmeta统计数据
     */
    private function get_legacy_statistics($customer_type_field, $document_field) {
        global $wpdb;
        
        // 获取当前配置的客户类型值
        $cpf_customer_type_value = $this->get_cpf_customer_type_value();
        $cnpj_customer_type_value = $this->get_cnpj_customer_type_value();
        
        // 统计当前字段的订单
        $current_field_orders = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = %s AND meta_value != ''
            LIMIT 1000
        ", $document_field));
        
        // 统计所有巴西字段的订单
        $all_brazil_orders = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE (
                meta_key = %s 
                OR meta_key = '_brazil_document' 
                OR meta_key = '_billing_cpf' 
                OR meta_key = '_billing_cnpj'
            ) 
            AND meta_value != ''
            LIMIT 1000
        ", $document_field));
        
        // 统计CPF订单 - 使用当前配置的客户类型值
        $cpf_orders = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE (
                (meta_key = %s AND meta_value = %s)
                OR (meta_key = '_customer_type' AND meta_value = %s)
                OR (meta_key = '_billing_cpf' AND meta_value != '')
            )
            LIMIT 1000
        ", $customer_type_field, $cpf_customer_type_value, $cpf_customer_type_value));
        
        // 统计CNPJ订单 - 使用当前配置的客户类型值
        $cnpj_orders = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE (
                (meta_key = %s AND meta_value = %s)
                OR (meta_key = '_customer_type' AND meta_value = %s)
                OR (meta_key = '_billing_cnpj' AND meta_value != '')
            )
            LIMIT 1000
        ", $customer_type_field, $cnpj_customer_type_value, $cnpj_customer_type_value));
        
        return array(
            'total' => intval($all_brazil_orders),
            'cpf' => intval($cpf_orders),
            'cnpj' => intval($cnpj_orders),
            'current_field' => intval($current_field_orders),
            'current_field_name' => $document_field,
            'customer_type_field_name' => $customer_type_field,
            'storage_type' => 'Legacy'
        );
    }
    
    /**
     * 显示最近订单表格
     */
    private function display_recent_orders_table() {
        // 检查缓存
        $cache_key = 'brazil_cpf_cnpj_recent_orders';
        $cached_orders = get_transient($cache_key);
        
        if ($cached_orders !== false) {
            echo $cached_orders;
            return;
        }
        
        ob_start(); // 开始输出缓冲
        
        try {
            set_time_limit(30);
            
            // 使用简化的查询来获取订单
            $recent_orders = wc_get_orders(array(
                'limit' => 10,
                'status' => array('completed', 'processing', 'on-hold', 'pending'),
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key' => BRAZIL_DOCUMENT_FIELD,
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key' => '_brazil_document',
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key' => '_billing_cpf',
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key' => '_billing_cnpj',
                        'compare' => 'EXISTS'
                    )
                ),
                'orderby' => 'date',
                'order' => 'DESC'
            ));
            
            if ($recent_orders && count($recent_orders) > 0) {
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr><th>' . __('Order ID', 'brazil-checkout-fields') . '</th><th>' . __('Customer Type', 'brazil-checkout-fields') . '</th><th>' . __('Document Number', 'brazil-checkout-fields') . '</th><th>' . __('Order Status', 'brazil-checkout-fields') . '</th><th>' . __('Creation Date', 'brazil-checkout-fields') . '</th></tr></thead>';
                echo '<tbody>';
                
                $display_count = 0;
                foreach ($recent_orders as $order) {
                    if ($display_count >= 10) break; // 限制显示数量
                    
                    $customer_type = '';
                    $document = '';
                    $field_source = '';
                    
                    // 简化的字段检查逻辑
                    $current_document = $order->get_meta(BRAZIL_DOCUMENT_FIELD);
                    if (!empty($current_document)) {
                        $customer_type = $order->get_meta(BRAZIL_CUSTOMER_TYPE_FIELD);
                        $document = $current_document;
                        $field_source = __('Current Field', 'brazil-checkout-fields');
                    } else {
                        // 检查主要的旧字段
                        $legacy_document = $order->get_meta('_brazil_document');
                        if (!empty($legacy_document)) {
                            $customer_type = $order->get_meta('_brazil_customer_type');
                            $document = $legacy_document;
                            $field_source = __('Legacy Field', 'brazil-checkout-fields');
                        } else {
                            // 检查最基本的兼容字段
                            $cpf = $order->get_meta('_billing_cpf');
                            $cnpj = $order->get_meta('_billing_cnpj');
                            if ($cpf) {
                                $document = $cpf;
                                $customer_type = $this->get_cpf_customer_type_value();
                                $field_source = __('CPF Field', 'brazil-checkout-fields');
                            } elseif ($cnpj) {
                                $document = $cnpj;
                                $customer_type = $this->get_cnpj_customer_type_value();
                                $field_source = __('CNPJ Field', 'brazil-checkout-fields');
                            }
                        }
                    }
                    
                    // 显示订单信息
                    if (!empty($document)) {
                        // 简化的类型显示
                        $display_type = '';
                        if ($this->is_cpf_customer_type($customer_type)) {
                            $display_type = '👤 ' . __('Pessoa Física', 'brazil-checkout-fields');
                        } elseif ($this->is_cnpj_customer_type($customer_type)) {
                            $display_type = '🏢 ' . __('Pessoa Jurídica', 'brazil-checkout-fields');
                        } else {
                            // 根据文档长度推断Pessoa Física
                            $clean_doc = preg_replace('/[^0-9]/', '', $document);
                            $display_type = (strlen($clean_doc) === 11) ? '👤 ' . __('Pessoa Física', 'brazil-checkout-fields') : '🏢 ' . __('Pessoa Jurídica', 'brazil-checkout-fields');
                        }
                        
                        echo '<tr>';
                        echo '<td><a href="' . admin_url('post.php?post=' . $order->get_id() . '&action=edit') . '">#' . $order->get_id() . '</a></td>';
                        echo '<td>' . esc_html($display_type) . '</td>';
                        echo '<td><code>' . esc_html(substr($document, 0, 20)) . '</code></td>';
                        echo '<td>' . wc_get_order_status_name($order->get_status()) . '</td>';
                        echo '<td>' . $order->get_date_created()->format('Y-m-d H:i') . '</td>';
                        echo '</tr>';
                        
                        $display_count++;
                    }
                }
                echo '</tbody></table>';
                
                // 简化的配置信息
                echo '<div style="margin-top: 15px; padding: 10px; background: #f1f1f1; border-radius: 5px;">';
                echo '<p><strong>' . __('Current Field:', 'brazil-checkout-fields') . '</strong> <code>' . BRAZIL_DOCUMENT_FIELD . '</code></p>';
                echo '</div>';
                
            } else {
                echo '<div class="notice notice-warning"><p>❌ ' . __('No orders found with Brazil field data.', 'brazil-checkout-fields') . '</p></div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . __('Error loading order data, please try again later.', 'brazil-checkout-fields') . '</p></div>';
            error_log('Brazil CPF/CNPJ: Recent orders error: ' . $e->getMessage());
        }
        
        $output = ob_get_contents();
        ob_end_clean();
        
        // 缓存输出5分钟
        set_transient($cache_key, $output, 300);
        
        echo $output;
    }
    
    /**
     * 显示调试信息
     */
    private function display_debug_info() {
        // 只有管理员且明确请求调试时才显示
        if (!current_user_can('manage_options')) {
            echo '<p>' . __('Administrator permissions required to view debug information.', 'brazil-checkout-fields') . '</p>';
            return;
        }
        
        global $wpdb;
        
        try {
            set_time_limit(30);
            
            // 检查存储模式 - 使用统一的检测方法
            $hpos_enabled = $this->detect_hpos_mode();
            
            echo '<h4>' . __('WooCommerce Storage Mode Detection:', 'brazil-checkout-fields') . '</h4>';
            echo '<div style="background: ' . ($hpos_enabled ? '#d4edda' : '#fff3cd') . '; padding: 10px; border-radius: 5px; margin: 10px 0;">';
            
            // 显示详细的检测过程
            echo '<h5>' . __('Detection Process:', 'brazil-checkout-fields') . '</h5>';
            echo '<ul>';
            
            // 检测WooCommerce设置
            $hpos_setting = get_option('woocommerce_custom_orders_table_enabled', 'no');
            echo '<li><strong>' . __('WC Settings Detection:', 'brazil-checkout-fields') . '</strong> woocommerce_custom_orders_table_enabled = ' . $hpos_setting . '</li>';
            
            // 检测数据库表
            $orders_table = $wpdb->prefix . 'wc_orders';
            $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
            $orders_exists = $wpdb->get_var("SHOW TABLES LIKE '{$orders_table}'") === $orders_table;
            $meta_exists = $wpdb->get_var("SHOW TABLES LIKE '{$orders_meta_table}'") === $orders_meta_table;
            
            echo '<li><strong>' . __('Database Tables Detection:', 'brazil-checkout-fields') . '</strong></li>';
            echo '<ul>';
            echo '<li>' . $orders_table . ' ' . __('table exists:', 'brazil-checkout-fields') . ' ' . ($orders_exists ? '✅ ' . __('Yes', 'brazil-checkout-fields') : '❌ ' . __('No', 'brazil-checkout-fields')) . '</li>';
            echo '<li>' . $orders_meta_table . ' ' . __('table exists:', 'brazil-checkout-fields') . ' ' . ($meta_exists ? '✅ ' . __('Yes', 'brazil-checkout-fields') : '❌ ' . __('No', 'brazil-checkout-fields')) . '</li>';
            
            if ($orders_exists) {
                $order_count = $wpdb->get_var("SELECT COUNT(*) FROM {$orders_table}");
                echo '<li>' . $orders_table . ' ' . __('record count:', 'brazil-checkout-fields') . ' ' . $order_count . '</li>';
            }
            
            if ($meta_exists) {
                $meta_count = $wpdb->get_var("SELECT COUNT(*) FROM {$orders_meta_table}");
                echo '<li>' . $orders_meta_table . ' ' . __('record count:', 'brazil-checkout-fields') . ' ' . $meta_count . '</li>';
            }
            echo '</ul>';
            
            // 检测API可用性
            $orderutil_available = class_exists('Automattic\WooCommerce\Utilities\OrderUtil');
            echo '<li><strong>' . __('WC OrderUtil Class:', 'brazil-checkout-fields') . '</strong> ' . ($orderutil_available ? '✅ ' . __('Available', 'brazil-checkout-fields') : '❌ ' . __('Not Available', 'brazil-checkout-fields')) . '</li>';
            
            if ($orderutil_available) {
                try {
                    $api_result = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
                    echo '<li><strong>' . __('OrderUtil Detection Result:', 'brazil-checkout-fields') . '</strong> ' . ($api_result ? '✅ ' . __('HPOS Enabled', 'brazil-checkout-fields') : '❌ ' . __('HPOS Not Enabled', 'brazil-checkout-fields')) . '</li>';
                } catch (Exception $e) {
                    echo '<li><strong>' . __('OrderUtil Detection Error:', 'brazil-checkout-fields') . '</strong> ' . esc_html($e->getMessage()) . '</li>';
                }
            }
            
            echo '</ul>';
            
            if ($hpos_enabled) {
                echo '<p><strong>🚀 ' . __('Final Result: High-Performance Order Storage (HPOS)', 'brazil-checkout-fields') . '</strong></p>';
                echo '<p>' . __('Data stored in:', 'brazil-checkout-fields') . ' <code>wp_wc_orders</code> ' . __('and', 'brazil-checkout-fields') . ' <code>wp_wc_orders_meta</code> ' . __('tables', 'brazil-checkout-fields') . '</p>';
            } else {
                echo '<p><strong>📦 ' . __('Final Result: Legacy Post Storage', 'brazil-checkout-fields') . '</strong></p>';
                echo '<p>' . __('Data stored in:', 'brazil-checkout-fields') . ' <code>wp_posts</code> ' . __('and', 'brazil-checkout-fields') . ' <code>wp_postmeta</code> ' . __('tables', 'brazil-checkout-fields') . '</p>';
            }
            echo '</div>';
            
            if ($hpos_enabled) {
                // 检查HPOS表中的数据
                echo '<h4>' . __('Related Fields in HPOS Tables (limited to top 20):', 'brazil-checkout-fields') . '</h4>';
                $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
                
                $hpos_fields = $wpdb->get_results("
                    SELECT meta_key, COUNT(*) as count, COUNT(DISTINCT order_id) as unique_orders
                    FROM {$orders_meta_table} 
                    WHERE meta_key LIKE '%brazil%' 
                       OR meta_key LIKE '%cpf%' 
                       OR meta_key LIKE '%cnpj%'
                    GROUP BY meta_key 
                    ORDER BY count DESC
                    LIMIT 20
                ");
                
                if ($hpos_fields) {
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr><th>' . __('Field Name', 'brazil-checkout-fields') . '</th><th>' . __('Record Count', 'brazil-checkout-fields') . '</th><th>' . __('Unique Orders', 'brazil-checkout-fields') . '</th></tr></thead>';
                    echo '<tbody>';
                    
                    foreach ($hpos_fields as $field) {
                        echo '<tr>';
                        echo '<td><code>' . esc_html($field->meta_key) . '</code></td>';
                        echo '<td>' . $field->count . '</td>';
                        echo '<td>' . $field->unique_orders . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>❌ ' . __('No related fields found in HPOS tables.', 'brazil-checkout-fields') . '</p>';
                }
            } else {
                // 检查传统postmeta表中的数据
                echo '<h4>' . __('Related Fields in PostMeta Table (limited to top 20):', 'brazil-checkout-fields') . '</h4>';
            }
            
            // 无论哪种模式都检查postmeta表（用于对比）
            $brazil_fields = $wpdb->get_results("
                SELECT meta_key, COUNT(*) as count, COUNT(DISTINCT post_id) as unique_orders
                FROM {$wpdb->postmeta} 
                WHERE meta_key LIKE '%brazil%' 
                   OR meta_key LIKE '%cpf%' 
                   OR meta_key LIKE '%cnpj%'
                GROUP BY meta_key 
                ORDER BY count DESC
                LIMIT 20
            ");
            
            if (!$hpos_enabled) {
                // 传统模式时显示postmeta结果
                if ($brazil_fields) {
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr><th>' . __('Field Name', 'brazil-checkout-fields') . '</th><th>' . __('Record Count', 'brazil-checkout-fields') . '</th><th>' . __('Unique Orders', 'brazil-checkout-fields') . '</th></tr></thead>';
                    echo '<tbody>';
                    
                    foreach ($brazil_fields as $field) {
                        echo '<tr>';
                        echo '<td><code>' . esc_html($field->meta_key) . '</code></td>';
                        echo '<td>' . $field->count . '</td>';
                        echo '<td>' . $field->unique_orders . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>❌ ' . __('No related fields found in PostMeta table.', 'brazil-checkout-fields') . '</p>';
                }
            } elseif ($brazil_fields) {
                // HPOS模式时显示postmeta作为对比
                echo '<h4>' . __('Legacy Data in PostMeta Table (for comparison only):', 'brazil-checkout-fields') . '</h4>';
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr><th>' . __('Field Name', 'brazil-checkout-fields') . '</th><th>' . __('Record Count', 'brazil-checkout-fields') . '</th><th>' . __('Unique Orders', 'brazil-checkout-fields') . '</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($brazil_fields as $field) {
                    echo '<tr>';
                    echo '<td><code>' . esc_html($field->meta_key) . '</code></td>';
                    echo '<td>' . $field->count . '</td>';
                    echo '<td>' . $field->unique_orders . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<p><small>' . __('Note: In HPOS mode, these may be old legacy data.', 'brazil-checkout-fields') . '</small></p>';
            }
            
            echo '<h4>' . __('Current Plugin Configuration:', 'brazil-checkout-fields') . '</h4>';
            echo '<ul>';
            echo '<li><strong>BRAZIL_CUSTOMER_TYPE_FIELD:</strong> <code>' . BRAZIL_CUSTOMER_TYPE_FIELD . '</code></li>';
            echo '<li><strong>BRAZIL_DOCUMENT_FIELD:</strong> <code>' . BRAZIL_DOCUMENT_FIELD . '</code></li>';
            echo '<li><strong>' . __('Plugin Version:', 'brazil-checkout-fields') . '</strong> 2.4.0</li>';
            echo '<li><strong>' . __('Storage Mode:', 'brazil-checkout-fields') . '</strong> ' . ($hpos_enabled ? 'HPOS (' . __('High-Performance Order Storage', 'brazil-checkout-fields') . ')' : 'Legacy (' . __('Legacy Post Storage', 'brazil-checkout-fields') . ')') . '</li>';
            echo '</ul>';
            
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . __('Debug information loading error:', 'brazil-checkout-fields') . ' ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
}

// 初始化插件
Brazil_Checkout_Fields_Blocks::get_instance();

/*
字段名称自定义说明 - Brazil CPF/CNPJ
====================

默认字段名称：
- BRAZIL_CUSTOMER_TYPE_FIELD = '_brazil_customer_type'
- BRAZIL_DOCUMENT_FIELD = '_brazil_document'

如需自定义字段名称，请在主题的 functions.php 或其他插件中定义常量：

例如：
define('BRAZIL_CUSTOMER_TYPE_FIELD', '_custom_customer_type');
define('BRAZIL_DOCUMENT_FIELD', '_custom_document');

数据结构：
- {BRAZIL_CUSTOMER_TYPE_FIELD}: 'pessoa_fisica' (CPF) 或 'pessoa_juridica' (CNPJ)
- {BRAZIL_DOCUMENT_FIELD}: 格式化的文档号码 (例如: '914.686.683-31' 或 '88.393.457/0001-54')

当前配置的字段名称：
- 客户类型字段: <?php echo BRAZIL_CUSTOMER_TYPE_FIELD; ?>

- 文档字段: <?php echo BRAZIL_DOCUMENT_FIELD; ?>


Brazil CPF/CNPJ Plugin v2.4.0
支持WooCommerce块编辑器的巴西CPF/CNPJ智能验证系统
*/
