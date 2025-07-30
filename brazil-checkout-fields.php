<?php
/**
 * Plugin Name: Brazil Checkout Fields - Block Editor Compatible
 * Description: 适配WooCommerce块编辑器的巴西结账字段
 * Version: 2.2.0
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
            'customer_type' => 'pessoa_fisica', // 默认选择个人
            'cpf' => '',
            'cnpj' => '',
        );
    }
    
    /**
     * 模式回调
     */
    public function checkout_schema_callback() {
        return array(
            'customer_type' => array(
                'description' => 'Customer type',
                'type' => 'string',
                'context' => array('view', 'edit'),
            ),
            'cpf' => array(
                'description' => 'CPF number',
                'type' => 'string',
                'context' => array('view', 'edit'),
            ),
            'cnpj' => array(
                'description' => 'CNPJ number',
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
                }
                .brazil-checkout-fields h4 {
                    margin: 0 0 15px 0;
                    color: #495057;
                    font-size: 18px;
                }
                .brazil-field-row {
                    margin-bottom: 15px;
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
                    padding: 12px;
                    border: 1px solid #ced4da;
                    border-radius: 4px;
                    font-size: 16px;
                    transition: border-color 0.3s;
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
                    'cpf_required' => 'CPF é obrigatório para Pessoa Física.',
                    'cpf_invalid' => 'CPF inválido. Verifique o número digitado.',
                    'cnpj_required' => 'CNPJ é obrigatório para Pessoa Jurídica.',
                    'cnpj_invalid' => 'CNPJ inválido. Verifique o número digitado.',
                    'customer_type_required' => 'Selecione o tipo de cliente.',
                    'cpf_valid' => 'CPF válido ✓',
                    'cnpj_valid' => 'CNPJ válido ✓'
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
                
                // 验证所有巴西字段
                validateAll: function() {
                    this.errors = [];
                    
                    var customerType = $('#brazil_customer_type').val();
                    
                    if (!customerType) {
                        this.errors.push(brazil_checkout_ajax.messages.customer_type_required);
                        return false;
                    }
                    
                    if (customerType === 'pessoa_fisica') {
                        var cpf = $('#brazil_cpf').val();
                        if (!cpf.trim()) {
                            this.errors.push(brazil_checkout_ajax.messages.cpf_required);
                            return false;
                        }
                        if (!this.validateCPF(cpf)) {
                            this.errors.push(brazil_checkout_ajax.messages.cpf_invalid);
                            return false;
                        }
                    } else if (customerType === 'pessoa_juridica') {
                        var cnpj = $('#brazil_cnpj').val();
                        if (!cnpj.trim()) {
                            this.errors.push(brazil_checkout_ajax.messages.cnpj_required);
                            return false;
                        }
                        if (!this.validateCNPJ(cnpj)) {
                            this.errors.push(brazil_checkout_ajax.messages.cnpj_invalid);
                            return false;
                        }
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
                
                // 设置默认值
                setTimeout(function() {
                    $('#brazil_customer_type').val('pessoa_fisica').trigger('change');
                }, 500);
            }
            
            function injectBrazilFieldsToFieldsBlock() {
                var brazilFieldsHtml = createBrazilFieldsHtml();
                $('.wp-block-woocommerce-checkout-fields-block').append(brazilFieldsHtml);
                console.log('巴西字段已插入到字段块');
                
                setupFieldListeners();
                setupValidation();
                
                setTimeout(function() {
                    $('#brazil_customer_type').val('pessoa_fisica').trigger('change');
                }, 500);
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
                
                setTimeout(function() {
                    $('#brazil_customer_type').val('pessoa_fisica').trigger('change');
                }, 500);
            }
            
            function createBrazilFieldsHtml() {
                return `
                    <div class="brazil-checkout-fields">
                        <h4>🇧🇷 Informações Fiscais Brasileiras</h4>
                        
                        <div class="brazil-field-row">
                            <label for="brazil_customer_type">Tipo de Cliente *</label>
                            <select id="brazil_customer_type" name="brazil_customer_type" required>
                                <option value="pessoa_fisica" selected>Pessoa Física (CPF)</option>
                                <option value="pessoa_juridica">Pessoa Jurídica (CNPJ)</option>
                            </select>
                        </div>
                        
                        <div class="brazil-field-row cpf-field">
                            <label for="brazil_cpf">CPF *</label>
                            <input type="text" id="brazil_cpf" name="brazil_cpf" 
                                   placeholder="000.000.000-00" maxlength="14" required>
                            <div class="cpf-error brazil-field-error"></div>
                            <div class="cpf-success brazil-field-success"></div>
                        </div>
                        
                        <div class="brazil-field-row cnpj-field brazil-field-hidden">
                            <label for="brazil_cnpj">CNPJ *</label>
                            <input type="text" id="brazil_cnpj" name="brazil_cnpj" 
                                   placeholder="00.000.000/0000-00" maxlength="18">
                            <div class="cnpj-error brazil-field-error"></div>
                            <div class="cnpj-success brazil-field-success"></div>
                        </div>
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
            
            function validateFieldReal(fieldId, type) {
                var field = $(fieldId);
                var value = field.val();
                var errorContainer = field.siblings('.brazil-field-error');
                var successContainer = field.siblings('.brazil-field-success');
                
                errorContainer.hide().text('');
                successContainer.hide().text('');
                field.removeClass('brazil-field-invalid brazil-field-valid');
                
                if (!value.trim()) {
                    return;
                }
                
                var isValid = false;
                if (type === 'cpf') {
                    isValid = brazilValidation.validateCPF(value);
                } else if (type === 'cnpj') {
                    isValid = brazilValidation.validateCNPJ(value);
                }
                
                if (isValid) {
                    field.addClass('brazil-field-valid');
                    successContainer.text(brazil_checkout_ajax.messages[type + '_valid']).show();
                } else {
                    field.addClass('brazil-field-invalid');
                    errorContainer.text(brazil_checkout_ajax.messages[type + '_invalid']).show();
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
        
        // 如果没有提交巴西字段数据，设置默认值
        if (!isset($_POST['brazil_customer_type'])) {
            $_POST['brazil_customer_type'] = 'pessoa_fisica';
        }
        
        $customer_type = sanitize_text_field($_POST['brazil_customer_type']);
        
        error_log('Brazil Checkout: 验证客户类型: ' . $customer_type);
        
        if (empty($customer_type)) {
            $customer_type = 'pessoa_fisica'; // 默认为个人
        }
        
        if ($customer_type === 'pessoa_fisica') {
            $cpf = isset($_POST['brazil_cpf']) ? sanitize_text_field($_POST['brazil_cpf']) : '';
            
            error_log('Brazil Checkout: 验证CPF: ' . $cpf);
            
            if (empty($cpf)) {
                $errors[] = 'CPF é obrigatório para Pessoa Física.';
            } elseif (!$this->is_valid_cpf(preg_replace('/[^0-9]/', '', $cpf))) {
                $errors[] = 'CPF inválido. Verifique o número digitado.';
            }
        } elseif ($customer_type === 'pessoa_juridica') {
            $cnpj = isset($_POST['brazil_cnpj']) ? sanitize_text_field($_POST['brazil_cnpj']) : '';
            
            error_log('Brazil Checkout: 验证CNPJ: ' . $cnpj);
            
            if (empty($cnpj)) {
                $errors[] = 'CNPJ é obrigatório para Pessoa Jurídica.';
            } elseif (!$this->is_valid_cnpj(preg_replace('/[^0-9]/', '', $cnpj))) {
                $errors[] = 'CNPJ inválido. Verifique o número digitado.';
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
        
        $customer_type = sanitize_text_field($_POST['customer_type']);
        $cpf = sanitize_text_field($_POST['cpf']);
        $cnpj = sanitize_text_field($_POST['cnpj']);
        
        $response = array('valid' => true, 'errors' => array());
        
        if ($customer_type === 'pessoa_fisica') {
            if (empty($cpf)) {
                $response['valid'] = false;
                $response['errors'][] = 'CPF é obrigatório para Pessoa Física.';
            } elseif (!$this->is_valid_cpf(preg_replace('/[^0-9]/', '', $cpf))) {
                $response['valid'] = false;
                $response['errors'][] = 'CPF inválido. Verifique o número digitado.';
            }
        } elseif ($customer_type === 'pessoa_juridica') {
            if (empty($cnpj)) {
                $response['valid'] = false;
                $response['errors'][] = 'CNPJ é obrigatório para Pessoa Jurídica.';
            } elseif (!$this->is_valid_cnpj(preg_replace('/[^0-9]/', '', $cnpj))) {
                $response['valid'] = false;
                $response['errors'][] = 'CNPJ inválido. Verifique o número digitado.';
            }
        }
        
        wp_send_json($response);
    }
    
    /**
     * 保存结账字段数据
     */
    public function save_checkout_fields($order, $request) {
        $customer_type = isset($_POST['brazil_customer_type']) ? sanitize_text_field($_POST['brazil_customer_type']) : 'pessoa_fisica';
        $order->update_meta_data('_customer_type', $customer_type);
        
        if ($customer_type === 'pessoa_fisica' && isset($_POST['brazil_cpf'])) {
            $order->update_meta_data('_cpf', sanitize_text_field($_POST['brazil_cpf']));
        }
        
        if ($customer_type === 'pessoa_juridica' && isset($_POST['brazil_cnpj'])) {
            $order->update_meta_data('_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        }
    }
    
    /**
     * 保存字段数据 - 后备方法
     */
    public function save_checkout_fields_fallback($order_id) {
        $customer_type = isset($_POST['brazil_customer_type']) ? sanitize_text_field($_POST['brazil_customer_type']) : 'pessoa_fisica';
        update_post_meta($order_id, '_customer_type', $customer_type);
        
        if ($customer_type === 'pessoa_fisica' && isset($_POST['brazil_cpf'])) {
            update_post_meta($order_id, '_cpf', sanitize_text_field($_POST['brazil_cpf']));
        }
        
        if ($customer_type === 'pessoa_juridica' && isset($_POST['brazil_cnpj'])) {
            update_post_meta($order_id, '_cnpj', sanitize_text_field($_POST['brazil_cnpj']));
        }
    }
    
    /**
     * 保存字段数据 - 创建订单时
     */
    public function save_checkout_fields_create_order($order, $data) {
        $customer_type = isset($_POST['brazil_customer_type']) ? sanitize_text_field($_POST['brazil_customer_type']) : 'pessoa_fisica';
        $order->update_meta_data('_customer_type', $customer_type);
        
        if ($customer_type === 'pessoa_fisica' && isset($_POST['brazil_cpf'])) {
            $order->update_meta_data('_cpf', sanitize_text_field($_POST['brazil_cpf']));
        }
        
        if ($customer_type === 'pessoa_juridica' && isset($_POST['brazil_cnpj'])) {
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
