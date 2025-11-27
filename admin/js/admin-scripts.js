(function($) {
    'use strict';

    var WCSchedDisc = {
        
        init: function() {
            this.bindEvents();
        },
        
        bindEvents: function() {
            $(document).on('click', '.wc-sched-disc-upload-btn', this.handleUpload);
            $(document).on('click', '.wc-sched-disc-remove-btn', this.handleRemove);
            $(document).on('keyup', '#product-search', this.debounce(this.handleSearch, 300));
            $(document).on('click', '.product-search-item:not(.no-results)', this.handleProductSelect);
            $(document).on('click', '.remove-product-btn', this.handleProductRemove);
            $(document).on('click', this.handleClickOutside);
            $(document).on('focusin', '#product-search', this.handleSearchFocus);
        },
        
        handleUpload: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var inputId = $btn.data('input');
            var previewId = $btn.data('preview');
            var $input = $('#' + inputId);
            var $preview = $('#' + previewId);
            
            var mediaUploader = wp.media({
                title: wcSchedDiscAdmin.i18n.selectImage,
                button: {
                    text: wcSchedDiscAdmin.i18n.useImage
                },
                multiple: false
            });
            
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                
                $input.val(attachment.url);
                
                var html = '<img src="' + attachment.url + '" alt="Badge">';
                html += '<button type="button" class="button wc-sched-disc-remove-btn" ';
                html += 'data-input="' + inputId + '" data-preview="' + previewId + '">';
                html += '<span class="dashicons dashicons-no"></span> Eliminar</button>';
                
                $preview.html(html);
            });
            
            mediaUploader.open();
        },
        
        handleRemove: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var inputId = $btn.data('input');
            var previewId = $btn.data('preview');
            
            $('#' + inputId).val('');
            $('#' + previewId).empty();
        },
        
        handleSearch: function(e) {
            var $input = $(e.target);
            var searchTerm = $input.val().trim();
            var $results = $('#product-search-results');
            
            if (searchTerm.length < 2) {
                $results.removeClass('active').empty();
                return;
            }
            
            $.ajax({
                url: wcSchedDiscAdmin.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_sched_disc_search_products',
                    nonce: wcSchedDiscAdmin.nonce,
                    search: searchTerm
                },
                beforeSend: function() {
                    $results.html('<div class="product-search-item no-results">Buscando...</div>').addClass('active');
                },
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        var html = '';
                        
                        $.each(response.data, function(i, product) {
                            html += '<div class="product-search-item" ';
                            html += 'data-id="' + product.id + '" ';
                            html += 'data-name="' + WCSchedDisc.escapeHtml(product.name) + '">';
                            html += '<div class="product-title">' + WCSchedDisc.escapeHtml(product.name) + '</div>';
                            html += '<div class="product-meta">ID: ' + product.id;
                            if (product.sku) {
                                html += ' | SKU: ' + WCSchedDisc.escapeHtml(product.sku);
                            }
                            html += ' | ' + product.price + '</div>';
                            html += '</div>';
                        });
                        
                        $results.html(html).addClass('active');
                    } else {
                        $results.html('<div class="product-search-item no-results">' + wcSchedDiscAdmin.i18n.noResults + '</div>').addClass('active');
                    }
                },
                error: function() {
                    $results.html('<div class="product-search-item no-results">Error en la búsqueda</div>').addClass('active');
                }
            });
        },
        
        handleSearchFocus: function() {
            var $results = $('#product-search-results');
            if ($results.children().length > 0) {
                $results.addClass('active');
            }
        },
        
        handleProductSelect: function(e) {
            e.preventDefault();
            
            var $item = $(this);
            var productId = $item.data('id');
            var productName = $item.data('name');
            
            if (!productId || !productName) {
                return;
            }
            
            if ($('.product-row[data-product-id="' + productId + '"]').length > 0) {
                alert(wcSchedDiscAdmin.i18n.alreadyAdded);
                return;
            }
            
            var html = '<div class="product-row" data-product-id="' + productId + '">';
            html += '<div class="product-info">';
            html += '<strong class="product-name">' + WCSchedDisc.escapeHtml(productName) + '</strong>';
            html += '<span class="product-id">ID: ' + productId + '</span>';
            html += '</div>';
            html += '<div class="product-actions">';
            html += '<select name="wc_sched_disc_settings[products][' + productId + ']" class="discount-select">';
            html += '<option value="10" selected>10% descuento</option>';
            html += '<option value="15">15% descuento</option>';
            html += '</select>';
            html += '<input type="number" name="wc_sched_disc_settings[product_quantities][' + productId + ']" ';
            html += 'class="quantity-input" placeholder="Cantidad" min="0" step="1">';
            html += '<button type="button" class="button button-link-delete remove-product-btn">';
            html += '<span class="dashicons dashicons-trash"></span> Eliminar';
            html += '</button>';
            html += '</div>';
            html += '</div>';
            
            $('#products-list').append(html);
            $('.no-products-message').hide();
            
            $('#product-search').val('');
            $('#product-search-results').removeClass('active').empty();
        },
        
        handleProductRemove: function(e) {
            e.preventDefault();
            
            var $row = $(this).closest('.product-row');
            $row.fadeOut(200, function() {
                $(this).remove();
                
                if ($('#products-list .product-row').length === 0) {
                    $('.no-products-message').show();
                }
            });
        },
        
        handleClickOutside: function(e) {
            if (!$(e.target).closest('.product-search-container').length) {
                $('#product-search-results').removeClass('active');
            }
        },
        
        debounce: function(func, wait) {
            var timeout;
            return function() {
                var context = this;
                var args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        },
        
        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };
    
    $(document).ready(function() {
        WCSchedDisc.init();
    });

})(jQuery);